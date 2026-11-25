<?php

namespace CryptoNoises\Networks\Tezos\Hunters;

use CryptoCore\Setapp;
use CryptoNoises\Core\Log;
use CryptoNoises\Entities\EventFlow;
use CryptoNoises\Entities\TokenActions;
use CryptoNoises\Enum;
use CryptoNoises\Networks\Tezos\TezosNetwork;
use Exception;

/**
 * EmProps' legacy marketplace (see Setapp\Emprops): list_token, unlist_token, create_sale.
 *
 * A sale cannot go through analyzeStdTokenPurchaseEvent(): the token changes hands inside the token contract's ledger
 * with no `transfer` call to read. Everything a purchase needs is in the calls themselves - the buyer and token id in
 * `create_sale`, the seller as `owner_token` in the Minter's `share_royalties`, and the split in the Minter's tez
 * payouts: the treasury's share is the fee, the seller's the revenue, anyone else's a royalty.
 *
 * Without this a sale showed up only as tez: the buyer paying a contract and the seller paid by a different one, with
 * no token in sight.
 */
class EmpropsHunter extends AbstractHunter
{
    public static function GetAllMarketContracts(): array
    {
        return [Setapp\Emprops::MarketplaceKTID];
    }

    public static function GetMarketplaceContractByTokenContract(string $tokensContract): ?string
    {
        return $tokensContract == Setapp\Emprops::TokenKTID ? Setapp\Emprops::MarketplaceKTID : null;
    }

    //------------------------------------------------------------------------------------------------------------------

    public function parseTransaction(array $recInit, array $subRecords): bool
    {
        if ($recInit['target'] != Setapp\Emprops::MarketplaceKTID)
            return false;

        switch ($recInit['entrypoint'])
        {
            case 'list_token':      return $this->listToken($recInit);
            case 'create_sale':     return $this->createSale($recInit, $subRecords);
            case 'unlist_token':    return $this->fixEventAsSkipped($recInit, $subRecords);
        }

        return false;
    }

    private function listToken(array $recInit): bool
    {
        try
        {
            $value = $recInit['parameter']['value'];

            $listing = new TokenActions\Listing();
            $listing->tokenAddress  = Setapp\Emprops::TokenKTID;
            $listing->tokenId       = (string) $value['token_id'];
            $listing->price         = $value['amount'];
            $listing->currency      = TezosNetwork::kCURRENCY_ID;
            $listing->quantity      = 1;

            $event = new EventFlow();
            $event->initInTezosNetworkByRecord($recInit);

            $event->event_type              = Enum\EventFlowType::TokenList;
            $event->sender_address          = $recInit['sender'];
            $event->target_address          = $recInit['target'];
            $event->price                   = $listing->price;
            $event->currency                = $listing->currency;
            $event->quantity                = $listing->quantity;
            $event->marketplace_address     = Setapp\Emprops::MarketplaceKTID;
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

    private function createSale(array $recInit, array $subRecords): bool
    {
        try
        {
            $share = self::ExtractRecordByEntrypoint($subRecords, 'share_royalties');

            $seller = $share['parameter']['value']['owner_token'] ?? null;

            if ($seller === null)
                throw new Exception('Cannot find the seller (share_royalties.owner_token)');

            $revenue   = 0;
            $fee       = 0;
            $royalties = [];   // receiver => amount

            foreach ($subRecords as $record)
            {
                if ($record['sender'] != Setapp\Emprops::MinterKTID || !empty($record['entrypoint']) || $record['amount'] <= 0)
                    continue;

                if ($record['target'] == Setapp\Emprops::TreasuryWallet)
                    $fee += $record['amount'];
                else if ($record['target'] == $seller)
                    $revenue += $record['amount'];
                else
                    $royalties[$record['target']] = ($royalties[$record['target']] ?? 0) + $record['amount'];
            }

            if ($revenue + $fee + array_sum($royalties) == 0)
                throw new Exception('No payouts found');

            $event = new EventFlow();
            $event->initInTezosNetworkByRecord($recInit);

            $event->event_type              = Enum\EventFlowType::TokenPurchase;
            $event->sender_address          = $seller;                  // the seller, as in every TokenPurchase
            $event->target_address          = $recInit['sender'];       // the buyer
            $event->royalty_address_list    = $royalties ?: null;
            $event->fee_address             = $fee ? Setapp\Emprops::TreasuryWallet : null;

            $event->revenue                 = $revenue;
            $event->royalty                 = array_sum($royalties) ?: null;
            $event->fee                     = $fee ?: null;
            $event->price                   = $recInit['amount'];
            $event->currency                = TezosNetwork::kCURRENCY_ID;
            $event->quantity                = 1;

            $event->marketplace_address     = Setapp\Emprops::MarketplaceKTID;
            $event->token_address           = Setapp\Emprops::TokenKTID;
            $event->token_id                = (string) $recInit['parameter']['value']['token_id'];

            $this->parser->pushEvent($event);
            return true;
        }
        catch (Exception $e)
        {
            Log::Warning(get_class($this), __FUNCTION__, $e->getMessage()." #{$recInit['id']}");
            return false;
        }
    }
}
