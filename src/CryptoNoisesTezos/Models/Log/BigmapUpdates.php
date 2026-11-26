<?php

namespace CryptoNoisesTezos\Models\Log;

use CryptoCore\Vss\Vss;
use CryptoCore\Vss\VssModel;

class BigmapUpdates extends VssModel
{
    const kACTION_Allocate  = 'allocate';
    const kACTION_Remove    = 'remove';

    const kACTION_AddKey    = 'add_key';
    const kACTION_UpdateKey = 'update_key';
    const kACTION_RemoveKey = 'remove_key';

    public static function model($className = __CLASS__): self
    {
        return parent::model($className);
    }

    public function tableName(int $level): string
    {
        if ($level < 1000000)
        {
            $group = floor($level / 1000000);
            $group = sprintf("%01d", $group).'xxxxxx';
        }
        else if ($level < 2200000)
        {
            $group = floor($level / 100000);
            $group = sprintf("%02d", $group).'xxxxx';
        }
        else
        {
            $group = floor($level / 10000);
            $group = sprintf("%03d", $group).'xxxx';
        }

        return "bigmap_updates_$group";
    }

    /** @var array<string, true> tables already created by this process (one table covers many levels) */
    private static array $PreparedTables = [];

    public function prepareTable(int $level): void
    {
        $tableName = $this->tableName($level);

        if (isset(self::$PreparedTables[$tableName]))
            return;

        // The template lives in the main Tezos DB (sql/schema/tezos.sql), log tables in the Tezos log DB
        $dbTezos = \VssCfg::cfg()->getDbName('dbTezos');

        $sql = "
            CREATE TABLE IF NOT EXISTS `$tableName`
                LIKE `$dbTezos`.`template__bigmap_updates_0xxxxx`
        ";
        Vss::dbTezosLog()->query($sql);

        self::$PreparedTables[$tableName] = true;
    }

    /**
     * Drops the log tables whose whole level range is below $level (a table may cover up to 1M levels).
     *
     * @return string[] dropped tables
     */
    public function removeTablesBefore(int $level): array
    {
        return LevelRangeTables::DropTablesBefore('bigmap_updates_', $level);
    }

    public function insert(int $level, array $item): int
    {
        return (int) Vss::dbTezosLog()->insert($this->tableName($level), $item, 'IGNORE');
    }

    public function getItemsCountInBlock(int $level): ?int
    {
        return Vss::dbTezosLog()->selectScalar('COUNT(*)', $this->tableName($level), ['level'=>$level]);
    }

    public function selectByLevel(int $level): array
    {
        if ($level < 0)
            return [];

        $sql = "
            SELECT *
            FROM {$this->tableName($level)}
            WHERE level = :level
            ORDER BY id
        ";
        return Vss::dbTezosLog()->query($sql, ['level'=>$level]);
    }

    public function cleanUp(int $level): void
    {
        Vss::dbTezosLog()->delete($this->tableName($level), ['level' => $level]);
    }

    // Batch versions, one statement per log table (a level range can straddle a table boundary)

    /** @param int[] $levels */
    public function cleanUpLevels(array $levels): void
    {
        foreach ($this->groupByTable($levels) as $tableName => $tableLevels)
            Vss::dbTezosLog()->query("DELETE FROM $tableName WHERE level IN (".join(',', $tableLevels).")");
    }

    /** @param array[] $rows rows as insert() takes them, each carrying its own 'level' */
    public function insertMany(array $rows): void
    {
        $byTable = [];

        foreach ($rows as $row)
            $byTable[$this->tableName((int) $row['level'])][] = $row;

        foreach ($byTable as $tableName => $tableRows)
            Vss::dbTezosLog()->insertMany($tableName, $tableRows, 'IGNORE');
    }

    /** @return array<int, int> level => rows stored, 0 for levels without rows */
    public function getItemsCountInBlocks(array $levels): array
    {
        $result = array_fill_keys(array_map('intval', $levels), 0);

        foreach ($this->groupByTable($levels) as $tableName => $tableLevels)
            foreach (Vss::dbTezosLog()->query("SELECT level, COUNT(*) AS n FROM $tableName WHERE level IN (".join(',', $tableLevels).") GROUP BY level") ?: [] as $row)
                $result[(int) $row['level']] = (int) $row['n'];

        return $result;
    }

    private function groupByTable(array $levels): array
    {
        $byTable = [];

        foreach ($levels as $level)
            $byTable[$this->tableName((int) $level)][] = (int) $level;

        return $byTable;
    }
}
