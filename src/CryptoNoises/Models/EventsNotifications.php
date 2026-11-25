<?php

namespace CryptoNoises\Models;

use CryptoNoises\Config;
use CryptoNoises\Core\Log;
use CryptoCore\Vss\Vss;
use CryptoCore\Vss\VssModel;
use CryptoCore\Vss\VssDbExp;
use CryptoNoises\Enum;

class EventsNotifications extends VssModel
{
    const kNotifyStatus_Idle                = 'idle';
    const kNotifyStatus_Active              = 'active';
    const kNotifyStatus_Done                = 'done';
    const kNotifyStatus_Skipped             = 'skipped';
    const kNotifyStatus_Error               = 'error';

    const kPRIORITY_INDEX_Normal            = 3;
    const kPRIORITY_INDEX_Low               = 5;    # TokenMint / TokenList -> (subscriber)
    const kPRIORITY_INDEX_Background        = 9;    # TokenMint (spam-channels)

    public static function model($className=__CLASS__): self
    {
        return parent::model($className);
    }

    public function tableName(string $platform): string
    {
        switch ($platform)
        {
            case Config::kPLATFORM_Discord:     return 'events_notifications_discord';
            case Config::kPLATFORM_Telegram:    return 'events_notifications_telegram';
            case Config::kPLATFORM_Matrix:      return 'events_notifications_matrix';
        }

        Log::Fatal(get_class($this), __FUNCTION__, "Unknown platform [$platform]");
        throw new \InvalidArgumentException("Unknown platform [$platform]");
    }

    public function insertNotifyTask(string $platform, int $userId,
                                     ?int $ownerAdid, ?string $ownerAddress,
                                     int $eventFlowId,
                                     int $priorityIndex,
                                     string $notificationType,
                                     string $note='')
    {
        $digestMinutes = Enum\NotificationType::IsDigestible($notificationType) ? self::DigestMinutes($userId) : 0;

        $item = [
            'user_id'               => $userId,
            'owner_adid'            => $ownerAdid,
            'owner_address'         => $ownerAddress,
            'priority_index'        => $priorityIndex,
            'event_flow_id'         => $eventFlowId,
            'notification_type'     => $notificationType,
            'note'                  => $note,
        ];

        // Due at the end of the user's current digest window (windows aligned on the clock)
        if ($digestMinutes > 0)
            $item['notify_after'] = VssDbExp::query('FROM_UNIXTIME(CEIL(UNIX_TIMESTAMP() / '.($digestMinutes * 60).') * '.($digestMinutes * 60).')');

        return Vss::db()->insert($this->tableName($platform), $item);
    }

    /** @var array<int, array{int, int}> user => [digest minutes, read at], per process */
    private static array $digestMinutes = [];

    private static function DigestMinutes(int $userId): int
    {
        if (!isset(self::$digestMinutes[$userId]) || time() - self::$digestMinutes[$userId][1] > 60)
        {
            $minutes = (int) Vss::db()->queryScalar("SELECT digest_minutes FROM options_notifier WHERE user_id = :u AND wallet_adid = 0", ['u' => $userId]);
            self::$digestMinutes[$userId] = [$minutes, time()];
        }

        return self::$digestMinutes[$userId][0];
    }

    /** A digest user's rows that are due, oldest first */
    public function getDueDigestRows(string $platform, int $userId): array
    {
        return Vss::db()->query("SELECT * FROM {$this->tableName($platform)}
            WHERE user_id = :u AND notify_status = :idle AND notify_after IS NOT NULL AND notify_after <= NOW()
            ORDER BY event_notification_id", ['u' => $userId, 'idle' => self::kNotifyStatus_Idle]);
    }

    public function updateNotifyStatus(string $platform, $eventNotificationId, $status, $execTime=null, $note=null)
    {
        $data = [
            'notify_status' => $status,
        ];

        if ($execTime)
        {
            $data['exec_time'] = $execTime;
            $data['task_lifetime'] = VssDbExp::query('TIMESTAMPDIFF(SECOND, ts_created, NOW())');
        }

        if ($note)
            $data['note'] = $note;

        return Vss::db()->update($this->tableName($platform), $data, ['event_notification_id'=>$eventNotificationId]);
    }

    public function getNextToNotify(string $platform)
    {
        $sql = "
            SELECT *
            FROM {$this->tableName($platform)}
            WHERE notify_status = :notify_status AND (notify_after IS NULL OR notify_after <= NOW())
            ORDER BY priority_index ASC, event_notification_id ASC
            LIMIT 1 
        ";

        return Vss::db()->queryRow($sql, ['notify_status'=>self::kNotifyStatus_Idle]);
    }
}
