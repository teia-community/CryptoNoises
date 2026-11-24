<?php

namespace CryptoNoisesTezos\Analyzers\BigmapUpdateEntities\PostActions;

/**
 * Tezos Domains bigmaps:
 *  1262    store.expiry_map        key: domain (hex)   val: expiry timestamp
 *  1264    store.records           key: domain (hex)   val: address, owner, level, expiry_key, tzip12_token_id, data
 *  1265    store.reverse_records   key: address        val: name (hex), owner
 */

use CryptoNoisesTezos\Core\Bigmap;
use CryptoNoisesTezos\Core\Helper;
use CryptoNoisesTezos\Models;

class TezosDomain implements PostActionInterface
{
    const kBIGMAP_ExpiryMap         = 1262;
    const kBIGMAP_Records           = 1264;
    const kBIGMAP_ReverseRecords    = 1265;

    public function save(int $bigmap, int $level, $key, string $cValString): void
    {
        if (!in_array($bigmap, [self::kBIGMAP_ExpiryMap, self::kBIGMAP_Records, self::kBIGMAP_ReverseRecords]))
            return;

        $cValue = json_decode($cValString, true);

        if ($bigmap == self::kBIGMAP_ExpiryMap)
        {
            $domain = Helper::UnpackHex2Bin($key);

            Models\DomainsExpiry::model()->save($domain, $cValue);
        }

        if ($bigmap == self::kBIGMAP_Records)
        {
            $domain = Helper::UnpackHex2Bin($key);

            Models\DomainsRecords::model()->save($domain, [
                'address'           => $cValue['address'],
                'owner'             => $cValue['owner'],
                'level'             => $cValue['level'],
                'expiry_key'        => Helper::UnpackHex2Bin($cValue['expiry_key']),
                'tzip12_token_id'   => $cValue['tzip12_token_id'],

                'data'              => ($cValue['data'] ?? null) ?: null,
                'internal_data'     => ($cValue['internal_data'] ?? null) ?: null,
            ]);

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            Models\Queues\QueueTokensDetected::model()->push([
                'bigmap'       => $bigmap,
                'bigmap_exkey' => Bigmap::KeyToExkey($key),
                'level'        => $level,
            ]);
        }

        if ($bigmap == self::kBIGMAP_ReverseRecords)
        {
            $address = $key;

            Models\DomainsRecordsReverse::model()->save($address, [
                'domain'            => Helper::UnpackHex2Bin($cValue['name']),
                'owner'             => $cValue['owner'],

                'internal_data'     => ($cValue['internal_data'] ?? null) ?: null,
            ]);
        }
    }

    public function remove(int $bigmap, int $level, $key): void
    {
        if (!in_array($bigmap, [self::kBIGMAP_ExpiryMap, self::kBIGMAP_Records, self::kBIGMAP_ReverseRecords]))
            return;

        if ($bigmap == self::kBIGMAP_ExpiryMap)
        {
            $domain = Helper::UnpackHex2Bin($key);
            Models\DomainsExpiry::model()->remove($domain);
        }

        if ($bigmap == self::kBIGMAP_Records)
        {
            $domain = Helper::UnpackHex2Bin($key);
            Models\DomainsRecords::model()->remove($domain);
        }

        if ($bigmap == self::kBIGMAP_ReverseRecords)
        {
            $address = $key;
            Models\DomainsRecordsReverse::model()->remove($address);
        }
    }

    public function removeAllInBigmap(int $bigmap, int $level): void
    {
        // nothing to do
    }
}
