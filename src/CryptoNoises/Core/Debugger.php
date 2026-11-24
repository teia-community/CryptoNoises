<?php

namespace CryptoNoises\Core;

use CryptoNoises\Bots\Connections\TelegramBotConnection;
use CryptoNoises\Config;
use Throwable;

class Debugger
{
    protected static ?TelegramBotConnection $tgBotConnection = null;

    // Notify() sends through the Telegram bot, and every send failure in TelegramBotConnection calls Log::Error(),
    // which calls Notify() again. Without this guard the two recurse until the memory limit is reached, so a single
    // logged error becomes a fatal and burns the whole memory_limit. That happens on any deployment that has no
    // debugger bot configured (the default), and on a configured one as soon as Telegram is unreachable, rate
    // limited, or the token is revoked.
    protected static bool $isNotifying = false;

    // Set once the debugger bot is known to be unusable, so later errors do not retry it on every single log line.
    protected static bool $isDisabled = false;

    protected static function bot(): ?TelegramBotConnection
    {
        if (self::$tgBotConnection === null)
        {
            if (TELEGRAM_DEBUGGER_API == '' || TELEGRAM_DEBUGGER_USER_ID <= 0)
                return null;

            $connection = new TelegramBotConnection(TELEGRAM_DEBUGGER_API);

            if (!$connection->initUserByTelegramIds(TELEGRAM_DEBUGGER_USER_ID, TELEGRAM_DEBUGGER_USER_ID))
                return null;

            self::$tgBotConnection = $connection;
        }

        return self::$tgBotConnection;
    }

    public static function Fatal(string $message): void
    {
        self::Notify('fatal', $message);
    }

    public static function Error(string $message): void
    {
        self::Notify('error', $message);
    }

    public static function Warning(string $message): void
    {
        self::Notify('warning', $message);
    }

    public static function Notice(string $message): void
    {
        self::Notify('notice', $message);
    }

    public static function Notify(string $level, string $message): void
    {
        if (self::$isNotifying || self::$isDisabled)
            return;

        self::$isNotifying = true;

        try
        {
            switch ($level)
            {
                case 'fatal':   $level = '🚨 FATAL';    break;
                case 'error':   $level = '🛑 ERROR';    break;
                case 'warning': $level = '⚠️ Warning';  break;
                case 'notice':  $level = '💬 Notice';   break;
                default:        $level = '';            break;
            }

            $environment = Config::cfg()->isProduction() ? 'PRO' : (Config::cfg()->isDevelopmentLocal() ? 'DEV-LOCAL' : 'DEV');

            $bot = self::bot();

            if ($bot === null)
            {
                self::$isDisabled = true;
                error_log("Debugger: no usable debugger bot, dropping [$environment] $level: $message");
                return;
            }

            $bot->sendMessage("[$environment] $level: $message");
        }
        catch (Throwable $e)
        {
            // Never let the error reporter raise an error of its own: that is what the recursion guard above exists
            // for, and error_log() is the one channel that cannot come back through Log/Debugger.
            error_log('Debugger::Notify failed: ' . $e->getMessage());
        }
        finally
        {
            self::$isNotifying = false;
        }
    }
}
