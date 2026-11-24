<?php

namespace CryptoNoises\Networks\Tezos\Hunters;

use CryptoCore\Setapp;

abstract class ObjktComV1Hunter extends AbstractHunter
{
    protected const kMarketplaceKTID                = Setapp\ObjktCom_v1::MarketplaceKTID;
    protected const kEnglishAuctionsKTID            = Setapp\ObjktCom_v1::EnglishAuctionsKTID;
    protected const kDutchAuctionsKTID              = Setapp\ObjktCom_v1::DutchAuctionsKTID;

    // objkt.com's first two auction contracts, same interface as the pair above
    protected const kDutchAuctionsKTID_v2           = Setapp\ObjktCom_v1::DutchAuctionsKTID_v2;

    protected const kEnglishAuctionsKTID_pre        = Setapp\ObjktCom_v1::EnglishAuctionsKTID_pre;
    protected const kDutchAuctionsKTID_pre          = Setapp\ObjktCom_v1::DutchAuctionsKTID_pre;

    protected const kMarketplaceFeeWallet           = Setapp\ObjktCom_v1::MarketplaceFeeWallet;

    //------------------------------------------------------------------------------------------------------------------

    public static function GetAllMarketContracts(): array
    {
        return [self::kMarketplaceKTID, self::kEnglishAuctionsKTID, self::kDutchAuctionsKTID,
                self::kEnglishAuctionsKTID_pre, self::kDutchAuctionsKTID_pre, self::kDutchAuctionsKTID_v2];
    }

    public static function GetMarketplaceContractByTokenContract(string $tokensContract): ?string
    {
        return null;
    }
}
