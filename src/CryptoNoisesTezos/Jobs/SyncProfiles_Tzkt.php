<?php

namespace CryptoNoisesTezos\Jobs;

use CryptoNoisesTezos\Core\TezosAddress;
use CryptoNoisesTezos\Models;
use CryptoNoisesTezos\Vendors;
use CryptoCore\Enum\JobResult;
use CryptoCore\Time;
use Exception;

trait SyncProfiles_Tzkt
{
    private function syncTzkt(array $taskItem): JobResult
    {
        try
        {
            $profileAdid    = $taskItem['source_adid'];
            $profileAddress = TezosAddress::GetAddressByAdid($profileAdid);

            $this->logMsg("Start sync profile_adid #$profileAdid");

            if ($profileAddress == null)
                throw new Exception("Cannot convert adid to address: $profileAdid", JobResult::kFailedAndClose);

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $profileItem = Models\ProfilesTzkt::model()->getItem($profileAdid);

            if ($profileItem === null)
            {
                $profileItem = Models\ProfilesTzkt::model()->create($profileAdid, $profileAddress);
            }
            else
            {
                $tsLastSave = $profileItem['ts_updated'] ?: $profileItem['ts_created'];

                if (Time::Time() - strtotime($tsLastSave) < 12*60*60)
                    throw new Exception("Skip by timeout (12h)", JobResult::kFailedAndClose);
            }

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $this->logMsg("Syncing base-data");

            $dataBase = Vendors\Tzkt::GetAccountDetails($profileAddress);

            if ($dataBase === null)
                throw new Exception("Cannot get profile base-details: $profileAddress", JobResult::kFailedAndPostpone);

            $this->logMsg("Sync completed");

            // Only type and alias are used (wallet names in bot messages). Contact fields (e-mail, social accounts)
            // of third-party accounts are not collected; the columns are cleared on every refresh.
            $updateData = [];
            $updateData['type']             = ($dataBase['type'] ?? null) ?: null;
            $updateData['alias']            = ($dataBase['alias'] ?? null) ?: null;
            $updateData['kind']             = null;
            $updateData['description']      = null;
            $updateData['email']            = null;
            $updateData['telegram']         = null;
            $updateData['twitter']          = null;
            $updateData['facebook']         = null;
            $updateData['instagram']        = null;
            $updateData['github']           = null;
            $updateData['website']          = null;

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            Models\ProfilesTzkt::model()->save($profileAdid, $updateData);

            $this->logMsg("Save");

            return JobResult::Completed();
        }
        catch (Exception $e)
        {
            $this->lastActionNotice = $e->getMessage();
            return JobResult::FailedWithCode($e->getCode());
        }
    }
}
