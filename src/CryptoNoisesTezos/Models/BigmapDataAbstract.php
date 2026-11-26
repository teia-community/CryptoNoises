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
    // Batching, for the bigmap analyzer: a level's upserts go out as multi-row INSERT ... ON DUPLICATE KEY UPDATE and
    // its removes as UPDATE ... WHERE (key) IN (...), instead of one round trip each. A table's pending changes all
    // have distinct keys of the same shape, so they touch distinct rows and do not depend on each other. They are
    // written before a change to a pending key or with another key shape, before a read or a partial-key remove,
    // and at the end of the level - so every key still sees its changes in order.

    const kBATCH_MAX_ROWS = 500;

    private static bool $batching = false;

    /** @var array<string, array{rows: array[], pkCols: string[][], keys: array<string, true>, shape: string, removes: array}> table => pending */
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

        foreach ($pending['removes'] ?? [] as $group)
            self::WriteRemoves($table, $group);

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

    /** One UPDATE per chunk for removes sharing their SET (same level and extra columns) and key columns */
    private static function WriteRemoves(string $table, array $group): void
    {
        $set = [];
        $params = [];

        foreach ($group['set'] as $column => $value)
        {
            if ($value instanceof VssDbExp)
                $set[] = "`$column` = ".$value->sql;
            else
            {
                $set[] = "`$column` = :s_$column";
                $params[":s_$column"] = $value;
            }
        }

        $columns = '('.join(', ', array_map(fn ($c) => "`$c`", $group['pkCols'])).')';

        foreach (array_chunk($group['tuples'], self::kBATCH_MAX_ROWS) as $chunk)
        {
            $tuples = [];
            $chunkParams = $params;

            foreach ($chunk as $i => $tuple)
            {
                $names = [];

                foreach (array_values($tuple) as $j => $value)
                {
                    $names[] = ":k{$i}_$j";
                    $chunkParams[":k{$i}_$j"] = $value;
                }

                $tuples[] = '('.join(', ', $names).')';
            }

            Vss::dbTezos()->query("UPDATE `$table` SET ".join(', ', $set)." WHERE $columns IN (".join(', ', $tuples).")", $chunkParams);
        }
    }

    /**
     * Makes room for a change to $primaryKey in this table's pending batch: writes the batch when the key is already
     * in it, or has another shape than the keys in it. @return string the key's hash
     */
    private function prepareKey(array $primaryKey): string
    {
        $table  = $this->tableName();
        $pkHash = json_encode($primaryKey);
        $shape  = join("\0", array_keys($primaryKey));

        if (isset(self::$pending[$table]['keys'][$pkHash])
            || (isset(self::$pending[$table]['shape']) && self::$pending[$table]['shape'] !== $shape)
            || count(self::$pending[$table]['keys'] ?? []) >= self::kBATCH_MAX_ROWS)
            self::FlushTable($table);

        self::$pending[$table]['shape']         = $shape;
        self::$pending[$table]['keys'][$pkHash] = true;

        return $pkHash;
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
            $table = $this->tableName();
            $this->prepareKey($primaryKey);

            self::$pending[$table]['rows'][]   = $dataInsert;
            self::$pending[$table]['pkCols'][] = array_keys($primaryKey);
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
        $dataUpdate                 = $saveData;
        $dataUpdate['state']        = self::kSTATE_Removed;
        $dataUpdate['last_level']   = $level;
        $dataUpdate['updates']      = VssDbExp::query('updates+1');

        // A single-column key is a whole bigmap (removeAllInBigmap): written at once, after what is pending
        if (self::$batching && count($primaryKey) > 1)
        {
            $table = $this->tableName();
            $this->prepareKey($primaryKey);

            $group = json_encode(array_map(fn ($v) => $v instanceof VssDbExp ? 'exp:'.$v->sql : $v, $dataUpdate));
            self::$pending[$table]['removes'][$group]['set']      = $dataUpdate;
            self::$pending[$table]['removes'][$group]['pkCols']   = array_keys($primaryKey);
            self::$pending[$table]['removes'][$group]['tuples'][] = $primaryKey;
            return;
        }

        $this->flushThisTable();

        Vss::dbTezos()->update($this->tableName(), $dataUpdate, $primaryKey);
    }
}
