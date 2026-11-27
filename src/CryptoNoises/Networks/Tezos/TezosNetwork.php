<?php

namespace CryptoNoises\Networks\Tezos;

use CryptoNoises\Core\Log;
use CryptoNoises\Core\URL;
use CryptoNoises\Helpers\Helper;
use CryptoNoises\Models;
use CryptoNoises\Networks\AbstractNetwork;

class TezosNetwork extends AbstractNetwork
{
    const kCURRENCY_ID                  = 'XTZ';

    const kCURRENCY_PUBLIC              = 'XTZ';
    const kCURRENCY_PUBLIC_MICRO        = 'mXTZ';

    const kADDRESS_BURN                 = 'tz1burnburnburnburnburnburnburjAYjjX';
    const kADDRESS_BURN_LOWERCASE       = 'tz1burnburnburnburnburnburnburjayjjx';

    public static array $HunterClasses = [
        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
        // Marketplaces

        'CryptoNoises\Networks\Tezos\Hunters\AkaSwap_Auction',
        'CryptoNoises\Networks\Tezos\Hunters\AkaSwap_Marketplace',
        'CryptoNoises\Networks\Tezos\Hunters\AkaSwap_Offers',

        'CryptoNoises\Networks\Tezos\Hunters\FxHashV3Hunter',
        'CryptoNoises\Networks\Tezos\Hunters\FxHashV2Hunter_Main',
        'CryptoNoises\Networks\Tezos\Hunters\FxHashV2Hunter_Issuer',
        'CryptoNoises\Networks\Tezos\Hunters\FxHashV0Hunter_Main',

        'CryptoNoises\Networks\Tezos\Hunters\ObjktComV23Hunter_Main',
        'CryptoNoises\Networks\Tezos\Hunters\ObjktComV23Hunter_Auctions',
        'CryptoNoises\Networks\Tezos\Hunters\ObjktComV23Hunter_Offers',
        'CryptoNoises\Networks\Tezos\Hunters\ObjktComV23Hunter_Wrapper',
        'CryptoNoises\Networks\Tezos\Hunters\ObjktComProjectOffersHunter',

        'CryptoNoises\Networks\Tezos\Hunters\TeiaHunter',

        'CryptoNoises\Networks\Tezos\Hunters\RaribleHunter',

        'CryptoNoises\Networks\Tezos\Hunters\KalamintHunter_Main',
        'CryptoNoises\Networks\Tezos\Hunters\KalamintHunter_Auctions',
        'CryptoNoises\Networks\Tezos\Hunters\KalamintHunter_Offers',

        'CryptoNoises\Networks\Tezos\Hunters\VersumHunter_Main',
        'CryptoNoises\Networks\Tezos\Hunters\VersumHunter_Auctions',
        'CryptoNoises\Networks\Tezos\Hunters\VersumHunter_Offers',

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
        // Projects / Games / Other markets

        'CryptoNoises\Networks\Tezos\Hunters\Dogami_Doga',
        'CryptoNoises\Networks\Tezos\Hunters\Dogami_Marketplace',

        'CryptoNoises\Networks\Tezos\Hunters\EightBidou_8rgb',
        'CryptoNoises\Networks\Tezos\Hunters\EightBidou_24mono',
        'CryptoNoises\Networks\Tezos\Hunters\EightBidou_24rgb',

        'CryptoNoises\Networks\Tezos\Hunters\Tezotopia_Marketplace',

        'CryptoNoises\Networks\Tezos\Hunters\Typed',

        'CryptoNoises\Networks\Tezos\Hunters\PigsAtWarHunter',

        'CryptoNoises\Networks\Tezos\Hunters\EightScriboHunter',

        'CryptoNoises\Networks\Tezos\Hunters\DropArtHunter',

        'CryptoNoises\Networks\Tezos\Hunters\ObjktComGenerativeHunter',

        'CryptoNoises\Networks\Tezos\Hunters\EmpropsHunter',

        'CryptoNoises\Networks\Tezos\Hunters\AleatoryHunter',

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
        // Not a marketplace: comments posted on a token (teia.art). Must come before the Default* hunters,
        // which would otherwise take the call as an unremarkable contract interaction.

        'CryptoNoises\Networks\Tezos\Hunters\TeiaCommentsHunter',

        // Not a marketplace either: messages to a wallet in tez.cool's on-chain chats
        'CryptoNoises\Networks\Tezos\Hunters\TezCoolHunter',

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
        // Deprecated Marketplaces

        'CryptoNoises\Networks\Tezos\Hunters\HicEtNuncHunter',

        'CryptoNoises\Networks\Tezos\Hunters\ObjktComV1Hunter_Main',
        'CryptoNoises\Networks\Tezos\Hunters\ObjktComV1Hunter_Auctions',
        'CryptoNoises\Networks\Tezos\Hunters\ObjktComV1Hunter_Offers',

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
        // Default behaviour

        'CryptoNoises\Networks\Tezos\Hunters\DefaultSwap',
        'CryptoNoises\Networks\Tezos\Hunters\DefaultLiquidity',
        'CryptoNoises\Networks\Tezos\Hunters\DefaultEvents',
        'CryptoNoises\Networks\Tezos\Hunters\DefaultIgnore',

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
    ];

    //------------------------------------------------------------------------------------------------------------------

    public static function IsWalletAddressBlocked(string $address): bool
    {
        $address = strtolower($address);

        // 'tz0000000000000000000000000000000000'
        // 'tz0000000000000000000000000000000001'
        // 'tz0*********************************'
        if (strpos($address, 'tz0') === 0)
            return true;

        return in_array($address, [
            self::kADDRESS_BURN_LOWERCASE,
        ]);
    }

    public static function IsWalletAddressValid(?string $address): bool
    {
        if ($address == null || self::IsWalletAddressBlocked($address))
            return false;

        return preg_match('/^tz[1-9]([a-zA-Z0-9]{33})$/u', $address) > 0;
    }

    public static function IsWalletAddressPrefixValid(?string $addressPrefix): bool
    {
        if ($addressPrefix == null || self::IsWalletAddressBlocked($addressPrefix))
            return false;

        if ($addressPrefix == 't' || $addressPrefix == 'tz')
            return true;

        return preg_match('/^tz[1-9]([a-zA-Z0-9]{0,33})$/u', $addressPrefix) > 0;
    }

    public static function IsDomainAddressValid(?string $domain): bool
    {
        if ($domain == null)
            return false;

        // domain.tez
        // sub.domain.tez
        return preg_match('#^[a-z0-9.-]+\.tez$#iu', $domain) > 0;
    }

    public static function IsContractAddressValid(?string $address): bool
    {
        if ($address == null)
            return false;

        return preg_match('/^KT([a-zA-Z0-9]{34})$/u', $address) > 0;
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public static function WalletShortName(string $address): string
    {
        return substr($address, 0, 5).'...'.substr($address, -5, 5);
    }

    public static function ContractShortName(string $address): string
    {
        return substr($address, 0, 5).'...'.substr($address, -5, 5);
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public function getWalletPublicName(string $address): string
    {
        $walletPublicName = \CryptoNoises\GatewayTezos\Wallets::GetWalletPublicName($address);

        return $walletPublicName ?: $this->getWalletShortName($address);
    }

    public function getWalletShortName(string $address): string
    {
        return self::WalletShortName($address);
    }

    public function getContractShortName(string $address): string
    {
        return self::ContractShortName($address);
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public function getAddressPublicUrl(string $address): string
    {
        if (self::IsContractAddressValid($address))
            return $this->getContractPublicUrl($address);

        $marketUrlItem = Models\Shared\MarketUrls::model()->getItemById(Models\Shared\MarketUrls::kDefaultTezos_User);

        if ($marketUrlItem == null) {
            Log::Fatal(get_class($this), __FUNCTION__, "Cannot get default MarketUrl", $address);
            return '';
        }

        return URL::InnerRedirectToUser($marketUrlItem, $address);
    }

    public function getContractPublicUrl(string $address): string
    {
        $marketUrlItem = Models\Shared\MarketUrls::model()->getItemById(Models\Shared\MarketUrls::kDefaultTezos_Contract);

        if ($marketUrlItem == null) {
            Log::Fatal(get_class($this), __FUNCTION__, "Cannot get default MarketUrl", $address);
            return '';
        }

        return URL::InnerRedirectToContract($marketUrlItem, $address);
    }

    public function getTransactionPublicUrl(string $hash, ?int $counter = null): ?string
    {
        if (!preg_match('/^o[1-9A-HJ-NP-Za-km-z]{50}$/', $hash))
            return null;

        return 'https://tzkt.io/'.$hash.($counter > 0 ? '/'.$counter : '');
    }

    private static array $operationGroups = [];

    /**
     * The link to one operation and, when it is internal (it has a nonce), the contract call it ran in, such as
     * "QuipuSwap TZD veto": the explorer shows that call, which is not what the notification is about.
     *
     * @return array{string, ?string}|null url, call
     */
    public function getOperationLink(string $hash, ?int $counter, ?int $operationId): ?array
    {
        $url = $this->getTransactionPublicUrl($hash, $counter);

        if ($url === null || !($counter > 0) || !($operationId > 0))
            return $url === null ? null : [$url, null];

        $key = "$hash/$counter";

        if (!isset(self::$operationGroups[$key]))
        {
            if (count(self::$operationGroups) > 100)
                self::$operationGroups = [];

            self::$operationGroups[$key] = \CryptoNoisesTezos\Vendors\Tzkt::GetOperationGroup($hash, $counter) ?? [];
        }

        $nonce = null;
        $call  = null;

        foreach (self::$operationGroups[$key] as $op)
        {
            if (($op['id'] ?? null) == $operationId)
                $nonce = $op['nonce'] ?? null;

            if ($call === null && ($op['type'] ?? null) == 'transaction' && !isset($op['nonce']))
                $call = $op;
        }

        if ($nonce === null)
            return [$url, null];

        // A self-hosted TzKT has no aliases: GetAlias asks the aliases service
        $address = $call['target']['address'] ?? null;
        $target  = $call['target']['alias'] ?? null;
        if (($target === null || $target === '') && $address !== null)
            $target = \CryptoNoisesTezos\Vendors\Tzkt::GetAlias($address) ?? $this->getContractShortName($address);

        $via = trim($target.' '.($call['parameter']['entrypoint'] ?? ''));

        return [$url.'/'.(int) $nonce, $via !== '' ? $via : null];
    }

    public function getTokenCollectionUrl(string $contract): string
    {
        $marketUrlItem = Models\Shared\MarketUrls::model()->getItemById(Models\Shared\MarketUrls::kDefaultTezos_Collection);

        if ($marketUrlItem == null) {
            Log::Fatal(get_class($this), __FUNCTION__, "Cannot get default MarketUrl", $contract);
            return '';
        }

        return URL::InnerRedirectToCollection($marketUrlItem, $contract);
    }

    public function getTokenDefaultDetailsUrl(string $contract, string $tokenId): ?string
    {
        $marketUrlItem = Models\Shared\MarketUrls::model()->getItemById(Models\Shared\MarketUrls::kDefaultTezos_Token);

        if ($marketUrlItem == null) {
            Log::Fatal(get_class($this), __FUNCTION__, "Cannot get default MarketUrl", [$contract, $tokenId]);
            return null;
        }

        return URL::InnerRedirectToToken($marketUrlItem, $contract, $tokenId);
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public function getCoinsFriendlyShortValue($value): string
    {
        if ($value == 0)
            return '0.0 '.TezosNetwork::kCURRENCY_PUBLIC;

        if ($value < 1000)
            return number_format($value/1000, 3).' '.TezosNetwork::kCURRENCY_PUBLIC_MICRO;

        $value = number_format(floor($value/1000)/1000, 3); # 12345678 -> 12.345 (not 12.345678)

        return Helper::TrimRightZerosInNumber($value).' '.TezosNetwork::kCURRENCY_PUBLIC;
    }

    public function getCoinsFriendlyFullValue($value): string
    {
        if ($value == 0)
            return '0.0 '.TezosNetwork::kCURRENCY_PUBLIC;

        if ($value < 1000)
            return number_format($value/1000, 3).' '.TezosNetwork::kCURRENCY_PUBLIC_MICRO;

        $value = number_format($value/1000000, 6); # 12345678 -> 12.345678 (not 12.345)

        return Helper::TrimRightZerosInNumber($value).' '.TezosNetwork::kCURRENCY_PUBLIC;
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public function getBalanceForAddress(string $address): ?int
    {
        return \CryptoNoises\GatewayTezos\Wallets::GetWalletBalance($address);
    }
}
