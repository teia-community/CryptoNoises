<?php

namespace CryptoNoisesTezos\Commands;

use CryptoNoisesTezos\Config;
use CryptoNoisesTezos\Models;
use CryptoNoisesTezos\Syncs;
use CryptoCore\Time;
use Exception;

class BlockchainTransactions extends AbstractCommand
{
    public function syncNew(): void
    {
        $tsStart = Time::Microtime();

        ini_set('memory_limit', '512M');
        set_time_limit(Config::runTimeLimit() + Config::kJOBS_TIMEOUT_TRANSACTION_SYNC);

        if (!$this->checkLockAndWaitUntilNoOtherInstanceRuns('command-lock.blockchain.transaction.sync-new', Config::kJOBS_TIMEOUT_DEFAULT))
            return;

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $sync = new Syncs\SyncTransactions();
        $sync->verbose = $this->verbose;

        $runner = new Syncs\BatchSyncRunner(Models\FixerTransactionsSync::model(), $sync, function (string $msg) { $this->logMsg($msg); });

        try
        {
            $runner->run($tsStart, (int) $this->getCmdVar('limit', 1000000));
            $this->logMsg("END.");
        }
        catch (Exception $e)
        {
            $this->logErr("ERROR: " . $e->getMessage());

            // A stage that exits at once on an error restarts in a tight loop until the error is resolved
            sleep(30);
        }
    }

    public function syncRange(): void
    {
        $this->verbose = true;

        ini_set('memory_limit', '256M');
        set_time_limit(86400);

        $sync = new Syncs\SyncTransactions();
        $sync->verbose = $this->verbose;

        $runner = new Syncs\RangeSyncRunner(Models\FixerTransactionsSync::model(), $sync, function (string $msg) { $this->logMsg($msg); });

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

    // A wallet-events stage that has not finished a level for this long is taken as switched off, so that it does not
    // hold the raw log back for good
    const kWALLET_EVENTS_STALE_SEC = 7 * 86400;

    /**
     * The last level the bots' wallet-events stage (/tezos-network/analyze-transactions/new) finished, while it is in
     * use: null when the bots were never started (no row) or it has not finished a level for kWALLET_EVENTS_STALE_SEC.
     */
    private static function WalletEventsLevel(): ?int
    {
        try
        {
            $row = \CryptoCore\Vss\Vss::db()->queryRow("SELECT level, ts_scan_finished FROM fixer_tezos_transactions_analyze
                WHERE state = 'success' ORDER BY level DESC LIMIT 1");
        }
        catch (\Throwable $e)
        {
            return null;    // no bots database here
        }

        if (empty($row) || strtotime((string) $row['ts_scan_finished']) < time() - self::kWALLET_EVENTS_STALE_SEC)
            return null;

        return (int) $row['level'];
    }

    public function cleanup(): void
    {
        $offset = (int) $this->getCmdVar('offset', Config::kLOG_CLEANUP_DEFAULT_OFFSET);

        if ($offset < Config::kLOG_CLEANUP_MIN_OFFSET)
        {
            $this->logErr('offset must be >= '.Config::kLOG_CLEANUP_MIN_OFFSET);
            return;
        }

        // Slowest reader of transaction tables: syncer or Tezos analyzer
        $syncedLevel   = Models\FixerTransactionsSync::model()->getLastSuccessLevelIndex();
        $analyzedLevel = Models\Config::model()->getValue('TezosAnalyzer.LastSyncedLevel');

        if ($syncedLevel === null || $analyzedLevel === null)
            return;

        $keepFrom = min($syncedLevel, (int) $analyzedLevel);

        // The bots' wallet-events stage reads these tables too, and runs behind the indexer while it catches up or
        // after it was down. Without waiting for it, a stage more than $offset levels behind lost the tables it had
        // not read yet, and analyzed those levels as empty: no error, just missed notifications.
        $eventsLevel = self::WalletEventsLevel();

        if ($eventsLevel !== null && $eventsLevel < $keepFrom)
        {
            $keepFrom = $eventsLevel;
            $this->logMsg("Keeping tables from level $eventsLevel for the wallet-events stage");
        }

        $dropped = Models\Log\Transactions::model()->removeTablesBefore($keepFrom - $offset);

        if (!empty($dropped))
            $this->logMsg('Dropped: '.join(', ', $dropped));
    }
}
