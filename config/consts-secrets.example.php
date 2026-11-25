<?php

// Copy this file to config/consts-secrets.php and fill in your credentials.
// config/consts-secrets.php is gitignored - never commit it.

//----------------------------------------------------------------------------------------------------------------------
// MySQL: CryptoNoises (bots + API)

const kSECRET_MYSQL_HOST_MAIN               = 'mysql:host=127.0.0.1;port=3306;dbname=cryptonoises';
const kSECRET_MYSQL_HOST_LOG                = 'mysql:host=127.0.0.1;port=3306;dbname=cryptonoises';
const kSECRET_MYSQL_HOST_SHARED             = 'mysql:host=127.0.0.1;port=3306;dbname=cryptonoises_shared';
const kSECRET_MYSQL_USER                    = '';
const kSECRET_MYSQL_PSWD                    = '';

//----------------------------------------------------------------------------------------------------------------------
// MySQL: CryptoNoisesTezos (indexer)

const kSECRET_TEZOS_MYSQL_HOST_MAIN         = 'mysql:host=127.0.0.1;port=3306;dbname=cryptonoises_tezos';
const kSECRET_TEZOS_MYSQL_HOST_LOG          = 'mysql:host=127.0.0.1;port=3306;dbname=cryptonoises_tezos_log';
const kSECRET_TEZOS_MYSQL_USER              = '';
const kSECRET_TEZOS_MYSQL_PSWD              = '';

// Optional: separate credentials for the Tezos log DB (falls back to kSECRET_TEZOS_MYSQL_USER / _PSWD)
// const kSECRET_TEZOS_MYSQL_USER_LOG       = '';
// const kSECRET_TEZOS_MYSQL_PSWD_LOG       = '';

//----------------------------------------------------------------------------------------------------------------------
// Telegram (tokens from @BotFather, format "12345:abcdef")

// Main bot
const TELEGRAM_BOT_API                      = '';

// Assistant bot: answers only to TELEGRAM_ASSISTANT_USER_ID (your Telegram user id)
const TELEGRAM_ASSISTANT_API                = '';
const TELEGRAM_ASSISTANT_USER_ID            = 0;

// Debugger bot: receives Fatal / Error / Warning logs, sends them to TELEGRAM_DEBUGGER_USER_ID
const TELEGRAM_DEBUGGER_API                 = '';
const TELEGRAM_DEBUGGER_USER_ID             = 0;

// Random secret (1-256 chars: A-Z a-z 0-9 _ -) that Telegram sends in "X-Telegram-Bot-Api-Secret-Token".
// After changing it, re-register webhooks: commands/run /telegram/webhook/set
const TELEGRAM_WEBHOOK_SECRET               = '';

//----------------------------------------------------------------------------------------------------------------------
// Discord (https://discord.com/developers/applications)

// Bot token for main-loop + notifier threads
const kDISCORD_TOKEN_MAINBOT                = '';

// Matrix bot: the password of api.matrix.user, used to log in through pantalaimon (which needs its own device)
const kMATRIX_PASSWORD                      = '';
// Bot token for assistant thread (server members sync + stats message); may be the same as main bot
const kDISCORD_TOKEN_ASSISTANT              = '';
// Your Discord server (guild) id - the assistant thread syncs its members (needs the GUILD_MEMBERS intent)
const kDISCORD_GUILD_ID                     = '';
// Application (client) id of the main bot - used to build "invite bot to server" links
const kDISCORD_CLIENT_ID_MAINBOT            = '';
