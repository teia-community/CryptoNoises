<?php

namespace CryptoNoisesTezos\Analyzers;

use CryptoNoisesTezos\Analyzers\BigmapUpdateEntities;
use CryptoNoisesTezos\Core\Bigmap;
use CryptoNoisesTezos\Models;
use CryptoNoisesTezos\Vendors\Tzkt;
use CryptoCore\Vss\Vss;
use Exception;

class AnalyzeBigmapUpdates
{
    private ?string $lastErrorMessage = null;

    /** Multi-row writes of the bigmap data tables (BigmapDataAbstract); off = one statement per update */
    public static bool $batchWrites = true;

    public function analyze(int $level): ?int
    {
        Vss::dbTezos()->transactionBegin();
        Vss::dbTezosLog()->transactionBegin();

        if (self::$batchWrites)
            Models\BigmapDataAbstract::BeginBatch();

        try
        {
            $items = Models\Log\BigmapUpdates::model()->selectByLevel($level);

            foreach ($items as $item)
            {
                switch ($item['action'])
                {
                    case Models\Log\BigmapUpdates::kACTION_Allocate:
                        $this->allocate($item);
                        break;

                    case Models\Log\BigmapUpdates::kACTION_Remove:
                        $this->remove($item);
                        break;

                    case Models\Log\BigmapUpdates::kACTION_AddKey:
                    case Models\Log\BigmapUpdates::kACTION_UpdateKey:
                    case Models\Log\BigmapUpdates::kACTION_RemoveKey:
                        $this->processKey($item['action'], $item);
                        break;

                    default:
                        throw new Exception('Unknown action ['.$item['action'].']');
                }
            }

            Models\BigmapDataAbstract::FlushBatch();
            Models\BigmapDataAbstract::EndBatch();

            Vss::dbTezos()->transactionCommit();
            Vss::dbTezosLog()->transactionCommit();

            return count($items);
        }
        catch (Exception $e)
        {
            Models\BigmapDataAbstract::EndBatch();

            Vss::dbTezos()->transactionRollback();
            Vss::dbTezosLog()->transactionRollback();

            $this->lastErrorMessage = $e->getMessage();
            return null;
        }
    }

    /** @var array<int, array> ptr => TzKT definition, fetched a page at a time ahead of need, per process */
    private static array $definitions = [];
    const kDEFINITIONS_PAGE = 1000;

    /**
     * A bigmap's TzKT definition. Bigmaps are allocated in pointer order, so a missing one is fetched together with the
     * next kDEFINITIONS_PAGE: one request instead of one per allocation. Falls back to the single request.
     */
    private static function BigmapDefinition(int $bigmap): ?array
    {
        if (!isset(self::$definitions[$bigmap]))
        {
            if (count(self::$definitions) > 10 * self::kDEFINITIONS_PAGE)
                self::$definitions = [];

            self::$definitions += Tzkt::GetBigmapDetailsFrom($bigmap, self::kDEFINITIONS_PAGE) ?? [];
        }

        $definition = self::$definitions[$bigmap] ?? Tzkt::GetBigmapDetails($bigmap);
        unset(self::$definitions[$bigmap]);

        return $definition;
    }

    public function getLastErrorMessage(): ?string
    {
        return $this->lastErrorMessage;
    }

    //------------------------------------------------------------------------------------------------------------------

    /**
     * @param array $item
     * @return void
     * @throws Exception
     */
    private function allocate(array $item): void
    {
        $this->ensureBigmapRecord($item['bigmap'], $item['level']);
    }

    /**
     * Makes sure the `bigmaps` row of a bigmap exists, reading its definition from TzKT, and returns the row.
     *
     * Normally the row is created from the 'allocate' action, in the block where the bigmap was allocated. An
     * indexer that does not start at block 1 (see sql/data/README.md) never sees those allocations, so the first
     * key update of an already existing bigmap creates the row instead. The definition comes from TzKT either way,
     * so the result is the same; only `first_level` is the block where this indexer first saw the bigmap.
     *
     * @throws Exception
     */
    private function ensureBigmapRecord(int $bigmap, int $level): array
    {
        $bigmapItem = Models\Bigmaps::model()->getItem($bigmap);

        if ($bigmapItem !== null)
            return $bigmapItem;

        $bigmapData = self::BigmapDefinition($bigmap);

        if ($bigmapData === null)
            throw new Exception('Cannot get bigmap data from tzkt');

        // TzKT only sends the fields it has: "alias" is missing for a contract without one, and "tags" for a
        // bigmap without tags. Reading them unguarded was a notice on PHP 7.4 and is a warning on PHP 8, which
        // Fat-Free turns into a fatal error. All of these columns are nullable.
        $item = [
            'bigmap'            => $bigmap,
            'contract'          => $bigmapData['contract']['address'] ?? null,
            'alias'             => $bigmapData['contract']['alias'] ?? null,
            'path'              => $bigmapData['path'] ?? null,
            'state'             => Models\Bigmaps::kSTATE_Active,
            'first_level'       => $level,
            'tags'              => !empty($bigmapData['tags']) ? json_encode($bigmapData['tags']) : null,
            'key_type'          => json_encode($bigmapData['keyType'] ?? null),
            'value_type'        => json_encode($bigmapData['valueType'] ?? null),
        ];

        //--------------------------------------------------------------------------------------------------------------

        if ($item['key_type']   == '{"schema:nat":"nat"}' &&
            $item['value_type'] == '{"schema:address":"address"}')
        {
            $item['type'] = Models\Bigmaps::kTYPE_LedgerLight;
        }
        else if($item['path']   == 'metadata' &&
            $item['key_type']   == '{"schema:string":"string"}' &&
            $item['value_type'] == '{"schema:bytes":"bytes"}')
        {
            $item['type'] = Models\Bigmaps::kTYPE_Metadata;
        }
        else if(
            ($item['path'] == 'token_metadata'        && $item['key_type'] == '{"schema:nat":"nat"}') ||
            ($item['path'] == 'assets.token_metadata' && $item['key_type'] == '{"schema:nat":"nat"}'))
        {
            $item['type'] = Models\Bigmaps::kTYPE_TokenMetadata;
        }
        else if(
            strpos($item['path'], 'supply') !== false &&
            ($item['key_type']   == '{"schema:nat":"nat"}' || $item['key_type']   == '{"schema:int":"int"}') &&
            ($item['value_type'] == '{"schema:nat":"nat"}' || $item['value_type'] == '{"schema:int":"int"}'))
        {
            $item['type'] = Models\Bigmaps::kTYPE_Supply;
        }
        else switch ($item['key_type'])
        {
            case '{"schema:object":{"address:address":"address","nat:nat":"nat"}}':
            case '{"schema:object":{"address:address":"address","nat:?nat":"nat"}}':
            case '{"schema:object":{"nat:nat":"nat","address:address":"address"}}':
                $item['type']               = Models\Bigmaps::kTYPE_Ledger;
                $item['fieldkey_token_id']  = 'nat';
                $item['fieldkey_address']   = 'address';
                break;

            case '{"schema:object":{"owner:address":"address","token_id:nat":"nat"}}':
            case '{"schema:object":{"token_id:nat":"nat","owner:address":"address"}}':
                $item['type']               = Models\Bigmaps::kTYPE_Ledger;
                $item['fieldkey_token_id']  = 'token_id';
                $item['fieldkey_address']   = 'owner';
                break;

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            case '{"schema:object":{"owner:address":"address","operator:address":"address","token_id:nat":"nat"}}':
            case '{"schema:object":{"token_id:nat":"nat","owner:address":"address","operator:address":"address"}}':
                $item['type']               = Models\Bigmaps::kTYPE_Operators;
                $item['fieldkey_token_id']  = 'token_id';
                $item['fieldkey_address']   = 'operator';
                $item['fieldkey_address2']  = 'owner';
                break;

            case '{"schema:object":{"address_0:address":"address","address_1:address":"address","nat:nat":"nat"}}':
            case '{"schema:object":{"address_0:address":"address","nat:nat":"nat","address_1:address":"address"}}':
            case '{"schema:object":{"nat:nat":"nat","address_0:address":"address","address_1:address":"address"}}':
                $item['type']               = Models\Bigmaps::kTYPE_Operators;
                $item['fieldkey_token_id']  = 'nat';
                $item['fieldkey_address']   = 'address_0';
                $item['fieldkey_address2']  = 'address_1';
                break;

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            default:
                $item['type']               = Models\Bigmaps::kTYPE_Basic;
                break;
        }

        //--------------------------------------------------------------------------------------------------------------

        Models\Bigmaps::model()->insert($item);

        // Re-read instead of returning $item: the classification above only fills the fieldkey_* columns it needs,
        // and callers read the row's columns directly.
        $bigmapItem = Models\Bigmaps::model()->getItem($bigmap);

        if ($bigmapItem === null)
            throw new Exception("Bigmap record #$bigmap could not be created");

        return $bigmapItem;
    }

    private function remove(array $item): void
    {
        $bigmap = $item['bigmap'];
        $level  = $item['level'];

        $entity = new BigmapUpdateEntities\Basic();
        $entity->init($bigmap, $level);
        $entity->removeAllInBigmap();

        $entity = new BigmapUpdateEntities\Ledger('*','*');
        $entity->init($bigmap, $level);
        $entity->removeAllInBigmap();

        $entity = new BigmapUpdateEntities\LedgerLight();
        $entity->init($bigmap, $level);
        $entity->removeAllInBigmap();

        $entity = new BigmapUpdateEntities\MarketDeals();
        $entity->init($bigmap, $level);
        $entity->removeAllInBigmap();

        $entity = new BigmapUpdateEntities\MarketOffers();
        $entity->init($bigmap, $level);
        $entity->removeAllInBigmap();

        $entity = new BigmapUpdateEntities\Metadata();
        $entity->init($bigmap, $level);
        $entity->removeAllInBigmap();

        $entity = new BigmapUpdateEntities\Operator('*','*','*');
        $entity->init($bigmap, $level);
        $entity->removeAllInBigmap();

        $entity = new BigmapUpdateEntities\TokenMetadata();
        $entity->init($bigmap, $level);
        $entity->removeAllInBigmap();

        $entity = new BigmapUpdateEntities\Supply();
        $entity->init($bigmap, $level);
        $entity->removeAllInBigmap();

        Models\Bigmaps::model()->remove($bigmap, $level);
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    /**
     * @throws Exception
     */
    private function processKey(string $action, array $item): void
    {
        $bigmap = $item['bigmap'];

        // Allowed: an indexer that started after this bigmap was allocated has no row for it yet
        $bigmapItem = $this->ensureBigmapRecord($bigmap, $item['level']);

        if ($bigmapItem['state'] == Models\Bigmaps::kSTATE_Removed)
        {

            // Allowed: a re-scan may start from a mid state
            Models\Bigmaps::model()->update($bigmap, ['state' => Models\Bigmaps::kSTATE_Active]);
        }

        $level      = $item['level'];
        $key        = json_decode($item['content_key'], true);
        $cValString = $item['content_value'];

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $entity = Bigmap::FactoryEntity($bigmapItem);

        if ($entity === null)
            throw new Exception("Unknown bigmap type [{$bigmapItem['type']}]");

        $entity->init($bigmap, $level);

        switch ($action)
        {
            case Models\Log\BigmapUpdates::kACTION_AddKey:
            case Models\Log\BigmapUpdates::kACTION_UpdateKey:
                $entity->save($key, $cValString);
                break;

            case Models\Log\BigmapUpdates::kACTION_RemoveKey:
                $entity->remove($key);
                break;
        }
    }
}
