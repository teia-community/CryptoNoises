<?php

namespace CryptoNoisesTezos\Models;

class FixerAccountOperationsSync extends FixerAbstract
{
    public static function model($className = __CLASS__): self
    {
        return parent::model($className);
    }

    public function tableName(): string
    {
        return 'fixer_account_operations_sync';
    }

    /**
     * Lowest level this sync started from, i.e. where its history begins - null while it has never run.
     */
    public function getFirstLevel(): ?int
    {
        $row = $this->dbConnection()->queryRow("SELECT MIN(level) AS level FROM {$this->tableName()}");

        return isset($row['level']) ? (int) $row['level'] : null;
    }

    /**
     * Gives a sync that never ran its start point. Existing installs gain this table empty, and an empty fixer
     * table cannot say what to do next (getNextLevelToProcess() throws).
     */
    public function seedStartLevel(int $level): void
    {
        $this->dbConnection()->insert($this->tableName(), ['level' => $level, 'state' => self::kSTATE_Idle], 'IGNORE');
    }
}
