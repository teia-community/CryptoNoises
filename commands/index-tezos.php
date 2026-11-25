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

$f3->route('GET /blockchain/transaction/sync-new',                              'CryptoNoisesTezos\Commands\BlockchainTransactions->syncNew');
$f3->route('GET /blockchain/transaction/sync-range',                            'CryptoNoisesTezos\Commands\BlockchainTransactions->syncRange');
$f3->route('GET /blockchain/transaction/cleanup',                               'CryptoNoisesTezos\Commands\BlockchainTransactions->cleanup');

$f3->route('GET /blockchain/bigmap-updates/sync-new',                           'CryptoNoisesTezos\Commands\BlockchainBigmapUpdates->syncNew');
$f3->route('GET /blockchain/bigmap-updates/sync-range',                         'CryptoNoisesTezos\Commands\BlockchainBigmapUpdates->syncRange');
$f3->route('GET /blockchain/bigmap-updates/sync-check',                         'CryptoNoisesTezos\Commands\BlockchainBigmapUpdates->syncCheck');
$f3->route('GET /blockchain/bigmap-updates/cleanup',                            'CryptoNoisesTezos\Commands\BlockchainBigmapUpdates->cleanup');

$f3->route('GET /blockchain/account-operations/sync-new',                       'CryptoNoisesTezos\Commands\BlockchainAccountOperations->syncNew');
$f3->route('GET /blockchain/account-operations/sync-range',                     'CryptoNoisesTezos\Commands\BlockchainAccountOperations->syncRange');

$f3->route('GET /analyze/transactions/new',                                     'CryptoNoisesTezos\Commands\AnalyzeTransactions->analyzeNew');
$f3->route('GET /analyze/transactions/range',                                   'CryptoNoisesTezos\Commands\AnalyzeTransactions->analyzeRange');

$f3->route('GET /analyze/bigmap-updates/new',                                   'CryptoNoisesTezos\Commands\AnalyzeBigmapUpdates->analyzeNew');

// - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

$f3->route('GET /jobs/exec',                                                    'CryptoNoisesTezos\Commands\JobsController->launchJob');

$f3->route('GET /jobs/recover-dead-jobs',                                       'CryptoNoisesTezos\Commands\JobsController->recoverDeadJobs');
$f3->route('GET /jobs/clean/file-cache',                                        'CryptoNoisesTezos\Commands\JobsController->cleanFileCache');
$f3->route('GET /jobs/clean/media-convert-zombies',                             'CryptoNoisesTezos\Commands\JobsController->cleanMediaConvertZombies');
$f3->route('GET /jobs/clean/old-previews',                                       'CryptoNoisesTezos\Commands\JobsController->cleanOldPreviews');

// - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

$f3->route('GET /recovery/bigmaps/fix-null-deal-records',                       'CryptoNoisesTezos\Commands\RecoveryBigmaps->fixNullDealRecords');
$f3->route('GET /recovery/bigmaps/fix-null-offer-records',                      'CryptoNoisesTezos\Commands\RecoveryBigmaps->fixNullOfferRecords');

// - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

$f3->route('GET /*',                                                            'CryptoNoisesTezos\Commands\Commands->run');
$f3->route('GET /',                                                             'CryptoNoisesTezos\Commands\Commands->run');

$f3->run();
