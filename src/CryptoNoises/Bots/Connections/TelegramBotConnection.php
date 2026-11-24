<?php

namespace CryptoNoises\Bots\Connections;

use CryptoNoises\Bots\Core\NotificationSendFailedException;
use CryptoNoises\Config;
use CryptoNoises\Core\Log;
use CryptoNoises\Entities\CallbackAction;
use CryptoNoises\Entities\User;
use CryptoNoises\Localize\Notification;
use CryptoNoises\Localize\NotificationButton;
use CryptoNoises\Models;
use Exception;
use TuriBot\Client;

/**
 * https://core.telegram.org/bots/api
 * https://core.telegram.org/bots/api#available-methods
 *
 * https://core.telegram.org/bots/api#html-style
 */
class TelegramBotConnection
{
    // Keep in sync with the log_bot_messages.query_type enum
    const kQUERY_TYPE_Message               = 'message';
    const kQUERY_TYPE_MessageEdited         = 'message:edited';
    const kQUERY_TYPE_CallbackQuery         = 'callback_query';
    const kQUERY_TYPE_UserAction            = 'user_action';
    const kQUERY_TYPE_ApiResponse           = 'api:response';
    const kQUERY_TYPE_ApiSendMessage        = 'api:send-message';

    const FORMAT_HTML                       = 'HTML';

    // Bot API 7.0 replaced the "disable_web_page_preview" flag by the LinkPreviewOptions object
    const LINK_PREVIEW_DISABLED             = ['is_disabled' => true];
    protected string $apiKey;
    protected ?Client $client;

    public User $user;
    #           $user->platformUserId     is telegram_user_id
    #           $user->platformChannelId  is telegram_chat_id

    public TelegramBotWebhook $webhook;

    //------------------------------------------------------------------------------------------------------------------

    public function __construct(string $apiKey = TELEGRAM_BOT_API)
    {
        $this->apiKey = $apiKey;
        $this->client = $this->buildClient($this->apiKey);

        $this->webhook = new TelegramBotWebhook($this);
    }

    //------------------------------------------------------------------------------------------------------------------

    private static array $clients = [];

    private function buildClient(string $apiKey): Client
    {
        if (!isset(self::$clients[$apiKey]))
            self::$clients[$apiKey] = new Client($apiKey, false);

        return self::$clients[$apiKey];
    }

    //------------------------------------------------------------------------------------------------------------------

    public function getBotId(): ?int
    {
        return self::UnpackBotId($this->apiKey);
    }

    public static function UnpackBotId($apiKey): ?int
    {
        if ($apiKey == '')
            return null;

        $channelId = explode(':', $apiKey, 2)[0];

        return is_numeric($channelId) ? $channelId : null;
    }

    //------------------------------------------------------------------------------------------------------------------

    public function initUserByTelegramIds($telegramUserId, $telegramChatId): bool
    {
        $user = User::GetOrCreatePlatformUser(Config::kPLATFORM_Telegram, $telegramUserId, $telegramChatId);

        if ($user == null)
        {
            Log::Error(get_class($this), __FUNCTION__, "Cannot find user item", [
                'telegramUserId' => $telegramUserId,
                'telegramChatId' => $telegramChatId,
            ]);
            return false;
        }

        return $this->initInnerData($user);
    }

    public function initUserById($userId): bool
    {
        $user = User::GetByUserId($userId);

        if ($user == null)
        {
            Log::Error(get_class($this), __FUNCTION__, "Cannot find user item", [
                'user_id' => $userId,
            ]);
            return false;
        }

        return $this->initInnerData($user);
    }

    private function initInnerData(User $user): bool
    {
        $this->user = $user;
        return true;
    }

    //------------------------------------------------------------------------------------------------------------------
    // Send notification

    public function sendBotNotifications(?Notification $notification): void
    {
        while ($notification !== null)
        {
            $this->sendNotification($notification);
            $notification = $notification->next;
        }

        if ($this->webhook->isCallback())
            $this->answerCallbackQuery(''); // Just to hide "Loading..." bar
    }

    const kDELIVERY_RETRY_DELAY             = 3; // seconds

    private ?string $lastSendError = null;

    /**
     * Notifier path: every message must reach Telegram. A failed message is sent once more after a short delay,
     * then the failure is reported to the caller (already delivered messages of the chain are not sent again).
     *
     * @throws NotificationSendFailedException
     */
    public function deliverBotNotifications(?Notification $notification): void
    {
        while ($notification !== null)
        {
            if (!$this->sendNotification($notification))
            {
                sleep(self::kDELIVERY_RETRY_DELAY);

                if (!$this->sendNotification($notification))
                    throw new NotificationSendFailedException('Telegram: '.($this->lastSendError ?? 'unknown error'));
            }

            $notification = $notification->next;
        }
    }

    private bool $didUpdateMessage = false;

    private function sendNotification(Notification $notification): bool
    {
        if (!$this->didUpdateMessage)
        {
            $editMessageId = $this->webhook->isCallback() ? $this->webhook->getMessageId() : null;
            $this->didUpdateMessage = true;
        }
        else
        {
            $editMessageId = null;
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        if ($notification instanceof Notification\System_Skip)
            return true;

        if ($notification instanceof Notification\System_Delete && $editMessageId !== null)
        {
            $this->deleteMessage($editMessageId);
            return true;
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        list ($message, $imageUrl, $videoUrl, $inlineKeyboard) = $this->buildMessage($notification);

        if ($videoUrl && $imageUrl)
        {
            $result = $this->sendVideo($videoUrl, $imageUrl, $message, $inlineKeyboard);

            if ($result)
                return true;
        }
        else if ($imageUrl)
        {
            $result = $this->sendPhoto($imageUrl, $message, $inlineKeyboard);

            if ($result)
                return true;
        }

        return $this->sendMessage($message, $inlineKeyboard, $editMessageId) !== null;
    }

    protected function buildMessage(Notification $notification): array
    {
        $blocks = [];

        if ($title = $notification->getTitle())
            $blocks[] = $title;

        if ($description = $notification->getDescription())
            $blocks[] = $description;

        if ($footer = $notification->getFooterWithAttentions())
            $blocks[] = $footer;

        $message = join(PHP_EOL.Notification::EMPTY_LINE.PHP_EOL, $blocks);

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $buttons = $notification->getButtons();

        if (!empty($buttons))
        {
            $inlineKeyboard = [];

            foreach ($buttons as $buttonsInRow)
            {
                $inlineButtons = [];

                foreach ($buttonsInRow as $button)
                {
                    $inlineButton = null;

                    switch ($button->type)
                    {
                        case NotificationButton::kTYPE_CallbackAction:
                            $inlineButton = [
                                'text'          => $button->caption,
                                'callback_data' => CallbackAction::PackFullCustomId($button->callbackAuid),
                            ];
                            break;

                        case NotificationButton::kTYPE_Link:
                            $inlineButton = [
                                'text'          => $button->caption,
                                'url'           => $button->url,
                            ];
                            break;
                    }

                    if ($inlineButton)
                        $inlineButtons[] = $inlineButton;
                }

                $inlineKeyboard[] = $inlineButtons;
            }
        }
        else
        {
            $inlineKeyboard = null;
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $imageUrl = $notification->getPreviewThumbnailUrl();
        $videoUrl = $notification->getPreviewMovieClipUrl();

        return [$message, $imageUrl, $videoUrl, $inlineKeyboard];
    }

    //------------------------------------------------------------------------------------------------------------------
    // Send messages

    public function sendMessage($message, $inlineKeyboard=null, $editMessageId=null): ?int
    {
        $this->lastSendError = null;

        try
        {
            if ($this->user->platformChannelId == null)
                throw new Exception("platformChannelId is null");

            if ($message == '')
                throw new Exception("Attempt to send an empty message");

            $menu = $inlineKeyboard ? ['inline_keyboard' => $inlineKeyboard] : null;

            if ($editMessageId)
            {
                $result = $this->client->editMessageText(
                    text:                   $message,
                    chat_id:                $this->user->platformChannelId,
                    message_id:             $editMessageId,
                    parse_mode:             self::FORMAT_HTML,
                    link_preview_options:   self::LINK_PREVIEW_DISABLED,
                    reply_markup:           $menu
                );
            }
            else
            {
                $result = $this->client->sendMessage(
                    chat_id:                $this->user->platformChannelId,
                    text:                   $message,
                    parse_mode:             self::FORMAT_HTML,
                    link_preview_options:   self::LINK_PREVIEW_DISABLED,
                    reply_markup:           $menu
                );
            }

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $request = [
                'message'         => $message,
                'inline_keyboard' => $inlineKeyboard,
            ];

            $this->postAnalyzeResponse($editMessageId ? 'message:edit' : 'message', $message, $request, $result);

            return $result->ok ? $result->result->message_id : null;
        }
        catch (Exception $e)
        {
            $this->lastSendError ??= $e->getMessage();
            Log::Error(get_class($this), __FUNCTION__, $e->getMessage(), $this->webhook->getRawData());
            return null;
        }
    }

    public function sendPhoto($imageUrl, $caption, $inlineKeyboard=null): ?int
    {
        $this->lastSendError = null;

        try
        {
            if ($this->user->platformChannelId == null)
                throw new Exception("platformChannelId is null");

            $menu = $inlineKeyboard ? ['inline_keyboard' => $inlineKeyboard] : null;

            $result = $this->client->sendPhoto(
                chat_id:        $this->user->platformChannelId,
                photo:          $imageUrl,
                caption:        $caption,
                parse_mode:     self::FORMAT_HTML,
                reply_markup:   $menu
            );

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $request = [
                'image_url'         => $imageUrl,
                'caption'           => $caption,
                'inline_keyboard'   => $inlineKeyboard,
            ];

            $this->postAnalyzeResponse('photo', $caption, $request, $result);

            return $result->ok ? $result->result->message_id : null;
        }
        catch (Exception $e)
        {
            $this->lastSendError ??= $e->getMessage();
            Log::Error(get_class($this), __FUNCTION__, $e->getMessage(), $this->webhook->getRawData());
            return null;
        }
    }

    public function sendAnimation($animeUrl, $thumbUrl, $caption, $inlineKeyboard=null): ?int
    {
        $this->lastSendError = null;

        try
        {
            if ($this->user->platformChannelId == null)
                throw new Exception("platformChannelId is null");

            $menu = $inlineKeyboard ? ['inline_keyboard' => $inlineKeyboard] : null;

            $result = $this->client->sendAnimation(
                chat_id:        $this->user->platformChannelId,
                animation:      $animeUrl,
                thumbnail:      $thumbUrl,
                caption:        $caption,
                parse_mode:     self::FORMAT_HTML,
                reply_markup:   $menu
            );

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $request = [
                'animation_url'     => $animeUrl,
                'thumb_url'         => $thumbUrl,
                'caption'           => $caption,
                'inline_keyboard'   => $inlineKeyboard,
            ];

            $this->postAnalyzeResponse('animation', $caption, $request, $result);

            return $result->ok ? $result->result->message_id : null;
        }
        catch (Exception $e)
        {
            $this->lastSendError ??= $e->getMessage();
            Log::Error(get_class($this), __FUNCTION__, $e->getMessage(), $this->webhook->getRawData());
            return null;
        }
    }

    public function sendVideo($videoUrl, $thumbUrl, $caption, $inlineKeyboard=null): ?int
    {
        $this->lastSendError = null;

        try
        {
            if ($this->user->platformChannelId == null)
                throw new Exception("platformChannelId is null");

            $menu = $inlineKeyboard ? ['inline_keyboard' => $inlineKeyboard] : null;

            $result = $this->client->sendVideo(
                chat_id:        $this->user->platformChannelId,
                video:          $videoUrl,
                thumbnail:      $thumbUrl,
                caption:        $caption,
                parse_mode:     self::FORMAT_HTML,
                reply_markup:   $menu
            );

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $request = [
                'video_url'         => $videoUrl,
                'thumb_url'         => $thumbUrl,
                'caption'           => $caption,
                'inline_keyboard'   => $inlineKeyboard,
            ];

            $this->postAnalyzeResponse('video', $caption, $request, $result);

            return $result->ok ? $result->result->message_id : null;
        }
        catch (Exception $e)
        {
            $this->lastSendError ??= $e->getMessage();
            Log::Error(get_class($this), __FUNCTION__, $e->getMessage(), $this->webhook->getRawData());
            return null;
        }
    }

    public function sendMediaGroup($media): ?int
    {
        $this->lastSendError = null;

        try
        {
            if ($this->user->platformChannelId == null)
                throw new Exception("platformChannelId is null");

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
            # https://core.telegram.org/bots/api#available-methods

            $result = $this->client->sendMediaGroup(
                $this->user->platformChannelId, #   $chat_id
                $media);                        #   $media .....

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $this->postAnalyzeResponse('media_group', null, $media, $result);

            return $result->ok ? ($result->result[0]->message_id ?? null) : null; // an album returns a list of messages
        }
        catch (Exception $e)
        {
            $this->lastSendError ??= $e->getMessage();
            Log::Error(get_class($this), __FUNCTION__, $e->getMessage(), $this->webhook->getRawData());
            return null;
        }
    }

    public function answerCallbackQuery(string $message): bool
    {
        try
        {
            $callbackQueryId = $this->webhook->getCallbackQueryId();

            if ($callbackQueryId == null)
                throw new Exception("callbackQueryId is NULL");

            $result = $this->client->answerCallbackQuery($callbackQueryId, $message);

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $this->postAnalyzeResponse('callback_answer', $message, null, $result);

            return $result->ok;
        }
        catch (Exception $e)
        {
            Log::Error(get_class($this), __FUNCTION__, $e->getMessage(), $this->webhook->getRawData());
            return false;
        }
    }

    //------------------------------------------------------------------------------------------------------------------

    public function deleteMessage($messageId): bool
    {
        try
        {
            if ($this->user->platformChannelId == null)
                throw new Exception("platformChannelId is null");

            $result = $this->client->deleteMessage(
                $this->user->platformChannelId,
                $messageId);

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $request = [
                'messageId' => $messageId,
            ];

            $this->postAnalyzeResponse('message:delete', '', $request, $result);

            return $result->ok;
        }
        catch (Exception $e)
        {
            Log::Error(get_class($this), __FUNCTION__, $e->getMessage(), $this->webhook->getRawData());
            return false;
        }
    }

    //------------------------------------------------------------------------------------------------------------------

    private function postAnalyzeResponse($subtype, $message, $request, $response): void
    {
        if (!$response->ok)
        {
            $errorMsg = '#'.$response->error_code.': '.$response->description;
            $this->lastSendError = $errorMsg;

            if ($response->error_code == 403)
                $this->user->deactivate($errorMsg);
        }
        else
        {
            $errorMsg = null;
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $logItem = [
            'channel_id'    => $this->getBotId(),
            'status'        => $response->ok ? Models\Log\BotMessagesTelegram::kSTATUS_Ok : Models\Log\BotMessagesTelegram::kSTATUS_Error,
            'query_type'    => $this->webhook->getMessageId() ? self::kQUERY_TYPE_ApiResponse : self::kQUERY_TYPE_ApiSendMessage,
            'query_subtype' => $subtype,
            'user_id'       => $this->user->userId,

            'from_user_id'  => $this->user->platformUserId,
            'chat_id'       => $this->user->platformChannelId,

            'message_id'    => is_object($response->result ?? null) ? ($response->result->message_id ?? null) : null,
            'message_pid'   => $this->webhook->getMessageId(),
            'message_text'  => $message,

            'data_request'  => $request,
            'data_response' => $response,
            'error'         => $errorMsg,
        ];

        Models\Log\BotMessagesTelegram::model()->save($logItem);
    }
}
