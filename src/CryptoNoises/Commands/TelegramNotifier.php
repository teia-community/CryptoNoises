<?php

namespace CryptoNoises\Commands;

use CryptoNoises\Bots\Connections\TelegramBotConnection;
use CryptoNoises\Bots\Core\NotificationSendSkippedException;
use CryptoNoises\Bots\Gates\TelegramBotGate;
use CryptoNoises\Config;
use CryptoNoises\Core\Debugger;
use CryptoNoises\Models;
use CryptoCore\Process\Shutdown;
use CryptoCore\Time;
use Exception;

class TelegramNotifier extends AbstractCommand
{
    /** @var TelegramBotGate[] */
    protected array $botsGates = [];

    public function __construct()
    {
        parent::__construct();

        Config::cfg()->initializeByPlatform(Config::kPLATFORM_Telegram);
    }

    public function notifyEventsFlow(): void
    {
        $this->processQueue('command-lock.notifier.events-flow', Models\EventsNotifications::model(), 'event_notification_id',
            function (TelegramBotGate $gate, array $notificationItem): void
            {
                $eventItem = Models\EventsFlow::model()->getEventById($notificationItem['event_flow_id']);

                if (empty($eventItem))
                    throw new Exception("Cannot find event-flow #".$notificationItem['event_flow_id']);

                $gate->notifyEventFlow($notificationItem, $eventItem);
            });
    }

    public function notifyInformsFlow(): void
    {
        $this->processQueue('command-lock.notifier.inform-flow', Models\InformNotifications::model(), 'inform_notification_id',
            function (TelegramBotGate $gate, array $notificationItem): void
            {
                $informItem = Models\InformFlow::model()->getInformById($notificationItem['inform_flow_id']);

                if (empty($informItem))
                    throw new Exception("Cannot find inform-flow #".$notificationItem['inform_flow_id']);

                $gate->notifyInformFlow($notificationItem, $informItem);
            });
    }

    //------------------------------------------------------------------------------------------------------------------

    /**
     * Takes queue rows one by one until the time limit and sends them; the row status becomes done / skipped / error.
     *
     * @param Models\EventsNotifications|Models\InformNotifications $queue
     * @param callable $send function (TelegramBotGate $gate, array $notificationItem): void - throws on failure
     */
    private function processQueue(string $lockId, $queue, string $idKey, callable $send): void
    {
        $tsStart = Time::Microtime();

        set_time_limit(Config::runTimeLimit() + Config::kJOBS_TIMEOUT_TELEGRAM_NOTIFIER);

        if (!$this->checkLockAndWaitUntilNoOtherInstanceRuns($lockId, Config::kJOBS_TIMEOUT_DEFAULT))
            return;

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $iteration = 0;

        while (Time::Microtime() - $tsStart <= Config::runTimeLimit() && !Shutdown::Requested())
        {
            $this->logMsg("Iteration ".(++$iteration));

            $notificationItem = $queue->getNextToNotify(Config::kPLATFORM_Telegram);

            if ($notificationItem == null)
            {
                $this->logMsg("No task. Sleep for 1 sec");
                sleep(1);
                continue;
            }

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $tsTaskStart    = Time::Microtime();
            $notificationId = $notificationItem[$idKey];
            $resultNote     = '';

            try
            {
                $queue->updateNotifyStatus(Config::kPLATFORM_Telegram, $notificationId, $queue::kNotifyStatus_Active);

                $gate = $this->getBotGate($notificationItem['user_id']);

                if (!$gate->isRecipientActive())
                    throw new NotificationSendSkippedException('User is frozen');

                $send($gate, $notificationItem);

                $status = $queue::kNotifyStatus_Done;
            }
            catch (NotificationSendSkippedException $e)
            {
                // the inform queue has no 'skipped' status
                $status     = defined(get_class($queue).'::kNotifyStatus_Skipped') ? $queue::kNotifyStatus_Skipped : $queue::kNotifyStatus_Error;
                $resultNote = $e->getMessage();
            }
            catch (Exception $e)
            {
                $status     = $queue::kNotifyStatus_Error;
                $resultNote = $e->getMessage();

                Debugger::Error($resultNote);
            }

            $queue->updateNotifyStatus(Config::kPLATFORM_Telegram, $notificationId,
                $status, Time::Microtime()-$tsTaskStart, $resultNote);
        }

        $this->logMsg("Done");
    }

    /**
     * @throws Exception
     */
    private function getBotGate(int $userId): TelegramBotGate
    {
        if (!isset($this->botsGates[$userId]))
        {
            $tgBotConnection = new TelegramBotConnection();

            if (!$tgBotConnection->initUserById($userId))
                throw new Exception('Cannot initialize Telegram user by userId#'.$userId);

            $this->botsGates[$userId] = new TelegramBotGate($tgBotConnection);
        }

        return $this->botsGates[$userId];
    }
}
