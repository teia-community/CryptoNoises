<?php

namespace CryptoNoisesTezos\Syncs;

use CryptoNoisesTezos\Config;
use CryptoNoisesTezos\Models\FixerAbstract;
use CryptoNoisesTezos\Vendors;
use CryptoCore\Process\ProgressLog;
use CryptoCore\Process\Shutdown;
use CryptoCore\Time;
use CryptoCore\Vss\Vss;
use Exception;

/**
 * "sync-new" loop shared by transactions and bigmap updates.
 *
 * Loads a whole range of levels from TzKT with LevelRangeLoader (a few requests instead of one per level), then syncs
 * level by level, so every level still gets its own fixer_* row (the cursor of the analyzers and sync-check).
 * The range size adapts: it grows while ranges are sparse and shrinks to what fits into one page.
 */
final class BatchSyncRunner
{
    const kSPAN_START           = 100;
    const kSPAN_MAX             = 10000;
    const kEMPTY_LEVELS_CHUNK   = 1000;

    private FixerAbstract $fixer;
    private LevelSyncInterface $sync;
    /** @var callable(string): void */
    private $log;

    private int $span = self::kSPAN_START;
    private ?int $lastLevelInBlockchain = null;

    private ProgressLog $progress;

    public function __construct(FixerAbstract $fixer, LevelSyncInterface $sync, callable $log)
    {
        $this->fixer    = $fixer;
        $this->sync     = $sync;
        $this->log      = $log;
        $this->progress = new ProgressLog('sync '.$sync->tzktRangePath());
    }

    /**
     * @param int $levelsLimit  max levels to sync in this run
     * @throws Exception        on a sync error (the level is marked as "error" in the fixer table)
     */
    public function run(float $tsStart, int $levelsLimit): void
    {
        while ($levelsLimit > 0 && Time::Microtime() - $tsStart <= Config::runTimeLimit())
        {
            if (Shutdown::Requested())
            {
                ($this->log)('Stopping on '.Shutdown::SignalName().'.');
                return;
            }

            Vss::dbCloseAll();

            $level = $this->getNextLevel();

            if ($level === null)
            {
                sleep(1);
                continue;
            }

            $levelTo = $this->getRangeEnd($level);

            if ($levelTo === null)
            {
                // Caught up with the head: keeps the heartbeat going while there is nothing to sync. Each pass asks
                // TzKT for its head, so not more often than half a block time
                $this->progress->report($level - 1, $this->lastLevelInBlockchain);
                sleep(3);
                continue;
            }

            $range = LevelRangeLoader::LoadFor($this->sync, $level, $levelTo);

            if ($range === null)
                throw new Exception("#$level: cannot load levels $level..$levelTo from TzKT");

            ($this->log)("Sync #$level..#{$range->levelTo}: {$range->itemsCount} item(s), span {$this->span}");

            $this->adaptSpan($level, $levelTo, $range);

            $synced = $this->syncRange($level, $range, $levelsLimit, $tsStart);
            $levelsLimit -= $synced;

            if ($synced > 0)
                $this->progress->report($level + $synced - 1, $this->lastLevelInBlockchain);
        }
    }

    /**
     * Syncs the loaded levels in order. Empty levels are marked in bulk (flushed before the next level with items),
     * so the fixer table stays ordered. Stops early on the time / levels limit: the rest is loaded again next run.
     *
     * @return int number of synced levels
     */
    private function syncRange(int $levelFrom, LevelRangeLoader $range, int $levelsLimit, float $tsStart): int
    {
        $synced   = 0;
        $batched  = $this->sync instanceof BatchedLevelSyncInterface;
        $emptyLevels = [];
        $batchLevels = [];

        // The cursor is the fixer row with the highest level (getLastJobItem orders by level DESC), so a row must
        // never appear for a level while a lower one still has none: the cursor would step over it for good.
        // Everything below therefore writes in level order - the pending batch is committed before any higher
        // level is marked, and the empty levels of a gap are marked before the batch that follows them.
        $flushBatch = function () use (&$batchLevels, $range) {
            if ($batchLevels)
            {
                $this->syncLevelsInOneTransaction($batchLevels, $range);
                $batchLevels = [];
            }
        };

        for ($level = $levelFrom; $level <= $range->levelTo && $synced < $levelsLimit; $level++, $synced++)
        {
            // Both stop conditions leave the loop, never the batch: the pending levels are committed by the
            // $flushBatch() below, so a stop costs nothing and the fixer cursor stays in level order
            if (Time::Microtime() - $tsStart > Config::runTimeLimit() || Shutdown::Requested())
                break;

            if (!isset($range->itemsByLevel[$level]))
            {
                $emptyLevels[] = $level;

                if (count($emptyLevels) >= self::kEMPTY_LEVELS_CHUNK)
                {
                    $flushBatch();
                    $this->closeEmptyLevels($emptyLevels);
                }

                continue;
            }

            if ($emptyLevels)
            {
                $flushBatch();
                $this->closeEmptyLevels($emptyLevels);
            }

            if ($batched)
                $batchLevels[] = $level;
            else
                $this->syncLevel($level, $range->itemsByLevel[$level]);
        }

        $flushBatch();
        $this->closeEmptyLevels($emptyLevels);

        return $synced;
    }

    /**
     * Syncs the levels that have items under a single database transaction.
     *
     * On any failure the batch is rolled back and the same levels are replayed one at a time through the
     * per-level path, so the level that actually failed is the one that ends up marked as an error - a batch
     * must not turn one bad level into a hundred.
     *
     * @param int[] $levels
     */
    private function syncLevelsInOneTransaction(array $levels, LevelRangeLoader $range): void
    {
        /** @var BatchedLevelSyncInterface $sync */
        $sync = $this->sync;

        $this->fixer->openLevels($levels);

        try
        {
            $itemsByLevel = [];

            foreach ($levels as $level)
                $itemsByLevel[$level] = $range->itemsByLevel[$level];

            $sync->batchBegin($itemsByLevel);

            foreach ($itemsByLevel as $level => $items)
                $sync->batchAdd($level, $items);

            $this->fixer->closeLevels($sync->batchCommit());
        }
        catch (Exception $e)
        {
            $sync->batchRollback();

            ($this->log)("Batch of ".count($levels)." level(s) failed, replaying one by one: ".$e->getMessage());

            foreach ($levels as $level)
                $this->syncLevel($level, $range->itemsByLevel[$level]);
        }
    }

    private function closeEmptyLevels(array &$levels): void
    {
        $this->fixer->closeEmptyLevels($levels);
        $levels = [];
    }

    //------------------------------------------------------------------------------------------------------------------

    private function getNextLevel(): ?int
    {
        $level = $this->fixer->getNextLevelToProcess();

        if ($level === null)
            ($this->log)("Last job state is 'processing'. Wait 1 sec and try again..");

        return $level;
    }

    /**
     * Last level of the next range, never past the chain head (minus kSYNC_BLOCK_BEHIND).
     * The head is requested from TzKT only when the sync has caught up with the last known head.
     */
    private function getRangeEnd(int $level): ?int
    {
        if ($this->lastLevelInBlockchain === null || $level > $this->lastLevelInBlockchain)
        {
            $head = Vendors\Tzkt::GetBlockLastIndex();

            if ($head === null)
                throw new Exception('Cannot detect the last block in the blockchain');

            $this->lastLevelInBlockchain = $head - Config::kSYNC_BLOCK_BEHIND;
        }

        if ($level > $this->lastLevelInBlockchain)
        {
            ($this->log)("Want to sync level #$level, but last synced level is #{$this->lastLevelInBlockchain}. Wait 1 sec and try again..");
            return null;
        }

        return min($level + $this->span - 1, $this->lastLevelInBlockchain);
    }

    private function adaptSpan(int $levelFrom, int $levelTo, LevelRangeLoader $range): void
    {
        if ($range->levelTo < $levelTo)
            $this->span = max(1, $range->levelTo - $levelFrom + 1);                 // page was full: take what fitted
        else if ($range->itemsCount < LevelRangeLoader::kPAGE_LIMIT / 2)
            $this->span = min(self::kSPAN_MAX, $this->span * 2);                    // sparse: try a longer range
    }

    private function syncLevel(int $level, array $items): void
    {
        $this->fixer->jobOpen($level);

        $itemsCount = $this->sync->sync($level, $items);

        if ($itemsCount === null)
        {
            $this->fixer->jobCloseWithError($level, $this->sync->getLastErrorMessage());
            throw new Exception("#$level: ".$this->sync->getLastErrorMessage());
        }

        $this->fixer->jobCloseSuccessfully($level, $itemsCount);
    }
}
