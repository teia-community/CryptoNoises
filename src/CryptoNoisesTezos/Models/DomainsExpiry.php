<?php

namespace CryptoNoisesTezos\Models;

use CryptoCore\Time;
use CryptoCore\Vss\Vss;
use CryptoCore\Vss\VssModel;

class DomainsExpiry extends VssModel
{
    const kSTATE_Active     = 'active';
    const kSTATE_Removed    = 'removed';

    public static function model($className = __CLASS__): self
    {
        return parent::model($className);
    }

    public function tableName(): string
    {
        return 'domains_expiry';
    }

    public function save(string $domain, string $expired): void
    {
        $data = [
            'domain'        => $domain,
            'state'         => self::kSTATE_Active,
            // DATETIME, not Time::NormalizeTimestamp(): that one drops dates after 2030 (TIMESTAMP columns end in
            // 2038), and domains are registered for up to 10 years
            'ts_expired'    => ($time = strtotime($expired)) !== false ? gmdate('Y-m-d H:i:s', $time) : null,
        ];

        Vss::dbTezos()->insertOrUpdate($this->tableName(), $data, $data);
    }

    public function remove(string $domain): void
    {
        Vss::dbTezos()->update($this->tableName(),
            ['state' => self::kSTATE_Removed],
            ['domain' => $domain]);
    }
}
