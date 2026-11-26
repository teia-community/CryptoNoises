<?php

namespace CryptoNoisesTezos\Syncs;

use CryptoNoisesTezos\Models;
use CryptoNoisesTezos\Vendors;
use CryptoCore\Vss\Vss;
use Exception;

class SyncBigmapUpdates implements BatchedLevelSyncInterface
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

            foreach ($this->buildRows($data) as $buItem)
                Models\Log\BigmapUpdates::model()->insert($buItem['level'], $buItem);

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

    /**
     * The log rows of TzKT items, as both paths store them.
     *
     * @throws Exception on an item with unexpected keys
     */
    private function buildRows(array $items): array
    {
        $rows = [];

        foreach ($items as $item)
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

        $rows[] = $buItem;
        }

        return $rows;
    }

    //------------------------------------------------------------------------------------------------------------------
    // Batch: a range of levels under one transaction, with the same rows and in/out check as sync()

    const kBATCH_FLUSH_ROWS = 5000;

    /** @var array<int, array[]> level => rows, pending write */
    private array $batchRows = [];
    /** @var array<int, int> level => items TzKT gave */
    private array $batchItemsIn = [];
    private int $batchPendingRows = 0;

    public function batchBegin(array $itemsByLevel): void
    {
        $this->batchRows        = [];
        $this->batchItemsIn     = [];
        $this->batchPendingRows = 0;

        // DDL commits implicitly, so the tables are created before the transaction (memoized per table)
        foreach (array_keys($itemsByLevel) as $level)
            Models\Log\BigmapUpdates::model()->prepareTable($level);

        Vss::dbTezos()->transactionBegin();
        Vss::dbTezosLog()->transactionBegin();
    }

    public function batchAdd(int $level, array $items): void
    {
        if (count($items) == 0)
            return;

        $this->batchRows[$level]    = $this->buildRows($items);
        $this->batchItemsIn[$level] = count($items);
        $this->batchPendingRows    += count($items);

        if ($this->batchPendingRows >= self::kBATCH_FLUSH_ROWS)
            $this->batchFlush();
    }

    private function batchFlush(): void
    {
        if (empty($this->batchRows))
            return;

        // As sync(): a level's earlier rows go before its rows are written
        Models\Log\BigmapUpdates::model()->cleanUpLevels(array_keys($this->batchRows));
        Models\Log\BigmapUpdates::model()->insertMany(array_merge(...array_values($this->batchRows)));

        $this->batchRows        = [];
        $this->batchPendingRows = 0;
    }

    public function batchCommit(): array
    {
        $this->batchFlush();

        $stored = Models\Log\BigmapUpdates::model()->getItemsCountInBlocks(array_keys($this->batchItemsIn));

        foreach ($this->batchItemsIn as $level => $itemsIn)
            if ($itemsIn != ($stored[$level] ?? 0))
                throw new Exception("#$level: In/Out items count mismatch $itemsIn <> ".($stored[$level] ?? 0));

        Vss::dbTezos()->transactionCommit();
        Vss::dbTezosLog()->transactionCommit();

        $this->batchItemsIn = [];
        return $stored;
    }

    public function batchRollback(): void
    {
        Vss::dbTezos()->transactionRollback();
        Vss::dbTezosLog()->transactionRollback();

        $this->batchRows        = [];
        $this->batchItemsIn     = [];
        $this->batchPendingRows = 0;
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
