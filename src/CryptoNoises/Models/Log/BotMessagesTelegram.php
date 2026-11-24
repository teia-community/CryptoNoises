<?php

namespace CryptoNoises\Models\Log;

use CryptoCore\Time;
use CryptoCore\Vss\Vss;
use CryptoCore\Vss\VssModel;

class BotMessagesTelegram extends VssModel
{
    const kSTATUS_Ok                = 'ok';
    const kSTATUS_Error             = 'error';

    public static function model($className=__CLASS__): self
    {
        return parent::model($className);
    }

    public function tableName(string $timePoint): string
    {
        return 'log_bot_messages_telegram_'.date('Y_m', strtotime($timePoint));
    }

    //------------------------------------------------------------------------------------------------------------------

    public function prepareTable(int $secondsAhead): void
    {
        MonthlyTables::Prepare('template__log_bot_messages_telegram', function (string $timePoint): string
        {
            return $this->tableName($timePoint);
        }, $secondsAhead);
    }

    public function dropTable(int $secondsOffset): void
    {
        $tableName = $this->tableName(Time::Now($secondsOffset));
        Vss::dbLog()->query("DROP TABLE IF EXISTS $tableName");
    }

    //------------------------------------------------------------------------------------------------------------------

    public function save($item)
    {
        $item['ts_event'] = Time::Now();

        // An incoming message is logged before anything has been sent, so it has no data_response at all;
        // BotMessagesDiscord already guards these the same way.
        if (is_object($item['data_request'] ?? null) || is_array($item['data_request'] ?? null))
            $item['data_request'] = json_encode($item['data_request']);

        if (is_object($item['data_response'] ?? null) || is_array($item['data_response'] ?? null))
            $item['data_response'] = json_encode($item['data_response']);

        return Vss::dbLog()->insert($this->tableName($item['ts_event']), $item);
    }
}
