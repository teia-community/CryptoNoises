<?php

namespace CryptoNoises\Models;

use CryptoCore\Vss\Vss;
use CryptoCore\Vss\VssModel;

/**
 * Baker alerts that went out, one row per (alert, user) - "period:187:tz1…", "proposal:Pt…:187:tz1…",
 * "vote:187:tz1…". Same reason as UnstakeRemindersSent: an event only stays in events_flow for about ten minutes, so
 * it cannot stop a job that runs every ten minutes from sending the same alert again.
 */
class BakerAlertsSent extends VssModel
{
    public static function model($className = __CLASS__): self
    {
        return parent::model($className);
    }

    public function tableName(): string
    {
        return 'baker_alerts_sent';
    }

    /**
     * @return bool true the first time only; claimed before the event is saved, so a failed save loses the alert
     *              rather than repeating it
     */
    public function has(string $alertKey, int $userId): bool
    {
        return (bool) Vss::db()->queryScalar("SELECT 1 FROM {$this->tableName()} WHERE alert_key = :k AND user_id = :u",
            ['k' => mb_substr($alertKey, 0, 128), 'u' => $userId]);
    }

    public function claim(string $alertKey, int $userId): bool
    {
        return (int) Vss::db()->insert($this->tableName(), ['alert_key' => mb_substr($alertKey, 0, 128), 'user_id' => $userId], 'IGNORE') === 1;
    }
}
