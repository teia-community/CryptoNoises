<?php

namespace CryptoNoisesTezos\Vendors;

use CryptoCore\Http\AbstractVendor;
use CryptoNoises\Core\Log;
use CryptoNoisesTezos\Config;

/**
 * Tezos node RPCs (tezos.rpc_urls), for what only the protocol itself knows - a baker's DAL participation in the
 * current cycle - and for the protocol constants.
 *
 * Several nodes, so that one falling behind the chain or failing does not stop the alerts. Every kHEALTH_TTL_SEC the
 * heads of all of them are compared, and a node more than kMAX_LAG_LEVELS behind the highest is left out until the
 * next comparison: a lagging node answers without error, with the state of an older block. A request goes to the
 * first node in the configured order that is in, and on a failure that another node may not have - no answer, a
 * rate limit, a server error - to the next; that node is left out until the next comparison too.
 */
class TezosRpc extends AbstractVendor
{
    const kHEALTH_TTL_SEC   = 60;
    const kMAX_LAG_LEVELS   = 2;
    const kHEAD_TIMEOUT_SEC = 5;
    const kTIMEOUT_SEC      = 20;

    /** @var string[] nodes in use, in the configured order */
    private static array $nodes = [];
    private static int $checkedAt = 0;

    /** @var array<string, true> nodes left out, so a change is logged once, not on every comparison */
    private static array $skipped = [];

    // Protocol constants change with protocol upgrades (the block time went from 60 s to 6 s), so they are re-read
    const kCONSTANTS_TTL_SEC = 600;

    private static ?array $constants = null;
    private static int $constantsAt = 0;

    /**
     * The nodes to ask, in the configured order: those whose head is within kMAX_LAG_LEVELS of the highest. When
     * none answers, all of them (the next comparison comes sooner).
     */
    private static function nodes(): array
    {
        $urls = Config::cfg()->tezosRpcUrls;

        if (count($urls) <= 1)
            return $urls;

        if (self::$nodes && time() - self::$checkedAt < self::kHEALTH_TTL_SEC)
            return self::$nodes;

        $levels = [];

        foreach ($urls as $url)
        {
            $result = parent::GetDataJson("$url/chains/main/blocks/head/header", [], 1, self::kHEAD_TIMEOUT_SEC);

            if ($result->isSuccess() && isset($result->data['level']))
                $levels[$url] = (int) $result->data['level'];
        }

        self::$checkedAt = time();

        if (!$levels)
        {
            Log::Warning(self::class, __FUNCTION__, 'no Tezos RPC node answered');
            self::$checkedAt -= intdiv(self::kHEALTH_TTL_SEC, 2);
            return self::$nodes = $urls;
        }

        $best  = max($levels);
        $nodes = array_values(array_filter($urls, fn ($url) => isset($levels[$url]) && $levels[$url] >= $best - self::kMAX_LAG_LEVELS));

        foreach ($urls as $url)
            if (!in_array($url, $nodes))
                self::skip($url, isset($levels[$url]) ? "at level {$levels[$url]}, ".($best - $levels[$url])." behind $best" : 'no answer');
            else if (isset(self::$skipped[$url]))
            {
                unset(self::$skipped[$url]);
                Log::Notice(self::class, __FUNCTION__, "using $url again: at level {$levels[$url]}");
            }

        return self::$nodes = $nodes;
    }

    private static function skip(string $url, string $reason): void
    {
        self::$nodes = array_values(array_diff(self::$nodes, [$url]));

        if (!isset(self::$skipped[$url]))
        {
            self::$skipped[$url] = true;
            Log::Warning(self::class, 'nodes', "skipping $url: $reason");
        }
    }

    /**
     * GET $path from the first node in use that answers. Null when none did; a failure that every node would give
     * (400, 404...) is not retried elsewhere.
     */
    private static function get(string $path): ?array
    {
        foreach (self::nodes() as $url)
        {
            $result = parent::GetDataJson($url.$path, [], 1, self::kTIMEOUT_SEC);

            if ($result->isSuccess())
                return is_array($result->data) ? $result->data : null;

            // A protocol error - an address that is no baker, say - comes as a 500 too, and every node gives it
            if (!self::IsTransient($result->code) || self::IsProtocolError($result->data))
                return null;

            // Out until the next comparison
            self::skip($url, 'HTTP '.($result->code ?: 'no answer')." on $path");
        }

        return null;
    }

    /** A node's error body for a protocol error: [{"kind":"temporary","id":"proto.025-PsUshuai.delegate.not_registered",...}] */
    private static function IsProtocolError($body): bool
    {
        return is_string($body) && preg_match('/"id"\s*:\s*"proto\./', $body) === 1;
    }

    /**
     * The baker's DAL participation in the current cycle: delegate_attested_dal_slots, delegate_attestable_dal_slots,
     * expected_dal_rewards (mutez), sufficient_dal_participation, denounced. Null when the node did not answer or the
     * address is not a registered baker (the node answers 500 then).
     */
    public static function GetDalParticipation(string $baker): ?array
    {
        $data = self::get("/chains/main/blocks/head/context/delegates/$baker/dal_participation");

        return isset($data['delegate_attestable_dal_slots']) ? $data : null;
    }

    /**
     * The current protocol's constants, re-read every kCONSTANTS_TTL_SEC. Null when the node never answered; when it
     * stops answering, the last ones read.
     */
    public static function GetConstants(): ?array
    {
        if (time() - self::$constantsAt >= self::kCONSTANTS_TTL_SEC)
        {
            $data = self::get("/chains/main/blocks/head/context/constants");

            if ($data !== null)
            {
                self::$constants   = $data;
                self::$constantsAt = time();
            }
        }

        return self::$constants;
    }

    /**
     * The cycle a level belongs to - also a future one - from the head's position in its cycle. Exact as long as
     * blocks_per_cycle does not change before that level, which holds for the end of a voting period: a new protocol
     * only activates right after the last level of an adoption period. Null when the node did not answer.
     */
    public static function CycleOfLevel(int $level): ?int
    {
        $head     = self::get("/chains/main/blocks/head/helpers/current_level");
        $perCycle = (int) (self::GetConstants()['blocks_per_cycle'] ?? 0);

        if (!isset($head['level'], $head['cycle'], $head['cycle_position']) || $perCycle <= 0)
            return null;

        return (int) $head['cycle'] + intdiv((int) $head['cycle_position'] + $level - (int) $head['level'], $perCycle);
    }
}
