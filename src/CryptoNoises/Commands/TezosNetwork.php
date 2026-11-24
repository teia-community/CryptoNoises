<?php

namespace CryptoNoises\Commands;

use CryptoNoises\Config;
use CryptoNoises\Entities\Wallet;
use CryptoNoises\Models;
use CryptoNoises\Networks\Tezos\TezosNetworkParser;
use CryptoCore\Time;
use CryptoCore\Vss\Vss;
use Exception;

class TezosNetwork extends AbstractCommand
{
    public function analyzeNewTransactions(): void
    {
        $tsStart = Time::Microtime();

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        set_time_limit(Config::runTimeLimit() + Config::kJOBS_TIMEOUT_TEZOS_TRANSACTION_ANALYZE);

        if (!$this->checkLockAndWaitUntilNoOtherInstanceRuns('command-lock.tezos-network.analyze-new-transactions', Config::kJOBS_TIMEOUT_DEFAULT))
            return;

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        try
        {
            $lastSyncedLevel = \CryptoNoises\GatewayTezos\Gateway::GetLastFullySyncedBlock();

            while (true)
            {
                Vss::dbCloseAll();

                if (Time::Microtime() - $tsStart > Config::runTimeLimit())
                    break;

                // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

                $level = Models\FixerTezosTransactionsAnalyze::model()->getNextLevelToProcess();

                if ($level === null)
                {
                    $this->logMsg("Last job state is 'processing'. Wait 1 sec and try again..");
                    sleep(1);
                    continue;
                }

                // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

                if ($level > $lastSyncedLevel)
                {
                    $this->logMsg("Want to analyze level #$level, but last synced level is #$lastSyncedLevel. Wait 1 sec and try again..");
                    sleep(1);

                    $lastSyncedLevel = \CryptoNoises\GatewayTezos\Gateway::GetLastFullySyncedBlock();
                    continue;
                }

                // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

                $this->logMsg("Analyze #$level");

                Models\FixerTezosTransactionsAnalyze::model()->jobOpen($level);

                $parser = new TezosNetworkParser();
                $parser->verbose = $this->verbose;
                $parser->syncWithBlockchainByLevel($level);
                $itemsCount = $parser->save();

                Models\FixerTezosTransactionsAnalyze::model()->jobCloseSuccessfully($level, $itemsCount, $parser->getProfilerData());
            }

            $this->logMsg("END.");
        }
        catch (Exception $e)
        {
            $this->logErr("ERROR: " . $e->getMessage());
        }
    }

    public function analyzeRangeTransactions(): void
    {
        ini_set('memory_limit', '256M');
        set_time_limit(86400);

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $level      = $this->getCmdVar('level');
        $skipExists = (bool) $this->getCmdVar('skip_exists', 1);

        if ($level == '')
        {
            $this->logErr("The level parameter is required");
            return;
        }

        if ($level === 'non-synced')
        {
            $levels = Models\FixerTezosTransactionsAnalyze::model()->getZombieLevels(3*60);
            $skipExists = false;
        }
        else
        {
            $level    = explode('-', $level);
            $levelMin = $level[0];
            $levelMax = count($level) >= 2 ? $level[1] : $level[0];

            $levels = range($levelMin, $levelMax);
        }

        try
        {
            foreach ($levels as $level)
            {
                $jobItem = Models\FixerTezosTransactionsAnalyze::model()->getJobItemByLevel($level);

                if ($jobItem && $skipExists)
                    continue;

                // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

                $this->logMsg("Sync #$level");

                Models\FixerTezosTransactionsAnalyze::model()->jobOpen($level);

                $parser = new TezosNetworkParser();
                $parser->verbose = $this->verbose;
                $parser->syncWithBlockchainByLevel($level);
                $itemsCount = $parser->save();

                Models\FixerTezosTransactionsAnalyze::model()->jobCloseSuccessfully($level, $itemsCount, $parser->getProfilerData());

                Vss::dbCloseAll();
            }

            $this->logMsg("END.");
        }
        catch (Exception $e)
        {
            $this->logErr("ERROR: " . $e->getMessage());
        }
    }

    public function analyzeNonSyncedTransactions(): void
    {
        $this->verbose = true;
        $this->forcedCmdVars['level'] = 'non-synced';
        $this->analyzeRangeTransactions();
    }

    //==================================================================================================================

    public function syncWalletsStates(): void
    {
        $tsStart = Time::Microtime();

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        set_time_limit(Config::runTimeLimit() + Config::kJOBS_TIMEOUT_TEZOS_TRANSACTION_ANALYZE);

        if (!$this->checkLockAndWaitUntilNoOtherInstanceRuns('command-lock.tezos-network.sync-wallets-states', Config::kJOBS_TIMEOUT_DEFAULT))
            return;

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        while (true)
        {
            Vss::dbCloseAll();

            if (Time::Microtime() - $tsStart > Config::runTimeLimit())
                break;

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $task = Models\QueueWalletsSync::model()->popNextTaskToSync();

            if ($task == null)
            {
                $this->logMsg("No task. Sleep for 1 sec");
                sleep(1);
                continue;
            }

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $this->logMsg("Sync {$task['wallet_address']}");

            $wallet = Wallet::WalletByAddress($task['wallet_address']);

            if ($wallet)
            {
                $wallet->syncBalance();
                $this->logMsg("> Done");
            }
            else
            {
                $this->logMsg("> Wallet does not exist");
            }
        }

        $this->logMsg("Done");
    }
}
