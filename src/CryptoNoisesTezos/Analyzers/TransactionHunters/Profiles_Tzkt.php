<?php

namespace CryptoNoisesTezos\Analyzers\TransactionHunters;

use CryptoNoisesTezos\Core\TezosAddress;
use CryptoNoisesTezos\Models;

class Profiles_Tzkt extends AbstractHunter
{
    // Mass-payout sender: its transactions would flood the profiles queue
    private const kMASS_PAYOUT_SENDER = 'tz1W1en9UpMCH4ZJL8wQCh8JDKCZARyVx2co';

    private array $addressList = [];

    public function analyze(array $recInit, array $records): bool
    {
        if ($recInit['sender_address'] == self::kMASS_PAYOUT_SENDER)
            return false;

        $this->push($recInit['sender_address']);
        $this->push($recInit['target_address']);

        foreach ($records as $record)
        {
            $this->push($record['sender_address']);
            $this->push($record['target_address']);
        }

        return false;
    }

    private function push(?string $address): void
    {
        if (empty($address) || !TezosAddress::IsWalletAddressValid($address))
            return;

        $this->addressList[] = $address;
    }

    // adid => when it was last checked, per process: an address in every block need not be checked every block
    private static array $checkedAt = [];
    const kRECHECK_SEC = 3600;

    public function finalize(): void
    {
        $addressList = array_unique($this->addressList);

        foreach ($addressList as $address)
        {
            $profileAdid = TezosAddress::GetOrCreateAdid($address);

            if ($profileAdid === null || time() - (self::$checkedAt[$profileAdid] ?? 0) < self::kRECHECK_SEC)
                continue;

            if (count(self::$checkedAt) >= 200000)
                self::$checkedAt = [];

            self::$checkedAt[$profileAdid] = time();

            $secondsGone = Models\ProfilesTzkt::model()->getSecondsSinceLastUpdate($profileAdid);

            if ($secondsGone === null || $secondsGone > 7 * 86400)
            {
                $syncMode = $secondsGone
                    ? Models\Queues\QueueAbstract::kSyncMode_Update
                    : Models\Queues\QueueAbstract::kSyncMode_New;

                Models\Queues\QueueProfiles::model()->push([
                    'profile_type'  => Models\Queues\QueueProfiles::kPROFILE_TYPE_tzkt,
                    'source_adid'   => $profileAdid,
                ], $syncMode);
            }
        }
    }
}
