<?php

namespace CryptoNoises\Networks\Tezos\Hunters;

use CryptoCore\Setapp;
use CryptoNoises\Core\Log;
use CryptoNoises\Entities\EventFlow;
use CryptoNoises\Enum;
use CryptoNoises\Networks\Tezos\Markets;
use CryptoNoises\Networks\Tezos\TezosNetwork;
use Exception;

/**
 * drop.art. See Setapp\DropArt for why a buy here is not an ordinary purchase.
 *
 * The event names the collection and the creator, and leaves the token id empty, because at buy time the piece
 * genuinely has not been decided. The buyer learns which piece they got from the mint a few levels later, which
 * already notifies them with the artwork.
 */
class DropArtHunter extends AbstractHunter
{
    public const kMarketplaceKTID                   = Setapp\DropArt::MarketplaceKTID;

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

        if ($recInit['entrypoint'] == 'buy')
            return $this->analyzeDropPurchaseEvent($recInit, $subRecords);

        // reveal mints the pieces that were bought earlier, and the mint is reported by the token analyzer the
        // same way any other mint is. The rest is project administration, and nobody's news.
        if (in_array($recInit['entrypoint'], ['reveal', 'set_project', 'delete_project', 'eject_fa2',
                                              'set_admin', 'set_reveal_fee', 'set_revealer',
                                              'update_fa2_metadata'], true))
            return $this->fixEventAsSkipped($recInit, $subRecords);

        return false;
    }

    //------------------------------------------------------------------------------------------------------------------

    private function analyzeDropPurchaseEvent(array $recInit, array $subRecords): bool
    {
        try
        {
            $recValue = $recInit['parameter']['value'] ?? null;

            if (!is_array($recValue))
                throw new Exception('buy without parameters');

            $projectId = $recValue['project_id'] ?? null;
            $project   = Markets\DropArt::GetProjectDetails($projectId);

            // Without the project there is no collection and no creator to name, and a drop purchase that names
            // neither is worth less than the plain transfers the fallback already reports.
            if ($project === null || $project['fa2'] === null)
                throw new Exception("Cannot resolve drop.art project [$projectId]");

            $buyer   = $recValue['recipient'] ?? $recInit['sender'];     // a buy can be made for someone else
            $creator = $project['creator'];

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
            // What the buyer paid, and where it went. The creator's share is the payout to the project's own
            // creator; everything else the contracts paid out is platform and service fees.

            $price   = (int) ($recInit['amount'] ?? 0);
            $revenue = 0;
            $fee     = 0;

            foreach ($subRecords as $record)
            {
                $amount = (int) ($record['amount'] ?? 0);

                if ($amount <= 0)
                    continue;

                if ($creator !== null && ($record['target'] ?? null) === $creator)
                    $revenue += $amount;
                else if (!$this->isDropArtInternalTarget($record['target'] ?? null))
                    $fee += $amount;
            }

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $event = new EventFlow();
            $event->initInTezosNetworkByRecord($recInit);

            $event->event_type              = Enum\EventFlowType::DropPurchase;

            $event->sender_address          = $creator;      // who sold it
            $event->target_address          = $buyer;        // who bought it
            $event->royalty_address         = null;

            $event->price                   = $price ?: null;
            $event->revenue                 = $revenue ?: null;
            $event->fee                     = $fee ?: null;
            $event->currency                = TezosNetwork::kCURRENCY_ID;
            $event->quantity                = (int) ($recValue['amount'] ?? 1);

            $event->marketplace_address     = $recInit['target'];
            $event->token_address           = $project['fa2'];
            $event->token_id                = null;          // decided when the piece is minted

            $this->parser->pushEvent($event);
            return true;
        }
        catch (Exception $e)
        {
            Log::Warning(get_class($this), __FUNCTION__, $e->getMessage()." #{$recInit['id']}", $recInit);
            return false;
        }
    }

    /** Money moving between drop.art's own contracts is not a payout to anybody. */
    private function isDropArtInternalTarget(?string $address): bool
    {
        return $address !== null && strpos($address, 'KT1') === 0;
    }
}
