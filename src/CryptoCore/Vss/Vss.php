<?php

namespace CryptoCore\Vss;

use Base;

class Vss
{
    private static ?Base $f3Instance = null;

    public static function initialize()
    {
        // Fat-Free itself sets the HTTP status and writes the error with trace to error_log before calling ONERROR.
        // This handler only suppresses F3's default error page, so no error details are sent to the client.
        Vss::f3()->set('ONERROR', function (Base $f3)
        {
        });

        self::KeepDeprecationsNonFatal();
    }

    /**
     * Fat-Free's error handler, installed by Base::instance(), turns every reported diagnostic into error 500, which
     * ends the process. That is wanted for warnings: on PHP 8 a missing array key is one, and those were real bugs.
     * It is wrong for deprecations, which only announce a future PHP version and which vendor code raises without
     * us being able to fix it.
     *
     * The case that forced this: TuriBot 2.16, its latest release, declares getUpdate(string $body = null), and
     * PHP 8.4 deprecates that implicit nullable at compile time. Every CLI process that loaded the Telegram client
     * died there - both notifiers on their first message, /telegram/webhook/set, /telegram/webhook/info. The CLI
     * compiles each file afresh; in FPM, opcache hid it after the first request.
     *
     * Deprecations go to the error log and are otherwise ignored. Everything else still reaches Fat-Free.
     */
    private static function KeepDeprecationsNonFatal(): void
    {
        $previous = null;

        $previous = set_error_handler(function (int $level, string $text, string $file = '', int $line = 0) use (&$previous)
        {
            if (($level & (E_DEPRECATED | E_USER_DEPRECATED)) && ($level & error_reporting()))
            {
                error_log("Deprecated (not fatal): $text in $file:$line");
                return true;
            }

            return $previous !== null ? $previous($level, $text, $file, $line) : false;
        });
    }

    public static function f3(): Base
    {
        if (self::$f3Instance === null)
            self::$f3Instance = Base::instance();

        return self::$f3Instance;
    }

    //------------------------------------------------------------------------------------------------------------------

    /** @var VssDb[] $DbInstances */
    private static array $DbInstances = [];

    public static function dbc(string $dbId, $charset = null): VssDb
    {
        $dbId_ext = $dbId . '_charset_' . $charset;

        if (!isset(self::$DbInstances[$dbId_ext]))
            self::$DbInstances[$dbId_ext] = new VssDb($dbId, $charset);

        return self::$DbInstances[$dbId_ext];
    }

    public static function dbCloseAll()
    {
        foreach (self::$DbInstances as $db)
        {
            $db->close();
        }
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public static function db(): VssDb
    {
        return self::dbc('dbMain', 'utf8mb4');
    }

    public static function dbShared(): VssDb
    {
        return self::dbc('dbShared', 'utf8mb4');
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public static function dbLog(): VssDb
    {
        return self::dbc('dbLog', 'utf8mb4');
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public static function dbTezos(): VssDb
    {
        return self::dbc('dbTezos', 'utf8mb4');
    }

    public static function dbTezosLog(): VssDb
    {
        return self::dbc('dbTezosLog', 'utf8mb4');
    }

    //------------------------------------------------------------------------------------------------------------------

    public static function request(): VssRequest
    {
        static $instance = null;

        if ($instance == null)
            $instance = new VssRequest();

        return $instance;
    }
}
