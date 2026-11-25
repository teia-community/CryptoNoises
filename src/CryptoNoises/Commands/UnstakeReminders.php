<?php

namespace CryptoNoises\Commands;

use CryptoCore\Process\Shutdown;
use CryptoCore\Time;
use CryptoNoises\Config;
use CryptoNoises\Core\CustomData;
use CryptoNoises\Entities\Address;
use CryptoNoises\Entities\EventFlow;
use CryptoNoises\Entities\OptionsNotifier;
use CryptoNoises\Enum;
use CryptoNoises\Models;
use CryptoNoises\Networks\Tezos\TezosNetwork;
use CryptoNoises\Networks\Tezos\TezosNetworkParser;
use CryptoNoisesTezos\Vendors\Tzkt;
use Exception;

/**
 * Reminds stakers about unstaked tez that have unlocked but are still not finalized.
 *
 * Unstaked tez stay frozen until their unlock cycle and then still need a finalize operation - by the staker, or by
 * anyone for them - before they can be spent. Most are finalized soon after unlocking, but not all: at any time a few
 * dozen requests sit unlocked for hours or days. Each user chooses in Settings how long after unlocking to be
 * reminded (OptionsNotifier::GetFinalizeReminderValues(), one day by default, or off).
 *
 * Every pass reads all unlocked, unfinalized requests on the chain (a few dozen, one or two TzKT requests) and keeps
 * those of watched wallets. A reminder is an event keyed by (request id, user id) at block 0, so EventsFlow::insert()
 * finds it on every later pass and nobody is reminded twice; a request finalized in the meantime is simply no
 * longer in the list.
 */
class UnstakeReminders extends AbstractCommand
{
    // Unlocks happen at cycle boundaries (about one a day), and the delays are counted in days
    const kPASS_INTERVAL_SEC = 600;

    public function run(): void
    {
        $tsStart = Time::Microtime();

        set_time_limit(Config::runTimeLimit() + Config::kJOBS_TIMEOUT_DEFAULT);

        if (!$this->checkLockAndWaitUntilNoOtherInstanceRuns('command-lock.unstake-reminders', Config::kJOBS_TIMEOUT_DEFAULT))
        {
            $this->idle($tsStart);
            return;
        }

        while (!Shutdown::Requested())
        {
            try
            {
                $due = $this->passOnce();

                // Printed every pass (every 10 minutes), so the container log shows the service is alive
                $this->logMsg(gmdate('H:i:s')." unstake reminders: $due due (already sent ones are skipped)", "\n", true);
            }
            catch (Exception $e)
            {
                $this->logErr("ERROR: ".$e->getMessage());
            }

            if (Time::Microtime() - $tsStart > Config::runTimeLimit() || Shutdown::Requested())
                break;

            sleep(self::kPASS_INTERVAL_SEC);
        }
    }

    /**
     * @return int reminders due in this pass, including those sent by an earlier pass (they are not sent again)
     */
    public function passOnce(): int
    {
        $head = Tzkt::GetBlockLastIndex();

        if ($head === null)
            throw new Exception("TzKT did not answer: no head level");

        $requests = Tzkt::GetUnlockedUnstakeRequests($head);

        if ($requests === null)
            throw new Exception("TzKT did not answer: no unstake requests");

        if (empty($requests))
            return 0;

        $watched = array_flip(Models\Wallets::model()->getActiveAddresses());
        $parser  = new TezosNetworkParser();
        $due     = 0;

        foreach ($requests as $request)
        {
            // No staker: a baker unstaking its own stake
            $staker = $request['staker']['address'] ?? $request['baker']['address'] ?? null;
            $baker  = $request['baker']['address'] ?? null;

            if ($staker === null || !isset($watched[$staker]) || empty($request['unlockTime']))
                continue;

            $unlockTs = strtotime($request['unlockTime']);
            $adid     = Address::GetOrCreateAdid($staker);

            foreach (Models\Users::model()->getActiveUsersByWalletAdid($adid) as $userItem)
            {
                $userId = (int) $userItem['user_id'];
                $delay  = OptionsNotifier::NewForUser($userId)->getFinalizeReminderSec();

                if ($delay < 0 || Time::Time() < $unlockTs + $delay)
                    continue;

                $event = new EventFlow();
                $event->initInTezosNetwork(EventFlow::UNDEFINED_NETWORK_BLOCK, (int) $request['id'], $userId);

                $event->event_type          = Enum\EventFlowType::UnstakeFinalizable;
                $event->transaction_event   = Time::Now();
                $event->target_address      = $staker;
                $event->sender_address      = $baker;
                $event->price               = (float) ($request['actualAmount'] ?? 0);
                $event->currency            = TezosNetwork::kCURRENCY_ID;
                $event->userIds             = [$userId];
                $event->custom_data         = array_filter([
                    CustomData::kBAKER_NAME   => $baker !== null ? (($request['baker']['alias'] ?? null) ?: Tzkt::GetAlias($baker)) : null,
                    CustomData::kUNLOCK_TIME  => $request['unlockTime'],
                    CustomData::kUNLOCK_CYCLE => $request['unlockCycle'] ?? null,
                ], fn ($v) => $v !== null);

                $parser->pushEvent($event);
                $due++;
            }
        }

        $parser->save();

        return $due;
    }

    public function runOnce(): void
    {
        $due = $this->passOnce();
        $this->logMsg("Unstake reminders due: $due (already sent ones are skipped)", "\n", true);
    }

    /**
     * Waits out the process lifetime instead of returning: an immediate exit would have "restart: always" start
     * the command again at once, in a loop.
     */
    private function idle(float $tsStart): void
    {
        while (Time::Microtime() - $tsStart <= Config::runTimeLimit() && !Shutdown::Requested())
            sleep(1);
    }
}
