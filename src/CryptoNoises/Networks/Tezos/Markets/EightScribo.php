<?php

namespace CryptoNoises\Networks\Tezos\Markets;

use CryptoCore\Http\AbstractVendor;
use CryptoCore\Setapp;
use CryptoNoises\Entities\TokenActions;
use CryptoNoises\GatewayTezos;

class EightScribo extends AbstractVendor
{
    /** A stored 8scribo swap, for the one case that needs it: a collect of a listing priced at zero. */
    public static function GetSwapDetails($swapId): ?TokenActions\Swap
    {
        if ($swapId === null)
            return null;

        $value = GatewayTezos\BigmapGate::GetValueByBigmapAndKey(Setapp\EightScribo::Bigmap_Swaps, $swapId);

        if ($value === null)
            return null;

        $swap = new TokenActions\Swap($swapId);
        $swap->seller       = $value['issuer'] ?? null;
        $swap->tokenAddress = $value['fa2'] ?? null;        // 8scribo trades more than one token contract
        $swap->tokenId      = $value['objkt_id'] ?? null;
        return $swap;
    }
}
