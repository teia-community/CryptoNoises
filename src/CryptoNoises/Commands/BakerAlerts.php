<?php

namespace CryptoNoises\Commands;

use CryptoCore\Process\Shutdown;
use CryptoCore\Time;
use CryptoNoises\Config;
use CryptoNoises\Core\CustomData;
use CryptoNoises\Entities\Address;
use CryptoNoises\Entities\EventFlow;
use CryptoNoises\Entities\OptionsNotifier;
use CryptoNoises\Enum;
use CryptoNoises\Models;
use CryptoNoises\Networks\Tezos\TezosNetworkParser;
use CryptoNoisesTezos\Vendors\TezosRpc;
use CryptoNoisesTezos\Vendors\Tzkt;
use Exception;

/**
 * Alerts for the watched wallets that are bakers: missed attestations and blocks, DAL participation, a new governance
 * period, a new proposal, and a reminder when a vote is about to close and the baker has not voted.
 *
 * Polls TzKT every ten minutes rather than following the indexer: these are about now, and the indexer may be days
 * behind the head. Every lookup is one request (or one per few bakers), whatever the number of wallets.
 *
 * Missed rights are counted within the last hour, a few levels short of the head where a right's outcome is final,
 * against each user's threshold (see missedRights()). One alert covers all the misses since the previous one, never
 * one per slot.
 *
 * Governance alerts go out once per (alert, user), recorded in baker_alerts_sent (see BakerAlertsSent for why the
 * events cannot do that themselves).
 */
class BakerAlerts extends AbstractCommand
{
    const kPASS_INTERVAL_SEC        = 600;
    const kFINALITY_LEVELS          = 3;
    const kWINDOW_SEC               = 3600;         // missed rights are counted over the last hour

    // DAL: alert once the cycle has this many attestable slots - at a cycle's start a single miss is "insufficient"
    const kDAL_MIN_ATTESTABLE       = 30;
    const kVOTE_REMINDER_SEC        = 86400;        // remind when a vote closes within a day

    const kPERIOD_SEEN              = 'BakerAlerts.Period';
    const kPROPOSALS_SEEN           = 'BakerAlerts.ProposalsSeen';     // JSON: hashes already announced in kPERIOD_SEEN

    // Periods with something to vote on (a proposal period only once it has proposals)
    const kBALLOT_KINDS             = ['exploration', 'promotion'];

    public const kGOVERNANCE_URL    = 'https://tzkt.io/governance';

    private ?TezosNetworkParser $parser = null;
    private int $uniqueId = 0;
    private int $uniqueSubId = 0;

    public function run(): void
    {
        $tsStart = Time::Microtime();

        set_time_limit(Config::runTimeLimit() + Config::kJOBS_TIMEOUT_DEFAULT);

        if (!$this->checkLockAndWaitUntilNoOtherInstanceRuns('command-lock.baker-alerts', Config::kJOBS_TIMEOUT_DEFAULT))
        {
            while (Time::Microtime() - $tsStart <= Config::runTimeLimit() && !Shutdown::Requested())
                sleep(1);
            return;
        }

        while (!Shutdown::Requested())
        {
            try
            {
                $sent = $this->passOnce();
                $this->logMsg(gmdate('H:i:s')." baker alerts: $sent sent", "\n", true);
            }
            catch (Exception $e)
            {
                $this->logErr("ERROR: ".$e->getMessage());
            }

            if (Time::Microtime() - $tsStart > Config::runTimeLimit() || Shutdown::Requested())
                break;

            sleep(self::kPASS_INTERVAL_SEC);
        }
    }

    public function runOnce(): void
    {
        $this->logMsg("Baker alerts sent: ".$this->passOnce(), "\n", true);
    }

    /**
     * @param array|null $period, $proposals, $voters  for tests: the governance state to use instead of TzKT's
     * @return int alerts sent
     */
    public function passOnce(?array $period = null, ?array $proposals = null, ?array $voters = null): int
    {
        $this->parser      = new TezosNetworkParser();
        $this->uniqueId    = Time::Time();
        $this->uniqueSubId = 0;

        $watched = Models\Wallets::model()->getActiveAddresses();
        $bakers  = $watched ? Tzkt::GetBakersAmong($watched) : [];

        if ($bakers === null)
            throw new Exception("TzKT did not answer: which watched wallets are bakers");

        $sent  = $this->missedRights($bakers);
        $sent += $this->dalParticipation($bakers);
        $sent += $this->governance($bakers, $period, $proposals, $voters);

        $this->parser->save();

        return $sent;
    }

    //------------------------------------------------------------------------------------------------------------------

    /**
     * Misses within the last hour, per baker, against each watcher's own threshold
     * (OptionsNotifier::getMissedAttestationsAlert(): 10 by default, or off). Missing an attestation now and then is
     * normal, but a minute of downtime misses about ten; an offline baker misses one every block. Missed blocks
     * always alert: they are rare and cost rewards.
     *
     * Only misses after the last one already reported to that user count, so a baker that stays down is reported again
     * once it has missed the threshold again, and the same miss is never reported twice. A trickle slower than the
     * threshold per hour never alerts, which is the point.
     */
    private function missedRights(array $bakers): int
    {
        if (!$bakers)
            return 0;

        $head = Tzkt::GetBlockLastIndex();

        if ($head === null)
            throw new Exception("TzKT did not answer: no head level");

        // The hour is taken by time, not as a count of blocks: the block time is a protocol constant that upgrades
        // change (60 s at launch, 6 s now), and an hour may straddle such an upgrade
        $after = Tzkt::GetLevelAt(Time::Time() - self::kWINDOW_SEC);

        if ($after === null)
            throw new Exception("TzKT did not answer: level an hour ago");

        $upto   = $head - self::kFINALITY_LEVELS;
        $rights = Tzkt::GetMissedRights($bakers, $after, $upto);

        if ($rights === null)
            throw new Exception("TzKT did not answer: missed rights");

        $byBaker = [];

        foreach ($rights as $right)
            if ($right['baker'] !== null)
                $byBaker[$right['baker']][] = $right;

        $sent = 0;

        foreach ($byBaker as $baker => $missed)
        {
            $bakerAdid = Address::GetOrCreateAdid($baker);

            foreach ($this->watcherIds($baker) as $userId)
            {
                $threshold = OptionsNotifier::NewForUser($userId)->getMissedAttestationsAlert();

                $lastKey  = "BakerAlerts.Missed.$userId.$bakerAdid";       // highest level already reported to this user
                $reported = (int) Models\Config::model()->getValue($lastKey);

                $new = array_values(array_filter($missed, fn ($r) => $r['level'] > $reported));

                if (!$new)
                    continue;

                $blocks       = count(array_filter($new, fn ($r) => $r['type'] == 'baking'));
                $attestations = array_values(array_filter($new, fn ($r) => $r['type'] != 'baking'));

                if ($blocks == 0 && ($threshold == OptionsNotifier::kMissedAttestationsOff || count($attestations) < $threshold))
                    continue;

                $levels = array_column($new, 'level');

                $this->push(Enum\EventFlowType::BakerMissedRights, null, $baker, $userId, [
                    'missed_attestations'   => count($attestations),
                    'missed_slots'          => array_sum(array_column($attestations, 'slots')),
                    'missed_blocks'         => $blocks,
                    'level_first'           => min($levels),
                    'level_last'            => max($levels),
                ]);

                Models\Config::model()->setValue($lastKey, (string) max($levels));
                $sent++;
            }
        }

        return $sent;
    }

    //------------------------------------------------------------------------------------------------------------------

    /**
     * DAL participation in the current cycle, from the node's RPC. A baker that attests less than the protocol's
     * minimal ratio of its attestable DAL slots (2/3) loses the cycle's DAL rewards, so this says so while the cycle
     * can still be saved - once per cycle - and again if it recovers; and once if the baker was denounced. Waits for
     * kDAL_MIN_ATTESTABLE attestable slots: early in a cycle a single miss reads as insufficient.
     */
    private function dalParticipation(array $bakers): int
    {
        if (!$bakers)
            return 0;

        $head = Tzkt::GetHead();

        if ($head === null)
            throw new Exception("TzKT did not answer: no head");

        $cycle = (int) $head['cycle'];
        $ratio = TezosRpc::GetConstants()['minimal_participation_ratio'] ?? ['numerator' => 2, 'denominator' => 3];
        $sent  = 0;

        foreach ($bakers as $baker)
        {
            $dal = TezosRpc::GetDalParticipation($baker);

            if ($dal === null)
                continue;   // no RPC configured, the node did not answer, or not a registered baker

            $attestable = (int) $dal['delegate_attestable_dal_slots'];
            $low        = empty($dal['sufficient_dal_participation']) && $attestable >= self::kDAL_MIN_ATTESTABLE;

            $info = [
                'cycle'             => $cycle,
                'dal_attested'      => (int) $dal['delegate_attested_dal_slots'],
                'dal_attestable'    => $attestable,
                'dal_rewards'       => (int) ($dal['expected_dal_rewards'] ?? 0),
                'dal_required'      => round(100 * $ratio['numerator'] / max(1, $ratio['denominator'])),
            ];

            foreach ($this->watcherIds($baker) as $userId)
            {
                $kLow = "dal-low:$cycle:$baker";

                if (!empty($dal['denounced']) && Models\BakerAlertsSent::model()->claim("dal-denounced:$cycle:$baker", $userId))
                {
                    $this->push(Enum\EventFlowType::BakerDal, 'denounced', $baker, $userId, $info);
                    $sent++;
                }

                if ($low && Models\BakerAlertsSent::model()->claim($kLow, $userId))
                {
                    $this->push(Enum\EventFlowType::BakerDal, 'insufficient', $baker, $userId, $info);
                    $sent++;
                }
                else if (!$low && !empty($dal['sufficient_dal_participation'])
                    && Models\BakerAlertsSent::model()->has($kLow, $userId)
                    && Models\BakerAlertsSent::model()->claim("dal-ok:$cycle:$baker", $userId))
                {
                    $this->push(Enum\EventFlowType::BakerDal, 'recovered', $baker, $userId, $info);
                    $sent++;
                }
            }
        }

        return $sent;
    }

    //------------------------------------------------------------------------------------------------------------------

    private function governance(array $bakers, ?array $period, ?array $proposals, ?array $voters): int
    {
        $period    ??= Tzkt::GetCurrentVotingPeriod();
        $proposals ??= Tzkt::GetRecentProposals();

        if ($period === null || $proposals === null)
            throw new Exception("TzKT did not answer: voting period or proposals");

        $index = (int) $period['index'];
        $kind  = (string) $period['kind'];

        // The proposals of this period: those of a proposal period, or the one being voted on in a ballot period
        $current = array_values(array_filter($proposals,
            fn ($p) => (int) ($p['firstPeriod'] ?? -1) <= $index && $index <= (int) ($p['lastPeriod'] ?? -1)));

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
        // What is new since the last pass. The first pass ever announces nothing: what is there already is not news.

        $seenPeriod = Models\Config::model()->getValue(self::kPERIOD_SEEN);
        $seenHashes = json_decode((string) Models\Config::model()->getValue(self::kPROPOSALS_SEEN), true) ?: [];
        $firstRun   = $seenPeriod === null || $seenPeriod === '';

        $newPeriod = !$firstRun && (int) $seenPeriod !== $index;

        if ($firstRun || $newPeriod)
            $seenHashes = $firstRun ? array_column($current, 'hash') : [];

        $newProposals = $kind == 'proposal'
            ? array_values(array_filter($current, fn ($p) => !in_array($p['hash'], $seenHashes)))
            : [];

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $secondsLeft = !empty($period['endTime']) ? strtotime($period['endTime']) - Time::Time() : null;
        $votable     = in_array($kind, self::kBALLOT_KINDS) || ($kind == 'proposal' && $current);
        $remindNow   = $votable && $secondsLeft !== null && $secondsLeft > 0 && $secondsLeft <= self::kVOTE_REMINDER_SEC;

        if ($remindNow && $voters === null && $bakers)
        {
            $voters = Tzkt::GetVoters($index);

            if ($voters === null)
                throw new Exception("TzKT did not answer: voters of period $index");
        }

        $periodInfo = [
            'period'        => $index,
            'period_kind'   => $kind,
            'period_end'    => $period['endTime'] ?? null,
            'period_end_level' => isset($period['lastLevel']) ? (int) $period['lastLevel'] : null,
            'period_end_cycle' => isset($period['lastLevel']) ? TezosRpc::CycleOfLevel((int) $period['lastLevel']) : null,
        ];

        // In a ballot period, which proposal it is about
        if (in_array($kind, ['exploration', 'cooldown', 'promotion', 'adoption', 'testing']) && $current)
            $periodInfo += self::ProposalInfo($current[0]);

        $sent = 0;

        foreach ($bakers as $baker)
        {
            foreach ($this->watcherIds($baker) as $userId)
            {
                if ($newPeriod && Models\BakerAlertsSent::model()->claim("period:$index:$baker", $userId))
                {
                    $this->push(Enum\EventFlowType::Governance, 'period', $baker, $userId, $periodInfo);
                    $sent++;
                }

                foreach ($newProposals as $proposal)
                {
                    if (Models\BakerAlertsSent::model()->claim("proposal:{$proposal['hash']}:$index:$baker", $userId))
                    {
                        $this->push(Enum\EventFlowType::Governance, 'proposal', $baker, $userId, $periodInfo + self::ProposalInfo($proposal));
                        $sent++;
                    }
                }

                // Not voted yet, and the vote closes within a day. A baker missing from the voters is not a voter.
                if ($remindNow && ($voters[$baker] ?? null) === 'none'
                    && Models\BakerAlertsSent::model()->claim("vote:$index:$baker", $userId))
                {
                    $info = $periodInfo + ($kind == 'proposal' ? ['proposals_count' => count($current)] : []);
                    $this->push(Enum\EventFlowType::Governance, 'vote-reminder', $baker, $userId, $info);
                    $sent++;
                }
            }
        }

        Models\Config::model()->setValue(self::kPERIOD_SEEN, (string) $index);
        Models\Config::model()->setValue(self::kPROPOSALS_SEEN, json_encode(array_values(array_unique(array_merge($seenHashes, array_column($newProposals, 'hash'))))));

        return $sent;
    }

    private static function ProposalInfo(array $proposal): array
    {
        $extras = is_array($proposal['extras'] ?? null) ? $proposal['extras'] : [];

        return array_filter([
            'proposal'          => $proposal['hash'] ?? null,
            'proposal_name'     => $extras['alias'] ?? null,
            'proposal_url'      => $extras['agora'] ?? null,
            'initiator'         => $proposal['initiator']['address'] ?? null,
            'initiator_name'    => $proposal['initiator']['alias'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    //------------------------------------------------------------------------------------------------------------------

    /** @return int[] users watching this wallet */
    private function watcherIds(string $address): array
    {
        $adid = Address::GetOrCreateAdid($address);

        return array_map('intval', array_column(Models\Users::model()->getActiveUsersByWalletAdid($adid), 'user_id'));
    }

    private function push(string $type, ?string $details, string $baker, int $userId, array $custom): void
    {
        $event = new EventFlow();
        $event->initInTezosNetwork(EventFlow::UNDEFINED_NETWORK_BLOCK, $this->uniqueId, ++$this->uniqueSubId);

        $event->event_type          = $type;
        $event->event_details       = $details;
        $event->transaction_event   = Time::Now();
        $event->target_address      = $baker;
        $event->userIds             = [$userId];
        $event->custom_data         = $custom;

        $this->parser->pushEvent($event);
    }
}
