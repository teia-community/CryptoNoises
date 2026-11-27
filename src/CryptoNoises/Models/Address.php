<?php

namespace CryptoNoises\Models;

use CryptoCore\Vss\Vss;
use CryptoCore\Vss\VssModel;

class Address extends VssModel
{
    public static function model($className=__CLASS__): self
    {
        return parent::model($className);
    }

    public function tableName(): string
    {
        return 'address';
    }

    public function getItemByAdid(int $adid): ?array
    {
        return Vss::db()->selectRow('*', $this->tableName(), ['adid' => $adid]);
    }

    public function getItemByAddress(?string $address): ?array
    {
        return $address
            ? Vss::db()->selectRow('*', $this->tableName(), ['address' => $address])
            : null;
    }

    /** @return array<string, int> adid by address, for the ones that exist */
    public function getAdidsByAddresses(array $addresses): array
    {
        $result = [];

        foreach (array_chunk(array_values(array_unique($addresses)), 500) as $chunk)
        {
            $params = [];
            foreach ($chunk as $i => $address)
                $params["a$i"] = $address;

            $rows = Vss::db()->query("SELECT adid, address FROM {$this->tableName()} WHERE address IN (:".join(', :', array_keys($params)).")", $params);

            foreach ($rows as $row)
                $result[$row['address']] = (int) $row['adid'];
        }

        return $result;
    }

    public function create(string $address): void
    {
        Vss::db()->insert($this->tableName(), [
            'address'    => $address,
            'address_ci' => $address,
        ], 'IGNORE');
    }
}
