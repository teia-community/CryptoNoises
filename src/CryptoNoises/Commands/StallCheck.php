<?php

namespace CryptoNoises\Commands;

use CryptoCore\Vss\Vss;
use CryptoNoises\Config;
use CryptoNoises\Core\Debugger;
use CryptoNoises\Models;
use CryptoNoisesTezos\Models as TezosModels;
use CryptoNoisesTezos\Vendors\Tzkt;
use Throwable;

/**
 * Alerts (OpsAlerts) when something stops moving without an error: a stage not caught up whose level has not
 * changed for kSTALL_SEC, a notification waiting longer than kSTALL_SEC, or a TzKT not at the head.
 * Run every few minutes; the last level and when it last changed are kept in config.
 */
class StallCheck extends AbstractCommand
{
    const kSTALL_SEC        = 900;
    const kCAUGHT_UP_LEVELS = 10;
    const kCFG_SNAPSHOT     = 'StallCheck.Snapshot';

    public function run(): void
    {
        $state = Tzkt::GetState();

        if (($reason = Tzkt::NotAtHeadReason($state, self::kSTALL_SEC)) !== null)
            Debugger::Error("Stalled: $reason");

        $head     = $state['level'] ?? null;
        $synced   = \CryptoNoises\GatewayTezos\Gateway::GetLastFullySyncedBlock();
        $analyzed = \CryptoNoises\GatewayTezos\Gateway::GetLastFullyAnalyzedBlock();

        // stage => [level, the level it works towards]
        $stages = [
            'transaction sync'      => [TezosModels\FixerTransactionsSync::model()->getLastSuccessLevelIndex(), $head],
            'bigmap sync'           => [TezosModels\FixerBigmapUpdatesSync::model()->getLastSuccessLevelIndex(), $head],
            'account-ops sync'      => [TezosModels\FixerAccountOperationsSync::model()->getLastSuccessLevelIndex(), $head],
            'transaction analyzer'  => [TezosModels\Config::model()->getValue('TezosAnalyzer.LastSyncedLevel'), $synced],
            'bigmap analyzer'       => [TezosModels\FixerBigmapUpdatesAnalyze::model()->getLastSuccessLevelIndex(), $synced],
            'wallet events'         => [Models\FixerTezosTransactionsAnalyze::model()->getLastSuccessLevelIndex(), $analyzed],
            'account events'        => [Models\Config::model()->getValue('TezosAccountOpsAnalyzer.LastSyncedLevel'), $analyzed],
        ];

        $snapshot = json_decode((string) Models\Config::model()->getValue(self::kCFG_SNAPSHOT), true) ?: [];

        foreach ($stages as $name => [$level, $target])
        {
            if ($level === null || $target === null)
                continue;   // not set up here

            $level = (int) $level;

            if (!isset($snapshot[$name]) || $snapshot[$name][0] !== $level)
            {
                $snapshot[$name] = [$level, time()];
                continue;
            }

            $still = time() - $snapshot[$name][1];

            if ($level < (int) $target - self::kCAUGHT_UP_LEVELS && $still >= self::kSTALL_SEC)
                Debugger::Error("Stalled: $name at level $level for ".intdiv($still, 60)." min (target $target)");
        }

        Models\Config::model()->setValue(self::kCFG_SNAPSHOT, json_encode($snapshot));

        $this->checkQueues();
    }

    /** A notification waiting this long means its notifier is not running or stuck */
    private function checkQueues(): void
    {
        foreach ([Config::kPLATFORM_Telegram, Config::kPLATFORM_Discord, Config::kPLATFORM_Matrix] as $platform)
        {
            try
            {
                $row = Vss::db()->queryRow("SELECT COUNT(*) n, TIMESTAMPDIFF(SECOND, MIN(COALESCE(notify_after, ts_created)), NOW()) age
                    FROM ".Models\EventsNotifications::model()->tableName($platform)."
                    WHERE notify_status = 'idle' AND (notify_after IS NULL OR notify_after <= NOW())");

                if (!empty($row['n']) && (int) $row['age'] >= self::kSTALL_SEC)
                    Debugger::Error("Stalled: $platform notifications waiting for ".intdiv((int) $row['age'], 60)." min ({$row['n']} queued)");
            }
            catch (Throwable $e)
            {
                Debugger::Error("Stall check of the $platform queue failed: ".$e->getMessage());
            }
        }
    }
}
