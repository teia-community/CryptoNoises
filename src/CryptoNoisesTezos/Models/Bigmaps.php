<?php

namespace CryptoNoisesTezos\Models;

use CryptoCore\Vss\Vss;
use CryptoCore\Vss\VssModel;

class Bigmaps extends VssModel
{
    const kTYPE_Basic                           = 'basic';
    const kTYPE_Ledger                          = 'ledger';
    const kTYPE_LedgerLight                     = 'ledger-light';
    const kTYPE_MarketDeals                     = 'market-deals';
    const kTYPE_MarketOffers                    = 'market-offers';
    const kTYPE_Metadata                        = 'metadata';
    const kTYPE_Operators                       = 'operators';
    const kTYPE_Supply                          = 'supply';
    const kTYPE_TokenMetadata                   = 'token-metadata';

    const kSTATE_Active                         = 'active';
    const kSTATE_Removed                        = 'removed';

    public static function model($className = __CLASS__): self
    {
        return parent::model($className);
    }

    public function tableName(): string
    {
        return 'bigmaps';
    }

    // bigmap => [row, read at], per process: the analyzer reads the same bigmaps for every update. Short-lived, since
    // other processes may change a row (a detected token type, say); this process's own writes clear it.
    private static array $cache = [];
    const kCACHE_TTL_SEC = 60;

    public function getItem(int $bigmap): ?array
    {
        if (isset(self::$cache[$bigmap]) && time() - self::$cache[$bigmap][1] < self::kCACHE_TTL_SEC)
            return self::$cache[$bigmap][0];

        $row = Vss::dbTezos()->selectRow('*', $this->tableName(), ['bigmap'=>$bigmap]) ?: null;

        if ($row !== null)
            self::$cache[$bigmap] = [$row, time()];

        return $row;
    }

    public function getItemByType(string $contract, string $type): ?array
    {
        return Vss::dbTezos()->selectRow('*', $this->tableName(), [
            'contract'  => $contract,
            'type'      => $type,
            'state'     => self::kSTATE_Active,
        ]);
    }

    public function getContractByBigmap(int $bigmap): ?string
    {
        $row = $this->getItem($bigmap);

        return $row !== null ? $row['contract'] : null;
    }

    public function insert(array $item)
    {
        unset(self::$cache[(int) ($item['bigmap'] ?? 0)]);
        return Vss::dbTezos()->insert($this->tableName(), $item);
    }

    public function update(int $bigmap, array $item): void
    {
        unset(self::$cache[$bigmap]);
        Vss::dbTezos()->update($this->tableName(), $item, ['bigmap'=>$bigmap]);
    }

    public function remove(int $bigmap, int $level): void
    {
        unset(self::$cache[$bigmap]);
        Vss::dbTezos()->update($this->tableName(),
            ['state' => self::kSTATE_Removed, 'last_level' => $level],
            ['bigmap' => $bigmap]);
    }
}
