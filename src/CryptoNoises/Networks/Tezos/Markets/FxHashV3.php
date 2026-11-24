<?php

namespace CryptoNoises\Networks\Tezos\Markets;

use CryptoCore\Http\AbstractVendor;
use CryptoCore\Setapp;
use CryptoNoises\Core\Log;
use CryptoNoises\Entities\TokenActions;
use CryptoNoises\GatewayTezos;

class FxHashV3 extends AbstractVendor
{
    /** The FA2 behind one of the marketplace's asset_contracts entries (null when it is not indexed here). */
    public static function GetTokenContractById($contractId): ?string
    {
        if ($contractId === null)
            return null;

        $value = GatewayTezos\BigmapGate::GetValueByBigmapAndKey(
            Setapp\FxHash_v3::Bigmap_AssetContracts, (string) $contractId);

        return is_array($value) ? ($value['fa2'] ?? null) : null;
    }

    /** A stored listing, for the one case that needs it: accepting a listing priced at zero. */
    public static function GetSwapDetails($listingId): ?TokenActions\Swap
    {
        if ($listingId === null)
            return null;

        $value = GatewayTezos\BigmapGate::GetValueByBigmapAndKey(Setapp\FxHash_v3::Bigmap_Swaps, $listingId);

        if ($value === null)
            return null;

        $tokenAddress = self::GetTokenContractById($value['asset']['contract_id'] ?? null);

        if ($tokenAddress === null)
        {
            Log::Error("FxHashV3", "GetSwapDetails", "Cannot resolve contract_id listingId=$listingId", $value);
            return null;
        }

        $swap = new TokenActions\Swap($listingId);
        $swap->tokenAddress = $tokenAddress;
        $swap->tokenId      = $value['asset']['asset_id'] ?? null;
        $swap->seller       = $value['seller'] ?? null;
        return $swap;
    }
}
