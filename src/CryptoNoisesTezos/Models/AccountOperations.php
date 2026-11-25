<?php

namespace CryptoNoisesTezos\Models;

use CryptoCore\Vss\Vss;
use CryptoCore\Vss\VssModel;

/**
 * Delegations, staking operations, baker parameter changes and originations (see sql/schema/tezos.sql).
 */
class AccountOperations extends VssModel
{
    const kTYPE_Delegation              = 'delegation';
    const kTYPE_Staking                 = 'staking';
    const kTYPE_SetDelegateParameters   = 'set_delegate_parameters';
    const kTYPE_Origination             = 'origination';

    // TzKT endpoint of each type
    const kTZKT_PATHS = [
        self::kTYPE_Delegation              => 'operations/delegations',
        self::kTYPE_Staking                 => 'operations/staking',
        self::kTYPE_SetDelegateParameters   => 'operations/set_delegate_parameters',
        self::kTYPE_Origination             => 'operations/originations',
    ];

    const kACTION_Stake     = 'stake';
    const kACTION_Unstake   = 'unstake';
    const kACTION_Finalize  = 'finalize';

    public static function model($className = __CLASS__): self
    {
        return parent::model($className);
    }

    public function tableName(): string
    {
        return 'account_operations';
    }

    /** @param array[] $rows */
    public function insertMany(array $rows): void
    {
        // IGNORE: a level that is synced again (sync-range, a replayed batch) writes the same operation ids
        if (!empty($rows))
            Vss::dbTezos()->insertMany($this->tableName(), $rows, 'IGNORE');
    }

    public function getItemsCountInLevel(int $level): int
    {
        $row = Vss::dbTezos()->queryRow("SELECT COUNT(*) AS cnt FROM {$this->tableName()} WHERE level = :level", ['level' => $level]);

        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * Applied operations of [levelFrom .. levelTo] in chain order, with every adid resolved to its address
     * (`*_address` next to each `*_adid`), which is what the bots side works with.
     */
    public function getAppliedInRange(int $levelFrom, int $levelTo): array
    {
        $sql = "
            SELECT ao.*,
                   s.address AS sender_address,
                   a.address AS account_address,
                   b.address AS baker_address,
                   pb.address AS prev_baker_address,
                   c.address AS contract_address
            FROM {$this->tableName()} ao
            JOIN address s       ON s.adid  = ao.sender_adid
            JOIN address a       ON a.adid  = ao.account_adid
            LEFT JOIN address b  ON b.adid  = ao.baker_adid
            LEFT JOIN address pb ON pb.adid = ao.prev_baker_adid
            LEFT JOIN address c  ON c.adid  = ao.contract_adid
            WHERE ao.level BETWEEN :level_from AND :level_to
              AND ao.status = 'applied'
            ORDER BY ao.id
        ";

        return Vss::dbTezos()->query($sql, ['level_from' => $levelFrom, 'level_to' => $levelTo]);
    }
}
