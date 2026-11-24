<?php

namespace CryptoNoises\Networks\Tezos\Hunters;

use CryptoNoises\Core\Log;
use CryptoNoises\Entities\EventFlow;
use CryptoNoises\Entities\TokenActions;
use CryptoNoises\Enum;
use CryptoNoises\Networks\Tezos\TezosNetwork;
use Exception;

class AkaSwap_Marketplace extends AkaSwap
{
    public function parseTransaction(array $recInit, array $subRecords): bool
    {
        if ($recInit['target'] != self::kMarketplaceKTID &&
            $recInit['target'] != self::kMarketplaceKTID_v2 &&
            $recInit['target'] != self::kMarketplaceKTID_v1)
            return false;

        if ($recInit['entrypoint'] == 'swap')
            return $this->swap($recInit, $subRecords);

        if ($recInit['entrypoint'] == 'collect')
            return $this->analyzePurchaseEvent($recInit, $subRecords);

        if ($recInit['entrypoint'] == 'cancel_swap')
            return $this->fixEventAsSkipped($recInit, $subRecords);

        return false;
    }

    private function swap(array $recInit, array $subRecords): bool
    {
        try
        {
            $valueSwap = $recInit['parameter']['value'];

            // V1 has no token_fa2 and names its fields after akaOBJ: it was written before akaswap traded more
            // than its own FA2, so the contract that was called tells us which layout to read.
            $isV1 = $recInit['target'] == self::kMarketplaceKTID_v1;

            $tokenContract = $isV1 ? self::kTokensKTID        : ($valueSwap['token_fa2'] ?? null);
            $priceXtz      = $isV1 ? ($valueSwap['xtz_per_akaOBJ'] ?? null) : ($valueSwap['xtz_per_token'] ?? null);

            if (!self::IsSupportedTokensContract($tokenContract))
                throw new Exception("Unknown token-address #$tokenContract");

            if ($priceXtz == 0 || $priceXtz === null)
                throw new Exception("XTZ price is ZERO");

            //----------------------------------------------------------------------------------------------------------

            $transfer = $this->unpackTransfersEvent($subRecords);

            $listing = new TokenActions\Listing();
            $listing->tokenAddress      = $transfer->tokenAddress;
            $listing->tokenId           = $transfer->tokenId;

            $listing->price             = $priceXtz;
            $listing->currency          = TezosNetwork::kCURRENCY_ID;
            $listing->quantity          = $transfer->quantity;

            $listing->creator           = null;
            $listing->royalty_d4        = null;

            //----------------------------------------------------------------------------------------------------------

            $event = new EventFlow();
            $event->initInTezosNetworkByRecord($recInit);

            $event->event_type              = Enum\EventFlowType::TokenList;
            $event->sender_address          = $transfer->sender;
            $event->target_address          = $transfer->target;
            $event->royalty_address         = $listing->creator;

            $event->price                   = $listing->price;
            $event->currency                = $listing->currency;
            $event->quantity                = $listing->quantity;

            $event->royalty_d4              = $listing->royalty_d4;

            $event->marketplace_address     = $recInit['target'];
            $event->token_address           = $listing->tokenAddress;
            $event->token_id                = $listing->tokenId;

            $this->parser->pushEvent($event);

            return true;
        }
        catch (Exception $e)
        {
            Log::Warning(get_class($this), __FUNCTION__, $e->getMessage()." #{$recInit['id']}", $recInit);
            return false;
        }
    }
}
