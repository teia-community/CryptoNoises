<?php

namespace CryptoNoisesTezos\Models;

use CryptoNoisesTezos\Core\Log;
use CryptoCore\Vss\Vss;
use CryptoCore\Vss\VssModel;

class Address extends VssModel
{
    const TYPE_Wallet                           = 'wallet';
    const TYPE_Contract                         = 'contract';
    const TYPE_Undefined                        = null;

    public static function model($className = __CLASS__): self
    {
        return parent::model($className);
    }

    public function tableName(): string
    {
        return 'address';
    }

    public function getItemByAdid(int $adid): ?array
    {
        $sql = "SELECT * FROM {$this->tableName()} WHERE adid = :adid";

        return Vss::dbTezos()->queryRow($sql, ['adid'=>$adid]);
    }

    public function getItemByAddress(string $address): ?array
    {
        $sql = "SELECT * FROM {$this->tableName()} WHERE address = :address";

        return Vss::dbTezos()->queryRow($sql, ['address'=>$address]);
    }

    /**
     * adids of the given addresses in one query per chunk: resolving them one at a time is the single biggest
     * source of round trips when a whole level range is synced at once.
     *
     * @param string[] $addresses
     * @return array<string, int> address => adid, missing addresses simply absent
     */
    public function getAdidsByAddresses(array $addresses): array
    {
        $result = [];

        foreach (array_chunk(array_values(array_unique($addresses)), 1000) as $chunk)
        {
            $names  = [];
            $params = [];

            foreach ($chunk as $i => $address)
            {
                $names[]            = ":a$i";
                $params["a$i"]      = $address;
            }

            $rows = Vss::dbTezos()->query("
                SELECT adid, address
                FROM {$this->tableName()}
                WHERE address IN (".join(',', $names).")
            ", $params);

            foreach ($rows ?: [] as $row)
                $result[(string) $row['address']] = (int) $row['adid'];
        }

        return $result;
    }

    /**
     * Creates the given addresses in one statement per chunk. Rows are built by the same code create() uses, so a
     * batch stores the same address_type, address_ci and notice a one-by-one run would.
     *
     * @param string[] $addresses
     */
    public function createMany(array $addresses): void
    {
        $rows = [];

        foreach (array_unique($addresses) as $address)
            $rows[] = $this->buildInsertRow($address);

        if ($rows)
            Vss::dbTezos()->insertMany($this->tableName(), $rows, 'IGNORE');
    }

    public function create(string $address)
    {
        return Vss::dbTezos()->insert($this->tableName(), $this->buildInsertRow($address), 'IGNORE');
    }

    private function buildInsertRow(string $address): array
    {
        $type = self::TYPE_Undefined;

        if (strlen($address) > 36) // longer than any Tezos address
        {
            Log::Fatal('Model-Address', 'create', "Address value too long", [
                'address' => $address,
            ]);

            $notice  = $address;
            $address = substr($address, 0, 36);
        }
        else
        {
            if (preg_match('/^tz[1-9]/u', $address) > 0)
            {
                $type = self::TYPE_Wallet;
            }
            if (preg_match('/^KT1/u', $address) > 0)
            {
                $type = self::TYPE_Contract;
            }

            $notice = null;
        }

        return [
            'address_type'      => $type,
            'address'           => $address,
            'address_ci'        => $address,
            'notice'            => $notice,
        ];
    }
}
