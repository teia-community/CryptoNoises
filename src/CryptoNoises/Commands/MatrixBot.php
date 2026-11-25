<?php

namespace CryptoNoises\Commands;

use CryptoCore\Process\Shutdown;
use CryptoCore\Time;
use CryptoCore\Vss\Vss;
use CryptoNoises\Bots\Connections\MatrixBotConnection;
use CryptoNoises\Bots\Connections\MatrixClient;
use CryptoNoises\Bots\Connections\MatrixCrossSigning;
use CryptoNoises\Bots\Gates\MatrixBotGate;
use CryptoNoises\Config;
use CryptoNoises\Localize\Notification;
use CryptoNoises\Models;
use Throwable;

/**
 * The Matrix bot's incoming side: a /sync long-poll loop (through pantalaimon, which decrypts).
 *
 * - An invite to a direct message is accepted and answered with the help text; the inviter becomes a user, and that
 *   room is where their notifications go. A room with more members is left with a note: notifications are personal.
 * - Text messages there are commands (MatrixBotGate).
 * - When the user leaves their room, the bot leaves too and stops notifying them.
 *
 * The sync position is kept in `config`; the first run starts from now, so old messages are not answered.
 */
class MatrixBot extends AbstractCommand
{
    const kCFG_SYNC_SINCE           = 'Matrix.SyncSince';
    const kSYNC_TIMEOUT_MS          = 30000;

    // Only what the bot reads: messages and memberships, a short timeline, no presence or account data
    const kSYNC_FILTER              = '{"presence":{"types":[]},"account_data":{"types":[]},'
                                    . '"room":{"timeline":{"limit":50,"types":["m.room.message","m.room.member"]},'
                                    . '"state":{"types":["m.room.member"],"lazy_load_members":true},'
                                    . '"ephemeral":{"types":[]},"account_data":{"types":[]}}}';

    /** @var array<string, int> room => joined members, per process */
    private array $roomSizes = [];

    /**
     * @var array<string, array{string, float}> room => [inviter, joined at]: welcomed one sync later. Sent at once,
     * the welcome went out before pantalaimon had seen the room and the user's devices, and could not be decrypted.
     */
    private array $pendingWelcomes = [];
    const kWELCOME_DELAY_SEC        = 3;

    public function __construct()
    {
        parent::__construct();

        Config::cfg()->initializeByPlatform(Config::kPLATFORM_Matrix);
    }

    public function sync(): void
    {
        $tsStart = Time::Microtime();

        set_time_limit(Config::runTimeLimit() + 120);

        if (!MatrixClient::IsConfigured())
        {
            $this->logErr('Matrix bot is not configured: set MATRIX_USER and MATRIX_PASSWORD');
            sleep(60);          // not a restart loop under "restart: always"
            return;
        }

        if (!$this->checkLockAndWaitUntilNoOtherInstanceRuns('command-lock.matrix.sync', Config::kJOBS_TIMEOUT_DEFAULT))
            return;

        $since = Models\Config::model()->getValue(self::kCFG_SYNC_SINCE) ?: null;

        // Clients show the bot's messages as from a device verified by its owner only once it is cross-signed
        try
        {
            foreach ((new MatrixCrossSigning(MatrixClient::I()))->ensure() as $line)
                $this->logMsg("Cross-signing: $line");
        }
        catch (Throwable $e)
        {
            $this->logErr('Cross-signing: '.$e->getMessage());
        }

        while (Time::Microtime() - $tsStart <= Config::runTimeLimit() && !Shutdown::Requested())
        {
            Vss::dbCloseAll();

            try
            {
                // Short polls while a welcome waits, so it goes out a few seconds after the join, not after the next event
                $timeoutMs = $since === null ? 0 : ($this->pendingWelcomes ? self::kWELCOME_DELAY_SEC * 1000 : self::kSYNC_TIMEOUT_MS);
                $data = MatrixClient::I()->sync($since, $timeoutMs, self::kSYNC_FILTER);

                // The first sync is the starting point: invites are accepted, old messages are not answered
                $this->handleInvites($data['rooms']['invite'] ?? []);

                if ($since !== null)
                    $this->handleJoinedRooms($data['rooms']['join'] ?? []);

                $this->sendPendingWelcomes();

                $since = (string) ($data['next_batch'] ?? $since);
                Models\Config::model()->setValue(self::kCFG_SYNC_SINCE, $since);
            }
            catch (Throwable $e)
            {
                $this->logErr('ERROR: '.$e->getMessage());
                sleep(10);
            }
        }

        $this->logMsg('END.');
    }

    //------------------------------------------------------------------------------------------------------------------

    private function handleInvites(array $invites): void
    {
        $bot = MatrixClient::I()->userId();

        foreach ($invites as $roomId => $room)
        {
            $inviter = null;

            foreach ($room['invite_state']['events'] ?? [] as $event)
                if (($event['type'] ?? '') == 'm.room.member' && ($event['state_key'] ?? '') == $bot)
                    $inviter = $event['sender'] ?? null;

            try
            {
                MatrixClient::I()->joinRoom($roomId);
                $members = MatrixClient::I()->joinedMembers($roomId);
                $this->roomSizes[$roomId] = count($members);

                if (count($members) > 2 || $inviter === null)
                {
                    $this->say($roomId, 'I only send notifications in a direct message: start a chat with me instead. Leaving this room.');
                    MatrixClient::I()->leaveRoom($roomId);
                    $this->logMsg("Left $roomId: ".count($members).' members');
                    continue;
                }

                $this->gate($inviter, $roomId);     // the user and their room exist from now on
                $this->pendingWelcomes[$roomId] = [$inviter, microtime(true)];

                $this->logMsg("Joined a direct message with a user");
            }
            catch (Throwable $e)
            {
                $this->logErr("Invite to $roomId: ".$e->getMessage());
            }
        }
    }

    private function handleJoinedRooms(array $rooms): void
    {
        $bot = MatrixClient::I()->userId();

        foreach ($rooms as $roomId => $room)
        {
            foreach ($room['timeline']['events'] ?? [] as $event)
            {
                $sender = $event['sender'] ?? '';

                if ($sender === '' || $sender === $bot)
                    continue;

                try
                {
                    if (($event['type'] ?? '') == 'm.room.member')
                    {
                        if (in_array($event['content']['membership'] ?? '', ['leave', 'ban']) && ($event['state_key'] ?? '') === $sender)
                            $this->userLeft($sender, $roomId);

                        continue;
                    }

                    if (($event['type'] ?? '') != 'm.room.message' || ($event['content']['msgtype'] ?? '') != 'm.text')
                        continue;

                    if ($this->roomSize($roomId) > 2)
                        continue;   // a group room the bot is still in: it does not take commands there

                    $this->gate($sender, $roomId)?->routeByUserMessage((string) ($event['content']['body'] ?? ''));
                }
                catch (Throwable $e)
                {
                    $this->logErr("Message in $roomId: ".$e->getMessage());
                }
            }
        }
    }

    /** The user left their room: stop notifying them, and leave it as well */
    private function userLeft(string $matrixUserId, string $roomId): void
    {
        $ref = Models\MatrixUsers::model()->getRefByRoom($roomId);

        if ($ref !== null)
        {
            $user = \CryptoNoises\Entities\User::GetByPlatformUserId(Config::kPLATFORM_Matrix, $ref, $ref);
            $user?->deactivate('Left the Matrix room');
        }

        MatrixClient::I()->leaveRoom($roomId);
        $this->logMsg('A user left their direct message; left it too');
    }

    private function sendPendingWelcomes(): void
    {
        foreach ($this->pendingWelcomes as $roomId => [$inviter, $joinedAt])
        {
            if (microtime(true) - $joinedAt < self::kWELCOME_DELAY_SEC)
                continue;

            unset($this->pendingWelcomes[$roomId]);

            try
            {
                $this->gate($inviter, $roomId)?->welcome();
            }
            catch (Throwable $e)
            {
                $this->logErr("Welcome in $roomId: ".$e->getMessage());
            }
        }
    }

    //------------------------------------------------------------------------------------------------------------------

    private function gate(string $matrixUserId, string $roomId): ?MatrixBotGate
    {
        $connection = new MatrixBotConnection();

        return $connection->initUserByMatrixIds($matrixUserId, $roomId) ? new MatrixBotGate($connection) : null;
    }

    private function roomSize(string $roomId): int
    {
        return $this->roomSizes[$roomId] ??= count(MatrixClient::I()->joinedMembers($roomId));
    }

    private function say(string $roomId, string $text): void
    {
        MatrixClient::I()->sendMessage($roomId, ['msgtype' => 'm.notice', 'body' => $text]);
    }
}
