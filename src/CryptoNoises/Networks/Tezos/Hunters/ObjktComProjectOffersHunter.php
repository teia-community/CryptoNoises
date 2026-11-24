<?php

namespace CryptoNoises\Networks\Tezos\Hunters;

use CryptoCore\Setapp;
use CryptoNoises\Core\Log;
use CryptoNoises\Entities\EventFlow;
use CryptoNoises\Entities\TokenActions;
use CryptoNoises\Enum;
use CryptoNoises\Networks\Tezos\TezosHelper;
use CryptoNoises\Networks\Tezos\TezosNetwork;
use Exception;

/**
 * objkt.com's collection offers, which live on their own contract (KT19NMJYBC…) since 2026-07.
 *
 * An offer is made on a whole collection, named by (fa_contract, collection_id), and anybody holding a token
 * from it can accept. The two halves are worth different amounts here:
 *
 *   fulfill_collection_offer  a sale, and everything needed is in the operation: the token transfer and the
 *                             payouts. It is read with the shared purchase handler, like any accepted offer.
 *   collection_offer          an offer, which is only news to the people holding tokens from that collection.
 *                             Resolving them needs the collection's tokens to be known locally by the same
 *                             (contract, collection_id) objkt.com uses, which holds for collections minted
 *                             through objkt.com and not for, say, an objkt.com offer on an fx(hash) collection.
 *                             Where they cannot be resolved the event carries no recipients, which is how the
 *                             existing collection-offer handling already behaves.
 */
class ObjktComProjectOffersHunter extends AbstractHunter
{
    public const kProjectOffersKTID                 = Setapp\ObjktCom_v4::ProjectOffersKTID;
    public const kMarketplaceFeeWallet              = Setapp\ObjktCom_v4::MarketplaceFeeWallet;

    //------------------------------------------------------------------------------------------------------------------

    public static function GetAllMarketContracts(): array
    {
        return [self::kProjectOffersKTID];
    }

    public static function GetMarketplaceContractByTokenContract(string $tokensContract): ?string
    {
        return null;
    }

    //------------------------------------------------------------------------------------------------------------------

    public function parseTransaction(array $recInit, array $subRecords): bool
    {
        if ($recInit['target'] != self::kProjectOffersKTID)
            return false;

        $entrypoint = $recInit['entrypoint'];

        if ($entrypoint == 'fulfill_collection_offer')
            return $this->analyzeStdTokenPurchaseEvent($recInit, $subRecords, self::kMarketplaceFeeWallet,
                Enum\EventFlowDetails::OfferAccepted);

        if ($entrypoint == 'collection_offer')
            return $this->analyzeCollectionOfferEvent($recInit, $subRecords);

        if ($entrypoint == 'retract_collection_offer')
            return $this->fixEventAsSkipped($recInit, $subRecords);

        return false;
    }

    //------------------------------------------------------------------------------------------------------------------

    private function analyzeCollectionOfferEvent(array $recInit, array $subRecords): bool
    {
        try
        {
            $recValue = $recInit['parameter']['value'] ?? null;

            if (!is_array($recValue) || !isset($recValue['collection']['fa_contract']))
                throw new Exception('collection is missing');

            $collectionAddress = $recValue['collection']['fa_contract'];
            $collectionId      = (string) ($recValue['collection']['collection_id'] ?? '');

            $offer = new TokenActions\CollectionOffer();
            $offer->collectionAddress   = $collectionAddress;
            $offer->collectionId        = $collectionId;
            $offer->tokenAmount         = 1;                // one token out of the collection, per offer
            $offer->creator             = null;
            $offer->buyer               = $recInit['sender'];
            $offer->price               = $recValue['amount'] ?? null;

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $event = new EventFlow();
            $event->initInTezosNetworkByRecord($recInit);

            $event->event_type              = Enum\EventFlowType::CollectionOffer;
            $event->sender_address          = $offer->buyer;
            $event->offer_owners_addresses  = TezosHelper::GetCollectionHolders($collectionAddress, $collectionId);
            $event->royalty_address         = null;

            $event->price                   = $offer->price;
            $event->currency                = $this->detectCurrency($recValue['currency'] ?? null, $recInit);
            $event->quantity                = $offer->tokenAmount;

            $event->marketplace_address     = $recInit['target'];
            $event->token_address           = $collectionAddress;
            $event->token_id                = $collectionId;

            $this->parser->pushEvent($event);
            return true;
        }
        catch (Exception $e)
        {
            Log::Warning(get_class($this), __FUNCTION__, $e->getMessage()." #{$recInit['id']}", $recInit);
            return false;
        }
    }

    /** Offers here are made in tez or in a token; naming the wrong unit would misstate the amount. */
    private function detectCurrency($currencyRecord, array $recInit): string
    {
        if (!is_array($currencyRecord) || isset($currencyRecord['tez']))
            return TezosNetwork::kCURRENCY_ID;

        if (isset($currencyRecord['fa12']))
            return $currencyRecord['fa12'];

        if (isset($currencyRecord['fa2']['address']))
            return $currencyRecord['fa2']['address'].':'.($currencyRecord['fa2']['token_id'] ?? '0');

        Log::Warning(get_class($this), __FUNCTION__, "Unknown currency on a collection offer #{$recInit['id']}", $recInit);
        return TezosNetwork::kCURRENCY_ID;
    }
}
