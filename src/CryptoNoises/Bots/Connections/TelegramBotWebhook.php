<?php

namespace CryptoNoises\Bots\Connections;

use CryptoNoises\Models;

class TelegramBotWebhook
{
    const kUSER_ACTION_BotActivated         =   'bot-activated';
    const kUSER_ACTION_BotDeactivated       =   'bot-deactivated';

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    private TelegramBotConnection $core;

    private ?object $webhookData            = null;

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
    // Data vars
    // WARNING! Use as readonly!

    private ?string $queryType              = null;
    private ?string $querySubtype           = null;

    private ?string $chatType               = null;

    private ?int $messageId                 = null;
    private ?string $messageText            = null;
    private ?string $messageData            = null;

    private ?string $callbackQueryId        = null;

    private ?string $userAction             = null;

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public function __construct(TelegramBotConnection $core)
    {
        $this->core = $core;
    }

    public function init(): bool
    {
        $rawData = file_get_contents("php://input");

        $this->webhookData = json_decode($rawData);

        if (empty($this->webhookData))
            return false;

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $whData = $this->webhookData; // Just a shortcut

        if (isset($whData->message) || isset($whData->edited_message))
        {
            if (isset($whData->edited_message))
            {
                $whDataMsg          = $whData->edited_message;
                $this->queryType    = TelegramBotConnection::kQUERY_TYPE_MessageEdited;
            }
            else
            {
                $whDataMsg          = $whData->message;
                $this->queryType    = TelegramBotConnection::kQUERY_TYPE_Message;
            }

            // "from" and "chat" are both optional in the Bot API (an anonymous channel post carries
            // neither), and every flow below needs the user: treat such an update as unsupported.
            if (!isset($whDataMsg->from, $whDataMsg->chat))
                return false;

            $telegramChatId         = $whDataMsg->chat->id;
            $this->chatType         = $whDataMsg->chat->type;

            $telegramUserId         = $whDataMsg->from->id;
            $userName               = $whDataMsg->from->username ?? null;
            $userFirstName          = $whDataMsg->from->first_name;
            $userLastName           = $whDataMsg->from->last_name ?? null;
            $userLanguageCode       = $whDataMsg->from->language_code ?? null;

            $this->messageId        = $whDataMsg->message_id;
            $this->messageText      = $whDataMsg->text ?? null;
            $this->messageData      = null;
        }
        else if(isset($whData->callback_query) && isset($whData->callback_query->message))
        {
            $whDataMsg              = $whData->callback_query->message;

            $this->queryType        = TelegramBotConnection::kQUERY_TYPE_CallbackQuery;

            // "from" and "chat" are both optional in the Bot API (an anonymous channel post carries
            // neither), and every flow below needs the user: treat such an update as unsupported.
            if (!isset($whData->callback_query->from, $whDataMsg->chat))
                return false;

            $telegramChatId         = $whDataMsg->chat->id;
            $this->chatType         = $whDataMsg->chat->type;

            $telegramUserId         = $whData->callback_query->from->id;
            $userName               = $whData->callback_query->from->username ?? null;
            $userFirstName          = $whData->callback_query->from->first_name;
            $userLastName           = $whData->callback_query->from->last_name ?? null;
            $userLanguageCode       = $whData->callback_query->from->language_code ?? null;

            $this->messageId        = $whDataMsg->message_id;
            $this->messageText      = null;
            $this->messageData      = $whData->callback_query->data;

            $this->callbackQueryId  = $whData->callback_query->id;
        }
        else if(isset($whData->my_chat_member))
        {
            $whDataMsg              = $whData->my_chat_member;
            $this->queryType        = TelegramBotConnection::kQUERY_TYPE_UserAction;

            // "from" and "chat" are both optional in the Bot API (an anonymous channel post carries
            // neither), and every flow below needs the user: treat such an update as unsupported.
            if (!isset($whDataMsg->from, $whDataMsg->chat))
                return false;

            $telegramChatId         = $whDataMsg->chat->id;
            $this->chatType         = $whDataMsg->chat->type;

            $telegramUserId         = $whDataMsg->from->id;
            $userName               = $whDataMsg->from->username ?? null;
            $userFirstName          = $whDataMsg->from->first_name;
            $userLastName           = $whDataMsg->from->last_name ?? null;
            $userLanguageCode       = $whDataMsg->from->language_code ?? null;

            if ($whDataMsg->old_chat_member->status == 'kicked' && $whDataMsg->new_chat_member->status == 'member')
            {
                $this->userAction = self::kUSER_ACTION_BotActivated;
            }
            else if ($whDataMsg->old_chat_member->status == 'member' && $whDataMsg->new_chat_member->status == 'kicked')
            {
                $this->userAction = self::kUSER_ACTION_BotDeactivated;
            }
            else
            {
                // Unknown user action: report as error
                return false;
            }

            $this->querySubtype     = $this->userAction;
        }
        else # 'channel_post' | 'edited_channel_post'
        {
            // Unsupported update type: report as error
            return false;
        }

        unset($whData, $whDataMsg);

        $result = $this->core->initUserByTelegramIds($telegramUserId, $telegramChatId);

        if ($result)
        {
            $this->core->user->setUserAttributes([
                'username'          => $userName,
                'first_name'        => $userFirstName,
                'last_name'         => $userLastName,
                'language_code'     => $userLanguageCode,
            ]);
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
        // Log incoming message (core->user is set only when the user was initialized)

        $logItem = [];
        $logItem['channel_id']      = $this->core->getBotId();
        $logItem['status']          = $result ? Models\Log\BotMessagesTelegram::kSTATUS_Ok : Models\Log\BotMessagesTelegram::kSTATUS_Error;
        $logItem['query_type']      = $this->queryType;
        $logItem['query_subtype']   = $this->querySubtype;
        $logItem['user_id']         = $result ? $this->core->user->userId : null;

        $logItem['chat_type']       = $this->chatType;
        $logItem['chat_id']         = $telegramChatId;

        $logItem['from_user_id']    = $telegramUserId;
        $logItem['from_username']   = $userName;
        $logItem['from_lang_code']  = $userLanguageCode;

        $logItem['message_id']      = $this->messageId;
        $logItem['message_text']    = $this->messageText;
        $logItem['message_data']    = $this->messageData;

        $logItem['data_request']    = $rawData;

        Models\Log\BotMessagesTelegram::model()->save($logItem);

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        return $result;
    }

    //------------------------------------------------------------------------------------------------------------------

    public function isPrivateChat(): bool
    {
        return $this->chatType == 'private';
    }

    public function isCallback(): bool
    {
        return $this->queryType == TelegramBotConnection::kQUERY_TYPE_CallbackQuery;
    }

    public function isUserAction(): bool
    {
        return $this->userAction !== null;
    }

    public function getRawData(): ?object
    {
        return $this->webhookData;
    }

    public function getMessageId(): ?int
    {
        return $this->messageId;
    }

    public function getMessageText(): string
    {
        return (string) $this->messageText;
    }

    public function getMessageData(): string
    {
        return (string) $this->messageData;
    }

    public function getCallbackQueryId(): ?string
    {
        return $this->callbackQueryId;
    }

    public function getUserAction(): ?string
    {
        return $this->userAction;
    }
}
