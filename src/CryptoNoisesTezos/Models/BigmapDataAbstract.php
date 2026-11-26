<?php

namespace CryptoNoisesTezos\Models;

use CryptoCore\Vss\Vss;
use CryptoCore\Vss\VssModel;
use CryptoCore\Vss\VssDbExp;

abstract class BigmapDataAbstract extends VssModel
{
    const kSTATE_Active     = 'active';
    const kSTATE_Removed    = 'removed';

    abstract public function tableName(): string;

    //------------------------------------------------------------------------------------------------------------------
    // Batching, for the bigmap analyzer: a level's upserts go out as multi-row INSERT ... ON DUPLICATE KEY UPDATE
    // instead of one round trip each. Rows of one statement have distinct keys, so they do not depend on each other;
    // a table's pending rows are written before a second upsert of a pending key, before any remove or read of that
    // table, and at the end of the level - so every key still sees its changes in order.

    const kBATCH_MAX_ROWS = 500;

    private static bool $batching = false;

    /** @var array<string, array{rows: array[], pkCols: string[][], keys: array<string, true>}> table => pending */
    private static array $pending = [];

    public static function BeginBatch(): void
    {
        self::$batching = true;
        self::$pending  = [];
    }

    /** Writes everything pending; call before the level's transaction commits */
    public static function FlushBatch(): void
    {
        foreach (array_keys(self::$pending) as $table)
            self::FlushTable($table);
    }

    /** Stops batching; pending rows are dropped (after FlushBatch, or with the rolled back transaction) */
    public static function EndBatch(): void
    {
        self::$batching = false;
        self::$pending  = [];
    }

    private static function FlushTable(string $table): void
    {
        $pending = self::$pending[$table] ?? null;
        unset(self::$pending[$table]);

        if (empty($pending['rows']))
            return;

        // One statement per row shape: the update clause names the shape's own columns
        $byShape = [];

        foreach ($pending['rows'] as $i => $row)
        {
            $shape = join("\0", array_keys($row)).'|'.join("\0", $pending['pkCols'][$i]);
            $byShape[$shape]['rows'][] = $row;
            $byShape[$shape]['pkCols'] = $pending['pkCols'][$i];
        }

        foreach ($byShape as $group)
        {
            $update = [];

            foreach (array_keys(reset($group['rows'])) as $column)
            {
                if (in_array($column, $group['pkCols'], true) || $column == 'first_level')
                    continue;

                $update[] = $column == 'updates' ? '`updates` = `updates` + 1' : "`$column` = VALUES(`$column`)";
            }

            Vss::dbTezos()->insertMany($table, $group['rows'], '', self::kBATCH_MAX_ROWS, 'ON DUPLICATE KEY UPDATE '.join(', ', $update));
        }
    }

    private function flushThisTable(): void
    {
        if (self::$batching)
            self::FlushTable($this->tableName());
    }

    //------------------------------------------------------------------------------------------------------------------

    public function getItemByPk(array $primaryKey): ?array
    {
        $this->flushThisTable();
        return Vss::dbTezos()->selectRow('*', $this->tableName(), $primaryKey);
    }

    public function addOrUpdateByPk(array $primaryKey, int $level, array $saveData)
    {
        $dataInsert                 = array_merge($primaryKey, $saveData);
        $dataInsert['state']        = self::kSTATE_Active;
        $dataInsert['first_level']  = $level;
        $dataInsert['last_level']   = $level;
        $dataInsert['updates']      = 1;

        if (self::$batching)
        {
            $table  = $this->tableName();
            $pkHash = json_encode($primaryKey);

            if (isset(self::$pending[$table]['keys'][$pkHash]) || count(self::$pending[$table]['rows'] ?? []) >= self::kBATCH_MAX_ROWS)
                self::FlushTable($table);

            self::$pending[$table]['rows'][]         = $dataInsert;
            self::$pending[$table]['pkCols'][]       = array_keys($primaryKey);
            self::$pending[$table]['keys'][$pkHash]  = true;
            return;
        }

        $dataUpdate                 = $saveData;
        $dataUpdate['state']        = self::kSTATE_Active;
        $dataUpdate['last_level']   = $level;
        $dataUpdate['updates']      = VssDbExp::query('updates+1');

        Vss::dbTezos()->insertOrUpdate($this->tableName(), $dataInsert, $dataUpdate);
    }

    public function removeByPk(array $primaryKey, int $level, array $saveData = []): void
    {
        $this->flushThisTable();

        $dataUpdate                 = $saveData;
        $dataUpdate['state']        = self::kSTATE_Removed;
        $dataUpdate['last_level']   = $level;
        $dataUpdate['updates']      = VssDbExp::query('updates+1');

        Vss::dbTezos()->update($this->tableName(), $dataUpdate, $primaryKey);
    }
}
