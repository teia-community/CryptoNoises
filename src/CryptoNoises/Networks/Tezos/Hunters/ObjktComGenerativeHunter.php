<?php

namespace CryptoNoises\Networks\Tezos\Hunters;

use CryptoCore\Setapp;
use CryptoNoises\Core\Log;
use CryptoNoises\Entities\EventFlow;
use CryptoNoises\Enum;
use CryptoNoises\Networks\Tezos\TezosNetwork;
use Exception;

/**
 * Mints through objkt.com's generative mint module (see Setapp\ObjktComGenerative).
 *
 * One `buy` is a primary sale: the payer's tez are split by a pricing handler (artists, split contracts, objkt's
 * treasury) and fresh tokens are minted - one token id per edition - to the payer or to `proxy_for`. Reported the
 * way fx(hash) mints are: the payer "minted for X", each payee "was paid Y for a mint". When the tokens go to
 * someone other than the payer (about 10% of mints), that wallet is told it received them.
 *
 * Without this, the operation reached the generic hunters as a bare payment to a contract and bare tez arriving
 * from one, with the mint itself attributed to nobody.
 */
class ObjktComGenerativeHunter extends AbstractHunter
{
    private const kModules = [
        Setapp\ObjktComGenerative::ModuleV1KTID,
        Setapp\ObjktComGenerative::ModuleV13KTID,
    ];

    private const kPricingHandlers = [
        Setapp\ObjktComGenerative::PricingHandlerV1KTID,
        Setapp\ObjktComGenerative::PricingHandlerV11KTID,
    ];

    public static function GetAllMarketContracts(): array
    {
        return array_merge(self::kModules, self::kPricingHandlers);
    }

    public static function GetMarketplaceContractByTokenContract(string $tokensContract): ?string
    {
        return null;    // every project mints into its own FA2 contract
    }

    //------------------------------------------------------------------------------------------------------------------

    public function parseTransaction(array $recInit, array $subRecords): bool
    {
        if (!in_array($recInit['target'], self::kModules) || $recInit['entrypoint'] != 'buy')
            return false;

        try
        {
            // Every edition is its own token id, minted by its own `mint` call into the project's FA2
            $mints = [];

            foreach ($subRecords as $record)
            {
                if ($record['entrypoint'] != 'mint')
                    continue;

                foreach ($record['parameter']['value']['mint_items'] ?? [] as $item)
                {
                    $mints[] = [
                        'token_address' => $record['target'],
                        'token_id'      => (string) $record['parameter']['value']['token_id'],
                        'to'            => $item['to_'],
                        'amount'        => (int) $item['amount'],
                    ];
                }
            }

            if (empty($mints))
                throw new Exception('No mint in a generative buy');

            // Payments go out from the pricing handler as plain tez transfers
            $fee     = 0;
            $incomes = [];    // payee => amount

            foreach ($subRecords as $record)
            {
                if (!in_array($record['sender'], self::kPricingHandlers) || !empty($record['entrypoint']) || $record['amount'] <= 0)
                    continue;

                if ($record['target'] == Setapp\ObjktComGenerative::TreasuryWallet)
                    $fee += $record['amount'];
                else
                    $incomes[$record['target']] = ($incomes[$record['target']] ?? 0) + $record['amount'];
            }

            $payer    = $recInit['sender'];
            $first    = $mints[0];
            $quantity = array_sum(array_column($mints, 'amount'));

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
            // The payer minted

            $event = new EventFlow();
            $event->initInTezosNetworkByRecord($recInit, 1);

            $event->event_type              = Enum\EventFlowType::TokenMintExpense;
            $event->sender_address          = $payer;
            $event->target_address          = $recInit['target'];
            $event->price                   = $recInit['amount'];
            $event->fee                     = $fee ?: null;
            $event->currency                = TezosNetwork::kCURRENCY_ID;
            $event->quantity                = $quantity;
            $event->marketplace_address     = $recInit['target'];
            $event->token_address           = $first['token_address'];
            $event->token_id                = $first['token_id'];

            $this->parser->pushEvent($event);

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
            // Minted for someone else (proxy_for): they received the tokens

            $received = [];

            foreach ($mints as $mint)
                if ($mint['to'] != $payer)
                    $received[$mint['to']][] = $mint;

            $subId = 2;

            foreach ($received as $receiver => $receiverMints)
            {
                $event = new EventFlow();
                $event->initInTezosNetworkByRecord($recInit, $subId++);

                $event->event_type          = Enum\EventFlowType::TokenTransfer_Receive;
                $event->sender_address      = $payer;
                $event->target_address      = $receiver;
                $event->quantity            = array_sum(array_column($receiverMints, 'amount'));
                $event->marketplace_address = $recInit['target'];
                $event->token_address       = $receiverMints[0]['token_address'];
                $event->token_id            = $receiverMints[0]['token_id'];

                $this->parser->pushEvent($event);
            }

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
            // Each payee was paid for the mint (a free mint pays nobody)

            ksort($incomes);
            $subId = 100;

            foreach ($incomes as $payee => $amount)
            {
                $event = new EventFlow();
                $event->initInTezosNetworkByRecord($recInit, $subId++);

                $event->event_type          = Enum\EventFlowType::TokenMintIncome;
                $event->sender_address      = $recInit['target'];
                $event->target_address      = $payee;
                $event->price               = $amount;
                $event->fee                 = $fee ?: null;
                $event->currency            = TezosNetwork::kCURRENCY_ID;
                $event->quantity            = $quantity;
                $event->marketplace_address = $recInit['target'];
                $event->token_address       = $first['token_address'];
                $event->token_id            = $first['token_id'];

                $this->parser->pushEvent($event);
            }

            return true;
        }
        catch (Exception $e)
        {
            Log::Warning(get_class($this), __FUNCTION__, $e->getMessage()." #{$recInit['id']}");
            return false;
        }
    }
}
