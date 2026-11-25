<?php

namespace CryptoNoisesTezos\Commands;

use CryptoNoisesTezos\Config;
use CryptoNoisesTezos\Models;
use CryptoNoisesTezos\Syncs;
use CryptoCore\Time;
use Exception;

/**
 * Delegations, staking, baker parameter changes and originations: TzKT -> account_operations.
 */
class BlockchainAccountOperations extends AbstractCommand
{
    public function syncNew(): void
    {
        $tsStart = Time::Microtime();

        ini_set('memory_limit', '256M');
        set_time_limit(Config::runTimeLimit() + Config::kJOBS_TIMEOUT_TRANSACTION_SYNC);

        if (!$this->checkLockAndWaitUntilNoOtherInstanceRuns('command-lock.blockchain.account-operations.sync-new', Config::kJOBS_TIMEOUT_DEFAULT))
            return;

        $sync = new Syncs\SyncAccountOperations();
        $sync->verbose = (bool) $this->verbose;

        $runner = new Syncs\BatchSyncRunner(Models\FixerAccountOperationsSync::model(), $sync, function (string $msg) { $this->logMsg($msg); });

        try
        {
            $this->seedIfNeverRan();

            $runner->run($tsStart, (int) $this->getCmdVar('limit', 1000000));
            $this->logMsg("END.");
        }
        catch (Exception $e)
        {
            $this->logErr("ERROR: " . $e->getMessage());

            // An error ends the process and "restart: always" starts it again at once: without a pause, an error
            // that persists (a level marked "error" waits for a human) turns into a restart loop
            sleep(self::kERROR_PAUSE_SEC);
        }
    }

    const kERROR_PAUSE_SEC = 30;

    public function syncRange(): void
    {
        $this->verbose = true;

        ini_set('memory_limit', '256M');
        set_time_limit(86400);

        $sync = new Syncs\SyncAccountOperations();
        $sync->verbose = true;

        $runner = new Syncs\RangeSyncRunner(Models\FixerAccountOperationsSync::model(), $sync, function (string $msg) { $this->logMsg($msg); });

        try
        {
            list($levelMin, $levelMax) = Syncs\RangeSyncRunner::ParseRange((string) $this->getCmdVar('level'));

            $runner->run($levelMin, $levelMax);
            $this->logMsg("END.");
        }
        catch (Exception $e)
        {
            $this->logErr("ERROR: " . $e->getMessage());
        }
    }

    /**
     * A fresh install seeds this cursor from sql/data like the others (moved to TEZOS_START_LEVEL by db-init).
     * An install that predates this sync has the table empty: it starts where the transaction sync stands, so the
     * account operations line up with everything else the bots already know about, rather than at block 1.
     */
    private function seedIfNeverRan(): void
    {
        if (Models\FixerAccountOperationsSync::model()->getLastJobItem() !== null)
            return;

        $transactionsNext = Models\FixerTransactionsSync::model()->getNextLevelToProcess();

        if ($transactionsNext === null)
            $transactionsNext = (int) Models\FixerTransactionsSync::model()->getLastJobItem()['level'];

        Models\FixerAccountOperationsSync::model()->seedStartLevel($transactionsNext);

        $this->logMsg("account_operations never synced: starting at level $transactionsNext, where the transaction sync is", "\n", true);
    }
}
