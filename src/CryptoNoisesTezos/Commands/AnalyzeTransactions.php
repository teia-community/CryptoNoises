<?php

namespace CryptoNoisesTezos\Commands;

use CryptoNoisesTezos\Analyzers;
use CryptoNoisesTezos\Config;
use CryptoNoisesTezos\Models;
use CryptoCore\Process\ProgressLog;
use CryptoCore\Process\Shutdown;
use CryptoCore\Time;
use CryptoCore\Vss\Vss;

class AnalyzeTransactions extends AbstractCommand
{
    const kRECONNECT_EVERY_LEVELS = 1000;

    public function analyzeNew(): void
    {
        $tsStart = Time::Microtime();

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        ini_set('memory_limit', '512M');
        set_time_limit(Config::runTimeLimit() + Config::kJOBS_TIMEOUT_TRANSACTION_ANALYZE);

        if (!$this->checkLockAndWaitUntilNoOtherInstanceRuns('command-lock.analyze.transactions.new', Config::kJOBS_TIMEOUT_DEFAULT))
            return;

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        try
        {
            $levelsSinceReconnect = 0;
            $progress = new ProgressLog('analyze transactions');

            while (true)
            {
                if (Time::Microtime() - $tsStart > Config::runTimeLimit() || Shutdown::Requested())
                    break;

                $lastSyncedLevel = (int) Models\Config::model()->getValue('TezosAnalyzer.LastSyncedLevel');
                $lastFullySyncedLevel = Analyzers\AnalyzeHelper::GetLastFullySyncedBlock();

                if ($lastFullySyncedLevel === null)
                {
                    $this->logErr('Cannot detect $lastFullySyncedLevel value');
                    sleep(1);
                    continue;
                }

                // Every path below starts here, so one call covers analyzing, skipping empty levels and idling
                $progress->report($lastSyncedLevel, $lastFullySyncedLevel, 'synced');

                if ($lastSyncedLevel >= $lastFullySyncedLevel)
                {
                    $this->logMsg("Sleep for 1 sec");
                    sleep(1);
                    continue;
                }

                // Levels without transactions need no analysis: jump to the next level that has some
                $level = Models\FixerTransactionsSync::model()->getNextLevelWithItems($lastSyncedLevel + 1, $lastFullySyncedLevel);

                if ($level === null)
                {
                    Models\Config::model()->setValue('TezosAnalyzer.LastSyncedLevel', $lastFullySyncedLevel);
                    continue;
                }

                $this->logMsg("Sync #$level");

                $analyzer = new Analyzers\AnalyzeTransactions();
                $analyzer->verbose = $this->verbose;
                $analyzer->analyze($level);

                Vss::dbTezos()->transactionBegin();
                Models\Config::model()->setValue('TezosAnalyzer.LastSyncedLevel', $level);
                Vss::dbTezos()->transactionCommit();

                // Reconnect now and then, not per level: during a catch-up thousands of levels per second would
                // exhaust the local TCP ports with TIME_WAIT sockets
                if (++$levelsSinceReconnect >= self::kRECONNECT_EVERY_LEVELS)
                {
                    Vss::dbCloseAll();
                    $levelsSinceReconnect = 0;
                }
            }
        }
        catch (\Exception $e)
        {
            $this->logErr("ERROR: " . $e->getMessage());

            // A stage that exits at once on an error restarts in a tight loop until the error is resolved
            sleep(30);
        }

        $this->logMsg("Done");
    }

    public function analyzeRange(): void
    {
        ini_set('memory_limit', '512M');
        set_time_limit(86400);

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $level      = $this->getCmdVar('level');
        $limit      = $this->getCmdVar('limit', 0);
        $hunterOnly = $this->getCmdVar('hunterOnly');

        if ($level == '')
        {
            $this->logErr("The level parameter is required");
            return;
        }

        $level = explode('-', $level);

        $levelMin = $level[0];
        $levelMax = count($level) >= 2 ? $level[1] : $level[0];

        if ($levelMax === 'max')
        {
            $levelMax = Analyzers\AnalyzeHelper::GetLastFullySyncedBlock();

            if ($levelMax === null)
            {
                $this->logErr('Cannot detect the max level');
                return;
            }
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $levels = range($levelMin, $levelMax);

        $scanCount = 0;
        foreach ($levels as $level)
        {
            $this->logMsg("Analyze #$level:");

            $analyzer = new Analyzers\AnalyzeTransactions();
            $analyzer->verbose = $this->verbose;
            $analyzer->applyOnlyHunter = $hunterOnly;
            $analyzer->analyze($level);

            $scanCount++;

            if ($limit > 0 && $scanCount == $limit)
                break;

            Vss::dbCloseAll();
        }

        $this->logMsg("END.");
    }
}
