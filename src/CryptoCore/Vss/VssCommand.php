<?php

namespace CryptoCore\Vss;

use CryptoCore\Process\ProcessList;
use CryptoCore\Process\Shutdown;
use CryptoCore\Time;

class VssCommand
{
    public array $forcedCmdVars = array();

    protected int $verbose = 0;

    //------------------------------------------------------------------------------------------------------------------

    public function __construct()
    {
        $this->verbose = (int) $this->getCmdVar('verbose', 0);
    }

    //------------------------------------------------------------------------------------------------------------------

    protected function waitUntilNoOtherInstanceRuns(int $waitTimeout): bool
    {
        $tsStart = Time::Microtime();

        while (true)
        {
            if (Time::Microtime() - $tsStart >= ($waitTimeout - 1))       // -1 sec leaves time to finish before set_time_limit() kills the process
                return false;

            if (Shutdown::Requested())
                return false;

            $activeThreadsCount = $this->getSelfTaskActiveThreadsCount();

            if ($activeThreadsCount <= 0)
                break;

            $this->logMsg("Job [{$this->getSelfTaskExecCode()}] is running in $activeThreadsCount other process(es). Sleeping 1 sec before retry");
            sleep(1);
        }

        return true;
    }

    private function getSelfTaskExecCode(): string
    {
        return join(' ', $_SERVER['argv']);
    }

    private function getSelfTaskActiveThreadsCount(): int
    {
        return ProcessList::CountSameCommand($this->getSelfTaskExecCode());
    }

    //------------------------------------------------------------------------------------------------------------------

    protected function logMsg(string $msg, string $endLine = "\n", bool $isForcedShow = false): bool
    {
        // bits---
        // 21
        // -- = 0 - none
        // -+ = 1 - echo
        // +- = 2 - write
        // ++ = 3 - echo + write

        $verbose = $this->verbose;

        if ($verbose == 0 && $isForcedShow)
            $verbose = 1;

        if ($verbose == 0)
            return false;

        if ($verbose & 1)
        {
            echo $msg . $endLine;
            ob_flush();
        }

        if ($verbose & 2)
        {
            file_put_contents(\VssCfg::cfg()->pathLog.'/'.substr(strrchr('\\'.get_class($this), '\\'), 1).'-'.date('Y-m').'.log', $msg.$endLine, FILE_APPEND);
        }

        return true;
    }

    protected function logErr(string $msg)
    {
        $this->logMsg($msg, "\n", true);
    }

    public function getCmdVar(string $lookupKey, $defaultValue = null)
    {
        if (isset($this->forcedCmdVars[$lookupKey]))
            return $this->forcedCmdVars[$lookupKey];

        for ($i = 2; $i < count($_SERVER['argv']); $i++)
        {
            $parts = explode('=', $_SERVER['argv'][$i], 2);   // argument without "=" has no value

            if ($parts[0] == $lookupKey)
                return $parts[1] ?? null;
        }

        return $defaultValue;
    }
}
