<?php

namespace CryptoNoisesTezos\Syncs;

use CryptoNoisesTezos\Models;
use CryptoNoisesTezos\Vendors;
use CryptoCore\Vss\Vss;
use Exception;

class SyncBigmapUpdates implements LevelSyncInterface
{
    private ?string $lastErrorMessage = null;

    public function tzktRangePath(): string
    {
        return 'bigmaps/updates';
    }

    public function sync(int $level, ?array $items = null): ?int
    {
        try
        {
            if ($items === null)
            {
                $data = Vendors\Tzkt::GetBlockBigmapUpdates($level);

                if ($data === null)
                    throw new Exception(self::kERROR_NoData);

                // A single request returns at most Tzkt::kMAX_ITEMS_PER_REQUEST items (LevelRangeLoader pages them, this path does not)
                if (count($data) == Vendors\Tzkt::kMAX_ITEMS_PER_REQUEST)
                    throw new Exception("Level has 10000+ bigmap updates: the single-request limit was hit, the result may be truncated");
            }
            else
            {
                $data = $items;     // loaded by LevelRangeLoader
            }

            $inItemsCount = count($data);

            if ($inItemsCount == 0)
                return 0;

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            Models\Log\BigmapUpdates::model()->prepareTable($level);

            Vss::dbTezos()->transactionBegin();
            Vss::dbTezosLog()->transactionBegin();

            Models\Log\BigmapUpdates::model()->cleanUp($level);

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            foreach ($data as $item)
            {
                // The 'allocate' and 'remove' actions describe the bigmap itself, so TzKT sends them without a
                // "content" object at all, and content_key / content_value are nullable for exactly that reason.
                // Reading them unguarded raised a notice on PHP 7.4, which was filtered out; on PHP 8 it is a
                // warning, and Fat-Free turns that into a fatal error - which would stop the sync at the first
                // bigmap allocation in a synced block.
                $contentKey         = $item['content']['key']   ?? null;
                $contentValue       = $item['content']['value'] ?? null;

                unset($item['contract']['alias']);
                unset($item['contract']['address']);

                unset($item['content']['hash']);
                unset($item['content']['key']);
                unset($item['content']['value']);

                if (!empty($item['contract']))
                    throw new Exception("Bad contract-keys count #{$item['id']}");

                if (!empty($item['content']))
                    throw new Exception("Bad content-keys count #{$item['id']}");

                // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

                $buItem = [];
                $buItem['id']               = $item['id'];
                $buItem['level']            = $item['level'];
                $buItem['timestamp']        = $item['timestamp'];
                $buItem['bigmap']           = $item['bigmap'];
                $buItem['path']             = $item['path'];
                $buItem['action']           = $item['action'];

                $buItem['content_key']      = json_encode($contentKey);
                $buItem['content_value']    = json_encode($contentValue);

                Models\Log\BigmapUpdates::model()->insert($item['level'], $buItem);
            }

            $outItemsCount = Models\Log\BigmapUpdates::model()->getItemsCountInBlock($level);

            if ($inItemsCount != $outItemsCount)
                throw new Exception("In/Out items count mismatch $inItemsCount <> $outItemsCount");

            Vss::dbTezos()->transactionCommit();
            Vss::dbTezosLog()->transactionCommit();

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

    public function getLastErrorMessage(): ?string
    {
        return $this->lastErrorMessage;
    }

    public function getRecordsCount(int $level): ?int
    {
        $data = Vendors\Tzkt::GetBlockBigmapUpdates($level);

        if ($data === null)
            return null;

        return count($data);
    }
}
