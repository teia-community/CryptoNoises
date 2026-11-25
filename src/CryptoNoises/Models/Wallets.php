<?php

namespace CryptoNoises\Models;

use CryptoCore\Vss\Vss;
use CryptoCore\Vss\VssModel;
use CryptoCore\Vss\VssDbExp;

class Wallets extends VssModel
{
    const kSTATUS_Active        = 'active';
    const kSTATUS_Frozen        = 'frozen';

    public static function model($className=__CLASS__): self
    {
        return parent::model($className);
    }

    public function tableName(): string
    {
        return 'wallets';
    }

    public function getWalletItem($walletAdid)
    {
        $sql = "
            SELECT *
            FROM {$this->tableName()}
            WHERE wallet_adid = :id
        ";

        return Vss::db()->queryRow($sql, ['id'=>$walletAdid]);
    }

    /**
     * Addresses of every wallet the bots watch. Used where an event concerns wallets by a relation the chain
     * knows and this database does not - e.g. who delegates to a baker that just changed its terms.
     *
     * @return string[]
     */
    public function getActiveAddresses(): array
    {
        $rows = Vss::db()->query("SELECT wallet_address FROM {$this->tableName()} WHERE status = :status",
            ['status' => self::kSTATUS_Active]);

        return array_column($rows, 'wallet_address');
    }

    public function freeze($walletAdid)
    {
        $sql = "UPDATE {$this->tableName()} SET status=:status WHERE wallet_adid=:wallet_adid";

        Vss::db()->query($sql, [
            'wallet_adid'       => $walletAdid,
            'status'            => self::kSTATUS_Frozen,
        ]);
    }

    public function create($walletAdid, $walletAddress, $currency)
    {
        $itemInsert = [
            'wallet_adid'       => $walletAdid,
            'wallet_address'    => $walletAddress,
            'currency'          => $currency,
            'status'            => self::kSTATUS_Active,
        ];

        $itemUpdate = [
            'status'            => self::kSTATUS_Active,
        ];

        return Vss::db()->insertOrUpdate($this->tableName(), $itemInsert, $itemUpdate);
    }

    public function update($walletAdid, $updateData)
    {
        $updateData['ts_updated'] = VssDbExp::now();

        return Vss::db()->update($this->tableName(), $updateData, ['wallet_adid'=> $walletAdid]);
    }

    //------------------------------------------------------------------------------------------------------------------

    public function search(int $userId, string $query, int $limit): ?array
    {
        $query = preg_replace('/[%_]/', '', $query); // to prevent MySQL long query in LIKE rule

        $sql = "
            SELECT a.address AS address, 
                   ref.wallet_name AS name
            FROM {$this->tableName()} AS w
            JOIN ".UsersRefWallets::model()->tableName()." AS ref
                ON w.wallet_adid = ref.wallet_adid
                AND ref.user_id = :user_id
            JOIN ".Address::model()->tableName()." AS a
                ON w.wallet_adid = a.adid
            WHERE w.status = :status_active
                AND (a.address_ci LIKE :address OR ref.wallet_name LIKE :address_in_name)
            LIMIT :limit
        ";

        return Vss::db()->query($sql, [
            'user_id'           => $userId,
            'status_active'     => self::kSTATUS_Active,
            'address'           => $query.'%',
            'address_in_name'   => '%'.$query.'%',
            'limit'             => $limit,
        ]);
    }
}
