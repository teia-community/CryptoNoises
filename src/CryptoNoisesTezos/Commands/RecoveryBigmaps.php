<?php

namespace CryptoNoisesTezos\Commands;

use CryptoCore\Vss\Vss;
use CryptoNoisesTezos\Analyzers\BigmapUpdateEntities\MarketDeals;
use CryptoNoisesTezos\Analyzers\BigmapUpdateEntities\MarketOffers;
use CryptoNoisesTezos\Models;

class RecoveryBigmaps extends AbstractCommand
{
    public function fixNullDealRecords(): void
    {
        $this->fixNullRecords(Models\BigmapDataMarketDeals::model()->tableName(), function (array $item): ?array
        {
            $rec = MarketDeals::PackDealRecord($item['bigmap'], $item['value']);

            return $rec === null ? null : [
                'exkey_token_address'   => $rec->exkey_token_address,
                'exkey_token_id'        => $rec->exkey_token_id,
                'exkey_token_owner'     => $rec->exkey_token_owner,
                'objkt_amount'          => $rec->objkt_amount,
                'objkt_price_xtz'       => $rec->objkt_price_xtz,
            ];
        });
    }

    public function fixNullOfferRecords(): void
    {
        $this->fixNullRecords(Models\BigmapDataMarketOffers::model()->tableName(), function (array $item): ?array
        {
            $rec = MarketOffers::PackOfferRecord($item['bigmap'], $item['value']);

            return $rec === null ? null : [
                'exkey_token_address'   => $rec->exkey_token_address,
                'exkey_token_id'        => $rec->exkey_token_id,
                'exkey_token_buyer'     => $rec->exkey_token_buyer,
                'objkt_price_xtz'       => $rec->objkt_price_xtz,
            ];
        });
    }

    /**
     * Re-packs rows of one bigmap whose token fields are NULL.
     *
     * @param callable $packFields function (array $item): ?array - token fields of the row, null if it cannot be packed
     */
    private function fixNullRecords(string $tableName, callable $packFields): void
    {
        $this->verbose = true;
        ini_set('memory_limit', '512M');
        set_time_limit(86400);

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $bigmap = (int) $this->getCmdVar('bigmap');
        $limit  = (int) $this->getCmdVar('limit', 1000);

        if ($bigmap == 0)
        {
            $this->logErr("The bigmap parameter is required");
            return;
        }

        while ($limit > 0)
        {
            Vss::dbTezos()->transactionBegin();

            $item = Vss::dbTezos()->queryRow("
                SELECT *
                FROM $tableName
                WHERE bigmap = $bigmap
                  AND exkey_token_address IS NULL
                ORDER BY exkey
                LIMIT 1
            ");

            if ($item == null)
            {
                Vss::dbTezos()->transactionRollback();
                break;
            }

            $this->logMsg("Item found: {$item['bigmap']}.{$item['exkey']}", ' -> ');

            $fields = $packFields($item);

            if ($fields === null)
            {
                $this->logMsg("Item ERROR: cannot convert to record");
                Vss::dbTezos()->transactionRollback();
                return;
            }

            $update = ['is_active' => ($item['is_active'] ?? null) ?: 'yes'] + $fields;

            Vss::dbTezos()->update($tableName, $update, [
                'bigmap' => $item['bigmap'],
                'exkey'  => $item['exkey'],
            ]);

            Vss::dbTezos()->transactionCommit();

            $this->logMsg("Ok");
            $limit--;
        }
        $this->logMsg("Done");
    }
}
