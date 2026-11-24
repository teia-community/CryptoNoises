<?php

namespace CryptoNoisesTezos\Analyzers\BigmapUpdateEntities\MarketRecords;

use CryptoCore\Setapp;
use CryptoNoisesTezos\Core\Bigmap;
use CryptoNoisesTezos\Core\Log;

trait FxHashV3
{
    /**
     * A listing on fx(hash)'s current marketplace.
     *
     * The token is named by asset.contract_id, a key into the marketplace's own asset_contracts bigmap, so the
     * FA2 has to be looked up rather than derived. That lookup is cached for the life of the process: there are
     * three such entries on chain and a listing update would otherwise read one of them every time.
     */
    public static function PackDealRecord_FxHashV3_Swaps(int $bigmap, /* mixed */ $value): ?MarketDealRecord
    {
        $assetId    = $value['asset']['asset_id'] ?? null;
        $contractId = $value['asset']['contract_id'] ?? null;

        if ($assetId === null || $contractId === null || ($value['seller'] ?? null) === null)
        {
            Log::Error("MarketRecords", __FUNCTION__, "Bad params at #$bigmap", $value);
            return null;
        }

        $tokenAddress = self::FxHashV3_TokenContractById($contractId);

        if ($tokenAddress === null)
        {
            Log::Error("MarketRecords", __FUNCTION__, "Cannot resolve asset contract_id=$contractId", $value);
            return null;
        }

        $rec = new MarketDealRecord();

        $rec->exkey_token_address   = self::KeyToExkey($tokenAddress);
        $rec->exkey_token_id        = self::KeyToExkey($assetId);
        $rec->exkey_token_artist    = null;
        $rec->exkey_token_owner     = self::KeyToExkey($value['seller']);
        $rec->objkt_amount          = $value['asset']['amount'] ?? 1;
        $rec->objkt_price_xtz       = isset($value['currency']['tez'])
            ? self::NormalizeXtzPrice($value['amount'])
            : null;                                     // priced in a token, so there is no tez price to record
        $rec->objkt_royalties       = null;

        $rec->is_active             = $rec->objkt_amount > 0 ? MarketDealRecord::kActive_Yes : MarketDealRecord::kActive_No;
        return $rec;
    }

    /** @var array<string, string|null> contract_id => FA2, for the life of the process */
    private static array $FxHashV3AssetContracts = [];

    private static function FxHashV3_TokenContractById($contractId): ?string
    {
        $key = (string) $contractId;

        if (!array_key_exists($key, self::$FxHashV3AssetContracts))
        {
            $entry = Bigmap::GetValueByBigmapAndKey(Setapp\FxHash_v3::Bigmap_AssetContracts, $key);

            self::$FxHashV3AssetContracts[$key] = is_array($entry) ? ($entry['fa2'] ?? null) : null;
        }

        return self::$FxHashV3AssetContracts[$key];
    }
}
