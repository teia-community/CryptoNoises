<?php

namespace CryptoNoises\Models;

use CryptoNoises\Config;
use CryptoNoises\Core\Log;
use CryptoCore\Vss\Vss;
use CryptoCore\Vss\VssModel;
use CryptoCore\Vss\VssDbExp;

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
        $item = [
            'user_id'               => $userId,
            'owner_adid'            => $ownerAdid,
            'owner_address'         => $ownerAddress,
            'priority_index'        => $priorityIndex,
            'event_flow_id'         => $eventFlowId,
            'notification_type'     => $notificationType,
            'note'                  => $note,
        ];
        return Vss::db()->insert($this->tableName($platform), $item);
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
            WHERE notify_status = :notify_status
            ORDER BY priority_index ASC, event_notification_id ASC
            LIMIT 1 
        ";

        return Vss::db()->queryRow($sql, ['notify_status'=>self::kNotifyStatus_Idle]);
    }
}
