<?php

namespace CryptoNoisesTezos\Models\Log;

use CryptoCore\Vss\Vss;
use CryptoCore\Vss\VssModel;

class Transactions extends VssModel
{
    const kSTATUS_Applied = 'applied';

    public static function model($className=__CLASS__): self
    {
        return parent::model($className);
    }

    public function tableName(int $level): string
    {
        if ($level < 1000000)
        {
            $group = floor($level / 100000);
            $group = sprintf("%02d", $group).'xxxxx';
        }
        else
        {
            $group = floor($level / 10000);
            $group = sprintf("%03d", $group).'xxxx';
        }
        return "transactions_{$group}";
    }

    public function selectByLevel_AppliedOnly(int $level): array
    {
        if ($level < 0)
            return [];

        $sql = "SELECT * FROM {$this->tableName($level)} WHERE level = :level AND status = :status ORDER BY id";

        return Vss::dbTezosLog()->query($sql, ['level'=>$level, 'status'=>self::kSTATUS_Applied]);
    }

    public function getItemsCountInBlock(int $level): ?int
    {
        return Vss::dbTezosLog()->selectScalar('COUNT(*)', $this->tableName($level), ['level'=>$level]);
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
                LIKE `$dbTezos`.`template__transactions_0xxxx`
        ";
        Vss::dbTezosLog()->query($sql);

        self::$PreparedTables[$tableName] = true;
    }

    /**
     * Drops the log tables whose whole level range is below $level (a table covers 10000 or 100000 levels).
     *
     * @return string[] dropped tables
     */
    public function removeTablesBefore(int $level): array
    {
        return LevelRangeTables::DropTablesBefore('transactions_', $level);
    }

    public function insert(int $level, array $item): int
    {
        return (int) Vss::dbTezosLog()->insert($this->tableName($level), $item, 'IGNORE');
    }

    /**
     * Inserts the rows of several levels with one statement per log table (a level range can straddle a table
     * boundary, so the rows are grouped by the table their level belongs to).
     *
     * @param array[] $rows rows as insert() takes them, each carrying its own 'level'
     */
    public function insertMany(array $rows): void
    {
        if (empty($rows))
            return;

        $byTable = [];

        foreach ($rows as $row)
            $byTable[$this->tableName((int) $row['level'])][] = $row;

        foreach ($byTable as $tableName => $tableRows)
            Vss::dbTezosLog()->insertMany($tableName, $tableRows, 'IGNORE');
    }

    /**
     * Rows stored per level for the given levels: the batch equivalent of getItemsCountInBlock(), so the in/out
     * check costs one query per log table instead of one per level.
     *
     * @param int[] $levels
     * @return array<int, int> level => count, levels with no rows included as 0
     */
    public function getItemsCountInBlocks(array $levels): array
    {
        $result = array_fill_keys(array_map('intval', $levels), 0);

        $byTable = [];

        foreach ($levels as $level)
            $byTable[$this->tableName((int) $level)][] = (int) $level;

        foreach ($byTable as $tableName => $tableLevels)
        {
            $rows = Vss::dbTezosLog()->query("
                SELECT level, COUNT(*) AS items_count
                FROM $tableName
                WHERE level IN (".join(',', $tableLevels).")
                GROUP BY level
            ");

            foreach ($rows ?: [] as $row)
                $result[(int) $row['level']] = (int) $row['items_count'];
        }

        return $result;
    }

    //------------------------------------------------------------------------------------------------------------------
    // Helpers

    // Neither 'counter' nor 'hash' is unique within a level,
    // so group by counter + hash
    public static function PrepareAndGroupTransactionsInBlocks(array $items): array
    {
        $data = [];
        foreach ($items as $item)
        {
            $item['parameter'] = json_decode($item['parameter'], true);

            $data[$item['counter'].':'.$item['hash']][] = $item;
        }
        return $data;
    }
}
