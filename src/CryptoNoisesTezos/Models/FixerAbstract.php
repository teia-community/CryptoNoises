<?php

namespace CryptoNoisesTezos\Models;

use CryptoCore\Vss\Vss;
use CryptoCore\Vss\VssDb;

abstract class FixerAbstract extends \CryptoCore\Models\FixerAbstract
{
    protected function dbConnection(): VssDb
    {
        return Vss::dbTezos();
    }

    // The indexer also resets levels that ended with an error
    protected function recoverableStates(): array
    {
        return [self::kSTATE_Processing, self::kSTATE_Error];
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    /**
     * Marks levels without items as synced, in one query (batch sync: most levels of the early chain are empty).
     *
     * @param int[] $levels ascending
     */
    public function closeEmptyLevels(array $levels): void
    {
        foreach (array_chunk($levels, 1000) as $chunk)
        {
            $values = join(',', array_map(function (int $level) { return "($level, 'success', 0, NOW(), NOW())"; }, $chunk));

            Vss::dbTezos()->query("
                INSERT INTO {$this->tableName()} (level, state, items_count, ts_scan_started, ts_scan_finished)
                VALUES $values
                ON DUPLICATE KEY UPDATE state = VALUES(state), items_count = 0, ts_scan_finished = NOW()
            ");
        }
    }

    /**
     * Marks many levels as being processed, in one query: the batch equivalent of jobOpen().
     *
     * Crash semantics are the same as jobOpen()'s, only wider: the levels of an interrupted batch stay
     * 'processing' and are recoverable (see recoverableStates()), where before it was one level.
     *
     * @param int[] $levels
     */
    public function openLevels(array $levels): void
    {
        foreach (array_chunk($levels, 1000) as $chunk)
        {
            $values = join(',', array_map(function (int $level) {
                return "($level, '".self::kSTATE_Processing."', NOW())";
            }, $chunk));

            Vss::dbTezos()->query("
                INSERT INTO {$this->tableName()} (level, state, ts_scan_started)
                VALUES $values
                ON DUPLICATE KEY UPDATE state = VALUES(state), ts_scan_started = NOW()
            ");
        }
    }

    /**
     * Closes many levels as synced with their own item counts, in one query: the batch equivalent of
     * jobCloseSuccessfully(). ts_scan_started is left alone, so a level keeps the time its batch opened it.
     *
     * @param array<int, int> $itemsCountByLevel level => items count
     */
    public function closeLevels(array $itemsCountByLevel): void
    {
        if (empty($itemsCountByLevel))
            return;

        $levels = array_keys($itemsCountByLevel);

        foreach (array_chunk($levels, 1000) as $chunk)
        {
            $values = join(',', array_map(function (int $level) use ($itemsCountByLevel) {
                return "($level, '".self::kSTATE_Success."', ".(int) $itemsCountByLevel[$level].", NOW(), NOW())";
            }, $chunk));

            Vss::dbTezos()->query("
                INSERT INTO {$this->tableName()} (level, state, items_count, ts_scan_started, ts_scan_finished)
                VALUES $values
                ON DUPLICATE KEY UPDATE
                    state = VALUES(state), items_count = VALUES(items_count), ts_scan_finished = NOW()
            ");
        }
    }

    /**
     * First successfully synced level in [$levelFrom .. $levelTo] that has items (null = all of them are empty).
     * Lets the analyzers jump over empty levels instead of visiting them one by one.
     */
    public function getNextLevelWithItems(int $levelFrom, int $levelTo): ?int
    {
        $level = Vss::dbTezos()->queryScalar("
            SELECT level
            FROM {$this->tableName()}
            WHERE level BETWEEN :level_from AND :level_to
              AND state = :state_success
              AND items_count > 0
            ORDER BY level
            LIMIT 1
        ", ['level_from' => $levelFrom, 'level_to' => $levelTo, 'state_success' => self::kSTATE_Success]);

        return $level === null || $level === false ? null : (int) $level;
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public function cleanUpLevelAndAllAfter(string $scope, int $level): void
    {
        $sql = "
            INSERT INTO ".FixerTrash::model()->tableName()." (
                id, ts_event, scope,
                level, state, items_count, notice, ts_scan_started, ts_scan_finished )
            SELECT
                NULL, NOW(), :scope,
                level, state, items_count, notice, ts_scan_started, ts_scan_finished
            FROM {$this->tableName()}
            WHERE level >= :level
        ";
        Vss::dbTezos()->query($sql, ['scope' => $scope, 'level' => $level]);

        $sql = "
            DELETE FROM {$this->tableName()}
            WHERE level >= :level
        ";
        Vss::dbTezos()->query($sql, ['level' => $level]);
    }
}
