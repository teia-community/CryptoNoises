<?php

namespace CryptoNoisesTezos\Syncs;

use CryptoNoisesTezos\Core\TezosAddress;
use CryptoNoisesTezos\Models;
use CryptoNoisesTezos\Vendors;
use CryptoCore\Vss\Vss;
use Exception;

class SyncTransactions implements LevelSyncInterface
{
    private ?string $lastErrorMessage = null;

    public bool $verbose = false;

    public function tzktRangePath(): string
    {
        return 'operations/transactions';
    }

    public function sync(int $level, ?array $items = null): ?int
    {
        try
        {
            if ($items === null)
            {
                $data = Vendors\Tzkt::GetBlockDetails($level);

                if ($data === null)
                    throw new Exception(self::kERROR_NoData);
            }
            else
            {
                // Transactions of this level loaded by LevelRangeLoader (the same objects as in the block response):
                // the block row is built from them, it is stored only for levels with transactions anyway
                $data = [
                    'level'         => $level,
                    'timestamp'     => $items[0]['timestamp'] ?? null,
                    'hash'          => $items[0]['block'] ?? null,
                    'transactions'  => $items,
                ];
            }

            $inItemsCount = count($data['transactions']);

            if ($inItemsCount == 0)
                return 0;

            $this->verbose("- items in: $inItemsCount");

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            Models\Log\Transactions::model()->prepareTable($level);
            Models\Log\Transactions::model()->prepareTable($level+10000);       // Create the next table in advance

            Vss::dbTezos()->transactionBegin();
            Vss::dbTezosLog()->transactionBegin();

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $blItem = [];
            $blItem['level']        = $data['level'];
            $blItem['timestamp']    = $data['timestamp'];
            $blItem['hash']         = $data['hash'] ?? '';

            Models\Blocks::model()->insert($blItem);

            $this->verbose("- block: #{$data['level']} : {$data['timestamp']} : {$data['hash']}");

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            foreach ($data['transactions'] as $item)
            {
                // TzKT leaves out the fields that do not apply to an operation: "nonce" and "initiator" only come
                // with internal operations, "tokenTransfersCount" only when there were transfers, and the fee and
                // storage counters only when they are not zero. Reading them unguarded raised a notice on PHP 7.4,
                // which was filtered out; on PHP 8 it is a warning, and Fat-Free turns that into a fatal error.
                // Nullable columns of template__transactions_0xxxx get null, NOT NULL counters get 0.
                $trItem = [];
                $trItem['id']               = $item['id'];                          unset($item['id']);
                $trItem['level']            = $item['level'];                       unset($item['level']);
                $trItem['counter']          = $item['counter'];                     unset($item['counter']);
                $trItem['timestamp']        = $item['timestamp'];                   unset($item['timestamp']);
                unset($item['type']);
                $trItem['hash']             = $item['hash'] ?? null;                unset($item['hash']);
                $trItem['gasLimit']         = $item['gasLimit'] ?? 0;               unset($item['gasLimit']);
                $trItem['gasUsed']          = $item['gasUsed'] ?? 0;                unset($item['gasUsed']);
                $trItem['storageLimit']     = $item['storageLimit'] ?? 0;           unset($item['storageLimit']);
                $trItem['storageUsed']      = $item['storageUsed'] ?? 0;            unset($item['storageUsed']);
                $trItem['bakerFee']         = $item['bakerFee'] ?? 0;               unset($item['bakerFee']);
                $trItem['storageFee']       = $item['storageFee'] ?? 0;             unset($item['storageFee']);
                $trItem['allocationFee']    = $item['allocationFee'] ?? 0;          unset($item['allocationFee']);
                $trItem['amount']           = $item['amount'] ?? 0;                 unset($item['amount']);
                $trItem['status']           = $item['status'] ?? null;              unset($item['status']);
                $trItem['hasInternals']     = (int) ($item['hasInternals'] ?? 0);   unset($item['hasInternals']);
                $trItem['nonce']            = $item['nonce'] ?? null;               unset($item['nonce']);

                $trItem['token_transfers_count'] = $item['tokenTransfersCount'] ?? null;
                unset($item['tokenTransfersCount']);

                // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

                // A plain tez transfer carries no "parameter" at all
                $parameter = $item['parameter'] ?? null;

                $trItem['parameter'] = json_encode($parameter);

                if (!empty($parameter) && isset($parameter['entrypoint']))
                    $trItem['entrypoint'] = $parameter['entrypoint'];

                unset($item['parameter']);
                unset($item['parameters']);

                // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

                // "initiator" is only set on internal operations, and an address object can carry an alias
                // without an address. The code below already treats a null initiator_address as normal.
                $trItem['initiator_address']    = $item['initiator']['address'] ?? null;
                $trItem['initiator_adid']       = $this->getAddressAdid($trItem['initiator_address']);

                $trItem['sender_address']       = $item['sender']['address'] ?? null;
                $trItem['sender_adid']          = $this->getAddressAdid($trItem['sender_address']);

                $trItem['target_address']       = $item['target']['address'] ?? null;
                $trItem['target_adid']          = $this->getAddressAdid($trItem['target_address']);

                unset($item['initiator']);
                unset($item['sender']);
                unset($item['target']);

                // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

                if (isset($item['errors']))
                {
                    $trItem['errors']       = json_encode($item['errors']);         unset($item['errors']);
                }

                // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

                // Remove unused data
                unset($item['block']);

                if (!empty($item))
                    $trItem['undefined_fields'] = json_encode($item);

                // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

                $this->verbose("- transaction: #{$trItem['id']} : {$trItem['hash']}");

                Models\Log\Transactions::model()->insert($level, $trItem);

                // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

                if ($trItem['initiator_address'] == null && $trItem['target_address'] != null && ($trItem['entrypoint'] ?? null) != null)
                {
                    Models\TransactionsEntrypoints::model()->fix($trItem['target_address'], $trItem['entrypoint'], $level);
                }
            }

            $outItemsCount = Models\Log\Transactions::model()->getItemsCountInBlock($level);

            if ($inItemsCount != $outItemsCount)
                throw new Exception("In/Out items count mismatch $inItemsCount <> $outItemsCount");

            Vss::dbTezos()->transactionCommit();
            Vss::dbTezosLog()->transactionCommit();

            $this->verbose("- found $outItemsCount transaction(s)");
            return $outItemsCount;
        }
        catch (Exception $e)
        {
            Vss::dbTezos()->transactionRollback();
            Vss::dbTezosLog()->transactionRollback();

            $this->lastErrorMessage = $e->getMessage();
            return null;
        }
    }

    //==================================================================================================================

    protected array $adidsCache = [];

    protected function getAddressAdid(?string $address)
    {
        if ($address === null)
            return null;

        if (!isset($this->adidsCache[$address]))
            $this->adidsCache[$address] = TezosAddress::GetOrCreateAdid($address);

        return $this->adidsCache[$address];
    }

    //==================================================================================================================

    public function getLastErrorMessage(): ?string
    {
        return $this->lastErrorMessage;
    }

    protected function verbose(string $message): void
    {
        if (!$this->verbose)
            return;

        echo get_class($this).": ".$message."\n";
        ob_flush();
    }
}
