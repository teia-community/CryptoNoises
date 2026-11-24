<?php

namespace CryptoNoisesTezos\Commands;

use CryptoNoisesTezos\Config;
use CryptoCore\Time;

trait JobsController_Cleaners
{
    public function cleanFileCache()
    {
        set_time_limit(Config::kJOBS_TIMEOUT_DEFAULT);

        if (!$this->checkLockAndWaitUntilNoOtherInstanceRuns('command-lock.jobs-clean.file-cache', Config::kJOBS_TIMEOUT_DEFAULT))
            return;

        $filesLimit = $this->getCmdVar('filesLimit', 1000);

        if ($filesLimit <= 0)
            $filesLimit = 1000;

        $this->cleanFileCacheInFolder(Config::cfg()->privateStorageBasePath.'/ipfs-cache/', $filesLimit);
        $this->cleanFileCacheInFolder(Config::cfg()->privateStorageBasePath.'/preview-origins/', $filesLimit);

        $this->logMsg("Done");
    }

    /**
     * Removes generated previews older than tezos.media.preview_retention_days.
     *
     * A preview exists so that a notification can carry an image, not as an archive. Telegram fetches the file
     * once while sending and serves its own copy afterwards, so the local file has done its job as soon as the
     * notification is delivered; Discord keeps the URL in the embed, so a very old embed may lose its image
     * once pruned. Either way nothing breaks: TelegramBotConnection::sendNotification() falls through to a
     * text-only message when sendPhoto() fails, so a missing preview costs the image, not the notification.
     *
     * The store is sharded by a hash of the IPFS URI (xx/yy/rest.jpg) and can hold millions of files, so this
     * walks it iteratively, stops after filesLimit deletions, and removes shard directories once they empty.
     */
    public function cleanOldPreviews()
    {
        set_time_limit(Config::runTimeLimit() + Config::kJOBS_TIMEOUT_DEFAULT);

        if (!$this->checkLockAndWaitUntilNoOtherInstanceRuns('command-lock.jobs-clean.old-previews', Config::kJOBS_TIMEOUT_DEFAULT))
            return;

        $retentionDays = Config::cfg()->media->previewRetentionDays;

        if ($retentionDays <= 0)
        {
            $this->logMsg("tezos.media.preview_retention_days is 0: keeping every preview, nothing to do");
            return;
        }

        $basePath = rtrim(Config::cfg()->ipfsLocalBasePath, '/');

        if ($basePath === '' || !is_dir($basePath))
        {
            $this->logErr("Preview path [$basePath] does not exist");
            return;
        }

        $filesLimit = (int) $this->getCmdVar('filesLimit', 50000);

        if ($filesLimit <= 0)
            $filesLimit = 50000;

        $cutoff  = Time::Time() - $retentionDays * 24 * 3600;
        $removed = 0;
        $failed  = 0;
        $freed   = 0;

        // Oldest first would be nicer, but sorting the whole tree is not an option at this size: a bounded
        // sweep per run gets there, because what is left over is simply older on the next run.
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($iterator as $entry)
        {
            /** @var \SplFileInfo $entry */
            if ($removed >= $filesLimit)
            {
                $this->logMsg("Reached filesLimit ($filesLimit), the rest waits for the next run");
                break;
            }

            $path = $entry->getPathname();

            // CHILD_FIRST means a directory is visited after its contents, so an emptied shard is removable here
            if ($entry->isDir())
            {
                if ($path !== $basePath && @rmdir($path))
                    $this->logMsg("Removed empty directory [$path]");

                continue;
            }

            if (!$entry->isFile())
                continue;

            // mtime, not atime: these files are written once and then served, and atime may well be disabled
            $mtime = $entry->getMTime();

            if ($mtime === false || $mtime >= $cutoff)
                continue;

            $size = $entry->getSize();

            if (@unlink($path))
            {
                $removed++;
                $freed += (int) $size;
                $this->logMsg("Removed [$path]");
            }
            else
            {
                // Suppressed above so one unreadable file cannot end the sweep, but a failure must not look
                // like "nothing to prune": a wrong owner on the shard directories would silently keep every
                // preview for ever.
                $failed++;
            }
        }

        // Forced: this is the one line a scheduled run should always leave behind
        $this->logMsg(sprintf('Removed %d preview(s) older than %d day(s), freeing %.1f MB',
            $removed, $retentionDays, $freed / 1024 / 1024), "\n", true);

        if ($failed > 0)
            $this->logErr(sprintf('%d preview(s) could not be removed - check that %s and its subdirectories '
                .'are owned by the user these commands run as', $failed, $basePath));
    }

    private function cleanFileCacheInFolder(string $basepath, int $filesLimit): void
    {
        $files = glob(rtrim($basepath, '/') . '/*');

        usort($files, function($a, $b)
        {
            $timestampA = file_exists($a) ? fileatime($a) : null;
            $timestampB = file_exists($b) ? fileatime($b) : null;

            if ($timestampA === null || $timestampB === null)
                return 0;

            return $timestampB - $timestampA;
        });

        // the tail holds the least recently accessed files
        while (count($files) > $filesLimit)
        {
            $file = array_pop($files);

            if (!file_exists($file))
                continue;

            unlink($file);
            $this->logMsg("Too many files, removing [$file]");
        }

        $days = 1;
        foreach ($files as $file)
        {
            if (!file_exists($file))
                continue;

            if (Time::Time()-fileatime($file) > $days * 86400)
            {
                unlink($file);
                $this->logMsg("Older than $days day(s), removing [$file]");
            }
        }
    }

    //------------------------------------------------------------------------------------------------------------------

    public function cleanMediaConvertZombies()
    {
        set_time_limit(Config::kJOBS_TIMEOUT_DEFAULT);

        if (!$this->checkLockAndWaitUntilNoOtherInstanceRuns('command-lock.jobs-clean.media-convert-zombies', Config::kJOBS_TIMEOUT_DEFAULT))
            return;

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $this->verbose = true;

        // Workaround: ImageMagick sometimes hangs on broken media; kill convert processes that run longer than 5 min.
        // `etime` is the elapsed time ([[dd-]hh:]mm:ss), not the CPU time, so hung processes are found too.
        // Match the tool and the storage path separately: MediaTool puts the "-limit ..." options and the forced
        // input coder ("png:/path/...") between the binary and the file name, so they are not adjacent in the args.
        $needleCmd  = Config::cfg()->cmd_imageMagickConvert;
        $needlePath = Config::cfg()->privateStorageBasePath.'/';
        $processes  = explode("\n", (string) shell_exec('ps -eo pid=,etime=,args='));

        foreach ($processes as $process)
        {
            $process = trim($process);

            if ($process == '' || strpos($process, $needleCmd) === false || strpos($process, $needlePath) === false)
                continue;

            if (preg_match('/^([0-9]+) +([0-9:-]+) +(.+)$/u', $process, $m) == 0)
                continue;

            $pid      = (int) $m[1];
            $seconds  = self::ParseElapsedTime($m[2]);
            $command  = $m[3];

            if ($seconds > 5*60)
            {
                shell_exec("kill -2 $pid");

                $this->logMsg("Kill process [$pid]: $command");
                $this->logMsg("");
                $this->logMsg("");
            }
        }
    }

    // ps etime: [[dd-]hh:]mm:ss -> seconds
    private static function ParseElapsedTime(string $etime): int
    {
        $days = 0;

        if (strpos($etime, '-') !== false)
            list($days, $etime) = explode('-', $etime, 2);

        $seconds = 0;
        foreach (explode(':', $etime) as $part)
            $seconds = $seconds * 60 + (int) $part;

        return (int) $days * 86400 + $seconds;
    }
}
