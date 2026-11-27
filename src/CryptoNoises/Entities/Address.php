<?php

namespace CryptoNoises\Entities;

use CryptoNoises\Models;
class Address
{
    public static function Normalize(?string $address): string
    {
        $address = trim((string) $address);

        // Accept an objkt.com profile URL (https://objkt.com/@alias) as alias.tez
        if (preg_match('#^https://objkt\.com/@(.+)#iu', $address, $m) > 0)
            return $m[1].'.tez';

        if (preg_match('/(tz[1-9][a-z0-9]{33})/iu', $address, $m) > 0)
        {
            $address = $m[1];

            // Convert { 'Tz...' | 'TZ...' } --> 'tz...'
            $address = 'tz'.substr($address, 2);
        }

        return $address;
    }

    public static function GetOrCreateAdid(?string $address): ?int
    {
        $item = self::GetItemByAddress($address, true);

        return $item ? $item['adid'] : null;
    }

    public static function GetAdid(?string $address): ?int
    {
        $item = self::GetItemByAddress($address, false);

        return $item ? $item['adid'] : null;
    }

    /** @var array<string, int> address => adid; an address keeps its adid, so found ones are kept */
    private static array $adids = [];

    private static function Remember(string $address, int $adid): int
    {
        if (count(self::$adids) > 50000)
            self::$adids = [];

        return self::$adids[$address] = $adid;
    }

    /** @return array<string, int> adid by address, for the addresses that exist; one query for the unknown ones */
    public static function GetAdids(array $addresses): array
    {
        $result  = [];
        $missing = [];

        foreach ($addresses as $address)
        {
            if (isset(self::$adids[$address]))
                $result[$address] = self::$adids[$address];
            else if ($address !== null && $address !== '')
                $missing[] = $address;
        }

        foreach (Models\Address::model()->getAdidsByAddresses($missing) as $address => $adid)
            $result[$address] = self::Remember($address, (int) $adid);

        return $result;
    }

    private static function GetItemByAddress(?string $address, bool $isRequireAutoCreate): ?array
    {
        if ($address === null)
            return null;

        if (isset(self::$adids[$address]))
            return ['adid' => self::$adids[$address], 'address' => $address];

        $item = Models\Address::model()->getItemByAddress($address);

        if (!empty($item))
        {
            self::Remember($address, (int) $item['adid']);
            return $item;
        }

        if (!$isRequireAutoCreate)
            return null;

        Models\Address::model()->create($address);

        return Models\Address::model()->getItemByAddress($address);
    }
}
