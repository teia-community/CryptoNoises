<?php

namespace CryptoNoisesTezos\Models;

use CryptoCore\Vss\Vss;
use CryptoCore\Vss\VssModel;

class Blocks extends VssModel
{
    public static function model($className = __CLASS__): self
    {
        return parent::model($className);
    }

    public function tableName(): string
    {
        return 'blocks';
    }

    public function insert(array $item)
    {
        return Vss::dbTezos()->insert($this->tableName(), $item, 'IGNORE');
    }

    /** @param array[] $items */
    public function insertMany(array $items): void
    {
        if (!empty($items))
            Vss::dbTezos()->insertMany($this->tableName(), $items, 'IGNORE');
    }
}
