<?php

namespace CryptoNoises\Networks;

use CryptoNoises\Config;
use CryptoNoises\Networks\Tezos\TezosNetwork;

abstract class AbstractNetwork
{
    /** @var AbstractNetwork[] */
    private static array $networkInstances = [];

    public static function GetNetworkById($networkId): ?AbstractNetwork
    {
        if (!isset(self::$networkInstances[$networkId]))
        {
            switch ($networkId)
            {
                case Config::kNETWORK_Tezos:
                    self::$networkInstances[$networkId] = new TezosNetwork();
                    break;

                default:
                    return null;
            }
        }

        return self::$networkInstances[$networkId];
    }

    //------------------------------------------------------------------------------------------------------------------

    abstract public static function IsWalletAddressBlocked(string $address): bool;

    abstract public static function IsWalletAddressValid(?string $address): bool;
    abstract public static function IsWalletAddressPrefixValid(?string $addressPrefix): bool;

    abstract public static function IsDomainAddressValid(?string $domain): bool;

    abstract public static function IsContractAddressValid(?string $address): bool;

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    abstract public function getWalletPublicName(string $address): string;
    abstract public function getWalletShortName(string $address): string;

    abstract public function getContractShortName(string $address): string;

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    abstract public function getAddressPublicUrl(string $address): string;
    abstract public function getContractPublicUrl(string $address): string;
    abstract public function getTransactionPublicUrl(string $hash, ?int $counter = null): ?string;

    abstract public function getTokenCollectionUrl(string $contract): string;
    abstract public function getTokenDefaultDetailsUrl(string $contract, string $tokenId): ?string;

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    abstract public function getCoinsFriendlyShortValue($value): string;
    abstract public function getCoinsFriendlyFullValue($value): string;

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    abstract public function getBalanceForAddress(string $address): ?int;
}
