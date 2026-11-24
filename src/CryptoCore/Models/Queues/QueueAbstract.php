<?php

namespace CryptoCore\Models\Queues;

use CryptoCore\Vss\VssDb;
use CryptoCore\Vss\VssModel;
use CryptoCore\Vss\VssDbExp;

abstract class QueueAbstract extends VssModel
{
    const kState_Idle               = 'idle';
    const kState_Syncing            = 'syncing';
    const kState_Success            = 'success';
    const kState_Error              = 'error';
    const kSyncMode_New             = 'new';
    const kSyncMode_Update          = 'update';

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
    // Select modes

    const kSELECT_MODE_FirstInFirstOut          = 'first-in-first-out';
    const kSELECT_MODE_Normal                   = 'normal';
    const kSELECT_MODE_Anyone                   = 'anyone';

    // kSELECT_MODE_Normal used to order by "priority, iteration, RAND()". The RAND() was there so that parallel
    // workers would not keep picking the same row; SKIP LOCKED does that properly now (see claimNextTask()), and
    // sorting the whole matching set at random is expensive once a queue holds a real backlog. The order is
    // deterministic and index-friendly instead.

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public string $selectMode                   = self::kSELECT_MODE_Normal;
    public int $maxTryCount                     = 16;

    /** How often a claim that lost a deadlock is retried before the worker is told the queue is empty */
    const kCLAIM_DEADLOCK_RETRIES               = 4;

    abstract public function tableName(): string;

    abstract protected function dbConnection(): VssDb;

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    /**
     * @param array $params
     * @param string|null $syncMode
     * @param int $priority             {1..9} - 1=HIGHEST .. 9=LOWEST
     * @return void
     */
    public function push(array $params, ?string $syncMode = null, int $priority = 5): void
    {
        $tableName = $this->tableName();

        if (isset($params['state']) && $params['state'] == self::kState_Error)
            $tableName .= '__failed';

        $params['priority']  = $priority;
        $params['sync_mode'] = $syncMode;

        $this->dbConnection()->insert($tableName, $params, 'IGNORE');
    }

    /**
     * Takes the next task and marks it 'syncing' in one transaction, so any number of workers can share a queue.
     *
     * SELECT ... FOR UPDATE SKIP LOCKED makes InnoDB hand each concurrent worker a different row instead of making
     * them wait for the same one. Without it the select and the 'syncing' update are two statements with a race
     * between them, which is why this used to need the queue_task_id % threadCount sharding to stay correct:
     * exactly one worker was allowed to look at each row. Workers are interchangeable now, so they can be scaled
     * freely (docker compose up --scale), and threadIndex / threadCount are only kept for callers that still pass
     * them. Needs MySQL 8.0+ or MariaDB 10.6+.
     *
     * A deadlock (SQLSTATE 40001) is a normal outcome of concurrent claims, not a failure: InnoDB rolls one
     * transaction back and the right answer is to re-issue it. Letting it out of here was expensive - the
     * exception reached the Fat-Free error handler, which ends the process, and "restart: always" started the
     * worker straight back into the same contention. Ten replicas of one queue turned that into a restart loop
     * that burned the host's CPU. Retries are bounded; after them the worker is told the queue is empty and
     * idles, which costs one pass rather than a process.
     *
     * @return array|null the claimed task, already in state 'syncing', or null when the queue has nothing ready
     */
    public function claimNextTask(int $threadIndex = 0, int $threadCount = 0): ?array
    {
        for ($attempt = 0; ; $attempt++)
        {
            try
            {
                return $this->claimNextTaskOnce($threadIndex, $threadCount);
            }
            catch (\Throwable $e)
            {
                if ($attempt >= self::kCLAIM_DEADLOCK_RETRIES || !self::IsRetryableLockError($e))
                    throw $e;

                // Sleep a random slice so the losers of one deadlock do not line up and collide again
                usleep(random_int(10000, 50000) * ($attempt + 1));
            }
        }
    }

    /**
     * True for the two errors that mean "try the transaction again": 1213 deadlock and 1205 lock wait timeout.
     */
    public static function IsRetryableLockError(\Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'SQLSTATE[40001]')
            || str_contains($message, '1213 Deadlock')
            || str_contains($message, '1205 Lock wait timeout');
    }

    private function claimNextTaskOnce(int $threadIndex, int $threadCount): ?array
    {
        $db = $this->dbConnection();

        $db->transactionBegin();

        try
        {
            $taskItem = $this->getNextTask($threadIndex, $threadCount, true);

            if ($taskItem === null)
            {
                $db->transactionCommit();
                return null;
            }

            // Claim it while the row is still locked. last_notice is cleared like openSyncProcess() did.
            $db->update($this->tableName(),
                ['state' => self::kState_Syncing, 'last_notice' => null],
                ['queue_task_id' => $taskItem['queue_task_id']]);

            $db->transactionCommit();

            $taskItem['state']       = self::kState_Syncing;
            $taskItem['last_notice'] = null;

            return $taskItem;
        }
        catch (\Throwable $e)
        {
            $db->transactionRollback();
            throw $e;
        }
    }

    /**
     * @param bool $lockRow adds FOR UPDATE SKIP LOCKED; only valid inside a transaction (see claimNextTask())
     */
    public function getNextTask(int $threadIndex, int $threadCount, bool $lockRow = false): ?array
    {
        $lock = $lockRow ? ' FOR UPDATE SKIP LOCKED' : '';

        if ($this->selectMode == self::kSELECT_MODE_FirstInFirstOut)
        {
            $sql = "
                SELECT *
                FROM {$this->tableName()}
                WHERE state = :state
                    AND ts_activate <= NOW()
                    ".($threadCount > 1 && $threadIndex < $threadCount ? " AND queue_task_id % $threadCount = $threadIndex " : '' )."
                ORDER BY queue_task_id
                LIMIT 1
            ".$lock;
        }
        else if ($this->selectMode == self::kSELECT_MODE_Anyone)
        {
            $sql = "
                SELECT *
                FROM {$this->tableName()}
                WHERE state = :state
                    AND ts_activate <= NOW()
                    ".($threadCount > 1 && $threadIndex < $threadCount ? " AND queue_task_id % $threadCount = $threadIndex " : '' )."
                LIMIT 1
            ".$lock;
        }
        else // self::kSELECT_MODE_Normal (default) and any unknown mode
        {
            $sql = "
                SELECT *
                FROM {$this->tableName()}
                WHERE state = :state
                  AND ts_activate <= NOW()
                      ".($threadCount > 1 && $threadIndex < $threadCount ? " AND queue_task_id % $threadCount = $threadIndex " : '' )."
                ORDER BY priority, iteration, queue_task_id
                LIMIT 1
            ".$lock;
        }

        return $this->dbConnection()->queryRow($sql, ['state'=>self::kState_Idle]);
    }

    public function getDeadSyncingTasks(int $timeout): array
    {
        $sql = "
            SELECT *
            FROM {$this->tableName()}
            WHERE state = :state_syncing
              AND ts_updated < NOW() - INTERVAL :timeout SECOND
        ";

        return $this->dbConnection()->query($sql, [
            'state_syncing' => self::kState_Syncing,
            'timeout'       => $timeout,
        ]);
    }

    public function openSyncProcess(array $taskItem): void
    {
        $this->updateTaskState($taskItem, self::kState_Syncing);
    }

    public function updateTaskState(array $taskItem, string $newState, ?float $jobExecTime = null, ?string $notice = null, array $extraDataUpdate = []): void
    {
        $data = $extraDataUpdate;
        $data['state']       = $newState;
        $data['last_notice'] = $notice;

        if ($jobExecTime > 0)
            $data['exec_time'] = $jobExecTime;

        $this->dbConnection()->update($this->tableName(), $data, ['queue_task_id' => $taskItem['queue_task_id']]);
    }

    public function closeTaskOnSuccess(array $taskItem, ?float $jobExecTime = null, ?string $notice = null): void
    {
        $this->updateTaskState($taskItem, self::kState_Success, $jobExecTime, $notice);
        $this->moveTaskToExtendedTable($taskItem['queue_task_id'], '__done');
    }

    /**
     * iterationUpdate:  1 = increase by 1, 0 = keep, -1 = close the task
     */
    public function postponeOrCloseTask(array $taskItem, ?float $jobExecTime = null, ?string $notice = null, int $iterationUpdate = 1): void
    {
        $newIteration = $taskItem['iteration'];

        if ($iterationUpdate > 0)
            $newIteration += $iterationUpdate;

        if ($iterationUpdate < 0 || $newIteration >= $this->maxTryCount)
        {
            $this->updateTaskState($taskItem, self::kState_Error, $jobExecTime, $notice);
            $this->moveTaskToExtendedTable($taskItem['queue_task_id'], '__failed');
        }
        else
        {
            $this->updateTaskState($taskItem, self::kState_Idle, $jobExecTime, $notice, [
                'iteration'     => $newIteration,
                'ts_activate'   => VssDbExp::now($newIteration*$newIteration * 60),
            ]);
        }
    }

    public function moveTaskToExtendedTable(int $queueTaskId, string $tableSuffix): void
    {
        $item = $this->dbConnection()->selectRow('*', $this->tableName(), ['queue_task_id' => $queueTaskId]);

        if ($item == null)
            return;

        $item['prev_queue_task_id'] = $item['queue_task_id'];
        unset($item['queue_task_id']);

        $this->dbConnection()->insert($this->tableName().$tableSuffix, $item, 'IGNORE');

        $this->dbConnection()->delete($this->tableName(), ['queue_task_id' => $queueTaskId]);
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
}
