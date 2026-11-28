<?php

namespace CryptoNoisesTezos\Core;

use CryptoNoisesTezos\Config;
use CryptoNoisesTezos\Core\Http\SafeCurl;

class Downloader
{
    protected string $downloadUrl;
    protected string $savePath;
    protected string $saveName;

    protected ?string $cachePath = null;
    protected ?string $cacheFileId = null;

    //------------------------------------------------------------------------------------------------------------------

    public static function GetDirect(string $url, ?string $cacheFullPath = null, int $timeout=300): ?string
    {
        // Shared by the workers: the file may go between the check and the read, then it is fetched again
        if ($cacheFullPath && file_exists($cacheFullPath))
        {
            $cached = @file_get_contents($cacheFullPath);

            if ($cached !== false)
            {
                @touch($cacheFullPath);
                return $cached;
            }
        }

        $response = SafeCurl::Fetch($url, $timeout, Config::kDOWNLOAD_MAX_METADATA_BYTES);

        if (!$response->isOk())
            return null;

        $data = $response->body;

        if ($cacheFullPath && mb_strlen($data) > 0)
        {
            if (mb_strlen($data) <= 4096 && strpos($data, '504 Gateway Time-out') !== false)
                return null;

            // The cache directory is not created anywhere else on this path (unlike setCacheFileId()), so on a
            // fresh install every write failed with "No such file or directory".
            //
            // mkdir() is suppressed and re-checked because several workers share this directory: two of them
            // creating it at the same time makes the loser warn "File exists", and Fat-Free turns that warning
            // into a fatal. The re-check still distinguishes a lost race (directory now exists, carry on) from a
            // real failure such as a permission problem or a full disk - and this is only a cache, so a real
            // failure skips the write instead of killing the job.
            $cacheDir = dirname($cacheFullPath);

            if (is_dir($cacheDir) || @mkdir($cacheDir, 0755, true) || is_dir($cacheDir))
                file_put_contents($cacheFullPath, $data);
        }

        return $data;
    }

    //------------------------------------------------------------------------------------------------------------------

    public function __construct(string $url, string $savePath, string $saveName)
    {
        $this->downloadUrl = $url;
        $this->savePath = $savePath;
        $this->saveName = $saveName;
    }

    public function getFullPathName(): string
    {
        return $this->savePath . '/' . $this->saveName;
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public function getCachePathName(): string
    {
        return $this->cachePath . '/' . $this->cacheFileId;
    }

    public function setCacheFileId(string $cachePath, string $cacheFileId): void
    {
        // Suppressed and re-checked: workers share this directory, see GetDirect(). A real failure is left to
        // surface on the write that follows, as it did before.
        if (!is_dir($cachePath))
            @mkdir($cachePath, 0755, true);

        $this->cachePath = $cachePath;
        $this->cacheFileId = $cacheFileId;
    }

    public function isUseCache(): bool
    {
        return $this->cacheFileId != null;
    }

    public function removeCache(): bool
    {
        if (!$this->isUseCache())
            return false;

        $cachePathName = $this->getCachePathName();

        // Another worker may remove it at the same time
        return @unlink($cachePathName);
    }

    //------------------------------------------------------------------------------------------------------------------

    public function save(): bool
    {
        // Suppressed and re-checked: workers share this directory, see GetDirect(). A real failure is left to
        // surface on the write that follows, as it did before.
        if (!is_dir($this->savePath))
            @mkdir($this->savePath, 0755, true);

        $savePathName = $this->getFullPathName();

        if (file_exists($savePathName))
            return true;

        if ($this->isUseCache())
        {
            $cachePathName = $this->getCachePathName();

            // Copy, so later changes to $savePathName never touch the cache file
            if (file_exists($cachePathName))
            {
                touch($cachePathName);
                return copy($cachePathName, $savePathName);
            }
        }

        $fileHandle = fopen($savePathName, 'w+');
        $response = SafeCurl::Fetch($this->downloadUrl, 5 * 60, Config::kDOWNLOAD_MAX_MEDIA_BYTES, $fileHandle);
        fclose($fileHandle);

        clearstatcache(true, $savePathName);
        $filesize = filesize($savePathName);

        if (!$response->isOk() || $filesize == 0)
        {
            unlink($savePathName);
            return false;
        }

        // Why I check filesize.
        // For example, it may return "504 Gateway Time-out" html-data with size 164 bytes
        // So, Ignore tiny files in cache
        if ($this->isUseCache() && $filesize >= 2000)
        {
            $cachePathName = $this->getCachePathName();

            if (!file_exists($cachePathName))
                copy($savePathName, $cachePathName);
        }

        return true;
    }

    public function delete(): bool
    {
        $saveFullName = $this->getFullPathName();

        if (!file_exists($saveFullName))
            return false;

        unlink($saveFullName);
        return true;
    }
}
