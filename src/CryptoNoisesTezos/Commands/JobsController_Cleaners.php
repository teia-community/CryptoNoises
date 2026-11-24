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
