<?php

namespace CryptoCore\Models;

use CryptoCore\Vss\VssDb;
use CryptoCore\Vss\VssDbExp;
use CryptoCore\Vss\VssModel;
use Exception;

/**
 * Per-level job journal of a sync or analyze process: one row per block level with its state.
 */
abstract class FixerAbstract extends VssModel
{
    const kSTATE_Idle       = 'idle';
    const kSTATE_Processing = 'processing';
    const kSTATE_Success    = 'success';
    const kSTATE_Error      = 'error';

    abstract public function tableName(): string;

    abstract protected function dbConnection(): VssDb;

    /**
     * States that recoverDeadJobs() resets to idle once they are older than the timeout.
     */
    protected function recoverableStates(): array
    {
        return [self::kSTATE_Processing];
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public function getJobItemByLevel($level): ?array
    {
        return $this->dbConnection()->queryRow("SELECT * FROM {$this->tableName()} WHERE level = :level", ['level' => $level]);
    }

    public function getLastJobItem(): ?array
    {
        $sql = "SELECT * FROM {$this->tableName()} ORDER BY level DESC LIMIT 1";
        return $this->dbConnection()->queryRow($sql);
    }

    public function getLastSuccessJobItem(): ?array
    {
        $sql = "SELECT * FROM {$this->tableName()} WHERE state = :state_success ORDER BY level DESC LIMIT 1";
        return $this->dbConnection()->queryRow($sql, ['state_success' => self::kSTATE_Success]);
    }

    private ?int $lastSuccessLevel = null;
    private float $lastSuccessReadAt = 0;

    /** Cached for a second: other stages ask before every level, and a second-old answer only makes them cautious */
    public function getLastSuccessLevelIndex(): ?int
    {
        if ($this->lastSuccessLevel !== null && microtime(true) - $this->lastSuccessReadAt < 1)
            return $this->lastSuccessLevel;

        $jobItem = $this->getLastSuccessJobItem();

        $this->lastSuccessLevel  = $jobItem ? (int) $jobItem['level'] : null;
        $this->lastSuccessReadAt = microtime(true);

        return $this->lastSuccessLevel;
    }

    /**
     * Level to process next, from the state of the last job: idle -> the same level, success -> the next one.
     *
     * @return int|null null while the last job is still processing (the caller waits and asks again)
     * @throws Exception when the journal is empty or the last job ended with an error
     */
    public function getNextLevelToProcess(): ?int
    {
        $job = $this->getLastJobItem();

        if ($job === null)
            throw new Exception("{$this->tableName()}: no jobs yet, seed the start level first");

        switch ($job['state'])
        {
            case self::kSTATE_Idle:       return (int) $job['level'];
            case self::kSTATE_Success:    return (int) $job['level'] + 1;
            case self::kSTATE_Processing: return null;

            case self::kSTATE_Error:
                throw new Exception("#{$job['level']}: Last job ended with an error and must be resolved first: {$job['notice']}");
        }

        throw new Exception("#{$job['level']}: Last job state is unknown [{$job['state']}]");
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public function jobOpen(int $level)
    {
        return $this->dbConnection()->insertOrUpdate($this->tableName(),
            ['level' => $level, 'state' => self::kSTATE_Processing, 'ts_scan_started' => VssDbExp::now()],
            ['state' => self::kSTATE_Processing]);
    }

    public function jobCloseSuccessfully(int $level, int $itemsCount, ?string $notice = null)
    {
        $data = ['state' => self::kSTATE_Success, 'items_count' => $itemsCount, 'ts_scan_finished' => VssDbExp::now()];

        if ($notice)
            $data['notice'] = $notice;

        return $this->dbConnection()->update($this->tableName(), $data, ['level' => $level]);
    }

    public function jobCloseWithError(int $level, ?string $notice)
    {
        $data = ['state' => self::kSTATE_Error, 'ts_scan_finished' => VssDbExp::now()];

        if ($notice)
            $data['notice'] = $notice;

        return $this->dbConnection()->update($this->tableName(), $data, ['level' => $level]);
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public function recoverDeadJobs(int $timeout): int
    {
        $params = ['timeout' => $timeout];
        $states = [];

        foreach (array_values($this->recoverableStates()) as $i => $state)
        {
            $params["state_$i"] = $state;
            $states[] = ":state_$i";
        }

        $sql = "
            SELECT *
              FROM {$this->tableName()}
             WHERE state IN(".join(',', $states).")
               AND ts_scan_started < NOW() - INTERVAL :timeout SECOND
        ";

        $items = $this->dbConnection()->query($sql, $params);

        foreach ($items as $item)
        {
            $data = [
                'state'  => self::kSTATE_Idle,
                'notice' => $item['notice'] . ' {Recovered} ',
            ];

            $this->dbConnection()->update($this->tableName(), $data, ['level' => $item['level']]);
        }
        return count($items);
    }
}
