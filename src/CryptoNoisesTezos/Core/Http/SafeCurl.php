<?php

namespace CryptoNoisesTezos\Core\Http;

use CryptoNoisesTezos\Config;

/**
 * HTTP(S) client for untrusted URLs (token metadata, media from on-chain URIs).
 *
 * - only http / https;
 * - redirects are followed manually, every hop must resolve to a public IP (no localhost, private or link-local
 *   ranges) - except the configured fetch gateway's own origin - and the resolved IP is pinned via CURLOPT_RESOLVE
 *   so DNS cannot change between check and request;
 * - response size is limited;
 * - TLS certificates are verified.
 */
final class SafeCurl
{
    private const kMAX_REDIRECTS = 5;

    /**
     * @param resource|null $fileHandle If set, the body is streamed into it (HttpResponse::$body is null)
     */
    public static function Fetch(string $url, int $timeout, int $maxBytes, $fileHandle = null): HttpResponse
    {
        for ($hop = 0; $hop <= self::kMAX_REDIRECTS; $hop++)
        {
            $resolve = self::ResolvePublicHost($url);

            if ($resolve === null)
                return HttpResponse::Failed("Blocked or unresolvable URL: $url");

            if ($fileHandle !== null)
            {
                ftruncate($fileHandle, 0);
                rewind($fileHandle);
            }

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL                 => $url,
                CURLOPT_RESOLVE             => [$resolve],
                CURLOPT_PROTOCOLS           => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION      => false,
                CURLOPT_HEADER              => false,
                CURLOPT_TIMEOUT             => $timeout,
                CURLOPT_SSL_VERIFYPEER      => true,
                CURLOPT_SSL_VERIFYHOST      => 2,
                CURLOPT_MAXFILESIZE         => $maxBytes,
                CURLOPT_NOPROGRESS          => false,
                self::ProgressOption()      => function ($ch, $downloadTotal, $downloadNow) use ($maxBytes)
                {
                    return $downloadNow > $maxBytes ? 1 : 0; // non-zero aborts the transfer
                },
            ]);

            if ($fileHandle !== null)
                curl_setopt($ch, CURLOPT_FILE, $fileHandle);
            else
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $body     = curl_exec($ch);
            $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $redirect = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            $error    = curl_error($ch);

            curl_close($ch);

            if ($body === false)
                return HttpResponse::Failed($error ?: 'Request failed');

            if ($code >= 300 && $code < 400 && $redirect !== '')
            {
                $url = $redirect;
                continue;
            }

            return new HttpResponse($code, $fileHandle !== null ? null : (string) $body);
        }

        return HttpResponse::Failed('Too many redirects');
    }

    /**
     * CURLOPT_XFERINFOFUNCTION exists since PHP 8.2; PHP 7.4 has CURLOPT_PROGRESSFUNCTION with the same callback args.
     */
    private static function ProgressOption(): int
    {
        return defined('CURLOPT_XFERINFOFUNCTION') ? constant('CURLOPT_XFERINFOFUNCTION') : CURLOPT_PROGRESSFUNCTION;
    }

    /**
     * @return string|null "host:port:ip" for CURLOPT_RESOLVE, or null if the URL is not allowed
     */
    private static function ResolvePublicHost(string $url): ?string
    {
        // The one private address allowed: the configured fetch gateway (the `ipfs` sidecar), and only its exact
        // origin - its gateway port serves nothing but IPFS content. Its admin API, other ports and other hosts on
        // the same network stay blocked, so an on-chain URI still cannot reach them.
        $trusted = self::TrustedOrigin();

        if ($trusted !== null && self::Origin($url) === $trusted)
        {
            $host = parse_url($url, PHP_URL_HOST);
            $ip   = filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : (gethostbynamel($host) ?: [null])[0];

            return $ip !== null ? "$host:{$trusted['port']}:$ip" : null;
        }

        $parts  = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host   = $parts['host'] ?? '';

        if (!in_array($scheme, ['http', 'https'], true) || $host === '')
            return null;

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (filter_var($host, FILTER_VALIDATE_IP) !== false)
            $ip = $host;
        else
            $ip = (gethostbynamel($host) ?: [null])[0];

        if ($ip === null || !self::IsPublicIp($ip))
            return null;

        return "$host:$port:$ip";
    }

    /** scheme, host and port of tezos.ipfs_fetch_gateway_url, or null when none is set */
    private static function TrustedOrigin(): ?array
    {
        $gateway = Config::cfg()->ipfsFetchGatewayUrl;

        return $gateway !== '' ? self::Origin($gateway) : null;
    }

    private static function Origin(string $url): ?array
    {
        $parts  = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host   = strtolower($parts['host'] ?? '');

        if (!in_array($scheme, ['http', 'https'], true) || $host === '')
            return null;

        return ['scheme' => $scheme, 'host' => $host, 'port' => (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80))];
    }

    private static function IsPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
