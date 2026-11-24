<?php

namespace CryptoNoises\Networks\Tezos\Hunters;

use CryptoCore\Setapp;
use CryptoNoises\Core\Log;
use CryptoNoises\Entities\TokenActions;
use CryptoNoises\Networks\Tezos\Markets;
use CryptoNoises\Networks\Tezos\TezosNetwork;

/**
 * fx(hash)'s current marketplace. Same three trading entrypoints as v2, so the purchase and listing helpers do
 * the work; what differs is how a listing names its token - see Setapp\FxHash_v3.
 */
class FxHashV3Hunter extends AbstractHunter
{
    public const kMarketplaceKTID                   = Setapp\FxHash_v3::MarketplaceKTID;
    public const kMarketplaceFeeWallet              = Setapp\FxHash_v3::MarketplaceFeeWallet;

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

        $entrypoint = $recInit['entrypoint'];

        if ($entrypoint == 'listing_accept')
        {
            if ($this->analyzeStdTokenPurchaseEvent($recInit, $subRecords, self::kMarketplaceFeeWallet))
                return true;

            return $this->analyzeFreePurchaseEvent($recInit, $subRecords);
        }

        if ($entrypoint == 'listing')
            return $this->analyzeListEvent($recInit, $subRecords);

        if ($entrypoint == 'listing_cancel')
            return $this->fixEventAsSkipped($recInit, $subRecords);

        // Present on the contract, never called, and the offers bigmap is empty. Recognised so they cannot be
        // reported as an unknown contract; if fx(hash) ever starts using them this is where they belong.
        if (in_array($entrypoint, ['offer', 'offer_accept', 'offer_cancel'], true))
            return $this->fixEventAsSkipped($recInit, $subRecords);

        return false;
    }

    //------------------------------------------------------------------------------------------------------------------

    private function analyzeFreePurchaseEvent(array $recInit, array $subRecords): bool
    {
        return $this->analyzeStdTokenFreePurchaseBlock($recInit, $subRecords, function($initParams): ?TokenActions\Swap
        {
            $value     = $initParams['value'] ?? null;
            $listingId = is_array($value) ? ($value['listing_id'] ?? null) : $value;

            if ($listingId === null)
                return null;

            return Markets\FxHashV3::GetSwapDetails($listingId);
        });
    }

    private function analyzeListEvent(array $recInit, array $subRecords): bool
    {
        return $this->analyzeStdTokenListEvent($recInit, $subRecords, function(array $recInit, TokenActions\Transfer $transfer)
        {
            $recValue = $recInit['parameter']['value'] ?? [];

            $listing = new TokenActions\Listing();

            // The token comes from the transfer that accompanies the listing, which is already resolved, rather
            // than from asset.contract_id - one less bigmap read on the hot path, and it cannot disagree with
            // what actually moved.
            $listing->tokenAddress      = $transfer->tokenAddress;
            $listing->tokenId           = $transfer->tokenId;

            $listing->price             = $recValue['amount'] ?? null;
            $listing->currency          = $this->detectCurrency($recValue['currency'] ?? null, $recInit);
            $listing->quantity          = $recValue['asset']['amount'] ?? $transfer->quantity;

            $listing->creator           = null;
            $listing->royalty_d4        = null;

            return $listing;
        });
    }

    /**
     * Every listing seen on chain so far is priced in tez. Anything else is reported rather than guessed at,
     * because pricing a listing in the wrong unit is worse than not describing it.
     */
    private function detectCurrency($currencyRecord, array $recInit): string
    {
        if (!is_array($currencyRecord) || isset($currencyRecord['tez']))
            return TezosNetwork::kCURRENCY_ID;

        if (isset($currencyRecord['fa12']))
            return $currencyRecord['fa12'];

        if (isset($currencyRecord['fa2']['address']))
            return $currencyRecord['fa2']['address'].':'.($currencyRecord['fa2']['token_id'] ?? '0');

        Log::Warning(get_class($this), __FUNCTION__, "Unknown currency on a listing #{$recInit['id']}", $recInit);
        return TezosNetwork::kCURRENCY_ID;
    }
}
