<?php

namespace CryptoNoisesTezos\Commands;

use CryptoNoisesTezos\Analyzers;
use CryptoNoisesTezos\Config;
use CryptoNoisesTezos\Models;
use CryptoCore\Time;
use CryptoCore\Vss\Vss;
use Exception;

class AnalyzeBigmapUpdates extends AbstractCommand
{
    const kRECONNECT_EVERY_LEVELS = 1000;
    const kEMPTY_LEVELS_SCAN      = 10000;

    public function analyzeNew(): void
    {
        $tsStart = Time::Microtime();

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        ini_set('memory_limit', '256M');
        set_time_limit(Config::kJOBS_TIMEOUT_BIGMAP_UPDATE_ANALYZE);

        if (!$this->checkLockAndWaitUntilNoOtherInstanceRuns('command-lock.analyze.bigmap-updates.new', Config::kJOBS_TIMEOUT_DEFAULT))
            return;

        $maxLevel = Models\Config::model()->getValue('command-lock.analyze.bigmap-updates.max-level');

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $limit = $this->getCmdVar('limit', 1000);
        $levelsSinceReconnect = 0;

        try
        {
            while($limit-- > 0)
            {
                if (Time::Microtime() - $tsStart > Config::runTimeLimit())
                    break;

                // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

                $level = Models\FixerBigmapUpdatesAnalyze::model()->getNextLevelToProcess();

                if ($level === null)
                {
                    $this->logMsg("Last job state is 'processing'. Wait 1 sec and try again..");
                    sleep(1);
                    continue;
                }

                // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

                if ($maxLevel > 0 && $level > $maxLevel)
                    throw new Exception("The next level $level exceeds the max allowed level #$maxLevel");

                // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

                $lastSyncedLevelIndex = Models\FixerBigmapUpdatesSync::model()->getLastSuccessLevelIndex();

                if ($lastSyncedLevelIndex === null)
                    throw new Exception("Cannot detect last synced job");

                if ($level > $lastSyncedLevelIndex)
                {
                    $this->logMsg("Want to analyze level #$level, but last synced level is #{$lastSyncedLevelIndex}. Wait 1 sec and try again..");
                    sleep(1);
                    continue;
                }

                // Levels without bigmap updates need no analysis: mark them in bulk up to the next level that has some
                $rangeTo = min($lastSyncedLevelIndex, $level + self::kEMPTY_LEVELS_SCAN - 1);

                if ($maxLevel > 0)
                    $rangeTo = min($rangeTo, (int) $maxLevel);

                $nextLevel = Models\FixerBigmapUpdatesSync::model()->getNextLevelWithItems($level, $rangeTo);
                $emptyTo   = $nextLevel === null ? $rangeTo : $nextLevel - 1;

                if ($emptyTo >= $level)
                {
                    Models\FixerBigmapUpdatesAnalyze::model()->closeEmptyLevels(range($level, $emptyTo));
                    continue;
                }

                // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

                $this->logMsg("Analyze #$level:");

                Models\FixerBigmapUpdatesAnalyze::model()->jobOpen($level);

                $analyzer = new Analyzers\AnalyzeBigmapUpdates();
                $itemsCount = $analyzer->analyze($level);

                if ($itemsCount === null)
                {
                    Models\FixerBigmapUpdatesAnalyze::model()->jobCloseWithError($level, $analyzer->getLastErrorMessage());
                    throw new Exception($analyzer->getLastErrorMessage());
                }

                Models\FixerBigmapUpdatesAnalyze::model()->jobCloseSuccessfully($level, $itemsCount);

                // Reconnect now and then, not per level (see AnalyzeTransactions)
                if (++$levelsSinceReconnect >= self::kRECONNECT_EVERY_LEVELS)
                {
                    Vss::dbCloseAll();
                    $levelsSinceReconnect = 0;
                }
            }

            $this->logMsg("END.");
        }
        catch (Exception $e)
        {
            $this->logErr("ERROR: " . $e->getMessage());
        }
    }
}
