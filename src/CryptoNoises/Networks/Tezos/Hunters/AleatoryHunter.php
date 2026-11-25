<?php

namespace CryptoNoises\Networks\Tezos\Hunters;

use CryptoCore\Setapp;
use CryptoNoises\Core\Log;
use CryptoNoises\Entities\EventFlow;
use CryptoNoises\Entities\TokenActions;
use CryptoNoises\Enum;
use CryptoNoises\Networks\Tezos\TezosHelper;
use CryptoNoises\Networks\Tezos\TezosNetwork;
use CryptoNoisesTezos\Models\Contracts;
use CryptoNoisesTezos\Vendors\Tzkt;
use Exception;

/**
 * aleatory.art (see Setapp\Aleatory): mints on its generator contracts, and its marketplace.
 *
 * Mints. A generator is not known in advance - a factory deploys one per artwork - so it is recognised by the
 * interface its metadata declares, ALEATORY-001: from the contracts table when the metadata job has seen it, else
 * from TzKT, cached per process. Only paid `mint` calls from the first generator's level on are looked at (every mint
 * so far was paid), which keeps the lookup to roughly one per new generator. The generator splits the payment - the
 * artist, the platform, the render provider's "render gas" - and mints inside its own ledger, with no `transfer` and an
 * empty parameter: the minted token id is only in the `mint` event, read from TzKT.
 *
 * Marketplace. list_token, make_offer and buy / accept_offer map onto the usual listing, offer and purchase events;
 * delist and cancel_offer are skipped.
 */
class AleatoryHunter extends AbstractHunter
{
    /** @var array<string, bool> generator address => is an aleatory generator, per process */
    private static array $generators = [];

    public static function GetAllMarketContracts(): array
    {
        return [Setapp\Aleatory::MarketplaceKTID];
    }

    public static function GetMarketplaceContractByTokenContract(string $tokensContract): ?string
    {
        return (self::$generators[$tokensContract] ?? false) ? Setapp\Aleatory::MarketplaceKTID : null;
    }

    //------------------------------------------------------------------------------------------------------------------

    public function parseTransaction(array $recInit, array $subRecords): bool
    {
        if ($recInit['target'] == Setapp\Aleatory::MarketplaceKTID)
        {
            switch ($recInit['entrypoint'])
            {
                case 'list_token':      return $this->listToken($recInit);
                case 'make_offer':      return $this->makeOffer($recInit);
                case 'buy':             return $this->purchase($recInit, $subRecords, null);
                case 'accept_offer':    return $this->purchase($recInit, $subRecords, Enum\EventFlowDetails::OfferAccepted);
                case 'delist':
                case 'cancel_offer':    return $this->fixEventAsSkipped($recInit, $subRecords);
            }

            return false;
        }

        if ($recInit['entrypoint'] == 'mint' && $recInit['amount'] > 0 && $recInit['level'] >= Setapp\Aleatory::FirstLevel
            && self::IsGenerator($recInit['target']))
        {
            return $this->mint($recInit, $subRecords);
        }

        return false;
    }

    /**
     * Whether a contract declares ALEATORY-001. A TzKT failure is not cached, so the next mint asks again.
     */
    public static function IsGenerator(string $contract): bool
    {
        if (isset(self::$generators[$contract]))
            return self::$generators[$contract];

        $row = Contracts::model()->getItem($contract);

        if (!empty($row['interfaces']))
        {
            $interfaces = json_decode($row['interfaces'], true) ?: [];
            return self::$generators[$contract] = in_array(Setapp\Aleatory::Interface, $interfaces);
        }

        $interfaces = Tzkt::GetContractInterfaces($contract);

        if ($interfaces === null)
            return false;

        return self::$generators[$contract] = in_array(Setapp\Aleatory::Interface, $interfaces);
    }

    //------------------------------------------------------------------------------------------------------------------

    private function mint(array $recInit, array $subRecords): bool
    {
        try
        {
            $generator = $recInit['target'];

            $event   = Tzkt::GetEventPayload($generator, 'mint', (int) $recInit['id']);
            $tokenId = isset($event['token_id']) ? (string) $event['token_id'] : null;
            $buyer   = $event['buyer'] ?? $recInit['sender'];

            $fee     = 0;
            $incomes = [];      // payee => amount: the artist, or the artist's split

            foreach ($subRecords as $record)
            {
                if ($record['sender'] != $generator || !empty($record['entrypoint']) || $record['amount'] <= 0)
                    continue;

                if ($record['target'] == Setapp\Aleatory::PlatformWallet || $record['target'] == Setapp\Aleatory::RenderProviderKTID)
                    $fee += $record['amount'];
                else
                    $incomes[$record['target']] = ($incomes[$record['target']] ?? 0) + $record['amount'];
            }

            // The payer minted
            $ev = new EventFlow();
            $ev->initInTezosNetworkByRecord($recInit, 1);

            $ev->event_type             = Enum\EventFlowType::TokenMintExpense;
            $ev->sender_address         = $recInit['sender'];
            $ev->target_address         = $generator;
            $ev->price                  = $recInit['amount'];
            $ev->fee                    = $fee ?: null;
            $ev->currency               = TezosNetwork::kCURRENCY_ID;
            $ev->marketplace_address    = Setapp\Aleatory::MarketplaceKTID;
            $ev->token_address          = $generator;
            $ev->token_id               = $tokenId;

            $this->parser->pushEvent($ev);

            // Minted for someone else: they received it
            if ($buyer != $recInit['sender'])
            {
                $ev = new EventFlow();
                $ev->initInTezosNetworkByRecord($recInit, 2);

                $ev->event_type             = Enum\EventFlowType::TokenTransfer_Receive;
                $ev->sender_address         = $recInit['sender'];
                $ev->target_address         = $buyer;
                $ev->marketplace_address    = Setapp\Aleatory::MarketplaceKTID;
                $ev->token_address          = $generator;
                $ev->token_id               = $tokenId;

                $this->parser->pushEvent($ev);
            }

            // The artist (and any split) was paid for it
            ksort($incomes);
            $subId = 100;

            foreach ($incomes as $payee => $amount)
            {
                $ev = new EventFlow();
                $ev->initInTezosNetworkByRecord($recInit, $subId++);

                $ev->event_type             = Enum\EventFlowType::TokenMintIncome;
                $ev->sender_address         = $generator;
                $ev->target_address         = $payee;
                $ev->price                  = $amount;
                $ev->fee                    = $fee ?: null;
                $ev->currency               = TezosNetwork::kCURRENCY_ID;
                $ev->marketplace_address    = Setapp\Aleatory::MarketplaceKTID;
                $ev->token_address          = $generator;
                $ev->token_id               = $tokenId;

                $this->parser->pushEvent($ev);
            }

            return true;
        }
        catch (Exception $e)
        {
            Log::Warning(get_class($this), __FUNCTION__, $e->getMessage()." #{$recInit['id']}");
            return false;
        }
    }

    //------------------------------------------------------------------------------------------------------------------

    private function listToken(array $recInit): bool
    {
        try
        {
            $value = $recInit['parameter']['value'];

            $listing = new TokenActions\Listing();
            $listing->tokenAddress  = (string) $value['collection'];
            $listing->tokenId       = (string) $value['token_id'];
            $listing->price         = $value['price'];
            $listing->currency      = TezosNetwork::kCURRENCY_ID;
            $listing->quantity      = 1;

            $ev = new EventFlow();
            $ev->initInTezosNetworkByRecord($recInit);

            $ev->event_type             = Enum\EventFlowType::TokenList;
            $ev->sender_address         = $recInit['sender'];
            $ev->target_address         = $recInit['target'];
            $ev->price                  = $listing->price;
            $ev->currency               = $listing->currency;
            $ev->quantity               = $listing->quantity;
            $ev->marketplace_address    = Setapp\Aleatory::MarketplaceKTID;
            $ev->token_address          = $listing->tokenAddress;
            $ev->token_id               = $listing->tokenId;

            $this->parser->pushEvent($ev);
            return true;
        }
        catch (Exception $e)
        {
            Log::Warning(get_class($this), __FUNCTION__, $e->getMessage()." #{$recInit['id']}");
            return false;
        }
    }

    /**
     * An offer escrows the tez it offers (the call's amount); its holders are told, as for objkt offers.
     */
    private function makeOffer(array $recInit): bool
    {
        try
        {
            $value = $recInit['parameter']['value'];

            $ev = new EventFlow();
            $ev->initInTezosNetworkByRecord($recInit);

            $ev->event_type             = Enum\EventFlowType::TokenOffer;
            $ev->sender_address         = $recInit['sender'];
            $ev->offer_owners_addresses = TezosHelper::GetTokenHolders((string) $value['collection'], (string) $value['token_id']);
            $ev->price                  = $recInit['amount'];
            $ev->currency               = TezosNetwork::kCURRENCY_ID;
            $ev->quantity               = 1;
            $ev->marketplace_address    = Setapp\Aleatory::MarketplaceKTID;
            $ev->token_address          = (string) $value['collection'];
            $ev->token_id               = (string) $value['token_id'];

            $this->parser->pushEvent($ev);
            return true;
        }
        catch (Exception $e)
        {
            Log::Warning(get_class($this), __FUNCTION__, $e->getMessage()." #{$recInit['id']}");
            return false;
        }
    }

    /**
     * A sale out of a listing (`buy`) or an accepted offer. The platform's fee is paid in the operation on some sales
     * and accrued in the contract (`withdraw_fees`) on others, so it is passed as the store wallet only when paid here.
     */
    private function purchase(array $recInit, array $subRecords, ?string $eventDetails): bool
    {
        $platformPaid = false;

        foreach ($subRecords as $record)
            if ($record['target'] == Setapp\Aleatory::PlatformWallet && $record['amount'] > 0)
                $platformPaid = true;

        return $this->analyzeStdTokenPurchaseEvent($recInit, $subRecords, $platformPaid ? Setapp\Aleatory::PlatformWallet : null, $eventDetails);
    }
}
