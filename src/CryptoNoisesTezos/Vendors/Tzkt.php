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

    public static function GetBlockLastIndex(): ?int
    {
        $result = self::GetDataJson(self::api()."/blocks/count");

        return $result->isSuccess() ? (int)($result->data)-1 : null;
    }

    public static function GetBlockDetails(int $level): ?array
    {
        $result = self::GetDataJson(self::api()."/blocks/{$level}?operations=true");

        return $result->isSuccess() ? $result->data : null;
    }

    public static function GetBlockBigmapUpdates(int $level): ?array
    {
        $result = self::GetDataJson(self::api()."/bigmaps/updates?level=$level&limit=".self::kMAX_ITEMS_PER_REQUEST);

        return $result->isSuccess() ? $result->data : null;
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
