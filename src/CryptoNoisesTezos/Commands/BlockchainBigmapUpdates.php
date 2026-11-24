<?php

namespace CryptoNoisesTezos\Commands;

use CryptoNoisesTezos\Analyzers;
use CryptoNoisesTezos\Config;
use CryptoNoisesTezos\Models;
use CryptoNoisesTezos\Syncs;
use CryptoCore\Time;
use CryptoCore\Vss\Vss;
use Exception;

class BlockchainBigmapUpdates extends AbstractCommand
{
    public function syncNew(): void
    {
        $tsStart = Time::Microtime();

        ini_set('memory_limit', '512M');
        set_time_limit(Config::runTimeLimit() + Config::kJOBS_TIMEOUT_BIGMAP_UPDATE_SYNC);

        if (!$this->checkLockAndWaitUntilNoOtherInstanceRuns('command-lock.blockchain.bigmap-updates.sync-new', Config::kJOBS_TIMEOUT_DEFAULT))
            return;

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $sync = new Syncs\SyncBigmapUpdates();

        $runner = new Syncs\BatchSyncRunner(Models\FixerBigmapUpdatesSync::model(), $sync, function (string $msg) { $this->logMsg($msg); });

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

        $sync = new Syncs\SyncBigmapUpdates();

        $runner = new Syncs\RangeSyncRunner(Models\FixerBigmapUpdatesSync::model(), $sync, function (string $msg) { $this->logMsg($msg); });

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

    public function syncCheck(): void
    {
        $tsStart = Time::Microtime();

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        ini_set('memory_limit', '256M');
        set_time_limit(Config::runTimeLimit() + Config::kJOBS_TIMEOUT_BIGMAP_UPDATE_SYNC);

        if (!$this->checkLockAndWaitUntilNoOtherInstanceRuns('command-lock.blockchain.bigmap-updates.sync-check', Config::kJOBS_TIMEOUT_DEFAULT))
            return;

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $threadId   = (int)$this->getCmdVar('thread-id', 0);
        $offset     = (int)$this->getCmdVar('offset', 0);
        $limit      = $this->getCmdVar('limit', 1000);

        try
        {
            if (!in_array($threadId, [1, 2, 3]))
                throw new Exception('thread-id must be 1..3');

            if ($offset <= 0)
                throw new Exception('offset must be greater than 0');

            while($limit-- > 0)
            {
                Vss::dbCloseAll();

                if (Time::Microtime() - $tsStart > Config::runTimeLimit())
                    break;

                // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

                $jobToSync = Models\FixerBigmapUpdatesSync::model()->getNextJobToCheck($threadId, $offset);

                if ($jobToSync === null)
                {
                    $this->logMsg("No job to check. Wait 1 sec and try again..");
                    sleep(1);
                    continue;
                }

                $level = $jobToSync['level'];

                // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

                $syncer = new Syncs\SyncBigmapUpdates();
                $recordsCount = $syncer->getRecordsCount($level);

                if ($recordsCount === null)
                    throw new Exception("Cannot detect records count for block #$level");

                if ($recordsCount == $jobToSync['items_count'])
                {
                    $this->logMsg("#$level: records count match ($recordsCount)");
                    Models\FixerBigmapUpdatesSync::model()->fixRecordsCountOnCheck($threadId, $level, $recordsCount);
                }
                else
                {
                    Vss::dbTezos()->transactionBegin();

                    Models\FixerBigmapUpdatesSync::model()->cleanUpLevelAndAllAfter('FixerBigmapUpdatesSync', $level);
                    Models\FixerBigmapUpdatesAnalyze::model()->cleanUpLevelAndAllAfter('FixerBigmapUpdatesAnalyze', $level);

                    Vss::dbTezos()->transactionCommit();

                    throw new Exception("#$level: Records count mismatch: {$jobToSync['items_count']} > $recordsCount");
                }
            }

            $this->logMsg("END.");
        }
        catch (Exception $e)
        {
            $this->logErr("ERROR: Thread #$threadId: " . $e->getMessage());
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

        // Slowest reader of bigmap tables: syncer or bigmap analyzer
        $syncedLevel   = Analyzers\AnalyzeHelper::GetLastBigmapsSyncedBlock();
        $analyzedLevel = Analyzers\AnalyzeHelper::GetLastBigmapsAnalyzedBlock();

        if ($syncedLevel === null || $analyzedLevel === null)
            return;

        $dropped = Models\Log\BigmapUpdates::model()->removeTablesBefore(min($syncedLevel, $analyzedLevel) - $offset);

        if (!empty($dropped))
            $this->logMsg('Dropped: '.join(', ', $dropped));
    }
}
