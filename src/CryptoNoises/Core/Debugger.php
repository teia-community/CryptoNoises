<?php

namespace CryptoNoises\Core;


/** Errors and fatals go to the operators (OpsAlerts); warnings and notices stay in the logs. */
class Debugger
{
    public static function Fatal(string $message): void
    {
        OpsAlerts::Send('🚨 FATAL', $message);
    }

    public static function Error(string $message): void
    {
        OpsAlerts::Send('🛑 ERROR', $message);
    }

    public static function Warning(string $message): void {}

    public static function Notice(string $message): void {}

    public static function Notify(string $level, string $message): void
    {
        match ($level) {
            'fatal' => self::Fatal($message),
            'error' => self::Error($message),
            default => null,
        };
    }
}
