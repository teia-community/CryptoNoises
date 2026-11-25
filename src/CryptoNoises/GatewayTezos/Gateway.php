<?php

namespace CryptoNoises\GatewayTezos;

use CryptoNoisesTezos\Models;

class Gateway
{
    public static function GetLastFullySyncedBlock(): ?int
    {
        return \CryptoNoisesTezos\Analyzers\AnalyzeHelper::GetLastFullySyncedBlock();
    }

    //------------------------------------------------------------------------------------------------------------------

    public static function GetLastTransactionsSyncedBlock(): ?int
    {
        return \CryptoNoisesTezos\Analyzers\AnalyzeHelper::GetLastTransactionsSyncedBlock();
    }

    public static function GetLastTransactionsAnalyzedBlock(): ?int
    {
        return Models\Config::model()->getValue('TezosAnalyzer.LastSyncedLevel');
    }

    public static function GetLastBigmapsSyncedBlock(): ?int
    {
        return \CryptoNoisesTezos\Analyzers\AnalyzeHelper::GetLastBigmapsSyncedBlock();
    }

    public static function GetLastBigmapsAnalyzedBlock(): ?int
    {
        return \CryptoNoisesTezos\Analyzers\AnalyzeHelper::GetLastBigmapsAnalyzedBlock();
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
    // Account operations: delegations, staking, baker parameter changes, originations

    public static function GetLastAccountOperationsSyncedBlock(): ?int
    {
        return Models\FixerAccountOperationsSync::model()->getLastSuccessLevelIndex();
    }

    /** Where the account-operations sync started, i.e. the first level there is anything to analyze */
    public static function GetAccountOperationsFirstLevel(): ?int
    {
        return Models\FixerAccountOperationsSync::model()->getFirstLevel();
    }

    /** Applied operations of [levelFrom .. levelTo], chain order, with addresses resolved */
    public static function GetAccountOperations(int $levelFrom, int $levelTo): array
    {
        return Models\AccountOperations::model()->getAppliedInRange($levelFrom, $levelTo);
    }

    //------------------------------------------------------------------------------------------------------------------

    public static function GetTransactions(int $level): ?array
    {
        $transactions = Models\Log\Transactions::model()->selectByLevel_AppliedOnly($level);

        array_walk($transactions, function (&$tr)
        {
            // Repack the row: renames some keys and documents exactly which fields are used
            $tr = [
                'id'            => $tr['id'],
                'level'         => $tr['level'],
                'counter'       => $tr['counter'],
                'hash'          => $tr['hash'],
                'nonce'         => $tr['nonce'],
                'timestamp'     => $tr['timestamp'],
                'initiator'     => $tr['initiator_address'],
                'sender'        => $tr['sender_address'],
                'target'        => $tr['target_address'],
                'amount'        => $tr['amount'],
                'status'        => $tr['status'],
                'entrypoint'    => $tr['entrypoint'],
                'parameter'     => json_decode($tr['parameter'], true),
            ];
        });

        return $transactions;
    }
}
