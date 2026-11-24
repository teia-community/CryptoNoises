<?php

namespace CryptoNoisesTezos\Analyzers\BigmapUpdateEntities;

use CryptoCore\Setapp;
use CryptoNoisesTezos\Analyzers\BigmapUpdateEntities\MarketRecords\MarketDealRecord;
use CryptoNoisesTezos\Models;

class MarketDeals extends AbstractEntity
{
    use MarketRecords\AkaSwap;
    use MarketRecords\Dogami;
    use MarketRecords\EightBidou;
    use MarketRecords\FxHashV0;
    use MarketRecords\FxHashV2;
    use MarketRecords\HicEtNunc;
    use MarketRecords\Kalamint;
    use MarketRecords\ObjktComV1;
    use MarketRecords\ObjktComV2;
    use MarketRecords\ObjktComV3;
    use MarketRecords\Teia;
    use MarketRecords\Typed;
    use MarketRecords\Versum;

    public function __construct()
    {
        $this->dbDataModel = Models\BigmapDataMarketDeals::model();
    }

    public function primaryKey($key): array
    {
        return [
            'bigmap'    => $this->bigmap,
            'exkey'     => self::KeyToExkey($key),
        ];
    }

    public function packSavingValues(string $cValString): array
    {
        $data = [];
        $data['value'] = $cValString;

        if ($record = self::PackDealRecord($this->bigmap, $cValString))
        {
            $data['is_active']              = $record->is_active;
            $data['ts_active_until']        = $record->ts_active_until;

            $data['exkey_token_address']    = $record->exkey_token_address;
            $data['exkey_token_id']         = $record->exkey_token_id;
            $data['exkey_token_artist']     = $record->exkey_token_artist;
            $data['exkey_token_owner']      = $record->exkey_token_owner;
            $data['objkt_amount']           = $record->objkt_amount;
            $data['objkt_price_xtz']        = $record->objkt_price_xtz;
            $data['objkt_royalties']        = $record->objkt_royalties;
            $data['ts_deal_expired']        = $record->ts_deal_expired;
        }

        return $data;
    }

    //------------------------------------------------------------------------------------------------------------------

    public static function PackDealRecord(int $bigmap, string $cValString): ?MarketDealRecord
    {
        $value = json_decode($cValString, true);

        switch ($bigmap)
        {
            case  55662:    return self::PackDealRecord_AkaSwap_Swaps($bigmap, $value);                                 # KT1Dn3sambs7KZGW88hH2obZeSzfmCmGvpFo / swaps
            case Setapp\AkaSwap::Bigmap_Auctions:              return self::PackDealRecord_AkaSwap_Auctions($bigmap, $value);

            case 113273:    return self::PackDealRecord_EightBidou_Swaps($bigmap, $value);                              # KT1BvWGFENd4CXW5F3u4n31xKfJhmBGipoqF / swap_list
            case 128202:    return self::PackDealRecord_EightBidou_Swaps($bigmap, $value);                              # KT1AHBvSo828QwscsjDjeUuep7MgApi8hXqA / swap_list

            case 115428:    return self::PackDealRecord_Dogami_Swaps($bigmap, $value);                                  # KT1QcZ81mxhYutPCA7WeVAd7Ko1etWJBuRqQ / swaps

            case Setapp\FxHash_v0::Bigmap_Swaps:                return self::PackDealRecord_FxHashV0_Swaps($bigmap, $value);
            case Setapp\FxHash_v2::Bigmap_Swaps:                return self::PackDealRecord_FxHashV2_Swaps($bigmap, $value);

            case Setapp\HicEtNunc::Bigmap_Swaps:               return self::PackDealRecord_HicEtNunc_Swaps($bigmap, $value);

            case Setapp\Kalamint::Bigmap_Tokens:               return self::PackDealRecord_Kalamint_Swaps($bigmap, $value);

            case Setapp\ObjktCom_v1::Bigmap_Swaps:              return self::PackDealRecord_ObjktComV1_Swaps($bigmap, $value);
            case Setapp\ObjktCom_v1::Bigmap_AuctionsEnglish:    return self::PackDealRecord_ObjktComV1_AuctionsEnglish($bigmap, $value);
            case Setapp\ObjktCom_v1::Bigmap_AuctionsDutch:      return self::PackDealRecord_ObjktComV1_AuctionsDutch($bigmap, $value);

            // objkt.com's first two auction contracts: the values carry the same keys as the pair above
            case Setapp\ObjktCom_v1::Bigmap_AuctionsEnglish_pre: return self::PackDealRecord_ObjktComV1_AuctionsEnglish($bigmap, $value);
            case Setapp\ObjktCom_v1::Bigmap_AuctionsDutch_pre:   return self::PackDealRecord_ObjktComV1_AuctionsDutch($bigmap, $value);
            case Setapp\ObjktCom_v1::Bigmap_AuctionsDutch_v2:    return self::PackDealRecord_ObjktComV1_AuctionsDutch($bigmap, $value);

            // objkt.com's english v5 and v5.1 carry the same values as english v6
            case Setapp\ObjktCom_v3::Bigmap_AuctionsEnglish_v5:  return self::PackDealRecord_ObjktComV3_AuctionsEnglish($bigmap, $value);
            case Setapp\ObjktCom_v3::Bigmap_AuctionsEnglish_v51: return self::PackDealRecord_ObjktComV3_AuctionsEnglish($bigmap, $value);

            case Setapp\ObjktCom_v2::Bigmap_Swaps:              return self::PackDealRecord_ObjktComV2_Swaps($bigmap, $value);
            case Setapp\ObjktCom_v2::Bigmap_AuctionsEnglish:    return self::PackDealRecord_ObjktComV2_AuctionsEnglish($bigmap, $value);
            case Setapp\ObjktCom_v2::Bigmap_AuctionsDutch:      return self::PackDealRecord_ObjktComV2_AuctionsDutch($bigmap, $value);

            case Setapp\ObjktCom_v3a::Bigmap_Swaps:             return self::PackDealRecord_ObjktComV3_Swaps($bigmap, $value);

            case Setapp\ObjktCom_v3::Bigmap_Swaps:              return self::PackDealRecord_ObjktComV3_Swaps($bigmap, $value);
            case Setapp\ObjktCom_v3::Bigmap_AuctionsEnglish:    return self::PackDealRecord_ObjktComV3_AuctionsEnglish($bigmap, $value);
            case Setapp\ObjktCom_v3::Bigmap_AuctionsDutch:      return self::PackDealRecord_ObjktComV3_AuctionsDutch($bigmap, $value);

            // v4 is objkt.com's marketplace v6.2 / english v6.1: the values carry the same keys as v3's, plus
            // nullable extras these packers do not read, so they go through the v3 packers unchanged.
            case Setapp\ObjktCom_v4::Bigmap_Swaps:              return self::PackDealRecord_ObjktComV3_Swaps($bigmap, $value);
            case Setapp\ObjktCom_v4::Bigmap_AuctionsEnglish:    return self::PackDealRecord_ObjktComV3_AuctionsEnglish($bigmap, $value);

            case Setapp\Teia::Bigmap_Swaps:                    return self::PackDealRecord_Teia_Swaps($bigmap, $value);

            case 196868:    return self::PackDealRecord_Typed_Swaps($bigmap, $value);                                   # KT1VoZeuBMJF6vxtLqEFMoc4no5VDG789D7z / swaps

            case  Setapp\Versum::Bigmap_Swaps:                  return self::PackDealRecord_Versum_Swaps($bigmap, $value);
            case  Setapp\Versum::Bigmap_Auctions:               return self::PackDealRecord_Versum_Auctions($bigmap, $value);

            // IGNORE:
            // - Rarible
            // - Tezotopia
        }

        return null;
    }
}
