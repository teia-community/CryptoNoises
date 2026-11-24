<?php

namespace CryptoNoisesTezos\Syncs;

use CryptoNoisesTezos\Core\TezosAddress;
use CryptoNoisesTezos\Models;
use CryptoNoisesTezos\Vendors;
use CryptoCore\Vss\Vss;
use Exception;

class SyncTransactions implements BatchedLevelSyncInterface
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
            $rows = $this->buildLevelRows($level, $items);

            if ($rows === null)
                return 0;

            $this->prepareTables($level);

            Vss::dbTezos()->transactionBegin();
            Vss::dbTezosLog()->transactionBegin();

            $this->writeRows([$level => $rows]);

            $outItemsCount = Models\Log\Transactions::model()->getItemsCountInBlock($level);

            if ($rows['items_in'] != $outItemsCount)
                throw new Exception("In/Out items count mismatch {$rows['items_in']} <> $outItemsCount");

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

    /**
     * Turns one level's TzKT items into the rows the log tables take, without touching the database beyond the
     * address lookups that getAddressAdid() memoizes.
     *
     * Both paths through this class go through here, so a batch writes exactly the rows a single level would.
     *
     * @return array|null null when the level has no items; otherwise block / transactions / entrypoints / items_in
     * @throws Exception  when TzKT returned nothing for the level
     */
    private function buildLevelRows(int $level, ?array $items): ?array
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
            return null;

        $this->verbose("- items in: $inItemsCount");

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $transactionRows  = [];
        $entrypointCalls  = [];

        $blItem = [];
        $blItem['level']        = $data['level'];
        $blItem['timestamp']    = $data['timestamp'];
        $blItem['hash']         = $data['hash'] ?? '';

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

            $transactionRows[] = $trItem;

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            if ($trItem['initiator_address'] == null && $trItem['target_address'] != null && ($trItem['entrypoint'] ?? null) != null)
            {
                $entrypointCalls[] = [$trItem['target_address'], $trItem['entrypoint'], $level];
            }
        }

        return [
            'block'         => $blItem,
            'transactions'  => $transactionRows,
            'entrypoints'   => $entrypointCalls,
            'items_in'      => $inItemsCount,
        ];
    }

    //==================================================================================================================
    // Batch writing
    //
    // One level with k transactions used to cost 9 + 2k queries: two BEGINs, a block insert, an insert per
    // transaction, an upsert per entrypoint call, a COUNT(*), two COMMITs, and the fixer's open and close. Nearly
    // all of that is round-trip latency, and the stage is a single serial cursor, so it was simply waiting.
    //
    // The batch path keeps every write and every check, and only stops paying for them one level at a time: one
    // transaction per batch, multi-row inserts, the entrypoint calls aggregated in PHP, and one grouped COUNT for
    // the whole range. A 100-level batch goes from well over a thousand queries to about ten.

    /** Flush the buffered rows once this many transactions are pending, so a dense range cannot grow unbounded. */
    const kBATCH_FLUSH_ROWS = 2000;

    /** @var array<int, array> level => rows, pending write */
    private array $batchRows = [];

    /** @var array<int, int> level => items TzKT gave us, kept after a flush for the in/out check */
    private array $batchItemsIn = [];

    /** @var array<string, array> "address\0entrypoint" => [levelMin, levelMax, calls] */
    private array $batchEntrypoints = [];

    private int $batchPendingRows = 0;

    public function batchBegin(array $itemsByLevel): void
    {
        $this->batchRows        = [];
        $this->batchItemsIn     = [];
        $this->batchEntrypoints = [];
        $this->batchPendingRows = 0;

        // Every log table this batch can touch is created first, outside the transaction. prepareTable() issues
        // CREATE TABLE IF NOT EXISTS, and DDL commits implicitly in MySQL, which inside the batch would commit
        // half of it. The per-level path prepares before its transaction for the same reason, and the call is
        // memoized per table, so doing it for every level costs one statement per new table and nothing after.
        foreach (array_keys($itemsByLevel) as $level)
            $this->prepareTables($level);

        $this->warmAddressCache($itemsByLevel);

        Vss::dbTezos()->transactionBegin();
        Vss::dbTezosLog()->transactionBegin();
    }

    /**
     * Resolves every address of the range in a couple of queries instead of one per address.
     *
     * Each transaction carries up to three addresses, so a dense range asks for tens of thousands of adids, and
     * GetOrCreateAdid() answers one address per round trip. The cache below is the same one getAddressAdid()
     * reads, so anything this does not find - a malformed or over-long address - still falls through to the
     * original per-address path and behaves exactly as before.
     */
    private function warmAddressCache(array $itemsByLevel): void
    {
        $wanted = [];

        foreach ($itemsByLevel as $items)
            foreach ($items as $item)
                foreach (['initiator', 'sender', 'target'] as $role)
                {
                    $address = $item[$role]['address'] ?? null;

                    if ($address !== null && !isset($this->adidsCache[$address]))
                        $wanted[$address] = true;
                }

        if (empty($wanted))
            return;

        $wanted = array_keys($wanted);
        $known  = Models\Address::model()->getAdidsByAddresses($wanted);
        $missing = array_values(array_diff($wanted, array_keys($known)));

        if ($missing)
        {
            Models\Address::model()->createMany($missing);
            $known += Models\Address::model()->getAdidsByAddresses($missing);
        }

        foreach ($known as $address => $adid)
            $this->adidsCache[$address] = $adid;
    }

    /**
     * Buffers one level's rows inside the open batch transaction.
     *
     * @throws Exception as buildLevelRows() does; the caller rolls the batch back
     */
    public function batchAdd(int $level, array $items): void
    {
        $rows = $this->buildLevelRows($level, $items);

        if ($rows === null)
            return;

        $this->batchRows[$level]    = $rows;
        $this->batchItemsIn[$level] = $rows['items_in'];
        $this->batchPendingRows    += count($rows['transactions']);

        if ($this->batchPendingRows >= self::kBATCH_FLUSH_ROWS)
            $this->batchFlush();
    }

    /**
     * Writes the buffered rows. Still inside the batch transaction, so a later error rolls these back too.
     */
    private function batchFlush(): void
    {
        if (empty($this->batchRows))
            return;

        $blockRows        = [];
        $transactionRows  = [];

        foreach ($this->batchRows as $rows)
        {
            $blockRows[] = $rows['block'];

            foreach ($rows['transactions'] as $trItem)
                $transactionRows[] = $trItem;

            // Aggregate here rather than per statement: fix() added 1 per call, so the batch adds its own count
            foreach ($rows['entrypoints'] as list($address, $entrypoint, $level))
            {
                $key = $address."\0".$entrypoint;

                if (!isset($this->batchEntrypoints[$key]))
                    $this->batchEntrypoints[$key] = [$level, $level, 0];

                $this->batchEntrypoints[$key][0] = min($this->batchEntrypoints[$key][0], $level);
                $this->batchEntrypoints[$key][1] = max($this->batchEntrypoints[$key][1], $level);
                $this->batchEntrypoints[$key][2]++;
            }
        }

        Models\Blocks::model()->insertMany($blockRows);
        Models\Log\Transactions::model()->insertMany($transactionRows);

        $this->batchRows        = [];
        $this->batchPendingRows = 0;
    }

    /**
     * Writes whatever is left, runs the in/out check for every level in one query, and commits.
     *
     * @return array<int, int> level => items stored, for the fixer rows
     * @throws Exception       on a count mismatch, with nothing committed
     */
    public function batchCommit(): array
    {
        $this->batchFlush();

        $entrypointRows = [];

        foreach ($this->batchEntrypoints as $key => list($levelMin, $levelMax, $calls))
        {
            list($address, $entrypoint) = explode("\0", $key, 2);

            $entrypointRows[] = [
                'target_address' => $address,
                'entrypoint'     => $entrypoint,
                'level_min'      => $levelMin,
                'level_max'      => $levelMax,
                'calls_count'    => $calls,
            ];
        }

        Models\TransactionsEntrypoints::model()->fixMany($entrypointRows);

        // The same check sync() does per level, for every level of the batch in one query
        $storedCounts = Models\Log\Transactions::model()->getItemsCountInBlocks(array_keys($this->batchItemsIn));

        foreach ($this->batchItemsIn as $level => $itemsIn)
        {
            $stored = $storedCounts[$level] ?? 0;

            if ($itemsIn != $stored)
                throw new Exception("#$level: In/Out items count mismatch $itemsIn <> $stored");
        }

        Vss::dbTezos()->transactionCommit();
        Vss::dbTezosLog()->transactionCommit();

        $result = $storedCounts;

        $this->batchRows        = [];
        $this->batchItemsIn     = [];
        $this->batchEntrypoints = [];
        $this->batchPendingRows = 0;

        return $result;
    }

    public function batchRollback(): void
    {
        Vss::dbTezos()->transactionRollback();
        Vss::dbTezosLog()->transactionRollback();

        $this->batchRows        = [];
        $this->batchItemsIn     = [];
        $this->batchEntrypoints = [];
        $this->batchPendingRows = 0;
    }

    //==================================================================================================================

    private function prepareTables(int $level): void
    {
        Models\Log\Transactions::model()->prepareTable($level);
        Models\Log\Transactions::model()->prepareTable($level+10000);       // Create the next table in advance
    }

    /**
     * @param array<int, array> $rowsByLevel level => rows from buildLevelRows()
     */
    private function writeRows(array $rowsByLevel): void
    {
        foreach ($rowsByLevel as $level => $rows)
        {
            Models\Blocks::model()->insert($rows['block']);

            foreach ($rows['transactions'] as $trItem)
                Models\Log\Transactions::model()->insert($level, $trItem);

            foreach ($rows['entrypoints'] as list($address, $entrypoint, $callLevel))
                Models\TransactionsEntrypoints::model()->fix($address, $entrypoint, $callLevel);
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
