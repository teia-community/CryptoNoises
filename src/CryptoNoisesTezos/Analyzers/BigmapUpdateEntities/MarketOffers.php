<?php

namespace CryptoNoisesTezos\Analyzers\BigmapUpdateEntities;

use CryptoCore\Setapp;
use CryptoNoisesTezos\Analyzers\BigmapUpdateEntities\MarketRecords\MarketOfferRecord;
use CryptoNoisesTezos\Models;

class MarketOffers extends AbstractEntity
{
    use MarketRecords\AkaSwap;
    use MarketRecords\FxHashV0;
    use MarketRecords\FxHashV2;
    use MarketRecords\Kalamint;
    use MarketRecords\ObjktComV1;
    use MarketRecords\ObjktComV2;
    use MarketRecords\ObjktComV3;
    use MarketRecords\Versum;

    public function __construct()
    {
        $this->dbDataModel = Models\BigmapDataMarketOffers::model();
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

        if ($record = self::PackOfferRecord($this->bigmap, $cValString))
        {
            $data['is_active']              = $record->is_active;

            $data['exkey_token_address']    = $record->exkey_token_address;
            $data['exkey_token_id']         = $record->exkey_token_id;
            $data['exkey_token_buyer']      = $record->exkey_token_buyer;
            $data['objkt_price_xtz']        = $record->objkt_price_xtz;
        }

        return $data;
    }

    //------------------------------------------------------------------------------------------------------------------

    public static function PackOfferRecord(int $bigmap, string $cValString): ?MarketOfferRecord
    {
        $value = json_decode($cValString, true);

        // When adding a bigmap here, also add it at @market:offers.bigmap-to-marketplace
        switch ($bigmap)
        {
            case Setapp\AkaSwap::Bigmap_Offers:          return self::PackOfferRecord_AkaSwap($bigmap, $value);
            case Setapp\FxHash_v2::Bigmap_Offers:           return self::PackOfferRecord_FxHashV2($bigmap, $value);
            case Setapp\Kalamint::Bigmap_Offers:         return self::PackOfferRecord_Kalamint($bigmap, $value);
            case Setapp\ObjktCom_v1::Bigmap_Offers:         return self::PackOfferRecord_ObjktComV1($bigmap, $value);
            case Setapp\ObjktCom_v2::Bigmap_Offers:         return self::PackOfferRecord_ObjktComV2($bigmap, $value);
            case Setapp\ObjktCom_v3a::Bigmap_Offers:        return self::PackOfferRecord_ObjktComV3($bigmap, $value);
            case Setapp\ObjktCom_v3::Bigmap_Offers:         return self::PackOfferRecord_ObjktComV3($bigmap, $value);
            case Setapp\ObjktCom_v4::Bigmap_Offers:         return self::PackOfferRecord_ObjktComV3($bigmap, $value);
            case Setapp\Versum::Bigmap_Offers:              return self::PackOfferRecord_Versum($bigmap, $value);
        }

        return null;
    }
}
