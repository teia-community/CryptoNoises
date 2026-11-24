<?php

namespace CryptoNoisesTezos\Vendors;

use CryptoCore\Http\AbstractVendor;
use CryptoNoisesTezos\Config;

class Tzkt extends AbstractVendor
{
    // TzKT returns at most this many items per request
    const kMAX_ITEMS_PER_REQUEST = 10000;

    /**
     * Base URL of the TzKT API, no trailing slash (tezos.tzkt_api_url).
     *
     * Worth pointing at a TzKT you control: the public api.tzkt.io rate-limits, and this indexer asks it for every
     * block, every bigmap it has not seen before and every contract. More workers means more requests, so a
     * scaled-up stack earns `HTTP 429` there, which surfaces as "Cannot get bigmap data from tzkt" and friends.
     */
    private static function api(): string
    {
        return Config::cfg()->tzktApiUrl;
    }

    public static function GetAccountDetails(string $contract): ?array
    {
            $result = self::GetDataJson(self::api()."/accounts/$contract");

        return $result->isSuccess() ? $result->data : null;
    }

    public static function GetAccountMetadata(string $contract): ?array
    {
        $result = self::GetDataJson(self::api()."/accounts/$contract/metadata");

        return $result->isSuccess() ? $result->data : null;
    }

    //------------------------------------------------------------------------------------------------------------------

    public static function GetBlockLastIndex(): ?int
    {
        $result = self::GetDataJson(self::api()."/blocks/count");

        return $result->isSuccess() ? (int)($result->data)-1 : null;
    }

    public static function GetBlockDetails(int $level): ?array
    {
        $result = self::GetDataJson(self::api()."/blocks/{$level}?operations=true");

        return $result->isSuccess() ? $result->data : null;
    }

    public static function GetBlockBigmapUpdates(int $level): ?array
    {
        $result = self::GetDataJson(self::api()."/bigmaps/updates?level=$level&limit=".self::kMAX_ITEMS_PER_REQUEST);

        return $result->isSuccess() ? $result->data : null;
    }

    /**
     * One page of a list endpoint ("operations/transactions", "bigmaps/updates") for levels [$levelFrom .. $levelTo],
     * sorted by id; $idAfter continues after the last item of the previous page.
     */
    public static function GetLevelRangePage(string $path, int $levelFrom, int $levelTo, int $limit, ?int $idAfter = null): ?array
    {
        $url = self::api()."/$path?level.ge=$levelFrom&level.le=$levelTo&sort.asc=id&limit=$limit"
            .($idAfter !== null ? "&id.gt=$idAfter" : '');

        $result = self::GetDataJson($url);

        return $result->isSuccess() && is_array($result->data) ? $result->data : null;
    }

    //------------------------------------------------------------------------------------------------------------------

    public static function GetBigmapDetails(int $bigmap): ?array
    {
        $result = self::GetDataJson(self::api()."/bigmaps/$bigmap");

        return $result->isSuccess() ? $result->data : null;
    }

    //------------------------------------------------------------------------------------------------------------------

    public static function GetContractStorageData(string $contract): ?array
    {
        $result = self::GetDataJson(self::api()."/contracts/$contract/storage/");

        return $result->isSuccess() ? $result->data : null;
    }
}
