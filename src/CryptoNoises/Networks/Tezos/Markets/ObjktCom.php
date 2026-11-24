<?php

namespace CryptoNoises\Networks\Tezos\Markets;

use CryptoCore\Setapp;
use CryptoNoises\Entities\TokenActions;
use CryptoNoises\GatewayTezos;
use CryptoCore\Http\AbstractVendor;

class ObjktCom extends AbstractVendor
{
    public static function GetAuctionDetails($tokenAddress, $auctionId): ?TokenActions\Auction
    {
        switch ($tokenAddress)
        {
            case Setapp\ObjktCom_v1::DutchAuctionsKTID_v2:      $version = 1; $bigmapId = Setapp\ObjktCom_v1::Bigmap_AuctionsDutch_v2;    break;
            case Setapp\ObjktCom_v3::EnglishAuctionsKTID_v5:    $version = 3; $bigmapId = Setapp\ObjktCom_v3::Bigmap_AuctionsEnglish_v5;  break;
            case Setapp\ObjktCom_v3::EnglishAuctionsKTID_v51:   $version = 3; $bigmapId = Setapp\ObjktCom_v3::Bigmap_AuctionsEnglish_v51; break;
            case Setapp\ObjktCom_v1::EnglishAuctionsKTID_pre:   $version = 1; $bigmapId = Setapp\ObjktCom_v1::Bigmap_AuctionsEnglish_pre; break;
            case Setapp\ObjktCom_v1::DutchAuctionsKTID_pre:     $version = 1; $bigmapId = Setapp\ObjktCom_v1::Bigmap_AuctionsDutch_pre;   break;
            case Setapp\ObjktCom_v1::EnglishAuctionsKTID:   $version = 1; $bigmapId = Setapp\ObjktCom_v1::Bigmap_AuctionsEnglish; break;
            case Setapp\ObjktCom_v1::DutchAuctionsKTID:     $version = 1; $bigmapId = Setapp\ObjktCom_v1::Bigmap_AuctionsDutch;   break;
            case Setapp\ObjktCom_v2::EnglishAuctionsKTID:   $version = 2; $bigmapId = Setapp\ObjktCom_v2::Bigmap_AuctionsEnglish; break;
            case Setapp\ObjktCom_v2::DutchAuctionsKTID:     $version = 2; $bigmapId = Setapp\ObjktCom_v2::Bigmap_AuctionsDutch;   break;
            // v4 is objkt.com's "english auction v6.1": same value shape as v3, so it reads as version 3
            case Setapp\ObjktCom_v4::EnglishAuctionsKTID:   $version = 3; $bigmapId = Setapp\ObjktCom_v4::Bigmap_AuctionsEnglish; break;
            case Setapp\ObjktCom_v3::EnglishAuctionsKTID:   $version = 3; $bigmapId = Setapp\ObjktCom_v3::Bigmap_AuctionsEnglish; break;
            case Setapp\ObjktCom_v3::DutchAuctionsKTID:     $version = 3; $bigmapId = Setapp\ObjktCom_v3::Bigmap_AuctionsDutch;   break;
            default: return null;
        }

        $value = GatewayTezos\BigmapGate::GetValueByBigmapAndKey($bigmapId, $auctionId);

        if ($value === null)
            return null;

        if ($version == 3)
        {
            $auction = new TokenActions\Auction();
            $auction->tokenAddress  = $value['token']['address'];
            $auction->tokenId       = $value['token']['token_id'];
            $auction->creator       = $value['creator'];
            return $auction;
        }
        else if ($version == 2)
        {
            $auction = new TokenActions\Auction();
            $auction->tokenAddress  = $value['token']['address'];
            $auction->tokenId       = $value['token']['token_id'];
            $auction->creator       = $value['creator'];
            return $auction;
        }
        else if ($version == 1)
        {
            $auction = new TokenActions\Auction();
            $auction->tokenAddress  = $value['fa2'];
            $auction->tokenId       = $value['objkt_id'];
            $auction->creator       = $value['creator'];
            return $auction;
        }

        return null; // unknown version
    }
}
