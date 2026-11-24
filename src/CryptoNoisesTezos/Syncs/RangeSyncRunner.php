<?php

namespace CryptoNoisesTezos\Syncs;

use CryptoNoisesTezos\Models\FixerAbstract;
use CryptoCore\Process\Shutdown;
use CryptoCore\Vss\Vss;
use Exception;

/**
 * Manual sync of a given level range ("sync-range" commands): one level at a time, levels synced before are skipped.
 * Stops at the first level that is processing, has an error or fails to sync.
 */
class RangeSyncRunner
{
    private FixerAbstract $fixer;
    private LevelSyncInterface $sync;

    /** @var callable(string): void */
    private $log;

    public function __construct(FixerAbstract $fixer, LevelSyncInterface $sync, callable $log)
    {
        $this->fixer = $fixer;
        $this->sync  = $sync;
        $this->log   = $log;
    }

    /**
     * @param string $range "<level>" or "<from>-<to>"
     * @throws Exception
     */
    public static function ParseRange(string $range): array
    {
        if ($range == '')
            throw new Exception("The level parameter is required");

        $levels = explode('-', $range);

        return [(int) $levels[0], (int) (count($levels) >= 2 ? $levels[1] : $levels[0])];
    }

    /**
     * @throws Exception
     */
    public function run(int $levelMin, int $levelMax): void
    {
        for ($level = $levelMin; $level <= $levelMax; $level++)
        {
            if (Shutdown::Requested())
            {
                ($this->log)('Stopping on '.Shutdown::SignalName()." at #$level.");
                return;
            }

            Vss::dbCloseAll();

            if (!$this->isToSync($level))
                continue;

            ($this->log)("Sync #$level:");

            $this->fixer->jobOpen($level);

            $itemsCount = $this->sync->sync($level);

            if ($itemsCount === null)
            {
                $this->fixer->jobCloseWithError($level, $this->sync->getLastErrorMessage());
                throw new Exception($this->sync->getLastErrorMessage());
            }

            $this->fixer->jobCloseSuccessfully($level, $itemsCount);
        }
    }

    /**
     * @throws Exception
     */
    private function isToSync(int $level): bool
    {
        $jobItem = $this->fixer->getJobItemByLevel($level);

        if ($jobItem === null)
            return true;

        switch ($jobItem['state'])
        {
            case FixerAbstract::kSTATE_Idle:
                return true;

            case FixerAbstract::kSTATE_Success:
                ($this->log)("Job #$level: Synced before. Skip it");
                return false;

            case FixerAbstract::kSTATE_Processing:
                throw new Exception("Job #$level: Is processing. BREAK SYNC");

            case FixerAbstract::kSTATE_Error:
                throw new Exception("Job #$level: Has error [{$jobItem['notice']}]. BREAK SYNC");
        }

        throw new Exception("Job #$level: Last synced Unknown state [{$jobItem['state']}]");
    }
}
