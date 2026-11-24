<?php

namespace CryptoCore\Commands;

use CryptoCore\Models\ConfigAbstract;
use CryptoCore\Vss\VssCommand;

abstract class AbstractCommand extends VssCommand
{
    /**
     * The config model that holds the command locks of this app.
     */
    abstract protected function lockConfig(): ConfigAbstract;

    protected function checkLockAndWaitUntilNoOtherInstanceRuns(?string $lockerKey, int $waitTimeout): bool
    {
        if ($this->isCommandLocked($lockerKey))
        {
            $this->logMsg("Command locked!");
            return false;
        }

        return $this->waitUntilNoOtherInstanceRuns($waitTimeout);
    }

    /**
     * Whether the command-lock.<command> switch is set (any value other than 0), unless unlock=1 was passed.
     *
     * A command that only runs for a minute reads this once at startup, which is how the switch used to take
     * effect within a minute. A long-lived worker has to keep checking it, see JobsController_Executor.
     */
    protected function isCommandLocked(?string $lockerKey): bool
    {
        if ($lockerKey === null || $lockerKey === '')
            return false;

        if (!empty($this->getCmdVar('unlock')))
            return false;

        return (bool) $this->lockConfig()->getValue($lockerKey);
    }
}
