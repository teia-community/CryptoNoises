<?php

namespace CryptoNoisesTezos\Jobs;

use CryptoNoisesTezos\Config;
use CryptoNoisesTezos\Core\Bigmap;
use CryptoNoisesTezos\Core\Downloader;
use CryptoNoisesTezos\Core\Helper;
use CryptoNoisesTezos\Models;
use CryptoNoisesTezos\Vendors\Ipfs;
use CryptoCore\Enum\JobResult;
use Exception;

abstract class AbstractJob extends \CryptoCore\Jobs\AbstractJob
{
    /**
     * @param int $bigmap
     * @param string|null $storageKey
     * @return array|null
     * @throws Exception
     */
    protected static function ExtractMetadataJsonFromTezosStorage(int $bigmap, ?string $storageKey): ?array
    {
        $bigmapContract = Models\Bigmaps::model()->getContractByBigmap($bigmap);

        if ($bigmapContract === null)
            throw new Exception('Cannot detect contract by bigmap', JobResult::kFailedAndClose);

        $bigmapItem = Models\Bigmaps::model()->getItemByType($bigmapContract, Models\Bigmaps::kTYPE_Metadata);

        if ($bigmapItem === null)
            throw new Exception('Cannot find bigmap-record with metadata-type', JobResult::kFailedAndClose);

        $bigmapMetadata = $bigmapItem['bigmap'];

        $value = Bigmap::GetValueByBigmapAndKey($bigmapMetadata, $storageKey);

        if ($value === null)
            throw new Exception("Cannot find bigmap-data-record #$bigmapMetadata [$storageKey]", JobResult::kFailedAndClose);

        if (is_string($value) && preg_match('/^7b(.+)7d/u', $value) > 0) # $json = "{.....}"
        {
            $value = Helper::UnpackHex2Bin($value);
            $value = $value ? json_decode($value, true) : null;
        }

        return is_array($value) ? $value : null;
    }

    //------------------------------------------------------------------------------------------------------------------

    /**
     * @param string|null $metadataUri
     * @param int $queueTaskId
     * @return mixed
     * @throws Exception
     */
    protected static function DownloadJson(?string $metadataUri, int $queueTaskId) // : mixed
    {
        if ($metadataUri == null)
            return null;

        if (strpos($metadataUri, "\x05") !== false)
            throw new Exception('$metadataUri contains the 0x05 byte...');

        $json = Models\Log\IpfsCache::model()->getJson($metadataUri);

        if ($json !== null)
            return $json;

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $metadataUrl = Ipfs::ConvertUriToUrl($metadataUri);

        // Some minting tools wrote a raw JavaScript value into the token's metadata URI. Build the sentinels from
        // the configured gateway, so the check keeps working when it is not the default ipfs.io one.
        $gateway = Config::cfg()->ipfsGatewayUrl;

        if ($metadataUrl == null ||
            $metadataUrl == $gateway.'undefined' ||
            $metadataUrl == $gateway.'[object Object]')
            throw new Exception("Bad metadata_uri: $metadataUri", JobResult::kFailedAndClose);


        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $ipfsSafeFileName = Ipfs::GetSafeFilename($metadataUri);

        $cacheFullPath = $ipfsSafeFileName
            ? Config::cfg()->privateStorageBasePath.'/ipfs-cache/direct_'.$ipfsSafeFileName
            : null;

        // Through the fetch gateway first when one is set, the public gateway after it
        $jsonString = null;

        foreach (Ipfs::FetchUrls($metadataUri) as $fetchUrl)
        {
            $metadataUrl = Ipfs::AppendUniqueTaskIdToUrl($fetchUrl, $queueTaskId);
            $jsonString  = Downloader::GetDirect($metadataUrl, $cacheFullPath, 20);

            if ($jsonString != null)
                break;
        }

        if ($jsonString == null)
            throw new Exception("IPFS returned empty data: $metadataUrl", JobResult::kFailedAndPostpone);

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $json = self::JsonDecode($jsonString);

        if ($json === null)
            throw new Exception("Failed to decode metadata JSON: $metadataUrl", JobResult::kFailedAndClose);

        Models\Log\IpfsCache::model()->save($metadataUri, $jsonString);

        // Tokens sharing a metadata URI share this file: another worker may have removed it already
        if ($cacheFullPath)
            @unlink($cacheFullPath);

        return $json;
    }

    protected static function JsonDecode(string $jsonString) // : mixed
    {
        // 1st try: convert as "original"
        $json = json_decode($jsonString, true);

        if (is_array($json))
            return $json;

        // 2nd try: convert with replacing undefined UTF8-chars
        $json = json_decode($jsonString, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);

        if (is_array($json))
            return $json;

        // 3rd try: naive fix for a trailing comma, like {'a':10,'b':20,}
        $jsonString = str_replace(',}', '}', $jsonString);
        $json = json_decode($jsonString, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);

        if (is_array($json))
            return $json;

        return null;
    }

    //------------------------------------------------------------------------------------------------------------------

    public static function GetSafeString($value, ?int $maxLength = null): ?string
    {
        if ($value === null || is_bool($value) || is_array($value) || is_object($value))
            return null;

        $value = (string) $value;

        if ($maxLength > 0 && mb_strlen($value) > $maxLength)
            return null;

        return $value;
    }
}
