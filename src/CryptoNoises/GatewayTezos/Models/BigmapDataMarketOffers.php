<?php

namespace CryptoNoises\GatewayTezos\Models;

use CryptoCore\Setapp;
use CryptoNoises\Config;
use CryptoNoises\Entities\Offer;
use CryptoNoises\GatewayTezos\Tokens;
use CryptoNoises\Networks\AbstractNetwork;
use CryptoNoises\Networks\Tezos\TezosHelper;
use CryptoNoises\Networks\Tezos\TezosNetwork;
use CryptoCore\Vss\Vss;

class BigmapDataMarketOffers
{
    /**
     * @param int $userId
     * @return Offer[]
     */
    public static function GetOffersByUserId(int $userId): array
    {
        $network = AbstractNetwork::GetNetworkById(Config::kNETWORK_Tezos);

        // @market:offers.bigmap-to-marketplace
        $bigmapMapMarketplace = [
            Setapp\AkaSwap::Bigmap_Offers              => Setapp\AkaSwap::OffersKTID,
            Setapp\FxHash_v2::Bigmap_Offers             => Setapp\FxHash_v2::MarketplaceKTID,
            Setapp\Kalamint::Bigmap_Offers             => Setapp\Kalamint::OffersKTID,
            Setapp\ObjktCom_v1::Bigmap_Offers           => Setapp\ObjktCom_v1::MarketplaceKTID,
            Setapp\ObjktCom_v2::Bigmap_Offers           => Setapp\ObjktCom_v2::MarketplaceKTID,
            Setapp\ObjktCom_v3a::Bigmap_Offers          => Setapp\ObjktCom_v3a::MarketplaceKTID,
            Setapp\ObjktCom_v3::Bigmap_Offers           => Setapp\ObjktCom_v3::MarketplaceKTID,
            Setapp\ObjktCom_v4::Bigmap_Offers           => Setapp\ObjktCom_v4::MarketplaceKTID,
            Setapp\Versum::Bigmap_Offers                => Setapp\Versum::MarketplaceKTID,
        ];

        $result = [];

        $dbTezos = \VssCfg::cfg()->getDbName('dbTezos');

        $sql = "
            SELECT offers.*
              FROM ".\CryptoNoises\Models\Address::model()->tableName()." AS addr
              JOIN ".\CryptoNoises\Models\UsersRefWallets::model()->tableName()." AS w
                ON addr.adid = w.wallet_adid
               AND w.user_id = :user_id 
            
              JOIN `$dbTezos`.".\CryptoNoisesTezos\Models\BigmapKeysAddress::model()->tableName()." AS bm_addr
                ON bm_addr.address = addr.address
            
              JOIN `$dbTezos`.".\CryptoNoisesTezos\Models\BigmapDataMarketOffers::model()->tableName()." AS offers
                ON offers.exkey_token_buyer = -bm_addr.exkey_id
               AND offers.state     = :state_active
               AND offers.is_active = :offer_active_yes
               
            ORDER BY offers.bigmap, offers.exkey_token_address DESC, offers.exkey_token_id, offers.last_level
            
            LIMIT 500
        ";
        // DESC because exkey values are negative.
        // LIMIT 500 keeps the per-offer loop below within the PHP time limit.

        $items = Vss::db()->query($sql, [
            'user_id'           => $userId,
            'state_active'      => \CryptoNoisesTezos\Models\BigmapDataAbstract::kSTATE_Active,
            'offer_active_yes'  => \CryptoNoisesTezos\Analyzers\BigmapUpdateEntities\MarketRecords\MarketOfferRecord::kActive_Yes,
        ]);

        foreach ($items as $item)
        {
            $marketplaceAddress = $bigmapMapMarketplace[$item['bigmap']] ?? null;

            if ($marketplaceAddress === null)
                continue;

            $tokenAddress = \CryptoNoisesTezos\Core\Bigmap::ExkeyToKey($item['exkey_token_address']);
            $tokenId      = \CryptoNoisesTezos\Core\Bigmap::ExkeyToKey($item['exkey_token_id']);

            $token        = Tokens::GetTokenDetails($tokenAddress, $tokenId);
            $marketplace  = TezosHelper::GetMarketplace($marketplaceAddress, $tokenAddress);

            // Token not synced yet or unknown marketplace: skip the offer instead of failing the whole list
            if ($token === null || $marketplace === null)
                continue;

            $offer = new Offer();
            $offer->token           = $token;
            $offer->marketplace     = $marketplace;

            $offer->buyerAddress    = \CryptoNoisesTezos\Core\Bigmap::ExkeyToKey($item['exkey_token_buyer']);
            $offer->buyerAddress    = $network->getWalletShortName($offer->buyerAddress);

            if ($item['objkt_price_xtz'] !== null)
            {
                $offer->priceAmount     = $item['objkt_price_xtz'];
                $offer->priceCurrency   = TezosNetwork::kCURRENCY_ID;
                $offer->priceFriendly   = $network->getCoinsFriendlyFullValue($item['objkt_price_xtz']);
            }
            else
            {
                $offer->priceAmount     = null;
                $offer->priceCurrency   = null;
                $offer->priceFriendly   = null;
            }

            $result[] = $offer;
        }

        return $result;
    }
}
