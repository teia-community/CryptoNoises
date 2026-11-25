<?php

namespace CryptoNoises\Models\Log;

use CryptoNoises\Config;
use CryptoNoises\Core\Log;
use CryptoNoises\Models\EventsFlow;
use CryptoNoises\Models\EventsNotifications;
use CryptoCore\Time;
use CryptoCore\Vss\Vss;
use CryptoCore\Vss\VssModel;

class EventsNotificationsLog extends VssModel
{
    public static function model($className=__CLASS__): self
    {
        return parent::model($className);
    }

    public function tableName(string $platform, string $timePoint): string
    {
        switch ($platform)
        {
            case Config::kPLATFORM_Discord:     return 'events_notifications_discord_'.date('Y_m',  strtotime($timePoint));
            case Config::kPLATFORM_Telegram:    return 'events_notifications_telegram_'.date('Y_m', strtotime($timePoint));
            case Config::kPLATFORM_Matrix:      return 'events_notifications_matrix_'.date('Y_m', strtotime($timePoint));
        }
        Log::Fatal(get_class($this), __FUNCTION__, "Unknown platform [$platform]");
        throw new \InvalidArgumentException("Unknown platform [$platform]");
    }

    //------------------------------------------------------------------------------------------------------------------

    public function prepareTable(string $platform, int $secondsAhead): void
    {
        // Template names follow the table names: template__events_notifications_discord / _telegram
        $templateTable = 'template__'.preg_replace('/_\d{4}_\d{2}$/', '', $this->tableName($platform, Time::Now()));

        MonthlyTables::Prepare($templateTable, function (string $timePoint) use ($platform): string
        {
            return $this->tableName($platform, $timePoint);
        }, $secondsAhead);
    }

    public function dropTable(string $platform, int $secondsOffset): void
    {
        $tableName = $this->tableName($platform, Time::Now($secondsOffset));
        Vss::dbLog()->query("DROP TABLE IF EXISTS $tableName");
    }

    //------------------------------------------------------------------------------------------------------------------

    public function moveData(string $platform, string $timePoint): void
    {
        $tableName = $this->tableName($platform, $timePoint);

        $sqlMain = "
            FROM ".Config::cfg()->dbMain.".".EventsNotifications::model()->tableName($platform)." AS a
            LEFT JOIN ".Config::cfg()->dbMain.".".EventsFlow::model()->tableName()." AS b
                   ON a.event_flow_id = b.event_flow_id
            WHERE b.event_flow_id IS NULL
        ";

        // not replicated (log database)
        Vss::dbLog()->query("INSERT IGNORE INTO $tableName SELECT a.* ".$sqlMain);

        // replicated to the replica
        Vss::db()->query("DELETE a ".$sqlMain);
    }
}
