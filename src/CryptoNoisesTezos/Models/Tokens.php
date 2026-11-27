<?php

namespace CryptoNoisesTezos\Models;

use CryptoCore\Vss\Vss;
use CryptoCore\Vss\VssModel;

class Tokens extends VssModel
{
    const kMETADATA_STATE_Idle      = 'idle';
    const kMETADATA_STATE_Syncing   = 'syncing';
    const kMETADATA_STATE_Success   = 'success';
    const kMETADATA_STATE_Error     = 'error';
    const kMETADATA_STATE_Empty     = 'empty';

    const kPREVIEW_STATE_Idle       = 'idle';
    const kPREVIEW_STATE_Syncing    = 'syncing';
    const kPREVIEW_STATE_Success    = 'success';
    const kPREVIEW_STATE_Error      = 'error';
    const kPREVIEW_STATE_Empty      = 'empty';
    const kPREVIEW_STATE_Teaser     = 'teaser';

    public static function model($className=__CLASS__): self
    {
        return parent::model($className);
    }

    public function tableName(): string
    {
        return 'tokens';
    }

    /** Seconds a row may be reused within this process; 0 (the default) always reads. Set by readers that tolerate it */
    public static int $cacheSec = 0;

    private static array $cache = [];

    public function getItem(int $contractAdid, int $tokenUnid): ?array
    {
        if (self::$cacheSec <= 0)
            return Vss::dbTezos()->selectRow('*', $this->tableName(), [
            'contract_adid' => $contractAdid,
            'token_unid'    => $tokenUnid,
        ]);

        $key = "$contractAdid:$tokenUnid";

        if (!isset(self::$cache[$key]) || time() - self::$cache[$key][1] > self::$cacheSec)
        {
            if (count(self::$cache) > 20000)
                self::$cache = [];

            self::$cache[$key] = [Vss::dbTezos()->selectRow('*', $this->tableName(), [
            'contract_adid' => $contractAdid,
            'token_unid'    => $tokenUnid,
        ]), time()];
        }

        return self::$cache[$key][0];
    }

    public function open(int $contractAdid, string $contractAddress, int $tokenUnid, array $data): bool
    {
        $data['contract_adid']      = $contractAdid;
        $data['contract_address']   = $contractAddress;
        $data['token_unid']         = $tokenUnid;

        return (bool) Vss::dbTezos()->insert($this->tableName(), $data, 'IGNORE');
    }

    public function update(int $contractAdid, int $tokenUnid, array $data): bool
    {
        return (bool) Vss::dbTezos()->update($this->tableName(), $data, [
            'contract_adid' => $contractAdid,
            'token_unid'    => $tokenUnid,
        ]);
    }
}
