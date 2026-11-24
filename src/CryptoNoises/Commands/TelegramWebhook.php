<?php

namespace CryptoNoises\Commands;

use CryptoNoises\Config;
use TuriBot\Client;

/**
 * Registers Telegram webhooks for all configured bots.
 * Webhook calls are authorized by TELEGRAM_WEBHOOK_SECRET (see ControllersApi\TelegramWebhookAuth).
 */
class TelegramWebhook extends AbstractCommand
{
    public function set(): void
    {
        if (!defined('TELEGRAM_WEBHOOK_SECRET') || TELEGRAM_WEBHOOK_SECRET === '')
        {
            $this->logErr('TELEGRAM_WEBHOOK_SECRET is not set in config/consts-secrets.php');
            return;
        }

        foreach ($this->getBots() as $path => $token)
        {
            $url = rtrim(Config::cfg()->publicApiUrl, '/').$path;

            $response = (new Client($token, false))->setWebhook(url: $url, secret_token: TELEGRAM_WEBHOOK_SECRET);

            $this->logMsg("$path -> $url: ".($response->ok ? 'OK' : 'FAILED: '.($response->description ?? 'unknown error')), "\n", true);
        }
    }

    public function info(): void
    {
        foreach ($this->getBots() as $path => $token)
        {
            $response = (new Client($token, false))->getWebhookInfo();

            $this->logMsg("$path: ".json_encode($response->result ?? $response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n", true);
        }
    }

    /**
     * @return array<string, string> webhook path (public-api route) => bot token; bots without token are skipped
     */
    private function getBots(): array
    {
        $bots = [
            '/telegram/webhook' => TELEGRAM_BOT_API,
            '/telegram/assist'  => TELEGRAM_ASSISTANT_API,
            '/telegram/debug'   => TELEGRAM_DEBUGGER_API,
        ];

        return array_filter($bots, function ($token)
        {
            return $token !== '';
        });
    }
}
