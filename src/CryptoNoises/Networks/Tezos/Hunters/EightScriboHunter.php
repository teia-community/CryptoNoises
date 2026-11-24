<?php

namespace CryptoNoises\Networks\Tezos\Hunters;

use CryptoCore\Setapp;
use CryptoNoises\Entities\TokenActions;
use CryptoNoises\Networks\Tezos\Markets\EightScribo;
use CryptoNoises\Networks\Tezos\TezosNetwork;

/**
 * 8scribo (8scribo.xyz): a hic et nunc style marketplace, so the same three trading entrypoints.
 *
 * The one difference that matters here is that a swap carries its own `fa2`: 8scribo trades more than one token
 * contract. The listing takes its token from the transfer that accompanies the swap either way, which is what
 * the hic et nunc handler does too, so the parameter only has to supply the price and the royalties.
 */
class EightScriboHunter extends AbstractHunter
{
    public const kMarketplaceKTID                   = Setapp\EightScribo::MarketplaceKTID;

    public const kMarketplaceFeeWallet              = null;

    //------------------------------------------------------------------------------------------------------------------

    public static function GetAllMarketContracts(): array
    {
        return [self::kMarketplaceKTID];
    }

    public static function GetMarketplaceContractByTokenContract(string $tokensContract): ?string
    {
        return null;
    }

    //------------------------------------------------------------------------------------------------------------------

    public function parseTransaction(array $recInit, array $subRecords): bool
    {
        if ($recInit['target'] != self::kMarketplaceKTID)
            return false;

        if ($recInit['entrypoint'] == 'collect')
        {
            if ($this->analyzeFreePurchaseEvent($recInit, $subRecords))
                return true;

            return $this->analyzeStdTokenPurchaseEvent($recInit, $subRecords, self::kMarketplaceFeeWallet);
        }

        if ($recInit['entrypoint'] == 'swap')
            return $this->analyzeListEvent($recInit, $subRecords);

        if ($recInit['entrypoint'] == 'cancel_swap')
            return $this->fixEventAsSkipped($recInit, $subRecords);

        return false;
    }

    private function analyzeFreePurchaseEvent(array $recInit, array $subRecords): bool
    {
        return $this->analyzeStdTokenFreePurchaseBlock($recInit, $subRecords, function($initParams): ?TokenActions\Swap
        {
            $value  = $initParams['value'] ?? null;
            $swapId = is_array($value) ? ($value['swap_id'] ?? null) : $value;

            if ($swapId === null)
                return null;

            return EightScribo::GetSwapDetails($swapId);
        });
    }

    private function analyzeListEvent(array $recInit, array $subRecords): bool
    {
        return $this->analyzeStdTokenListEvent($recInit, $subRecords, function(array $recInit, TokenActions\Transfer $transfer)
        {
            $recValue = $recInit['parameter']['value'] ?? [];

            $listing = new TokenActions\Listing();
            $listing->tokenAddress      = $transfer->tokenAddress;
            $listing->tokenId           = $transfer->tokenId;

            $listing->price             = $recValue['xtz_per_objkt'] ?? null;
            $listing->currency          = TezosNetwork::kCURRENCY_ID;
            $listing->quantity          = $recValue['objkt_amount'] ?? $transfer->quantity;

            $listing->creator           = $recValue['creator'] ?? null;
            $listing->royalty_d4        = isset($recValue['royalties'])
                ? $recValue['royalties'] * 10   // '250' means 25%, stored here as 2500
                : null;

            return $listing;
        });
    }
}
