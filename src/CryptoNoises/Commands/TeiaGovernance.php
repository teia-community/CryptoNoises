<?php

namespace CryptoNoises\Commands;

use CryptoCore\Setapp;
use CryptoCore\Time;
use CryptoNoises\Entities\Address;
use CryptoNoises\Entities\EventFlow;
use CryptoNoises\Enum;
use CryptoNoises\Models;
use CryptoNoises\Networks\Tezos\TezosNetworkParser;
use CryptoNoisesTezos\Vendors\Tzkt;
use Exception;

/**
 * Teia governance, run by the Teia events job:
 * - core team multisig: new proposals and a reminder a day before expiry, to users watching a signer's wallet;
 * - DAO: new proposals, to everyone;
 * - community polls: new polls, to everyone.
 * What was sent is kept in config; the first run only records what exists.
 */
class TeiaGovernance
{
    const kCFG_STATE            = 'TeiaGovernance.State';      // JSON: multisig, reminded, dao, polls
    const kREMIND_BEFORE_SEC    = 86400;

    private TezosNetworkParser $parser;
    private array $state;
    private int $sent = 0;

    /** @return int events created */
    public function passOnce(): int
    {
        $this->parser = new TezosNetworkParser();
        $stored = json_decode((string) Models\Config::model()->getValue(self::kCFG_STATE), true);
        $firstRun = !is_array($stored);
        $empty = ['multisig' => [], 'reminded' => [], 'dao' => [], 'polls' => []];
        $this->state = is_array($stored) ? $stored + $empty : $empty;

        // A section added after the first run starts from what exists, like a first run
        $pollsFirstRun = $firstRun || !isset($stored['polls']);

        $this->multisig($firstRun);
        $this->dao($firstRun);
        $this->polls($pollsFirstRun);

        if ($this->sent)
            $this->parser->save();

        Models\Config::model()->setValue(self::kCFG_STATE, json_encode($this->state));

        return $this->sent;
    }

    //------------------------------------------------------------------------------------------------------------------

    private function multisig(bool $firstRun): void
    {
        $storage   = Tzkt::GetContractStorageData(Setapp\TeiaGovernance::MultisigKTID);
        $proposals = Tzkt::GetContractBigmapRows(Setapp\TeiaGovernance::MultisigKTID, 'proposals');

        if ($storage === null || $proposals === null)
            throw new Exception('TzKT did not answer: Teia multisig');

        $expirySec  = (int) ($storage['expiration_time'] ?? 10) * 86400;
        $minVotes   = (int) ($storage['minimum_votes'] ?? 0);
        $watchers   = $this->signerWatchers((array) ($storage['users'] ?? []));

        foreach ($proposals as $row)
        {
            $id = (string) $row['key'];
            $p  = $row['value'];
            $expiresAt = strtotime($p['timestamp']) + $expirySec;
            $open = empty($p['executed']) && $expiresAt > time();

            if ($firstRun || !$open)
            {
                $this->state['multisig'][] = $id;
                continue;
            }

            $info = self::MultisigInfo($id, $p, $minVotes, $expiresAt);

            if (!in_array($id, $this->state['multisig'], true))
            {
                foreach ($watchers as $signer => $userIds)
                    $this->push('multisig-proposal', "ms-proposal:$id:$signer", $signer, $userIds, $info);

                $this->state['multisig'][] = $id;
            }

            // A day before expiry, to signers who have not voted, while votes are still missing
            if ($expiresAt - time() <= self::kREMIND_BEFORE_SEC && (int) $p['positive_votes'] < $minVotes)
            {
                $voters = Tzkt::GetContractBigmapRows(Setapp\TeiaGovernance::MultisigKTID, 'votes', ['key.nat' => $id]);

                if ($voters === null)
                    continue;

                $voted = array_map(fn ($r) => $r['key']['address'] ?? '', $voters);

                foreach ($watchers as $signer => $userIds)
                    if (!in_array($signer, $voted, true) && !in_array("$id:$signer", $this->state['reminded'], true))
                    {
                        $this->push('multisig-reminder', "ms-reminder:$id:$signer", $signer, $userIds, $info);
                        $this->state['reminded'][] = "$id:$signer";
                    }
            }
        }

        $this->state['multisig'] = array_values(array_unique($this->state['multisig']));
    }

    /** @return array<string, int[]> signer address => ids of active users watching it */
    private function signerWatchers(array $signers): array
    {
        $watchers = [];

        foreach ($signers as $signer)
        {
            $adid = Address::GetAdid($signer);
            $userIds = $adid ? array_map('intval', array_column(Models\Users::model()->getActiveUsersByWalletAdid($adid), 'user_id')) : [];

            if ($userIds)
                $watchers[$signer] = $userIds;
        }

        return $watchers;
    }

    private static function MultisigInfo(string $id, array $p, int $minVotes, int $expiresAt): array
    {
        $kind = array_key_first((array) ($p['kind'] ?? [])) ?? 'unknown';
        $text = isset($p['text']) ? self::HexText($p['text']) : null;

        $summary = match ($kind) {
            'text'              => 'Text proposal',
            'transfer_mutez'    => self::MutezSummary((array) $p['mutez_transfers']),
            'transfer_token'    => self::TokenSummary((array) $p['token_transfers']),
            'lambda_function'   => 'Run a contract call',
            'add_user'          => 'Add signer '.($p['user'] ?? '?'),
            'remove_user'       => 'Remove signer '.($p['user'] ?? '?'),
            'minimum_votes'     => 'Require '.($p['minimum_votes'] ?? '?').' votes',
            'expiration_time'   => 'Expire proposals after '.($p['expiration_time'] ?? '?').' days',
            default             => ucfirst(str_replace('_', ' ', $kind)),
        };

        return [
            'proposal_id'   => $id,
            'summary'       => $summary,
            'text'          => $text !== null && !str_starts_with($text, 'ipfs://') ? $text : null,
            'details_uri'   => $text !== null && str_starts_with($text, 'ipfs://') ? $text : null,
            'issuer'        => $p['issuer'] ?? null,
            'votes'         => (int) ($p['positive_votes'] ?? 0),
            'min_votes'     => $minVotes,
            'ends_at'       => gmdate('Y-m-d\TH:i:s\Z', $expiresAt),
        ];
    }

    private static function MutezSummary(array $transfers): string
    {
        $total = array_sum(array_map(fn ($t) => (int) $t['amount'], $transfers));

        return 'Send '.rtrim(rtrim(number_format($total / 1e6, 6, '.', ','), '0'), '.').' tez to '.count($transfers).' recipient(s)';
    }

    private static function TokenSummary(array $transfer): string
    {
        $distribution = (array) ($transfer['distribution'] ?? []);
        $total = array_sum(array_map(fn ($t) => (int) $t['amount'], $distribution));
        [$symbol, $decimals] = Setapp\TeiaGovernance::Tokens[$transfer['fa2'] ?? ''] ?? [null, 0];

        $amount = $symbol ? rtrim(rtrim(number_format($total / 10 ** $decimals, $decimals, '.', ','), '0'), '.')." $symbol" : "$total tokens";

        return "Send $amount to ".count($distribution).' recipient(s)';
    }

    //------------------------------------------------------------------------------------------------------------------

    private function dao(bool $firstRun): void
    {
        $proposals = Tzkt::GetContractBigmapRows(Setapp\TeiaGovernance::DaoKTID, 'proposals');
        $params    = Tzkt::GetContractBigmapKeys(Setapp\TeiaGovernance::DaoKTID, 'governance_parameters');

        if ($proposals === null || $params === null)
            throw new Exception('TzKT did not answer: Teia DAO');

        foreach ($proposals as $row)
        {
            $id = (string) $row['key'];
            $p  = $row['value'];

            if (in_array($id, $this->state['dao'], true))
                continue;

            $this->state['dao'][] = $id;

            if ($firstRun || array_key_first((array) $p['status']) !== 'open')
                continue;

            $gp = $params[$p['gp_index']] ?? [];
            $endsAt = strtotime($p['timestamp']) + ((int) ($gp['wait_period'] ?? 0) + (int) ($gp['vote_period'] ?? 0)) * 86400;
            $description = self::HexText($p['description'] ?? '');

            $this->push('dao-proposal', "dao-proposal:$id", null, [], [
                'proposal_id'   => $id,
                'title'         => self::HexText($p['title'] ?? '') ?: "Proposal #$id",
                'details_uri'   => $description && str_starts_with($description, 'ipfs://') ? $description : null,
                'issuer'        => $p['issuer'] ?? null,
                'ends_at'       => gmdate('Y-m-d\TH:i:s\Z', $endsAt),
            ]);
        }
    }

    //------------------------------------------------------------------------------------------------------------------

    private function polls(bool $firstRun): void
    {
        $polls = Tzkt::GetContractBigmapRows(Setapp\TeiaGovernance::PollsKTID, 'polls');

        if ($polls === null)
            throw new Exception('TzKT did not answer: Teia polls');

        foreach ($polls as $row)
        {
            $id = (string) $row['key'];
            $p  = $row['value'];

            if (in_array($id, $this->state['polls'], true))
                continue;

            $this->state['polls'][] = $id;

            $endsAt = strtotime($p['timestamp']) + (int) $p['vote_period'] * 86400;

            if ($firstRun || $endsAt <= time())
                continue;

            $description = self::HexText($p['description'] ?? '');
            $options = array_filter(array_map(fn ($hex) => self::HexText((string) $hex), (array) ($p['options'] ?? [])));

            $this->push('poll', "poll:$id", null, [], [
                'proposal_id'   => $id,
                'title'         => self::HexText($p['question'] ?? '') ?: "Poll #$id",
                'options'       => join(' · ', $options),
                'text'          => $description && !str_starts_with($description, 'ipfs://') ? $description : null,
                'details_uri'   => $description && str_starts_with($description, 'ipfs://') ? $description : null,
                'issuer'        => $p['issuer'] ?? null,
                'ends_at'       => gmdate('Y-m-d\TH:i:s\Z', $endsAt),
            ]);
        }
    }

    //------------------------------------------------------------------------------------------------------------------

    private function push(string $details, string $uniqueKey, ?string $signer, array $userIds, array $info): void
    {
        $event = new EventFlow();
        $event->initInTezosNetwork(EventFlow::UNDEFINED_NETWORK_BLOCK, crc32($uniqueKey), 1);

        $event->event_type          = Enum\EventFlowType::TeiaGovernance;
        $event->event_details       = $details;
        $event->transaction_event   = Time::Now();
        $event->target_address      = $signer;
        $event->userIds             = $userIds;
        $event->custom_data         = $info;

        $this->parser->pushEvent($event);
        $this->sent++;
    }

    /** Michelson bytes holding UTF-8 text (titles, IPFS links), or null when they are not text */
    private static function HexText(string $hex): ?string
    {
        $text = ctype_xdigit($hex) && strlen($hex) % 2 == 0 ? hex2bin($hex) : $hex;

        return $text !== '' && mb_check_encoding($text, 'UTF-8') ? trim($text) : null;
    }
}
