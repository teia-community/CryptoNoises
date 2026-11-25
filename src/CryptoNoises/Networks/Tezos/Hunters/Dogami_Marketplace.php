<?php

namespace CryptoNoises\Networks\Tezos\Hunters;

use CryptoNoises\Core\Log;
use CryptoNoises\Enum;
use CryptoNoises\Entities\EventFlow;
use CryptoNoises\Entities\TokenActions;
use CryptoNoises\Networks\Tezos\TezosNetwork;
use Exception;

class Dogami_Marketplace extends Dogami
{
    public function parseTransaction(array $recInit, array $subRecords): bool
    {
        if ($recInit['target'] != self::kMarketplaceKTID && $recInit['target'] != self::kMarketplaceV2KTID)
            return false;

        if ($recInit['entrypoint'] == 'addToMarketplace')
            return $this->addToMarketplace($recInit, $subRecords);

        if ($recInit['entrypoint'] == 'removeFromMarketplace')
            return $this->removeFromMarketplace($recInit, $subRecords);

        if ($recInit['entrypoint'] == 'collect')
            return $this->collect($recInit, $subRecords);

        return false;
    }

    private function addToMarketplace(array $recInit, array $subRecords): bool
    {
        try
        {
            $value = $recInit['parameter']['value'];

            // Listings priced in DOGA, dogami's own token (7% on v2), are not reported: the notification path has
            // no DOGA price. Handing them on silently rather than warning about every one.
            if ($value['token_symbol'] != 'XTZ')
                return false;

            if (!isset($value['swap_type']['regular']))
                throw new Exception("Unknown swap_type (not 'regular')");

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $listing = new TokenActions\Listing();
            $listing->tokenAddress      = self::kTokensKTID;
            $listing->tokenId           = $value['token_id'];

            $listing->price             = $value['token_price'];
            $listing->currency          = TezosNetwork::kCURRENCY_ID;
            $listing->quantity          = 1;

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $event = new EventFlow();
            $event->initInTezosNetworkByRecord($recInit);

            $event->event_type              = Enum\EventFlowType::TokenList;
            $event->sender_address          = $recInit['sender'];
            $event->target_address          = $recInit['target'];

            $event->price                   = $listing->price;
            $event->currency                = $listing->currency;
            $event->quantity                = $listing->quantity;

            $event->marketplace_address     = $recInit['target'];
            $event->token_address           = $listing->tokenAddress;
            $event->token_id                = $listing->tokenId;

            $this->parser->pushEvent($event);

            return true;
        }
        catch (Exception $e)
        {
            Log::Warning(get_class($this), __FUNCTION__, $e->getMessage()." #{$recInit['id']}");
            return false;
        }
    }

    private function removeFromMarketplace(array $recInit, array $subRecords): bool
    {
        return $this->fixEventAsSkipped($recInit, $subRecords);
    }

    private function collect(array $recInit, array $subRecords): bool
    {
        // A DOGA-paid purchase moves DOGA by token transfers, not tez: the generic hunters report what moved
        if (($recInit['parameter']['value']['token_symbol'] ?? 'XTZ') != 'XTZ')
            return false;

        return $this->analyzeStdTokenPurchaseEvent($recInit, $subRecords, self::kMarketplaceFeeWallet);
    }
}
