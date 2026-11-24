<?php

namespace CryptoNoisesTezos\Models;

use CryptoCore\Vss\Vss;
use CryptoCore\Vss\VssModel;

class TransactionsEntrypoints extends VssModel
{
    public static function model($className = __CLASS__): self
    {
        return parent::model($className);
    }

    public function tableName(): string
    {
        return 'transactions_entrypoints';
    }

    public function fix(string $address, string $entrypoint, int $level)
    {
        $sql = "
            INSERT INTO {$this->tableName()} 
                (target_address, entrypoint, level_min, level_max, calls_count)
            VALUES (:address, :entrypoint, :level, :level, 1)
            ON DUPLICATE KEY UPDATE 
                level_min = LEAST(level_min, :level),
                level_max = GREATEST(level_max, :level),
                calls_count = calls_count + 1;
        ";

        return Vss::dbTezos()->query($sql, ['address' => $address, 'entrypoint' => $entrypoint, 'level' => $level]);
    }

    /**
     * The batch form of fix(): takes the calls of a whole level range already aggregated per
     * (target_address, entrypoint) and applies them in one statement.
     *
     * Aggregating in PHP first is what makes the result identical to calling fix() once per transaction:
     * calls_count adds the batch's count instead of 1, and level_min / level_max still take the lowest and
     * highest level seen. Each key appears once per statement, so no chunk can double-count.
     *
     * @param array[] $calls rows of [target_address, entrypoint, level_min, level_max, calls_count]
     */
    public function fixMany(array $calls): void
    {
        if (empty($calls))
            return;

        Vss::dbTezos()->insertMany($this->tableName(), $calls, '', 500, "
            ON DUPLICATE KEY UPDATE
                level_min   = LEAST(level_min, VALUES(level_min)),
                level_max   = GREATEST(level_max, VALUES(level_max)),
                calls_count = calls_count + VALUES(calls_count)
        ");
    }
}
