<?php

namespace CryptoNoisesTezos\Vendors;

use CryptoCore\Http\AbstractVendor;
use CryptoNoisesTezos\Config;

class Tzkt extends AbstractVendor
{
    // TzKT returns at most this many items per request
    const kMAX_ITEMS_PER_REQUEST = 10000;

    /**
     * Base URL of the TzKT API, no trailing slash (tezos.tzkt_api_url).
     *
     * Worth pointing at a TzKT you control: the public api.tzkt.io rate-limits, and this indexer asks it for every
     * block, every bigmap it has not seen before and every contract. More workers means more requests, so a
     * scaled-up stack earns `HTTP 429` there, which surfaces as "Cannot get bigmap data from tzkt" and friends.
     */
    private static function api(): string
    {
        return Config::cfg()->tzktApiUrl;
    }

    /**
     * Every request to TzKT goes through here: a TzKT Pro key (tezos.tzkt_api_key) is sent as the `apikey` header -
     * TzKT's docs advise the header over the query string, which can leak into logs - and only to the configured
     * TzKT URLs.
     */
    public static function GetData(string $url, array $headers = [], int $attempts = 3, int $timeoutSec = 60): \CryptoCore\Http\SyncResult
    {
        return parent::GetData($url, array_merge($headers, self::AuthHeaders($url)), $attempts, $timeoutSec);
    }

    private static function AuthHeaders(string $url): array
    {
        $key = Config::cfg()->tzktApiKey;

        if ($key === '')
            return [];

        foreach ([Config::cfg()->tzktApiUrl, Config::cfg()->tzktAliasesUrl] as $base)
            if ($base !== '' && str_starts_with($url, "$base/"))
                return ["apikey: $key"];

        return [];
    }

    /**
     * TzKT's own state (/head): level, cycle, timestamp, and whether its indexer keeps up - `synced`, and
     * `knownLevel`, the last level its node knows. Null when TzKT did not answer.
     */
    public static function GetState(): ?array
    {
        $result = self::GetDataJson(self::api()."/head");

        return $result->isSuccess() && isset($result->data['level']) ? $result->data : null;
    }

    /**
     * Why TzKT cannot be trusted to be at the chain's head right now, or null when it can: its indexer reports it is
     * not synced, or its head block is more than $maxAgeSec old - a stalled TzKT still answers, with old data.
     */
    // TzKT reports synced: false for a moment whenever its node has a block it has not indexed yet
    const kMAX_SYNC_LAG_LEVELS = 10;

    public static function NotAtHeadReason(?array $state, int $maxAgeSec = 180): ?string
    {
        if ($state === null)
            return 'TzKT did not answer';

        $lag = (int) ($state['knownLevel'] ?? 0) - (int) $state['level'];

        if (isset($state['synced']) && $state['synced'] === false && $lag > self::kMAX_SYNC_LAG_LEVELS)
            return "TzKT is not synced (level {$state['level']}, its node knows {$state['knownLevel']})";

        $age = time() - strtotime($state['timestamp'] ?? '');

        if ($age > $maxAgeSec)
            return "TzKT's head (level {$state['level']}) is {$age} s old";

        return null;
    }

    public static function GetAccountDetails(string $contract): ?array
    {
            $result = self::GetDataJson(self::api()."/accounts/$contract");

        return $result->isSuccess() ? $result->data : null;
    }

    public static function GetAccountMetadata(string $contract): ?array
    {
        $result = self::GetDataJson(self::api()."/accounts/$contract/metadata");

        return $result->isSuccess() ? $result->data : null;
    }

    //------------------------------------------------------------------------------------------------------------------

    /** The head level TzKT has indexed (/head, a cheap read of its state, rather than counting blocks) */
    public static function GetBlockLastIndex(): ?int
    {
        $state = self::GetState();

        return $state !== null ? (int) $state['level'] : null;
    }

    public static function GetBlockDetails(int $level): ?array
    {
        $result = self::GetDataJson(self::api()."/blocks/{$level}?operations=true");

        return $result->isSuccess() ? $result->data : null;
    }

    /** All bigmap updates of a level, paged by id: one level can hold more than a page */
    public static function GetBlockBigmapUpdates(int $level): ?array
    {
        $updates = [];
        $idAfter = null;

        do
        {
            $page = self::GetLevelRangePage('bigmaps/updates', $level, $level, self::kMAX_ITEMS_PER_REQUEST, $idAfter);

            if ($page === null)
                return null;

            array_push($updates, ...$page);
            $idAfter = $page ? end($page)['id'] : null;
        }
        while (count($page) == self::kMAX_ITEMS_PER_REQUEST);

        return $updates;
    }

    /**
     * One page of a list endpoint ("operations/transactions", "bigmaps/updates") for levels [$levelFrom .. $levelTo],
     * sorted by id; $idAfter continues after the last item of the previous page.
     */
    public static function GetLevelRangePage(string $path, int $levelFrom, int $levelTo, int $limit, ?int $idAfter = null): ?array
    {
        $url = self::api()."/$path?level.ge=$levelFrom&level.le=$levelTo&sort.asc=id&limit=$limit"
            .($idAfter !== null ? "&id.gt=$idAfter" : '');

        $result = self::GetDataJson($url);

        return $result->isSuccess() && is_array($result->data) ? $result->data : null;
    }

    //------------------------------------------------------------------------------------------------------------------

    public static function GetBigmapDetails(int $bigmap): ?array
    {
        $result = self::GetDataJson(self::api()."/bigmaps/$bigmap");

        return $result->isSuccess() ? $result->data : null;
    }

    /**
     * Definitions of the bigmaps from $fromPtr up, in pointer order: ptr => the same object GetBigmapDetails() gives.
     * /bigmaps has no pointer filter (ptr, ptr.in and ptr.ge are silently ignored), so this pages by cursor on ptr.
     * Null when TzKT did not answer.
     */
    public static function GetBigmapDetailsFrom(int $fromPtr, int $limit = 1000): ?array
    {
        $result = self::GetDataJson(self::api()."/bigmaps?sort.asc=ptr&offset.cr=".($fromPtr - 1)."&limit=$limit");

        if (!$result->isSuccess() || !is_array($result->data))
            return null;

        return array_column($result->data, null, 'ptr');
    }

    //------------------------------------------------------------------------------------------------------------------

    public static function GetContractStorageData(string $contract): ?array
    {
        $result = self::GetDataJson(self::api()."/contracts/$contract/storage/");

        return $result->isSuccess() ? $result->data : null;
    }

    //------------------------------------------------------------------------------------------------------------------
    // Staking. These run when a notification is being prepared for a rare event (an unstake, a baker changing its
    // terms), never per block.

    /**
     * The unstake request an unstake at $level went into: the staker's newest request opened at or before that
     * level. A request covers one staker, baker and cycle, so that is the one for the unstake's cycle.
     *
     * @return array|null cycle, unlockCycle, unlockTime (ISO), requestedAmount...
     */
    public static function GetUnstakeRequest(string $staker, string $baker, int $level): ?array
    {
        $result = self::GetDataJson(self::api()."/staking/unstake_requests?staker=$staker&baker=$baker"
            ."&firstLevel.le=$level&sort.desc=id&limit=1");

        return $result->isSuccess() && !empty($result->data[0]) ? $result->data[0] : null;
    }

    /**
     * The parameters a baker had set before the change with operation id $beforeId, or null when that was its
     * first change (then only the new values are known).
     */
    public static function GetPreviousDelegateParameters(string $baker, int $beforeId): ?array
    {
        $result = self::GetDataJson(self::api()."/operations/set_delegate_parameters?sender=$baker&id.lt=$beforeId"
            ."&status=applied&sort.desc=id&limit=1&select=limitOfStakingOverBaking,edgeOfBakingOverStaking,activationCycle");

        return $result->isSuccess() && !empty($result->data[0]) ? $result->data[0] : null;
    }

    /**
     * Which of $addresses delegate to $baker right now. Stakers always delegate to the baker they stake with, so
     * this covers stakers too.
     *
     * @return string[]|null null when TzKT did not answer
     */
    public static function GetAddressesDelegatingTo(string $baker, array $addresses): ?array
    {
        $found = [];

        // address.in goes into the URL: chunks keep it well under any URL length limit
        foreach (array_chunk(array_values(array_unique($addresses)), 100) as $chunk)
        {
            $result = self::GetDataJson(self::api()."/accounts?delegate=$baker&address.in=".join(',', $chunk)
                ."&select=address&limit=".count($chunk));

            if (!$result->isSuccess() || !is_array($result->data))
                return null;

            $found = array_merge($found, $result->data);
        }

        return $found;
    }

    /**
     * Every unstake request on the chain that has unlocked and is not finalized - the protocol's "finalizable" -
     * as of $headLevel. A few dozen at any time, so this is one or two requests whatever the number of wallets.
     *
     * TzKT reports such a request as `pending` with unlockLevel <= head (its `finalizable` status exists but has
     * been empty), so both are asked for. Both filters are applied here, not in the query: TzKT silently ignores
     * status.in (it returns every request ever made) and unlockLevel.le (it returns every pending request, locked or
     * not), so only status=<one value> is trusted server-side. A request without `staker` is a baker unstaking its
     * own stake.
     *
     * @return array[]|null requests by id; null when TzKT did not answer
     */
    public static function GetUnlockedUnstakeRequests(int $headLevel): ?array
    {
        $select = 'select=id,staker,baker,actualAmount,unlockTime,unlockCycle,unlockLevel,status';
        $found  = [];

        foreach (['status=pending', 'status=finalizable'] as $filter)
        {
            $idAfter = 0;

            do
            {
                $result = self::GetDataJson(self::api()."/staking/unstake_requests?$filter&id.gt=$idAfter&sort.asc=id"
                    ."&limit=".self::kMAX_ITEMS_PER_REQUEST."&$select");

                if (!$result->isSuccess() || !is_array($result->data))
                    return null;

                foreach ($result->data as $request)
                {
                    $idAfter = (int) $request['id'];

                    if ((int) ($request['unlockLevel'] ?? PHP_INT_MAX) <= $headLevel)
                        $found[(int) $request['id']] = $request;
                }
            }
            while (count($result->data) == self::kMAX_ITEMS_PER_REQUEST);
        }

        return $found;
    }

    /**
     * The interfaces a contract declares in its TZIP-16 metadata (e.g. "TZIP-012", "ALEATORY-001"), or null when
     * that is not known.
     *
     * Read from tezos.tzkt_aliases_url, not the main TzKT: contract metadata, like aliases, comes from TzKT's
     * metadata service, which a self-hosted TzKT does not run - it answers with no metadata at all. A contract
     * without metadata therefore also gives null (unknown), never an empty list that a caller would cache as "no".
     * The whole account is fetched: metadata is not selectable from the list endpoints (they answer an empty list),
     * and /contracts/{address} ignores select anyway. Callers cache the answer.
     */
    public static function GetContractInterfaces(string $contract): ?array
    {
        $base = Config::cfg()->tzktAliasesUrl;

        if ($base === '')
            return null;

        $result = self::GetDataJson("$base/contracts/$contract");

        if (!$result->isSuccess() || !is_array($result->data) || empty($result->data['metadata']))
            return null;

        $interfaces = $result->data['metadata']['interfaces'] ?? [];

        return is_array($interfaces) ? $interfaces : [];
    }

    /**
     * Payload of the event `$tag` that the transaction with TzKT id $transactionId emitted from $contract, or null.
     */
    public static function GetEventPayload(string $contract, string $tag, int $transactionId): ?array
    {
        $result = self::GetDataJson(self::api()."/contracts/events?contract=$contract&tag=$tag&transactionId=$transactionId"
            ."&select=payload&limit=1");

        return $result->isSuccess() && isset($result->data[0]) && is_array($result->data[0]) ? $result->data[0] : null;
    }

    /**
     * Current value of one bigmap key, or null when the key does not exist or TzKT did not answer. Core data, so any
     * TzKT serves it, a self-hosted one included.
     */
    /** Active keys of a contract's bigmap, by its storage path: key => value. Null when TzKT did not answer. */
    public static function GetContractBigmapKeys(string $contract, string $path): ?array
    {
        $result = self::GetDataJson(self::api()."/contracts/$contract/bigmaps/$path/keys?active=true&select=key,value&limit=".self::kMAX_ITEMS_PER_REQUEST);

        if (!$result->isSuccess() || !is_array($result->data))
            return null;

        return array_column($result->data, 'value', 'key');
    }

    /** Active key/value rows of a contract's bigmap, filtered by e.g. ['key.nat' => 66]. Null when TzKT did not answer. */
    public static function GetContractBigmapRows(string $contract, string $path, array $filters = []): ?array
    {
        $query = http_build_query($filters + ['active' => 'true', 'select' => 'key,value', 'limit' => self::kMAX_ITEMS_PER_REQUEST]);
        $result = self::GetDataJson(self::api()."/contracts/$contract/bigmaps/$path/keys?$query");

        return $result->isSuccess() && is_array($result->data) ? $result->data : null;
    }

    public static function GetBigmapKeyValue(int $bigmap, string $key): ?array
    {
        $result = self::GetDataJson(self::api()."/bigmaps/$bigmap/keys?key=".rawurlencode($key)."&select=value&limit=1");

        return $result->isSuccess() && isset($result->data[0]) && is_array($result->data[0]) ? $result->data[0] : null;
    }

    //------------------------------------------------------------------------------------------------------------------
    // Baking and governance. Filters below were each checked against an impossible value: TzKT silently ignores
    // address.in on /delegates and firstPeriod on /voting/proposals, so neither is used.

    /**
     * Which of $addresses are bakers. @return string[]|null null when TzKT did not answer
     */
    public static function GetBakersAmong(array $addresses): ?array
    {
        $found = [];

        foreach (array_chunk(array_values(array_unique($addresses)), 100) as $chunk)
        {
            $result = self::GetDataJson(self::api()."/accounts?address.in=".join(',', $chunk)."&type=delegate&select=address&limit=".count($chunk));

            if (!$result->isSuccess() || !is_array($result->data))
                return null;

            $found = array_merge($found, $result->data);
        }

        return $found;
    }

    /**
     * Missed baking and attestation rights of $bakers in levels ($after .. $upto], oldest first, at most
     * kMAX_ITEMS_PER_REQUEST per chunk of bakers. @return array[]|null type, level, slots, baker address
     */
    public static function GetMissedRights(array $bakers, int $after, int $upto): ?array
    {
        $rights = [];

        // 4 bakers per request: a fully offline baker misses ~1 attestation a block, so even a window of a couple of
        // thousand levels stays under the page size for four of them (BakerAlerts asks for an hour: ~600 at 6 s blocks)
        foreach (array_chunk(array_values(array_unique($bakers)), 4) as $chunk)
        {
            $result = self::GetDataJson(self::api()."/rights?baker.in=".join(',', $chunk)."&status=missed"
                ."&level.gt=$after&level.le=$upto&sort.asc=level&limit=".self::kMAX_ITEMS_PER_REQUEST);

            if (!$result->isSuccess() || !is_array($result->data))
                return null;

            foreach ($result->data as $right)
                $rights[] = [
                    'type'  => $right['type'] ?? null,
                    'level' => (int) ($right['level'] ?? 0),
                    'slots' => (int) ($right['slots'] ?? 0),
                    'baker' => $right['baker']['address'] ?? null,
                ];
        }

        return $rights;
    }

    /** The level of the last block at or before a unix time. Null when TzKT did not answer. */
    /** XTZ price at a level: btc, eur, usd, cny, jpy, krw, eth, gbp */
    public static function GetQuote(int $level): ?array
    {
        $result = self::GetDataJson(self::api()."/quotes?level=$level");

        return $result->isSuccess() && isset($result->data[0]['usd']) ? $result->data[0] : null;
    }

    public static function GetLevelAt(int $time): ?int
    {
        $result = self::GetDataJson(self::api()."/blocks/".gmdate('Y-m-d\TH:i:s\Z', $time)."/level");

        return $result->isSuccess() && is_numeric($result->data) ? (int) $result->data : null;
    }

    public static function GetCurrentVotingPeriod(): ?array
    {
        $result = self::GetDataJson(self::api()."/voting/periods/current");

        return $result->isSuccess() && !empty($result->data['index']) ? $result->data : null;
    }

    /**
     * The latest proposals, newest first; callers pick the ones of a period themselves.
     */
    public static function GetRecentProposals(int $limit = 30): ?array
    {
        $result = self::GetDataJson(self::api()."/voting/proposals?sort.desc=id&limit=$limit");

        return $result->isSuccess() && is_array($result->data) ? $result->data : null;
    }

    /**
     * Every voter of a period with its vote: none, upvoted, voted_yay, voted_nay, voted_pass. A baker missing from
     * the list is not a voter in that period. One request for all bakers: the voters endpoint ignores a `delegate`
     * filter, and the single-voter endpoint answers a non-voter with 204, which the HTTP layer retries as a failure.
     *
     * @return array<string, string>|null address => status; null when TzKT did not answer
     */
    public static function GetVoters(int $period): ?array
    {
        $result = self::GetDataJson(self::api()."/voting/periods/$period/voters?limit=10000");

        if (!$result->isSuccess() || !is_array($result->data))
            return null;

        $voters = [];

        foreach ($result->data as $voter)
            if (isset($voter['delegate']['address']))
                $voters[$voter['delegate']['address']] = (string) ($voter['status'] ?? '');

        return $voters;
    }

    /** @var array<string, ?string> address => alias (null = none, or the lookup failed), per process */
    private static array $aliasCache = [];

    /**
     * TzKT's alias of an address ("Finn", a baker's name) from tezos.tzkt_aliases_url, for when the data from the
     * main TzKT carries none - a self-hosted TzKT runs no metadata service, so it never does.
     *
     * Optional by nature, so it must never hold anything up: one request with a short timeout, no retries, and a
     * failed lookup is cached like an empty one for the rest of the process.
     */
    public static function GetAlias(string $address): ?string
    {
        if (array_key_exists($address, self::$aliasCache))
            return self::$aliasCache[$address];

        $base = Config::cfg()->tzktAliasesUrl;

        if ($base === '' || !preg_match('/^(tz[1-4]|KT1|sr1)[1-9A-HJ-NP-Za-km-z]{33}$/', $address))
            return self::$aliasCache[$address] = null;

        // The list endpoint: /accounts/{address} ignores select and answers the whole account (kilobytes)
        $ch = curl_init("$base/accounts?address=$address&select=alias");
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER      => self::AuthHeaders("$base/accounts"),
            CURLOPT_ENCODING        => '',
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_CONNECTTIMEOUT  => 3,
            CURLOPT_TIMEOUT         => 5,
            CURLOPT_USERAGENT       => self::kUserAgent,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // ["Finn"], or [null] when the account has no alias
        $decoded = ($code == 200 && is_string($body)) ? json_decode($body, true) : null;
        $alias   = is_array($decoded) ? ($decoded[0] ?? null) : null;

        return self::$aliasCache[$address] = (is_string($alias) && $alias !== '') ? $alias : null;
    }

    /**
     * Start time of a cycle: TzKT's own for a cycle that has begun, an estimate for one in the future (TzKT serves
     * past and current cycles only). The estimate extrapolates the current cycle's length, which is only good for
     * the next few cycles - cycle lengths changed across protocol upgrades, so it is never used for a past cycle.
     * Null when TzKT did not answer.
     */
    public static function EstimateCycleStartTime(int $cycle): ?string
    {
        $head = self::GetDataJson(self::api()."/head");

        if (!$head->isSuccess() || !isset($head->data['cycle']))
            return null;

        $current = (int) $head->data['cycle'];

        if ($cycle <= $current)
        {
            $known = self::GetDataJson(self::api()."/cycles/$cycle");

            return $known->isSuccess() && !empty($known->data['startTime']) ? $known->data['startTime'] : null;
        }

        $info = self::GetDataJson(self::api()."/cycles/$current");

        if (!$info->isSuccess() || empty($info->data['startTime']) || empty($info->data['endTime']))
            return null;

        $start    = strtotime($info->data['startTime']);
        $duration = strtotime($info->data['endTime']) - $start;

        return gmdate('Y-m-d\TH:i:s\Z', $start + ($cycle - $current) * $duration);
    }
}
