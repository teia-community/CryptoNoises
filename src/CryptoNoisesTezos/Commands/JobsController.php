<?php

namespace CryptoNoisesTezos\Commands;

use CryptoNoisesTezos\Config;
use CryptoNoisesTezos\Jobs;
use CryptoNoisesTezos\Models;

class JobsController extends AbstractCommand
{
    use \CryptoCore\Commands\JobsController_Executor;

    use JobsController_Cleaners;
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
            case 'detect-new-tokens':
                $this->startJob($jobId, 'command-lock.job.detect-new-tokens',
                    Models\Queues\QueueTokensDetected::model(), new Jobs\AnalyzeNewlyDetectedToken(),
                    $threadIdx, $threadCnt, $limit, $timeLimit, $timeLimit + 10*60);
                break;

            case 'sync-contracts-metadata':
                $this->startJob($jobId, 'command-lock.job.sync-contracts-metadata',
                    Models\Queues\QueueContracts::model(), new Jobs\SyncContractMetadata(),
                    $threadIdx, $threadCnt, $limit, $timeLimit, $timeLimit + 10*60);
                break;

            case 'sync-tokens-metadata':
                $this->startJob($jobId, 'command-lock.job.sync-tokens-metadata',
                    Models\Queues\QueueTokensMetadata::model(), new Jobs\SyncTokenMetadata(),
                    $threadIdx, $threadCnt, $limit, $timeLimit, $timeLimit + 10*60);
                break;

            case 'sync-ipfs.primary':
                $this->startJob($jobId, 'command-lock.job.sync-ipfs.primary',
                    Models\Queues\QueueIpfsPrimary::model(), new Jobs\SyncIpfsMedia(),
                    $threadIdx, $threadCnt, $limit, $timeLimit, $timeLimit + 10*60);
                break;

            case 'sync-ipfs.secondary':
                $this->startJob($jobId, 'command-lock.job.sync-ipfs.secondary',
                    Models\Queues\QueueIpfsSecondary::model(), new Jobs\SyncIpfsMedia(),
                    $threadIdx, $threadCnt, $limit, $timeLimit, $timeLimit + 10*60);
                break;

            case 'sync-profiles':
                $this->startJob($jobId, 'command-lock.job.sync-profiles',
                    Models\Queues\QueueProfiles::model(), new Jobs\SyncProfiles(),
                    $threadIdx, $threadCnt, $limit, $timeLimit, $timeLimit + 10*60);
                break;

            case 'merge-tokens-metadata':
                $this->startJob($jobId, 'command-lock.job.merge-tokens-metadata',
                    Models\Queues\QueueTokensMetadataMerge::model(), new Jobs\SyncTokenMetadataMerge(),
                    $threadIdx, $threadCnt, $limit, $timeLimit, $timeLimit + 10*60);
                break;

            default:
                $this->logErr("Unknown jobId. Allowed values:");
                $this->logErr("- detect-new-tokens");
                $this->logErr("- sync-contracts-metadata");
                $this->logErr("- sync-tokens-metadata");
                $this->logErr("- sync-ipfs.primary");
                $this->logErr("- sync-ipfs.secondary");
                $this->logErr("- sync-profiles");
                $this->logErr("- merge-tokens-metadata");
                break;
        }
    }
}
