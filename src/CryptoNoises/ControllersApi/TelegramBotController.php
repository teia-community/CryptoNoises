<?php

namespace CryptoNoises\ControllersApi;

use CryptoNoises\Bots\Connections\TelegramBotConnection;
use CryptoNoises\Bots\Connections\TelegramBotWebhook;
use CryptoNoises\Bots\Gates\TelegramBotGate;
use CryptoNoises\Config;
use CryptoNoises\Core\Log;
use Exception;
use const TELEGRAM_BOT_API;

class TelegramBotController
{
    public function webhook()
    {
        Config::cfg()->initializeByPlatform(Config::kPLATFORM_Telegram);

        if (!TelegramWebhookAuth::Authorize())
            return;

        self::HandleUpdate();
    }

    /**
     * Routes one Telegram update to the bot. Shared by the webhook (the request body, $rawUpdate = null) and by
     * the polling command, which fetches updates with getUpdates where no public HTTPS endpoint exists.
     * The caller has already called Config::initializeByPlatform() and checked authorization where there is any.
     */
    public static function HandleUpdate(?string $rawUpdate = null): void
    {
        try
        {
            $tgBotConnection = new TelegramBotConnection();

            if ($tgBotConnection->webhook->init($rawUpdate) == false)
                throw new Exception("Failed to parse the webhook request");

            if (!isset($tgBotConnection->user))
                throw new Exception("User not initialized");

            $botGate = new TelegramBotGate($tgBotConnection);

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            // Read (and clear) the pending user action here:
            // if any other command is applied, the pending action is dropped for the next messages
            $tgBotConnection->user->readNextActionAndCleanUp();

            if (!$tgBotConnection->webhook->isPrivateChat())
            {
                $botGate->onlyPrivateChatSupported();
            }
            else if ($tgBotConnection->webhook->isUserAction())
            {
                switch ($tgBotConnection->webhook->getUserAction())
                {
                    case TelegramBotWebhook::kUSER_ACTION_BotActivated:
                        $tgBotConnection->user->activate();
                        break;

                    case TelegramBotWebhook::kUSER_ACTION_BotDeactivated:
                        $tgBotConnection->user->deactivate();
                        break;
                }
            }
            else if ($tgBotConnection->webhook->isCallback())
            {
                $botGate->routeByCallback($tgBotConnection->webhook->getMessageData());
            }
            else # Message
            {
                $botGate->routeByUserMessage($tgBotConnection->webhook->getMessageText());
            }
        }
        catch (Exception $e)
        {
            $errorData = [];
            $errorData['channel_id']   = TelegramBotConnection::UnpackBotId(TELEGRAM_BOT_API);
            $errorData['data_request'] = $rawUpdate ?? file_get_contents("php://input");

            Log::Fatal(self::class, __FUNCTION__, $e->getMessage(), $errorData);
        }
    }
}
