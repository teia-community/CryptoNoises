<?php

namespace CryptoNoisesTezos\Analyzers;

use CryptoNoisesTezos\Models;

class AnalyzeHelper
{
    public static function GetLastFullySyncedBlock(): ?int
    {
        $lastTransactionsSyncedBlock = self::GetLastTransactionsSyncedBlock();
        $lastBigmapsSyncedBlock = self::GetLastBigmapsSyncedBlock();

        if ($lastTransactionsSyncedBlock === null || $lastBigmapsSyncedBlock === null)
            return null;

        return min($lastTransactionsSyncedBlock, $lastBigmapsSyncedBlock);
    }

    //------------------------------------------------------------------------------------------------------------------

    /** The last level the syncs and the analyzers have all finished: notifications need the analyzed token data */
    public static function GetLastFullyAnalyzedBlock(): ?int
    {
        $synced     = self::GetLastFullySyncedBlock();
        $txAnalyzed = Models\Config::model()->getValue('TezosAnalyzer.LastSyncedLevel');
        $bmAnalyzed = self::GetLastBigmapsAnalyzedBlock();

        if ($synced === null || $txAnalyzed === null || $bmAnalyzed === null)
            return null;

        return min($synced, (int) $txAnalyzed, $bmAnalyzed);
    }

    public static function GetLastTransactionsSyncedBlock(): ?int
    {
        return Models\FixerTransactionsSync::model()->getLastSuccessLevelIndex();
    }

    public static function GetLastBigmapsSyncedBlock(): ?int
    {
        return Models\FixerBigmapUpdatesSync::model()->getLastSuccessLevelIndex();
    }

    public static function GetLastBigmapsAnalyzedBlock(): ?int
    {
        return Models\FixerBigmapUpdatesAnalyze::model()->getLastSuccessLevelIndex();
    }
}
