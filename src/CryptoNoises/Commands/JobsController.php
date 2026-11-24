<?php

namespace CryptoNoises\Commands;

use CryptoNoises\Config;
use CryptoNoises\Models;
use CryptoNoises\Jobs;

class JobsController extends AbstractCommand
{
    use \CryptoCore\Commands\JobsController_Executor;

    use JobsController_RecoveryDeadJobs;

    public function launchJob()
    {
        $jobId     = $this->getCmdVar('jobId');
        $threadIdx = $this->getCmdVar('threadIdx', 0);
        $threadCnt = $this->getCmdVar('threadCnt', 1);
        $limit     = $this->getCmdVar('limit', 0);
        $timeLimit = (int) $this->getCmdVar('timeLimit', Config::runTimeLimit());

        switch ($jobId)
        {
            case 'domains-expiry-find-new':
                $this->startJobOnce($jobId, new Jobs\DomainsExpiryFindNew());
                break;

            case 'domains-expiry':
                $this->startJob($jobId, 'command-lock.job.domains-expiry',
                    Models\Queues\QueueDomainsExpiry::model(), new Jobs\DomainsExpiry(),
                    $threadIdx, $threadCnt, $limit, $timeLimit, $timeLimit + 10*60);
                break;

            case 'domains-expiry-cleanup':
                $this->startJobOnce($jobId, new Jobs\DomainsExpiryCleanup());
                break;

            default:
                $this->logErr("Unknown jobId. Allowed values:");
                $this->logErr("- domains-expiry-find-new");
                $this->logErr("- domains-expiry");
                $this->logErr("- domains-expiry-cleanup");
                break;
        }
    }
}
