<?php

namespace CryptoNoises\Networks\Tezos\Markets;

use CryptoNoises\Entities\TokenActions;
use CryptoNoises\GatewayTezos;
use CryptoCore\Http\AbstractVendor;
use CryptoCore\Setapp;
use CryptoNoises\Networks\Tezos\Hunters\HicEtNuncHunter;

class HicEtNunc extends AbstractVendor
{
    /**
     * @param string|null $marketplaceContract which marketplace the swap belongs to (null = the v2 marketplace)
     */
    public static function GetSwapDetails($swapId, ?string $marketplaceContract = null): ?TokenActions\Swap
    {
        if ($swapId === null)
            return null;

        $bigmap = $marketplaceContract === Setapp\HicEtNunc::MarketplaceKTID_v1
            ? Setapp\HicEtNunc::Bigmap_Swaps_v1
            : Setapp\HicEtNunc::Bigmap_Swaps;

        $value = GatewayTezos\BigmapGate::GetValueByBigmapAndKey($bigmap, $swapId);

        if ($value === null)
            return null;

        $swap = new TokenActions\Swap($swapId);
        $swap->seller       = $value['issuer'];
        $swap->tokenAddress = HicEtNuncHunter::kTokensKTID;
        $swap->tokenId      = $value['objkt_id'];
        return $swap;
    }
}
