<?php

namespace CryptoNoisesTezos\Models;

use CryptoCore\Vss\Vss;

class FixerBigmapUpdatesSync extends FixerAbstract
{
    public static function model($className = __CLASS__): self
    {
        return parent::model($className);
    }

    public function tableName(): string
    {
        return 'fixer_bigmap_updates_sync';
    }

    public function getNextJobToCheck(int $threadId, int $offset): ?array
    {
        $fieldKey = 'items_count_check'.$threadId;

        // FORCE INDEX is not an optimisation here, it is the difference between a seek and a table scan.
        //
        // The index on $fieldKey carries the primary key (level) as its suffix, so "$fieldKey IS NULL ORDER BY
        // level ASC LIMIT 1" is one seek into it. Left to itself the optimizer instead range-scans the PRIMARY
        // key, because `level <= ...` is a range and walking the PK appears to satisfy the ORDER BY for free. It
        // then filters row by row - and every already-checked level has to be skipped before the first match, so
        // the cost is the *length of the checked prefix*, which grows as the check advances. That makes the whole
        // stage quadratic: measured at 220ms per call with a 75k-level prefix, against 2.6ms with the index
        // forced, and it would keep getting worse as the prefix lengthens.
        //
        // The index is part of sql/schema/tezos.sql and is named after the column, so $fieldKey names it too.
        $sql = "
            SELECT *
            FROM {$this->tableName()} FORCE INDEX ($fieldKey)
            WHERE   level <= (SELECT MAX(level) FROM {$this->tableName()}) - :offset
                AND $fieldKey is null
            ORDER BY level ASC 
            LIMIT 1
        ";

        return Vss::dbTezos()->queryRow($sql, ['offset' => $offset]);
    }

    public function fixRecordsCountOnCheck(int $threadId, int $level, int $recordsCount): void
    {
        Vss::dbTezos()->update($this->tableName(),
            [
                'items_count_check'.$threadId => $recordsCount,
            ],
            ['level' => $level]);
    }
}
