<?php

namespace CryptoNoisesTezos\Syncs;

use CryptoNoisesTezos\Config;
use CryptoNoisesTezos\Models\FixerAbstract;
use CryptoNoisesTezos\Vendors;
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

    public function __construct(FixerAbstract $fixer, LevelSyncInterface $sync, callable $log)
    {
        $this->fixer    = $fixer;
        $this->sync     = $sync;
        $this->log      = $log;
    }

    /**
     * @param int $levelsLimit  max levels to sync in this run
     * @throws Exception        on a sync error (the level is marked as "error" in the fixer table)
     */
    public function run(float $tsStart, int $levelsLimit): void
    {
        while ($levelsLimit > 0 && Time::Microtime() - $tsStart <= Config::runTimeLimit())
        {
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
                sleep(1);
                continue;
            }

            $range = LevelRangeLoader::Load($this->sync->tzktRangePath(), $level, $levelTo);

            if ($range === null)
                throw new Exception("#$level: cannot load levels $level..$levelTo from TzKT");

            ($this->log)("Sync #$level..#{$range->levelTo}: {$range->itemsCount} item(s), span {$this->span}");

            $this->adaptSpan($level, $levelTo, $range);

            $levelsLimit -= $this->syncRange($level, $range, $levelsLimit, $tsStart);
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
        $synced = 0;
        $emptyLevels = [];

        for ($level = $levelFrom; $level <= $range->levelTo && $synced < $levelsLimit; $level++, $synced++)
        {
            if (Time::Microtime() - $tsStart > Config::runTimeLimit())
                break;

            if (!isset($range->itemsByLevel[$level]))
            {
                $emptyLevels[] = $level;

                if (count($emptyLevels) >= self::kEMPTY_LEVELS_CHUNK)
                    $this->closeEmptyLevels($emptyLevels);

                continue;
            }

            $this->closeEmptyLevels($emptyLevels);
            $this->syncLevel($level, $range->itemsByLevel[$level]);
        }

        $this->closeEmptyLevels($emptyLevels);

        return $synced;
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
