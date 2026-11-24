<?php

namespace CryptoNoisesTezos\Analyzers\BigmapUpdateEntities\MarketRecords;

use CryptoNoisesTezos\Core\Log;
use CryptoCore\Time;

trait AkaSwap
{
    public static function PackDealRecord_AkaSwap_Swaps(int $bigmap, /* mixed */ $value): ?MarketDealRecord
    {
        if ($value['token_fa2'] === null || $value['token_id'] === null)
        {
            Log::Error("MarketRecords", __FUNCTION__, "Bad params at #$bigmap", $value);
            return null;
        }

        $rec = new MarketDealRecord();
        $rec->exkey_token_address   = self::KeyToExkey($value['token_fa2']);
        $rec->exkey_token_id        = self::KeyToExkey($value['token_id']);
        $rec->exkey_token_artist    = null;
        $rec->exkey_token_owner     = self::KeyToExkey($value['issuer']);
        $rec->objkt_amount          = $value['token_amount'];
        $rec->objkt_price_xtz       = self::NormalizeXtzPrice($value['xtz_per_token']);
        $rec->objkt_royalties       = null;

        $rec->is_active             = $rec->objkt_amount > 0 ? MarketDealRecord::kActive_Yes : MarketDealRecord::kActive_No;
        return $rec;
    }

    /**
     * akaswap V1's swaps: written before the contract traded more than its own FA2, so the fields are named
     * after akaOBJ and the token contract is implied rather than stored.
     */
    public static function PackDealRecord_AkaSwap_Swaps_v1(int $bigmap, /* mixed */ $value): ?MarketDealRecord
    {
        if (($value['akaOBJ_id'] ?? null) === null)
        {
            Log::Error("MarketRecords", __FUNCTION__, "Bad params at #$bigmap", $value);
            return null;
        }

        $rec = new MarketDealRecord();
        $rec->exkey_token_address   = self::KeyToExkey(\CryptoNoises\Networks\Tezos\Hunters\AkaSwap::kTokensKTID);
        $rec->exkey_token_id        = self::KeyToExkey($value['akaOBJ_id']);
        $rec->exkey_token_artist    = null;
        $rec->exkey_token_owner     = self::KeyToExkey($value['issuer']);
        $rec->objkt_amount          = $value['akaOBJ_amount'];
        $rec->objkt_price_xtz       = self::NormalizeXtzPrice($value['xtz_per_akaOBJ']);
        $rec->objkt_royalties       = null;

        $rec->is_active             = $rec->objkt_amount > 0 ? MarketDealRecord::kActive_Yes : MarketDealRecord::kActive_No;
        return $rec;
    }

    public static function PackDealRecord_AkaSwap_Auctions(int $bigmap, /* mixed */ $value): ?MarketDealRecord
    {
        if ($value['token_fa2'] === null || $value['token_id'] === null)
        {
            Log::Error("MarketRecords", "PackDealRecord_AkaSwap_Auctions", "Bad params at #$bigmap", $value);
            return null;
        }

        $rec = new MarketDealRecord();
        $rec->exkey_token_address   = self::KeyToExkey($value['token_fa2']);
        $rec->exkey_token_id        = self::KeyToExkey($value['token_id']);
        $rec->exkey_token_artist    = null;
        $rec->exkey_token_owner     = self::KeyToExkey($value['issuer']);
        $rec->objkt_amount          = 1;
        $rec->objkt_price_xtz       = null;
        $rec->objkt_royalties       = null;
        $rec->ts_deal_expired       = Time::NormalizeTimestamp($value['due_time']);

        $rec->is_active             = $value['is_done'] ? MarketDealRecord::kActive_No : MarketDealRecord::kActive_Yes;
        $rec->ts_active_until       = $rec->ts_deal_expired;
        return $rec;
    }

    //------------------------------------------------------------------------------------------------------------------

    public static function PackOfferRecord_AkaSwap(int $bigmap, /* mixed */ $value): ?MarketOfferRecord
    {
        if ($value['token_fa2'] === null || $value['token_id'] === null)
        {
            Log::Error("MarketRecords", "PackOfferRecord_AkaSwap", "Bad params at #$bigmap", $value);
            return null;
        }

        $rec = new MarketOfferRecord();
        $rec->exkey_token_address   = self::KeyToExkey($value['token_fa2']);
        $rec->exkey_token_id        = self::KeyToExkey($value['token_id']);
        $rec->exkey_token_buyer     = self::KeyToExkey($value['buyer']);
        $rec->objkt_price_xtz       = self::NormalizeXtzPrice($value['xtz_per_token']);

        $rec->is_active             = $value['is_done'] ? MarketOfferRecord::kActive_No : MarketOfferRecord::kActive_Yes;
        return $rec;
    }
}
