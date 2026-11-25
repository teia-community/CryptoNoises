<?php

namespace CryptoNoises\Core;

use CryptoCore\Vss\Vss;
use CryptoNoises\Bots\Connections\MatrixClient;
use CryptoNoises\Bots\Connections\TelegramBotConnection;
use CryptoNoises\Config;
use Throwable;

/**
 * Errors to the operators: a Matrix room (api.matrix.alerts_room) and/or the Telegram debugger bot.
 *
 * The same error (digits ignored) goes out at most once an hour, with a count of the repeats, and at most
 * kMAX_PER_HOUR alerts go out per hour in all; ops_alerts_sent keeps that across processes.
 */
final class OpsAlerts
{
    const kREPEAT_AFTER_SEC     = 3600;
    const kMAX_PER_HOUR         = 30;

    private static bool $sending = false;
    private static ?TelegramBotConnection $telegram = null;

    public static function Send(string $level, string $message): void
    {
        if (self::$sending)
            return;     // a failed send logs an error, which must not come back here

        self::$sending = true;

        try
        {
            $matrixRoom = Config::cfg()->matrixAlertsRoom;
            $telegram   = self::Telegram();

            if ($matrixRoom === '' && $telegram === null)
                return;

            $repeats = self::Claim($message);

            if ($repeats === null)
                return;

            $environment = Config::cfg()->isProduction() ? 'PROD' : 'STAGING';
            $text = "[$environment] $level: ".mb_substr($message, 0, 1500).($repeats ? " (+$repeats similar in the last hour)" : '');

            if ($matrixRoom !== '' && MatrixClient::IsConfigured())
                MatrixClient::I()->sendMessage($matrixRoom, ['msgtype' => 'm.notice', 'body' => $text]);

            $telegram?->sendMessage(htmlspecialchars($text, ENT_QUOTES));
        }
        catch (Throwable $e)
        {
            error_log('OpsAlerts: '.$e->getMessage());
        }
        finally
        {
            self::$sending = false;
        }
    }

    /** @return int|null repeats since the last time this error went out; null when it is not to be sent now */
    private static function Claim(string $message): ?int
    {
        $hash = sha1(preg_replace('/\d+/', '#', $message));
        $row  = Vss::db()->queryRow("SELECT repeats, ts_sent > NOW() - INTERVAL :s SECOND AS recent FROM ops_alerts_sent WHERE alert_hash = :h",
            ['h' => $hash, 's' => self::kREPEAT_AFTER_SEC]);

        if (!empty($row['recent']))
        {
            Vss::db()->query("UPDATE ops_alerts_sent SET repeats = repeats + 1 WHERE alert_hash = :h", ['h' => $hash]);
            return null;
        }

        $sentLastHour = (int) Vss::db()->queryScalar("SELECT COUNT(*) FROM ops_alerts_sent WHERE ts_sent > NOW() - INTERVAL 1 HOUR");

        if ($sentLastHour >= self::kMAX_PER_HOUR)
            return null;

        Vss::db()->query("INSERT INTO ops_alerts_sent (alert_hash, ts_sent, repeats) VALUES (:h, NOW(), 0)
            ON DUPLICATE KEY UPDATE ts_sent = NOW(), repeats = 0", ['h' => $hash]);

        return (int) ($row['repeats'] ?? 0);
    }

    private static function Telegram(): ?TelegramBotConnection
    {
        if (self::$telegram === null && defined('TELEGRAM_DEBUGGER_API') && TELEGRAM_DEBUGGER_API != '' && TELEGRAM_DEBUGGER_USER_ID > 0)
        {
            $connection = new TelegramBotConnection(TELEGRAM_DEBUGGER_API);

            if ($connection->initUserByTelegramIds(TELEGRAM_DEBUGGER_USER_ID, TELEGRAM_DEBUGGER_USER_ID))
                self::$telegram = $connection;
        }

        return self::$telegram;
    }
}
