<?php

// Docker: credentials come from the environment (see .env / compose.yaml).
// The entrypoint installs this file as config/consts-secrets.php unless you mount your own.

/**
 * Required env var; fails with a clear message instead of connecting with an empty credential.
 *
 * Exits non-zero on purpose: die('message') exits 0, so a service started without its credentials would look
 * like a clean exit and the restart policy would keep restarting it with nothing to show for it.
 */
$cnRequiredEnv = static function (string $name): string
{
    $value = getenv($name);

    if ($value === false || $value === '')
    {
        fwrite(STDERR, 'Missing required environment variable ['.$name.']'.PHP_EOL);
        exit(1);
    }

    return $value;
};

$cnEnv = static function (string $name, string $default = ''): string
{
    $value = getenv($name);

    return ($value === false) ? $default : $value;
};

//----------------------------------------------------------------------------------------------------------------------
// MySQL: one server, four databases. Cross-database queries are used, so one user needs access to all of them.

$cnDbHost = $cnEnv('DB_HOST', 'db');
$cnDbPort = $cnEnv('DB_PORT', '3306');

$cnDsn = static function (string $dbName) use ($cnDbHost, $cnDbPort): string
{
    return 'mysql:host='.$cnDbHost.';port='.$cnDbPort.';dbname='.$dbName;
};

// Bots + API. MAIN and LOG are deliberately the same database (see README).
define('kSECRET_MYSQL_HOST_MAIN',           $cnDsn($cnEnv('DB_NAME_MAIN',      'cryptonoises')));
define('kSECRET_MYSQL_HOST_LOG',            $cnDsn($cnEnv('DB_NAME_MAIN',      'cryptonoises')));
define('kSECRET_MYSQL_HOST_SHARED',         $cnDsn($cnEnv('DB_NAME_SHARED',    'cryptonoises_shared')));
define('kSECRET_MYSQL_USER',                $cnRequiredEnv('DB_USER'));
define('kSECRET_MYSQL_PSWD',                $cnRequiredEnv('DB_PASSWORD'));

// Indexer
define('kSECRET_TEZOS_MYSQL_HOST_MAIN',     $cnDsn($cnEnv('DB_NAME_TEZOS',     'cryptonoises_tezos')));
define('kSECRET_TEZOS_MYSQL_HOST_LOG',      $cnDsn($cnEnv('DB_NAME_TEZOS_LOG', 'cryptonoises_tezos_log')));
define('kSECRET_TEZOS_MYSQL_USER',          $cnRequiredEnv('DB_USER'));
define('kSECRET_TEZOS_MYSQL_PSWD',          $cnRequiredEnv('DB_PASSWORD'));

//----------------------------------------------------------------------------------------------------------------------
// Telegram

define('TELEGRAM_BOT_API',                  $cnEnv('TELEGRAM_BOT_API'));

define('TELEGRAM_ASSISTANT_API',            $cnEnv('TELEGRAM_ASSISTANT_API'));
define('TELEGRAM_ASSISTANT_USER_ID',        (int) $cnEnv('TELEGRAM_ASSISTANT_USER_ID', '0'));

define('TELEGRAM_DEBUGGER_API',             $cnEnv('TELEGRAM_DEBUGGER_API'));
define('TELEGRAM_DEBUGGER_USER_ID',         (int) $cnEnv('TELEGRAM_DEBUGGER_USER_ID', '0'));

define('TELEGRAM_WEBHOOK_SECRET',           $cnEnv('TELEGRAM_WEBHOOK_SECRET'));

//----------------------------------------------------------------------------------------------------------------------
// Discord

define('kDISCORD_TOKEN_MAINBOT',            $cnEnv('DISCORD_TOKEN_MAINBOT'));
define('kDISCORD_TOKEN_ASSISTANT',          $cnEnv('DISCORD_TOKEN_ASSISTANT'));
define('kDISCORD_GUILD_ID',                 $cnEnv('DISCORD_GUILD_ID'));
define('kDISCORD_CLIENT_ID_MAINBOT',        $cnEnv('DISCORD_CLIENT_ID_MAINBOT'));

unset($cnRequiredEnv, $cnEnv, $cnDsn, $cnDbHost, $cnDbPort);
