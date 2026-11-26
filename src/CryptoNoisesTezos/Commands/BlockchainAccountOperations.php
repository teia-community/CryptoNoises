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

            // No level cap by default, unlike the other syncs: this one does thousands of levels a second while it
            // catches up, so a 1,000,000-level cap ended a run every few minutes - an exit and a restart each time.
            // The process lifetime (runTimeLimit) bounds a run anyway.
            $runner->run($tsStart, (int) $this->getCmdVar('limit', PHP_INT_MAX));
            $this->logMsg("END.");
        }
        catch (Exception $e)
        {
            $this->logErr("ERROR: " . $e->getMessage());

            // A stage that exits at once on an error restarts in a tight loop until the error is resolved
            sleep(30);

            // An error ends the process and "restart: always" starts it again at once: without a pause, an error
            // that persists (a level marked "error" waits for a human) turns into a restart loop
            sleep(self::kERROR_PAUSE_SEC);
        }
    }

    const kERROR_PAUSE_SEC = 30;

    // A stage that has not moved for this long is taken as switched off, so it cannot keep every row for good
    const kEVENTS_STALE_SEC = 7 * 86400;

    /**
     * Deletes rows the bots' account-events stage has read, keeping `offset` levels (default 50,000). Without the
     * bots, or with the stage stopped for a week, it follows the sync instead.
     */
    public function cleanup(): void
    {
        $offset = max(Config::kLOG_CLEANUP_MIN_OFFSET, (int) $this->getCmdVar('offset', Config::kLOG_CLEANUP_DEFAULT_OFFSET));
        $keepFrom = Models\FixerAccountOperationsSync::model()->getLastSuccessLevelIndex();

        if ($keepFrom === null)
            return;

        try
        {
            $row = \CryptoCore\Vss\Vss::db()->queryRow("SELECT cfg_value, ts_updated FROM config WHERE cfg_key = 'TezosAccountOpsAnalyzer.LastSyncedLevel'");

            if (!empty($row) && strtotime((string) $row['ts_updated']) >= time() - self::kEVENTS_STALE_SEC)
                $keepFrom = min($keepFrom, (int) $row['cfg_value']);
        }
        catch (\Throwable $e) {}   // no bots database here

        $deleted = 0;

        do
        {
            $chunk = (int) \CryptoCore\Vss\Vss::dbTezos()->query("DELETE FROM account_operations WHERE level < :l LIMIT 10000",
                ['l' => $keepFrom - $offset]);
            $deleted += $chunk;
        }
        while ($chunk >= 10000);

        if ($deleted)
            $this->logMsg("Deleted $deleted row(s) before level ".($keepFrom - $offset));
    }

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
