<?php

namespace CryptoCore\Commands;

use CryptoCore\Enum\JobResult;
use CryptoCore\Process\Shutdown;
use CryptoCore\Time;
use CryptoCore\Vss\Vss;
use VssCfg;

trait JobsController_Executor
{
    // How often a running worker re-reads its command-lock.<job> switch
    private const kLOCK_RECHECK_INTERVAL_SEC = 10;

    protected function startJob(string                                  $jobId, string $jobLockerId,
                                \CryptoCore\Models\Queues\QueueAbstract $queueModel,
                                \CryptoCore\Jobs\AbstractJob            $jobInstance,
                                int                                     $threadIdx, int $threadCnt, int $limit, int $timeLimit, int $timeLimitWithSafeReserve)
    {
        VssCfg::cfg()->pushErrorReportingScope($jobId);

        $tsStart = Time::Microtime();

        ini_set('memory_limit', '512M');
        set_time_limit($timeLimitWithSafeReserve);

        // Only the parallel-run guard here. The command-lock.<job> switch is handled inside the loop instead, so a
        // paused worker idles and resumes on its own: exiting would just have the restart policy start it again,
        // which is far more churn than the once-a-minute cron invocation this replaces.
        if (!$this->waitUntilNoOtherInstanceRuns($timeLimit))
            return;

        $jobInstance->verbose = $this->verbose;

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        // command-lock.<job> is re-read while the worker runs, not only at startup: a worker lives for $timeLimit
        // seconds, so reading it once would mean the pause switch took effect at the next restart at the earliest.
        $tsLockChecked = 0.0;   // 0 forces a check on the first iteration
        $isLocked      = false;

        while (true)
        {
            Vss::dbCloseAll();

            // Between tasks only: a claimed task is always finished and closed, never abandoned in 'syncing'
            if (Time::Microtime() - $tsStart > $timeLimit || Shutdown::Requested())
                break;

            if (Time::Microtime() - $tsLockChecked >= self::kLOCK_RECHECK_INTERVAL_SEC)
            {
                $tsLockChecked = Time::Microtime();
                $wasLocked     = $isLocked;
                $isLocked      = $this->isCommandLocked($jobLockerId);

                if ($isLocked && !$wasLocked)
                    $this->logMsg("Command locked! Waiting for the lock to be released");
                else if (!$isLocked && $wasLocked)
                    $this->logMsg("Command unlocked, resuming");
            }

            // Paused: stay alive and keep polling the switch, so no task is claimed meanwhile
            if ($isLocked)
            {
                sleep(1);
                continue;
            }

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $tsJobStart = Time::Microtime();

            // Claims the task atomically (SELECT ... FOR UPDATE SKIP LOCKED + mark 'syncing'), so several workers
            // can share one queue without the threadIdx / threadCnt sharding this used to depend on.
            $taskItem = $queueModel->claimNextTask($threadIdx, $threadCnt);

            if ($taskItem == null)
            {
                $this->logMsg("Sleep for 1 sec");
                sleep(1);
                continue;
            }

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            VssCfg::cfg()->pushErrorReportingScope($taskItem['queue_task_id']);

            $jobParams = '';
            array_walk($taskItem, function($val, $key) use(&$jobParams)
            {
                if (in_array($key, [
                    'prev_queue_task_id',
                    'state','sync_mode','priority','iteration',
                    'ts_created','ts_activate','ts_updated','exec_time','last_notice'
                ]))
                    return;

                $jobParams .= "$key=$val; ";
            });

            $this->logMsg("Job[$jobId]: ".$jobParams);

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            // claimNextTask() already put the task into 'syncing'

            $result = $jobInstance->execute($taskItem);

            $jobExecTime = Time::Microtime() - $tsJobStart;

            switch ($result->code())
            {
                case JobResult::kCompleted:
                    $queueModel->closeTaskOnSuccess($taskItem, $jobExecTime, $jobInstance->lastActionNotice);
                    $this->logMsg("Success: ".$jobInstance->lastActionNotice);
                    break;

                case JobResult::kFailedAndPostpone:
                    $queueModel->postponeOrCloseTask($taskItem, $jobExecTime, $jobInstance->lastActionNotice, +1);
                    $this->logMsg("Failed: ".$jobInstance->lastActionNotice);
                    break;

                case JobResult::kFailedAndRepeat:
                    $queueModel->postponeOrCloseTask($taskItem, $jobExecTime, $jobInstance->lastActionNotice, 0);
                    break;

                case JobResult::kFailedAndClose:
                    $queueModel->postponeOrCloseTask($taskItem, $jobExecTime, $jobInstance->lastActionNotice, -1);
                    $this->logMsg("Failed: ".$jobInstance->lastActionNotice);
                    break;
            }

            VssCfg::cfg()->popErrorReportingScope();

            if ($limit > 0 && --$limit == 0)
                break;
        }

        $this->logMsg("END.");
    }

    protected function startJobOnce(string                       $jobId,
                                    \CryptoCore\Jobs\AbstractJob $jobInstance)
    {
        VssCfg::cfg()->pushErrorReportingScope($jobId);

        ini_set('memory_limit', '512M');
        set_time_limit(15*60);

        $jobInstance->verbose = $this->verbose;

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $this->logMsg("Job #[$jobId] exec once");

        $result = $jobInstance->execute([]);

        $this->logMsg("END.");
    }
}
