<?php

namespace CryptoNoises\Networks\Tezos;

use CryptoNoises\Core\CustomData;
use CryptoNoises\Core\Log;
use CryptoNoises\Entities\EventFlow;
use CryptoNoises\Enum;
use CryptoNoises\Models;
use CryptoNoisesTezos\Models\AccountOperations;
use CryptoNoisesTezos\Vendors\Tzkt;

/**
 * Turns account operations - delegations, staking, baker parameter changes, originations - into events.
 *
 * Every event has target_address = the wallet it is about, so routing is the plain "whoever watches this wallet"
 * case; sender_address is the counterparty (the baker, or whoever originated for the wallet). Names come from the
 * TzKT aliases stored with the operation, because bakers and finalizing services are never in the profile cache.
 */
class AccountOperationsParser
{
    /** @var EventFlow[] */
    private array $events = [];

    /** @var string[]|null addresses of watched wallets, loaded once per parser when a baker changes its terms */
    private ?array $watchedAddresses = null;

    /**
     * @param array[] $rows AccountOperations::getAppliedInRange() rows
     */
    public function analyze(array $rows): void
    {
        foreach ($rows as $row)
        {
            switch ($row['type'])
            {
                case AccountOperations::kTYPE_Delegation:            $this->analyzeDelegation($row); break;
                case AccountOperations::kTYPE_Staking:               $this->analyzeStaking($row); break;
                case AccountOperations::kTYPE_SetDelegateParameters: $this->analyzeBakerParameters($row); break;
                case AccountOperations::kTYPE_Origination:           $this->analyzeOrigination($row); break;
            }
        }
    }

    /**
     * The alias stored with the operation, or TzKT's from tezos.tzkt_aliases_url when there is none - a
     * self-hosted TzKT runs no metadata service, so its operations never carry one. Only called for the
     * addresses a notification names.
     */
    private static function Name(?string $stored, ?string $address): ?string
    {
        if ($stored !== null && $stored !== '')
            return $stored;

        return $address !== null ? Tzkt::GetAlias($address) : null;
    }

    public function save(): int
    {
        foreach ($this->events as $event)
            $event->save();

        EventFlow::LogExecAt_SaveData();

        return count($this->events);
    }

    //------------------------------------------------------------------------------------------------------------------

    private function newEvent(array $row, string $type, int $subId = 0): EventFlow
    {
        $event = new EventFlow();
        $event->initInTezosNetworkByRecord($row + ['entrypoint' => null], $subId);

        $event->event_type     = $type;
        $event->target_address = $row['account_address'];
        $event->currency       = TezosNetwork::kCURRENCY_ID;

        return $event;
    }

    private function analyzeDelegation(array $row): void
    {
        $baker     = $row['baker_address'];
        $prevBaker = $row['prev_baker_address'];

        if ($baker === null && $prevBaker === null)
            return;     // undelegating an account that had no delegate: nothing happened

        if ($baker !== null && $baker === $prevBaker)
            return;     // re-delegating to the same baker: nothing changed

        $event = $this->newEvent($row, Enum\EventFlowType::Delegation);

        $event->event_details  = $baker === null ? 'remove' : ($prevBaker === null ? 'set' : 'change');
        $event->sender_address = $baker ?? $prevBaker;
        $event->price          = (float) $row['amount'];
        $event->custom_data    = array_filter([
            CustomData::kBAKER_NAME      => $baker !== null ? self::Name($row['baker_alias'], $baker) : self::Name($row['prev_baker_alias'], $prevBaker),
            CustomData::kPREV_BAKER      => $baker !== null ? $prevBaker : null,
            CustomData::kPREV_BAKER_NAME => $baker !== null && $prevBaker !== null ? self::Name($row['prev_baker_alias'], $prevBaker) : null,
        ], fn ($v) => $v !== null);

        $this->events[] = $event;

        // The bakers' side: one gained a delegator, the other lost one. A baker registering (delegating to itself)
        // is not a delegator.
        $delegator = $row['account_address'];

        if ($baker !== null && $baker !== $delegator)
            $this->pushBakerSide($row, Enum\EventFlowType::BakerDelegator, 'joined', $baker, self::kSUBID_BakerJoined,
                $prevBaker !== null ? [CustomData::kPREV_BAKER => $prevBaker, CustomData::kPREV_BAKER_NAME => self::Name($row['prev_baker_alias'], $prevBaker)] : []);

        if ($prevBaker !== null && $prevBaker !== $delegator)
            $this->pushBakerSide($row, Enum\EventFlowType::BakerDelegator, 'left', $prevBaker, self::kSUBID_BakerLeft,
                $baker !== null ? [CustomData::kPREV_BAKER => $baker, CustomData::kPREV_BAKER_NAME => self::Name($row['baker_alias'], $baker)] : []);
    }

    private function analyzeStaking(array $row): void
    {
        // Bakers often call finalize with nothing to finalize: an amount of 0 is not news
        if ((int) $row['amount'] <= 0)
            return;

        $event = $this->newEvent($row, Enum\EventFlowType::Staking);

        $event->event_details  = $row['action'];
        $event->sender_address = $row['baker_address'];
        $event->price          = (float) $row['amount'];

        $custom = [CustomData::kBAKER_NAME => self::Name($row['baker_alias'], $row['baker_address'])];

        // Anyone may finalize for a staker - near the head almost all such finalizations come from one service,
        // "Finn" - and a notification saying "you finalized" when you did not would be wrong
        if ($row['sender_address'] !== $row['account_address'])
        {
            $custom[CustomData::kOPERATED_BY]      = $row['sender_address'];
            $custom[CustomData::kOPERATED_BY_NAME] = self::Name($row['sender_alias'], $row['sender_address']);
        }

        if ($row['action'] === AccountOperations::kACTION_Unstake && $row['baker_address'] !== null)
        {
            // When the tez can be finalized: worth saying now, it is days away and nothing else will tell them
            $request = Tzkt::GetUnstakeRequest($row['account_address'], $row['baker_address'], (int) $row['level']);

            if ($request !== null)
            {
                $custom[CustomData::kUNLOCK_CYCLE] = $request['unlockCycle'] ?? null;
                $custom[CustomData::kUNLOCK_TIME]  = $request['unlockTime'] ?? null;
            }
        }

        $event->custom_data = array_filter($custom, fn ($v) => $v !== null);

        $this->events[] = $event;

        // The baker's side of someone else staking or unstaking with it. Finalizing only moves tez that already
        // left the stake, so it is not news for the baker; nor is a baker staking its own tez.
        if (in_array($row['action'], [AccountOperations::kACTION_Stake, AccountOperations::kACTION_Unstake])
            && $row['baker_address'] !== null && $row['baker_address'] !== $row['account_address'])
        {
            $this->pushBakerSide($row, Enum\EventFlowType::BakerStaker, $row['action'], $row['baker_address'], self::kSUBID_BakerJoined, []);
        }
    }

    // Sub-ids of the bakers' events. EventsFlow::insert() recognises an event by operation id and sub-id alone, not
    // by type, so these must differ from the wallet's own event (sub-id 0) or they are taken for it and dropped.
    const kSUBID_BakerJoined    = 1;
    const kSUBID_BakerLeft      = 2;

    /**
     * An event for the baker about one of its delegators or stakers: target = the baker, sender = the delegator.
     */
    private function pushBakerSide(array $row, string $type, string $details, string $baker, int $subId, array $custom): void
    {
        $event = $this->newEvent($row, $type, $subId);

        $event->event_details  = $details;
        $event->target_address = $baker;
        $event->sender_address = $row['account_address'];
        $event->price          = (float) $row['amount'];
        $event->custom_data    = array_filter($custom, fn ($v) => $v !== null) ?: null;

        $this->events[] = $event;
    }

    /**
     * A baker changed what it offers stakers. The operation is the baker's; the news is for everyone delegating
     * to it - stakers always delegate to the baker they stake with - so there is one event per watched wallet
     * that delegates to this baker.
     *
     * Who delegates is read from TzKT as of now, not as of the operation. Near the head that is the same thing;
     * a replay of old levels would address the baker's current delegators.
     */
    private function analyzeBakerParameters(array $row): void
    {
        $baker = $row['baker_address'];

        $edge  = $row['edge_of_baking_over_staking'] !== null ? (int) $row['edge_of_baking_over_staking'] : null;
        $limit = $row['limit_of_staking_over_baking'] !== null ? (int) $row['limit_of_staking_over_baking'] : null;

        $prev = Tzkt::GetPreviousDelegateParameters($baker, (int) $row['id']);

        $edgePrev  = isset($prev['edgeOfBakingOverStaking'])  ? (int) $prev['edgeOfBakingOverStaking']  : null;
        $limitPrev = isset($prev['limitOfStakingOverBaking']) ? (int) $prev['limitOfStakingOverBaking'] : null;

        // Setting the same terms again changes nothing for anybody
        if ($prev !== null && $edge === $edgePrev && $limit === $limitPrev)
            return;

        if ($this->watchedAddresses === null)
            $this->watchedAddresses = Models\Wallets::model()->getActiveAddresses();

        $candidates = array_values(array_diff($this->watchedAddresses, [$baker]));

        if (empty($candidates))
            return;

        $delegators = Tzkt::GetAddressesDelegatingTo($baker, $candidates);

        if ($delegators === null)
        {
            Log::Error(get_class($this), __FUNCTION__, "TzKT did not answer: cannot tell who delegates to $baker", $row);
            return;
        }

        $activationCycle = $row['activation_cycle'] !== null ? (int) $row['activation_cycle'] : null;
        $activationTime  = $activationCycle !== null ? Tzkt::EstimateCycleStartTime($activationCycle) : null;

        $custom = array_filter([
            CustomData::kBAKER_NAME       => self::Name($row['baker_alias'], $baker),
            CustomData::kEDGE             => $edge,
            CustomData::kEDGE_PREV        => $edgePrev,
            CustomData::kLIMIT            => $limit,
            CustomData::kLIMIT_PREV       => $limitPrev,
            CustomData::kACTIVATION_CYCLE => $activationCycle,
            CustomData::kACTIVATION_TIME  => $activationTime,
        ], fn ($v) => $v !== null);

        // One operation, many recipients: the sub-id keeps the events apart in events_flow's unique key
        foreach (array_values($delegators) as $idx => $delegator)
        {
            $event = $this->newEvent($row, Enum\EventFlowType::BakerParameters, $idx + 1);

            $event->target_address = $delegator;
            $event->sender_address = $baker;
            $event->custom_data    = $custom;

            $this->events[] = $event;
        }
    }

    private function analyzeOrigination(array $row): void
    {
        if ($row['contract_address'] === null)
            return;

        $event = $this->newEvent($row, Enum\EventFlowType::Origination);

        // When a wallet deploys through a factory contract, the factory is the sender and the wallet the account
        $event->sender_address = $row['sender_address'];
        $event->price          = (float) $row['amount'];
        $event->custom_data    = array_filter([
            CustomData::kCONTRACT          => $row['contract_address'],
            CustomData::kOPERATED_BY_NAME  => $row['sender_address'] !== $row['account_address'] ? self::Name($row['sender_alias'], $row['sender_address']) : null,
        ], fn ($v) => $v !== null);

        $this->events[] = $event;
    }
}
