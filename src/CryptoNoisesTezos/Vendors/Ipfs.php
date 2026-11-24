<?php

namespace CryptoNoisesTezos\Vendors;

use CryptoNoisesTezos\Config;

class Ipfs
{
    public static function IsExactlyIpfsUri(?string $uri): bool
    {
        return !empty($uri) && preg_match('#^ipfs://#', $uri) > 0;
    }

    public static function GetSafeFilename(?string $uri): ?string
    {
        if (empty($uri))
            return null;

        return sha1($uri);
    }

    public static function ConvertUriToUrl(?string $uri): ?string
    {
        $uri = trim((string) $uri);

        // Case 0: sha256-prefixed URI
        // sha256://0x73ba00c01494a50316bb6e451fafcd3893d7d8a0e725793960b1856ab24d1bc1/https:%2F%2Fcollectible.sweet.io%2Fseries%2F835%2F1%2Ftezos
        if (preg_match('#^sha256://0x([0-9a-z])+/(.*)#', $uri, $m) != 0)
            $uri = urldecode($m[2]);
        // falls through to the checks below

        // Case 1: already an https URL
        // https://ipfs.io/ipfs/QmPnKG1u8iU3owL86wbtUCcZak9Q9ZaeHv19FvYttLago3
        // https://gateway.pinata.cloud/ipfs/QmZYm7618U26rAWpUk3kqnVAC2F9fjPwF5xCSAWJwun1RW
        if (preg_match('#^https://#', $uri))
            return $uri;

        $gateway = Config::cfg()->ipfsGatewayUrl;

        // Case 2: bare CID
        // QmPnKG1u8iU3owL86wbtUCcZak9Q9ZaeHv19FvYttLago3
        if (preg_match('#^Qm([A-Za-z0-9]{44})$#', $uri))
            return $gateway . $uri;

        if (preg_match('#^ipfs://#', $uri) == 0)
            return null;

        $url = $uri;
        $url = str_replace('ipfs://ipfs/',  'ipfs://', $url);   # Rarible-style ipfs://ipfs/<cid>
        $url = str_replace('ipfs://',       $gateway,  $url);
        $url = str_replace($gateway.$gateway, $gateway, $url);  # a URI that already carried the gateway
        return $url;
    }

    /*
     * The IPFS gateway answers only one of several parallel requests for the same URL,
     * so each job makes its URL unique.
     */
    public static function AppendUniqueTaskIdToUrl(string $url, string $jobTaskId): string
    {
        return $url . (strpos($url, '?') === false ? '?' : '&') . '__jobTaskId='.$jobTaskId;
    }
}
