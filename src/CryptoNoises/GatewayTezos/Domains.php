<?php

namespace CryptoNoises\GatewayTezos;

use CryptoNoises\Entities\UserDomain;
class Domains
{
    const kHIDE_EXPIRED_AFTER_SEC = 90 * 86400;

    /**
     * @param string $address
     * @return UserDomain[]
     */
    public static function GetUserDomainsByOwner(string $address): array
    {
        $result = [];

        $domains = \CryptoNoises\GatewayTezos\Models\DomainsRecords::model()->selectDomainsByOwner($address);

        foreach ($domains as $domain)
        {
            $tsExpired = \CryptoNoises\GatewayTezos\Models\DomainsExpiry::model()->getExpireDateForDomain($domain);

            // Domains that expired long ago are gone from Tezos Domains: not worth listing
            if ($tsExpired == null || strtotime($tsExpired.' UTC') < time() - self::kHIDE_EXPIRED_AFTER_SEC)
                continue;

            $result[] = new UserDomain($domain, $address, $tsExpired);
        }

        return $result;
    }

    public static function GetDomainOwner(string $domain): ?string
    {
        $item = \CryptoNoises\GatewayTezos\Models\DomainsRecords::model()->getItemByDomain($domain);

        return $item ? $item['owner'] : null;
    }
}
