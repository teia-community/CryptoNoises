<?php

namespace CryptoNoisesTezos\Analyzers\BigmapUpdateEntities\MarketRecords;

use CryptoNoisesTezos\Core\Log;

trait HicEtNunc
{
    public static function PackDealRecord_HicEtNunc_Swaps(int $bigmap, /* mixed */ $value): ?MarketDealRecord
    {
        if ($value['objkt_id'] === null || $value['issuer'] === null)
        {
            Log::Error("MarketRecords", __FUNCTION__, "Bad params at #$bigmap", $value);
            return null;
        }

        // Three contracts store a swap in this shape, and they differ only in what they leave out:
        //   hic et nunc v2     issuer, creator, objkt_id, royalties, objkt_amount, xtz_per_objkt
        //   hic et nunc v1     the same without creator and royalties (it predates both)
        //   8scribo            the same plus fa2, because it trades more than one token contract
        // So the token contract is read when it is there, and the two fields v1 lacks stay null.
        $rec = new MarketDealRecord();
        $rec->exkey_token_address   = self::KeyToExkey($value['fa2'] ?? 'KT1RJ6PbjHpwc3M5rw5s2Nbmefwbuwbdxton');
        $rec->exkey_token_id        = self::KeyToExkey($value['objkt_id']);
        $rec->exkey_token_artist    = isset($value['creator']) ? self::KeyToExkey($value['creator']) : null;
        $rec->exkey_token_owner     = self::KeyToExkey($value['issuer']);
        $rec->objkt_amount          = $value['objkt_amount'];
        $rec->objkt_price_xtz       = self::NormalizeXtzPrice($value['xtz_per_objkt']);
        $rec->objkt_royalties       = $value['royalties'] ?? null;

        $rec->is_active             = $rec->objkt_amount > 0 ? MarketDealRecord::kActive_Yes : MarketDealRecord::kActive_No;
        return $rec;
    }
}
