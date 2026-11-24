<?php

namespace CryptoNoises\Bots\Threads;

use CryptoNoises\Models;
use Discord\Parts\Guild\Guild;
use Discord\Parts\Guild\Member\Member;
use Exception;
use Throwable;

/**
 * Keeps `discord_users` in sync with the members of the project Discord server (kDISCORD_GUILD_ID).
 * Used to decide where to report a notification that could not be sent as a DM (see DiscordBotConnection).
 */
trait DiscordAssistantThread_ServerMembers
{
    private function serverMembers_sync(): void
    {
        if ($this->isWorkerActive(self::kWORKER_ID_ServerMembers))
            return;

        $this->activateWorker(self::kWORKER_ID_ServerMembers);

        try
        {
            /* @var Guild $guild */
            $guild = $this->discord->guilds[kDISCORD_GUILD_ID];

            if ($guild === null)
                throw new Exception('Cannot find guild instance');

            $guild->members->freshen()->then(
                function () use ($guild)
                {
                    $this->serverMembers_save($guild);
                },
                function ($error)
                {
                    $this->closeWorker(self::kWORKER_ID_ServerMembers);

                    $this->discord->getLogger()->error("ERR: Cannot refresh guild members", [
                        'location'      => get_class($this).'->serverMembers_sync',
                        'error'         => $error instanceof Throwable ? $error->getMessage() : null,
                    ]);
                });
        }
        catch (Exception $e)
        {
            $this->closeWorker(self::kWORKER_ID_ServerMembers);

            $this->discord->getLogger()->error("ERR: ".$e->getMessage(), [
                'location'      => get_class($this).'->'.__FUNCTION__,
            ]);
        }
    }

    private function serverMembers_save(Guild $guild): void
    {
        // Runs inside a promise callback: always close the worker, or a DB error would block every next sync
        try
        {
            /** @var Member $member */
            foreach ($guild->members as $member)
            {
                Models\DiscordUsers::model()->save($member->user->id, $member->user->username, $member->user->discriminator);
            }

            // Members not seen for a while have left the server
            Models\DiscordUsers::model()->fixDisconnectedUsers();
        }
        catch (Throwable $e)
        {
            $this->discord->getLogger()->error("ERR: Cannot save guild members: ".$e->getMessage(), [
                'location'      => get_class($this).'->'.__FUNCTION__,
            ]);
        }
        finally
        {
            $this->closeWorker(self::kWORKER_ID_ServerMembers);
        }
    }
}
