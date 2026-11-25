<?php

namespace CryptoNoises\Commands;

use CryptoNoises\Bots\Core\NotificationSendSkippedException;
use CryptoNoises\Config;
use CryptoNoises\Core\Debugger;
use CryptoNoises\Models;
use CryptoCore\Process\Shutdown;
use CryptoCore\Time;
use Exception;

/**
 * The notification queues of one messenger (events and informs), sent one row at a time by a single process per
 * queue. The messenger supplies its platform id and a gate per recipient; Discord runs its own loop inside the
 * Discord client instead (DiscordNotifierThread).
 */
abstract class AbstractNotifier extends AbstractCommand
{
    /** Config::kPLATFORM_* */
    abstract protected function platform(): string;

    /**
     * The gate that sends to this user: isRecipientActive(), notifyEventFlow($item, $event), notifyInformFlow($item,
     * $inform).
     *
     * @throws Exception
     */
    abstract protected function getBotGate(int $userId): object;

    /** All of a digest user's due rows, as one message */
    private function processDigest(Models\EventsNotifications $queue, int $userId): void
    {
        $tsStart = Time::Microtime();
        $rows    = $queue->getDueDigestRows($this->platform(), $userId);

        foreach ($rows as $row)
            $queue->updateNotifyStatus($this->platform(), $row['event_notification_id'], $queue::kNotifyStatus_Active);

        try
        {
            $gate = $this->getBotGate($userId);

            $results = $gate->isRecipientActive()
                ? $gate->notifyEventDigest($rows)
                : array_fill_keys(array_column($rows, 'event_notification_id'), [$queue::kNotifyStatus_Skipped, 'User is frozen']);
        }
        catch (Exception $e)
        {
            $results = array_fill_keys(array_column($rows, 'event_notification_id'), [$queue::kNotifyStatus_Error, $e->getMessage()]);
            Debugger::Error($e->getMessage());
        }

        foreach ($results as $id => [$status, $note])
            $queue->updateNotifyStatus($this->platform(), $id, $status, Time::Microtime() - $tsStart, $note);

        $this->logMsg('Digest for user #'.$userId.': '.count($rows).' notification(s)');
    }

    /** Lock of one queue's sender. Telegram keeps its original lock ids, so a mixed-version restart cannot run two. */
    protected function lockId(string $queue): string
    {
        return 'command-lock.notifier.'.$queue;
    }

    //------------------------------------------------------------------------------------------------------------------

    public function notifyEventsFlow(): void
    {
        $this->processQueue($this->lockId('events-flow'), Models\EventsNotifications::model(), 'event_notification_id',
            function (object $gate, array $notificationItem): void
            {
                $eventItem = Models\EventsFlow::model()->getEventById($notificationItem['event_flow_id']);

                if (empty($eventItem))
                    throw new Exception("Cannot find event-flow #".$notificationItem['event_flow_id']);

                $gate->notifyEventFlow($notificationItem, $eventItem);
            });
    }

    public function notifyInformsFlow(): void
    {
        $this->processQueue($this->lockId('inform-flow'), Models\InformNotifications::model(), 'inform_notification_id',
            function (object $gate, array $notificationItem): void
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
     * @param callable $send function (object $gate, array $notificationItem): void - throws on failure
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

            $notificationItem = $queue->getNextToNotify($this->platform());

            if ($notificationItem == null)
            {
                $this->logMsg("No task. Sleep for 1 sec");
                sleep(1);
                continue;
            }

            if (!empty($notificationItem['notify_after']) && $queue instanceof Models\EventsNotifications)
            {
                $this->processDigest($queue, (int) $notificationItem['user_id']);
                continue;
            }

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $tsTaskStart    = Time::Microtime();
            $notificationId = $notificationItem[$idKey];
            $resultNote     = '';

            try
            {
                $queue->updateNotifyStatus($this->platform(), $notificationId, $queue::kNotifyStatus_Active);

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

            $queue->updateNotifyStatus($this->platform(), $notificationId,
                $status, Time::Microtime()-$tsTaskStart, $resultNote);
        }

        $this->logMsg("Done");
    }
}
