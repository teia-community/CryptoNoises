<?php

namespace CryptoNoisesTezos\Vendors;

use CryptoCore\Http\AbstractVendor;
use CryptoNoisesTezos\Config;

/**
 * A Tezos node's RPC (tezos.rpc_url), for what only the protocol itself knows - a baker's DAL participation in the
 * current cycle - and for the protocol constants.
 */
class TezosRpc extends AbstractVendor
{
    // Protocol constants change with protocol upgrades (the block time went from 60 s to 6 s), so they are re-read
    const kCONSTANTS_TTL_SEC = 600;

    private static ?array $constants = null;
    private static int $constantsAt = 0;

    private static function api(): string
    {
        return Config::cfg()->tezosRpcUrl;
    }

    /**
     * The baker's DAL participation in the current cycle: delegate_attested_dal_slots, delegate_attestable_dal_slots,
     * expected_dal_rewards (mutez), sufficient_dal_participation, denounced. Null when the node did not answer or the
     * address is not a registered baker (the node answers 500 then).
     */
    public static function GetDalParticipation(string $baker): ?array
    {
        if (self::api() === '')
            return null;

        $result = self::GetDataJson(self::api()."/chains/main/blocks/head/context/delegates/$baker/dal_participation");

        return $result->isSuccess() && isset($result->data['delegate_attestable_dal_slots']) ? $result->data : null;
    }

    /**
     * The current protocol's constants, re-read every kCONSTANTS_TTL_SEC. Null when the node never answered; when it
     * stops answering, the last ones read.
     */
    public static function GetConstants(): ?array
    {
        if (time() - self::$constantsAt >= self::kCONSTANTS_TTL_SEC && self::api() !== '')
        {
            $result = self::GetDataJson(self::api()."/chains/main/blocks/head/context/constants");

            if ($result->isSuccess() && is_array($result->data))
            {
                self::$constants   = $result->data;
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
        $result = self::GetDataJson(self::api()."/chains/main/blocks/head/helpers/current_level");
        $perCycle = (int) (self::GetConstants()['blocks_per_cycle'] ?? 0);

        if (!$result->isSuccess() || !isset($result->data['level'], $result->data['cycle'], $result->data['cycle_position']) || $perCycle <= 0)
            return null;

        $head = $result->data;

        return (int) $head['cycle'] + intdiv((int) $head['cycle_position'] + $level - (int) $head['level'], $perCycle);
    }
}
