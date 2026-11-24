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

        $dropped = Models\Log\Transactions::model()->removeTablesBefore(min($syncedLevel, (int) $analyzedLevel) - $offset);

        if (!empty($dropped))
            $this->logMsg('Dropped: '.join(', ', $dropped));
    }
}
