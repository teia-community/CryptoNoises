<?php

use CryptoCore\Process\Shutdown;
use CryptoCore\Vss\Vss;

require_once(dirname(__FILE__) . '/../vendor/autoload.php');

VssCfg::cfg()->initialize();
Vss::initialize();

// Installed before any command runs: the loops that can stop cleanly poll Shutdown::Requested()
Shutdown::Install();

$f3 = Vss::f3();

// - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

$f3->route('GET /tezos-network/analyze-transactions/new',       'CryptoNoises\Commands\TezosNetwork->analyzeNewTransactions');
$f3->route('GET /tezos-network/analyze-transactions/range',     'CryptoNoises\Commands\TezosNetwork->analyzeRangeTransactions');
$f3->route('GET /tezos-network/analyze-transactions/non-synced','CryptoNoises\Commands\TezosNetwork->analyzeNonSyncedTransactions');

$f3->route('GET /tezos-network/analyze-account-operations/new',  'CryptoNoises\Commands\TezosNetwork->analyzeNewAccountOperations');
$f3->route('GET /tezos-network/analyze-account-operations/range','CryptoNoises\Commands\TezosNetwork->analyzeRangeAccountOperations');

$f3->route('GET /tezos-network/unstake-reminders',               'CryptoNoises\Commands\UnstakeReminders->run');
$f3->route('GET /tezos-network/unstake-reminders/once',          'CryptoNoises\Commands\UnstakeReminders->runOnce');

$f3->route('GET /tezos-network/baker-alerts',                    'CryptoNoises\Commands\BakerAlerts->run');
$f3->route('GET /tezos-network/baker-alerts/once',               'CryptoNoises\Commands\BakerAlerts->runOnce');

$f3->route('GET /tezos-network/wallets/sync',                   'CryptoNoises\Commands\TezosNetwork->syncWalletsStates');

$f3->route('GET /events/nfts-mint/tezos',                       'CryptoNoises\Commands\EventsTokensMint->tezos');
$f3->route('GET /events/nfts-updates/tezos',                    'CryptoNoises\Commands\EventsTokensUpdates->tezos');

// - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

$f3->route('GET /jobs/exec',                                    'CryptoNoises\Commands\JobsController->launchJob');
$f3->route('GET /jobs/recover-dead-jobs',                       'CryptoNoises\Commands\JobsController->recoverDeadJobs');

// - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

$f3->route('GET /lifecycle/check/error-logs',                   'CryptoNoises\Commands\Lifecycles->checkErrorLogs');
$f3->route('GET /lifecycle/clean-up/callback-actions',          'CryptoNoises\Commands\Lifecycles->cleanUpCallbackActions');
$f3->route('GET /lifecycle/clean-up/events-flow',               'CryptoNoises\Commands\Lifecycles->cleanUpEventsFlow');
$f3->route('GET /lifecycle/clean-up/user-actions',              'CryptoNoises\Commands\Lifecycles->cleanUpUserActions');
$f3->route('GET /lifecycle/clean-up/old-data',                  'CryptoNoises\Commands\Lifecycles->cleanUpOldData');
$f3->route('GET /lifecycle/prepare/tables',                     'CryptoNoises\Commands\Lifecycles->prepareTables');

// - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
// Telegram

$f3->route('GET /telegram/notifier/events-flow',                'CryptoNoises\Commands\TelegramNotifier->notifyEventsFlow');
$f3->route('GET /telegram/notifier/inform-flow',                'CryptoNoises\Commands\TelegramNotifier->notifyInformsFlow');

$f3->route('GET /telegram/webhook/set',                        'CryptoNoises\Commands\TelegramWebhook->set');
$f3->route('GET /telegram/webhook/info',                       'CryptoNoises\Commands\TelegramWebhook->info');

$f3->route('GET /telegram/poll',                               'CryptoNoises\Commands\TelegramPoll->poll');

$f3->route('GET /matrix/sync',                                  'CryptoNoises\Commands\MatrixBot->sync');
$f3->route('GET /matrix/notifier/events-flow',                  'CryptoNoises\Commands\MatrixNotifier->notifyEventsFlow');
$f3->route('GET /matrix/notifier/inform-flow',                  'CryptoNoises\Commands\MatrixNotifier->notifyInformsFlow');

// - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
// Discord

$f3->route('GET /discord/bot/start/main-loop',                  'CryptoNoises\Commands\DiscordBot->startMainLoop');
$f3->route('GET /discord/bot/start/notifier',                   'CryptoNoises\Commands\DiscordBot->startNotifier');
$f3->route('GET /discord/bot/start/assistant',                  'CryptoNoises\Commands\DiscordBot->startAssistant');

$f3->route('GET /discord/bot/stop/all',                         'CryptoNoises\Commands\DiscordBot->stopAll');
$f3->route('GET /discord/bot/stop/main-loop',                   'CryptoNoises\Commands\DiscordBot->stopMainLoop');
$f3->route('GET /discord/bot/stop/notifier',                    'CryptoNoises\Commands\DiscordBot->stopNotifier');
$f3->route('GET /discord/bot/stop/assistant',                   'CryptoNoises\Commands\DiscordBot->stopAssistant');

$f3->route('GET /discord/bot/commands/show',                    'CryptoNoises\Commands\DiscordBot->commandsShow');
$f3->route('GET /discord/bot/commands/create',                  'CryptoNoises\Commands\DiscordBot->commandsCreate');
$f3->route('GET /discord/bot/commands/delete',                  'CryptoNoises\Commands\DiscordBot->commandsDelete');

// - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

$f3->route('GET /coingecko/sync',                               'CryptoNoises\Commands\CoinGecko->sync');

// - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

$f3->route('GET /utils/generate-telegram-bot-commands',         'CryptoNoises\Commands\Commands->generateTelegramBotCommands');

// - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

$f3->route('GET /*',                                            'CryptoNoises\Commands\Commands->run' );
$f3->route('GET /',                                             'CryptoNoises\Commands\Commands->run' );

$f3->run();
