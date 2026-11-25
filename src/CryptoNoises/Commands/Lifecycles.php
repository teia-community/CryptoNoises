<?php

namespace CryptoNoises\Commands;

use CryptoNoises\Config;
use CryptoNoises\Core\Debugger;
use CryptoNoises\Models;
use CryptoCore\Time;
use VssCfg;

class Lifecycles extends AbstractCommand
{
    public function prepareTables(): void
    {
        $secondsAhead = 3 * 86400;

        Models\Log\EventsFlowLog::model()->prepareTable($secondsAhead);
        Models\Log\EventsFlowExecAt::model()->prepareTable($secondsAhead);

        Models\Log\EventsNotificationsLog::model()->prepareTable(Config::kPLATFORM_Discord, $secondsAhead);
        Models\Log\EventsNotificationsLog::model()->prepareTable(Config::kPLATFORM_Telegram, $secondsAhead);
        Models\Log\EventsNotificationsLog::model()->prepareTable(Config::kPLATFORM_Matrix, $secondsAhead);

        Models\Log\BotMessagesDiscord::model()->prepareTable($secondsAhead);
        Models\Log\BotMessagesTelegram::model()->prepareTable($secondsAhead);
    }

    public function cleanUpCallbackActions(): void
    {
        $result = Models\CallbackActions::model()->cleanUpExpired();
        $this->logMsg("Models\CallbackActions.cleanUpExpired: ".$result);
    }

    public function cleanUpEventsFlow(): void
    {
        $timePoint = Time::Now(-10*60);

        // This month's log tables must exist before rows move there - a platform added mid-month has none yet
        foreach ([Config::kPLATFORM_Discord, Config::kPLATFORM_Telegram, Config::kPLATFORM_Matrix] as $platform)
            Models\Log\EventsNotificationsLog::model()->prepareTable($platform, 0);

        Models\Temp_EventsFlowLockedIds::model()->begin();
        Models\Temp_EventsFlowLockedIds::model()->fixLockedIds(Config::kPLATFORM_Discord);
        Models\Temp_EventsFlowLockedIds::model()->fixLockedIds(Config::kPLATFORM_Telegram);
        Models\Temp_EventsFlowLockedIds::model()->fixLockedIds(Config::kPLATFORM_Matrix);

        Models\Log\EventsFlowLog::model()->moveData($timePoint);
        Models\Log\EventsNotificationsLog::model()->moveData(Config::kPLATFORM_Discord, $timePoint);
        Models\Log\EventsNotificationsLog::model()->moveData(Config::kPLATFORM_Telegram, $timePoint);
        Models\Log\EventsNotificationsLog::model()->moveData(Config::kPLATFORM_Matrix, $timePoint);

        Models\Temp_EventsFlowLockedIds::model()->close();
    }

    public function cleanUpUserActions(): void
    {
        $result = Models\UsersAction::model()->cleanUpExpired();
        $this->logMsg("Models\UsersAction.cleanUpExpired: ".$result);
    }

    public function cleanUpOldData(): void
    {
        $secondsBack = -64 * 86400; // back for 64 days!

        Models\Log\EventsFlowLog::model()->dropTable($secondsBack);
        Models\Log\EventsFlowExecAt::model()->dropTable($secondsBack);

        Models\Log\EventsNotificationsLog::model()->dropTable(Config::kPLATFORM_Discord, $secondsBack);
        Models\Log\EventsNotificationsLog::model()->dropTable(Config::kPLATFORM_Telegram, $secondsBack);
        Models\Log\EventsNotificationsLog::model()->dropTable(Config::kPLATFORM_Matrix, $secondsBack);

        Models\Log\BotMessagesDiscord::model()->dropTable($secondsBack);
        Models\Log\BotMessagesTelegram::model()->dropTable($secondsBack);

        foreach (Models\Log\PersonalDataRetention::CleanUp(64) as $source => $count)
            $this->logMsg("$source: $count deleted");
    }

    public function checkErrorLogs(): void
    {
        if (!$this->checkLockAndWaitUntilNoOtherInstanceRuns('command-lock.lifecycles.check-error-logs', 50))
            return;

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $path = VssCfg::cfg()->pathLog;

        $hd = opendir($path);

        while(($file = readdir($hd)) !== false)
        {
            if ($file == '.' || $file == '..')
                continue;

            if (preg_match('/(.*)error(.+)log/i', $file) == 0)
                continue;

            // Deprecations are logged but are not errors; alert on the first real error line, if any
            $firstError = null;
            $handle = fopen($path.'/'.$file, 'r');

            while ($handle && $firstError === null && ($line = fgets($handle)) !== false)
                if (trim($line) !== '' && !str_contains($line, 'Deprecated (not fatal)'))
                    $firstError = trim($line);

            if ($handle)
                fclose($handle);

            if ($firstError !== null)
                Debugger::Error("PHP error in '$file': ".mb_substr($firstError, 0, 500));

            $file_new = $file;
            $file_new = str_ireplace('error', 'err_'.Time::Time(), $file_new); // need remove word 'error' (any case, same as the match above)

            rename($path.'/'.$file, $path.'/'.$file_new);
        }

        closedir($hd);
    }
}
