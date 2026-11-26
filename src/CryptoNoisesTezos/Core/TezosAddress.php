<?php

namespace CryptoNoisesTezos\Core;

use CryptoNoisesTezos\Models;

class TezosAddress
{
    public static function IsWalletAddressValid(?string $address): bool
    {
        if (strlen((string) $address) != 36)
            return false;

        // tz1...
        // tz2...
        // tz3...
        return preg_match('/^tz[1-9]([a-zA-Z0-9]{33})$/u', $address);
    }

    public static function IsContractAddressValid(?string $address): bool
    {
        if (strlen((string) $address) != 36)
            return false;

        // KT1..
        return preg_match('/^KT1([a-zA-Z0-9]{33})$/u', $address);
    }

    public static function IsWalletOrContractAddress(?string $address): bool
    {
        return self::IsWalletAddressValid($address) || self::IsContractAddressValid($address);
    }

    public static function GetAddressByAdid(int $adid): ?string
    {
        $item = Models\Address::model()->getItemByAdid($adid);

        return $item ? $item['address'] : null;
    }

    // address => adid, per process: an adid never changes, and the analyzers look the same addresses up constantly
    private static array $adids = [];
    const kADID_CACHE_MAX = 200000;

    private static function Remember(string $address, int $adid): int
    {
        if (count(self::$adids) >= self::kADID_CACHE_MAX)
            self::$adids = [];

        return self::$adids[$address] = $adid;
    }

    public static function GetAdidByAddress(string $address): ?int
    {
        if (isset(self::$adids[$address]))
            return self::$adids[$address];

        $item = Models\Address::model()->getItemByAddress($address);

        return $item ? self::Remember($address, (int) $item['adid']) : null;
    }

    private static function GetOrCreateItem(?string $address): ?array
    {
        if (empty($address))
            return null;

        $item = Models\Address::model()->getItemByAddress($address);

        if ($item)
            return $item;

        Models\Address::model()->create($address);

        return Models\Address::model()->getItemByAddress($address);
    }

    public static function GetOrCreateAdid(?string $address): ?int
    {
        if (empty($address))
            return null;

        if (isset(self::$adids[$address]))
            return self::$adids[$address];

        $item = self::GetOrCreateItem($address);
        return $item ? self::Remember($address, (int) $item['adid']) : null;
    }
}
