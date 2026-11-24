<?php

namespace CryptoCore\Vss;

use DB;
use VssCfg;

class VssDb
{
    private $dbId;
    private $charset;

    private ?DB\SQL $dbc = null;

    //------------------------------------------------------------------------------------------------------------------

    public function __construct($dbId, $charset = null)
    {
        $this->dbId    = $dbId;
        $this->charset = $charset;
    }

    public function open()
    {
        if ($this->dbc !== null)
            return;

        $config = VssCfg::cfg()->getDbConfig($this->dbId);
        $this->dbc = new DB\SQL($config['connectionString'], $config['username'], $config['password']);

        // Fat-Free keeps a query log in memory, and nothing here ever reads it. Writing it is not free: for every
        // statement it builds a regex per bound parameter and runs preg_replace with all of them over the SQL, to
        // store an interpolated copy of the query. A multi-row insert binds thousands of parameters, so that one
        // statement costs seconds; and because the log only grows, a stage that runs for an hour keeps every
        // query it has ever made. Measured on a 500-row insert: about 3 seconds with the log, 30ms without.
        $this->dbc->log(false);

        if ($this->charset)
            $this->dbc->exec("SET NAMES '{$this->charset}';");
    }

    public function close()
    {
        $this->dbc = null;
    }

    //------------------------------------------------------------------------------------------------------------------

    const kTRANSACTION_STATE_Idle = 0;
    const kTRANSACTION_STATE_Opened = 2;

    private int $transactionState = self::kTRANSACTION_STATE_Idle;

    public function transactionBegin()
    {
        $this->exec('BEGIN');
        $this->transactionState = self::kTRANSACTION_STATE_Opened;
    }

    public function transactionCommit()
    {
        if ($this->transactionState == self::kTRANSACTION_STATE_Opened)
        {
            $this->exec('COMMIT');
            $this->transactionState = self::kTRANSACTION_STATE_Idle;
        }
    }

    public function transactionRollback()
    {
        if ($this->transactionState == self::kTRANSACTION_STATE_Opened)
        {
            $this->exec('ROLLBACK');
            $this->transactionState = self::kTRANSACTION_STATE_Idle;
        }
    }

    //------------------------------------------------------------------------------------------------------------------

    protected function exec($sql, $rules = null)
    {
        $this->open();
        return $this->dbc->exec($sql, $rules);
    }

    //------------------------------------------------------------------------------------------------------------------

    public function query($sql, $rules = null)
    {
        return $this->exec($sql, $rules);
    }

    public function queryRow($sql, $rules = null)
    {
        $data = $this->exec($sql, $rules);

        if (empty($data))
            return null;

        return $data[0];
    }

    public function queryColumn($sql, $rules = null): array
    {
        $data = $this->exec($sql, $rules);

        if (empty($data))
            return [];

        $result = array();

        foreach ($data as $item)
        {
            $result[] = current($item);
        }

        return $result;
    }

    public function queryScalar($sql, $rules = null)
    {
        $data = $this->exec($sql, $rules);

        if (empty($data))
            return null;

        return current($data[0]);
    }

    //------------------------------------------------------------------------------------------------------------------

    private function buildSelectQuery(string $selectFields, string $tableName, ?array $filterFields = null): array
    {
        $sqlWhere = array();
        $sqlData = array();

        if (is_array($filterFields))
            foreach ($filterFields as $key => $value)
            {
                if (is_object($value) && get_class($value) == 'CryptoCore\Vss\VssDbExp')
                {
                    $sqlWhere[] = "`$key` = " . $value->sql;
                }
                else
                {
                    $sqlWhere[] = "`$key` = :where_$key";
                    $sqlData[':where_' . $key] = $value;
                }
            }

        if (empty($sqlWhere))
            $sqlWhere[] = 'TRUE';

        $sql = "SELECT $selectFields FROM $tableName WHERE " . join(' AND ', $sqlWhere);

        return [$sql, $sqlData];
    }

    public function selectRow(string $selectFields, string $tableName, ?array $filterFields = null)
    {
        list ($sql, $sqlData) = $this->buildSelectQuery($selectFields, $tableName, $filterFields);

        return $this->queryRow($sql, $sqlData);
    }

    public function selectColumn(string $selectFields, string $tableName, ?array $filterFields = null): array
    {
        list ($sql, $sqlData) = $this->buildSelectQuery($selectFields, $tableName, $filterFields);

        return $this->queryColumn($sql, $sqlData);
    }

    public function selectScalar(string $selectFields, string $tableName, ?array $filterFields = null)
    {
        list ($sql, $sqlData) = $this->buildSelectQuery($selectFields, $tableName, $filterFields);

        return $this->queryScalar($sql, $sqlData);
    }

    //------------------------------------------------------------------------------------------------------------------

    public function insert($tableName, $fields, $extendedParams = '')
    {
        $fieldsKeys = array();
        $valuesKeys = array();
        $valuesData = array();

        foreach ($fields as $key => $value)
        {
            if (is_object($value) && get_class($value) == 'CryptoCore\Vss\VssDbExp')
            {
                $fieldsKeys[] = "`$key`";
                $valuesKeys[] = $value->sql;
            }
            else
            {
                $fieldsKeys[] = "`$key`";
                $valuesKeys[] = ':' . $key;
                $valuesData[':' . $key] = $value;
            }
        }

        $sql = " INSERT $extendedParams INTO $tableName (" . join(',', $fieldsKeys) . ") 
            VALUES (" . join(',', $valuesKeys) . ") ";

        return $this->exec($sql, $valuesData);
    }

    /**
     * Inserts many rows with one statement per chunk, so a batch of block levels costs a few round trips instead of
     * one per row. A VssDbExp value is inlined per row exactly as insert() does.
     *
     * Rows do not have to carry the same keys: callers build a row by setting only the fields that apply, so one
     * row can have an `entrypoint` and the next none. Rows are therefore grouped by their exact set of keys and a
     * statement is written per group, which is what makes each row's columns the ones insert() would have named.
     * Taking the first row's keys for the whole set instead would quietly drop the extra fields of every other
     * shape, and the remaining columns would still be stored - so the loss would not show up as an error.
     *
     * The chunk size is a compromise: large enough that the round trips disappear, small enough that the statement
     * stays well inside max_allowed_packet even with json columns in the row.
     *
     * @param array[] $rows
     * @param string   $suffixSql appended after VALUES, for an ON DUPLICATE KEY UPDATE clause
     * @return int number of statements executed (0 for an empty set)
     */
    public function insertMany($tableName, array $rows, $extendedParams = '', int $chunkSize = 500, string $suffixSql = '')
    {
        if (empty($rows))
            return 0;

        $byShape = array();

        foreach ($rows as $row)
            $byShape[join("\0", array_keys($row))][] = $row;

        $chunks = 0;

        foreach ($byShape as $shapeRows)
            $chunks += $this->insertManyOfOneShape($tableName, $shapeRows, $extendedParams, $chunkSize, $suffixSql);

        return $chunks;
    }

    /** @param array[] $rows all carrying the same keys in the same order */
    private function insertManyOfOneShape($tableName, array $rows, $extendedParams, int $chunkSize, string $suffixSql)
    {
        $keys       = array_keys(reset($rows));
        $fieldsSql  = join(',', array_map(function ($key) { return "`$key`"; }, $keys));
        $chunks     = 0;

        foreach (array_chunk($rows, $chunkSize) as $chunkIndex => $chunk)
        {
            $tuples     = array();
            $valuesData = array();

            foreach ($chunk as $rowIndex => $row)
            {
                $placeholders = array();

                foreach ($keys as $key)
                {
                    $value = $row[$key] ?? null;

                    if (is_object($value) && get_class($value) == 'CryptoCore\Vss\VssDbExp')
                    {
                        $placeholders[] = $value->sql;
                        continue;
                    }

                    // Named rather than positional: the rest of this class uses named parameters, and a name per
                    // row and column cannot be thrown off by the order the driver binds them in.
                    $name = ':r'.$rowIndex.'_'.count($placeholders);
                    $placeholders[] = $name;
                    $valuesData[$name] = $value;
                }

                $tuples[] = '('.join(',', $placeholders).')';
            }

            $sql = " INSERT $extendedParams INTO $tableName ($fieldsSql) VALUES ".join(',', $tuples)." $suffixSql ";

            $this->exec($sql, $valuesData);
            $chunks++;
        }

        return $chunks;
    }

    public function insertAndGetId($tableName, $fields, $extendedParams = '')
    {
        $this->insert($tableName, $fields, $extendedParams);
        return $this->lastInsertId();
    }

    public function insertOrUpdate($tableName, $fields, $updateFields)
    {
        $fieldsKeys = array();
        $valuesKeys = array();
        $valuesData = array();

        foreach ($fields as $key => $value)
        {
            if (is_object($value) && get_class($value) == 'CryptoCore\Vss\VssDbExp')
            {
                $fieldsKeys[] = "`$key`";
                $valuesKeys[] = $value->sql;
            }
            else
            {
                $fieldsKeys[] = "`$key`";
                $valuesKeys[] = ':' . $key;
                $valuesData[':' . $key] = $value;
            }
        }

        $sqlUpdate = array();

        foreach ($updateFields as $key => $value)
        {
            if (is_object($value) && get_class($value) == 'CryptoCore\Vss\VssDbExp')
            {
                $sqlUpdate[] = "`$key` = " . $value->sql;
            }
            else
            {
                $sqlUpdate[] = "`$key` = :update_$key";
                $valuesData[':update_' . $key] = $value;
            }
        }

        $sql = "INSERT INTO $tableName (" . join(',', $fieldsKeys) . ") " .
            " VALUES (" . join(',', $valuesKeys) . ") " . " ON DUPLICATE KEY UPDATE " . join(',', $sqlUpdate);

        return $this->exec($sql, $valuesData);
    }

    public function lastInsertId()
    {
        if ($this->dbc === null)
            return null;

        return $this->dbc->lastInsertId();
    }

    //------------------------------------------------------------------------------------------------------------------

    public function update($tableName, $updateFields, $filterFields)
    {
        $sqlSet = array();
        $sqlWhere = array();
        $sqlData = array();

        foreach ($updateFields as $key => $value)
        {
            if (is_object($value) && get_class($value) == 'CryptoCore\Vss\VssDbExp')
            {
                $sqlSet[] = "`$key` = " . $value->sql;
            }
            else
            {
                $sqlSet[] = "`$key` = :set_$key";
                $sqlData[':set_' . $key] = $value;
            }
        }

        foreach ($filterFields as $key => $value)
        {
            if (is_object($value) && get_class($value) == 'CryptoCore\Vss\VssDbExp')
            {
                $sqlWhere[] = "`$key` = " . $value->sql;
            }
            else
            {
                $sqlWhere[] = "`$key` = :where_$key";
                $sqlData[':where_' . $key] = $value;
            }
        }

        $sql = "UPDATE $tableName SET " . join(' , ', $sqlSet) . " WHERE " . join(' AND ', $sqlWhere);

        return $this->exec($sql, $sqlData);
    }

    //------------------------------------------------------------------------------------------------------------------

    public function delete($tableName, $filterFields)
    {
        $sqlWhere = array();
        $sqlData = array();

        foreach ($filterFields as $key => $value)
        {
            if (is_object($value) && get_class($value) == 'CryptoCore\Vss\VssDbExp')
            {
                $sqlWhere[] = "`$key` = " . $value->sql;
            }
            else
            {
                $sqlWhere[] = "`$key` = :where_$key";
                $sqlData[':where_' . $key] = $value;
            }
        }

        $sql = "DELETE FROM $tableName WHERE " . join(' AND ', $sqlWhere);

        return $this->exec($sql, $sqlData);
    }
}
