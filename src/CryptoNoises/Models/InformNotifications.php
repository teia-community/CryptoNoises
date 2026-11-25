<?php

namespace CryptoNoises\Models;

use CryptoNoises\Config;
use CryptoNoises\Core\Log;
use CryptoCore\Vss\Vss;
use CryptoCore\Vss\VssModel;

class InformNotifications extends VssModel
{
    const kNotifyStatus_Idle        = 'idle';
    const kNotifyStatus_Active      = 'active';
    const kNotifyStatus_Done        = 'done';
    const kNotifyStatus_Error       = 'error';

    public static function model($className=__CLASS__): self
    {
        return parent::model($className);
    }

    public function tableName(string $platform): string
    {
        switch ($platform)
        {
            case Config::kPLATFORM_Discord:     return 'inform_notifications_discord';
            case Config::kPLATFORM_Telegram:    return 'inform_notifications_telegram';
            case Config::kPLATFORM_Matrix:      return 'inform_notifications_matrix';
        }

        Log::Fatal(get_class($this), __FUNCTION__, "Unknown platform [$platform]");
        throw new \InvalidArgumentException("Unknown platform [$platform]");
    }

    public function updateNotifyStatus(string $platform, $informNotificationId, $status, $execTime=null, $note=null)
    {
        $data = [
            'notify_status' => $status,
        ];

        if ($execTime)
            $data['exec_time'] = $execTime;

        if ($note)
            $data['note'] = $note;

        return Vss::db()->update($this->tableName($platform), $data, ['inform_notification_id'=>$informNotificationId]);
    }

    public function getNextToNotify(string $platform)
    {
        $sql = "
            SELECT *
            FROM {$this->tableName($platform)}
            WHERE notify_status = :notify_status
            ORDER BY priority_index ASC, inform_notification_id ASC
            LIMIT 1 
        ";

        return Vss::db()->queryRow($sql, ['notify_status'=>self::kNotifyStatus_Idle]);
    }
}
