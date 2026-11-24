<?php

namespace CryptoNoises\Models\Log;

use CryptoNoises\Localize\Notification;
use CryptoCore\Time;
use CryptoCore\Vss\Vss;
use CryptoCore\Vss\VssModel;
use Discord\Parts\Channel\Message\Message;
use Discord\Parts\Interactions\Interaction;
class BotMessagesDiscord extends VssModel
{
    const TYPE_Message              = 'message';
    const TYPE_Interaction          = 'interaction';
    const TYPE_Response             = 'response';

    const kSTATUS_Ok                = 'ok';
    const kSTATUS_Error             = 'error';

    public static function model($className=__CLASS__): self
    {
        return parent::model($className);
    }

    public function tableName(string $timePoint): string
    {
        return 'log_bot_messages_discord_'.date('Y_m', strtotime($timePoint));
    }

    //------------------------------------------------------------------------------------------------------------------

    public function prepareTable(int $secondsAhead): void
    {
        MonthlyTables::Prepare('template__log_bot_messages_discord', function (string $timePoint): string
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

    public function saveMessage(Message $message): BotMessagesDiscordRecord
    {
        $logItem = [];
        $logItem['type']                = self::TYPE_Message;
        $logItem['platform_user_id']    = $message->author->id;
        $logItem['channel_id']          = $message->channel_id;
        $logItem['guild_id']            = $message->guild_id;
        $logItem['application_id']      = $message->application_id;
        $logItem['message']             = $message->content;
        $logItem['data_request']        = $message->getRawAttributes();

        return $this->save($logItem);
    }

    public function saveInteraction(Interaction $interaction): BotMessagesDiscordRecord
    {
        $logItem = [];
        $logItem['type']                = self::TYPE_Interaction;
        $logItem['platform_user_id']    = $interaction->member ? $interaction->member->user->id : $interaction->user->id;
        $logItem['channel_id']          = $interaction->channel_id;
        $logItem['guild_id']            = $interaction->guild_id;
        $logItem['application_id']      = $interaction->application_id;
        $logItem['message']             = '/'.$interaction->data->name;
        $logItem['data_request']        = $interaction->data->getRawAttributes();

        return $this->save($logItem);
    }

    public function saveResponse(?int $discordUserId, ?int $discordChannelId, Notification $notification): BotMessagesDiscordRecord
    {
        $logItem = [];
        $logItem['type']                = self::TYPE_Response;
        $logItem['platform_user_id']    = $discordUserId;
        $logItem['channel_id']          = $discordChannelId;
        $logItem['message']             = $notification->getTitle();
        $logItem['data_response']       = get_class($notification);

        return $this->save($logItem);
    }

    public function save(array $item): BotMessagesDiscordRecord
    {
        $item['ts_event'] = Time::Now();

        if (is_object($item['data_request'] ?? null) || is_array($item['data_request'] ?? null))
            $item['data_request'] = json_encode($item['data_request']);

        if (is_object($item['data_response'] ?? null) || is_array($item['data_response'] ?? null))
            $item['data_response'] = json_encode($item['data_response']);

        $logId = Vss::dbLog()->insertAndGetId($this->tableName($item['ts_event']), $item);

        return new BotMessagesDiscordRecord($item['ts_event'], $logId);
    }

    public function update(string $timePoint, int $logId, array $data)
    {
        Vss::dbLog()->update($this->tableName($timePoint), $data, ['id' => $logId]);
    }
}
