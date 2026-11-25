<?php

namespace CryptoNoises\Commands;

use CryptoNoises\Config;
use CryptoNoises\Entities\Wallet;
use CryptoNoises\Models;
use CryptoNoises\Networks\Tezos\TezosNetworkParser;
use CryptoCore\Process\ProgressLog;
use CryptoCore\Process\Shutdown;
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
            $progress = new ProgressLog('analyze for notifications');

            while (true)
            {
                Vss::dbCloseAll();

                if (Time::Microtime() - $tsStart > Config::runTimeLimit() || Shutdown::Requested())
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

                // $level is the next one to analyze, so everything before it is done. $lastSyncedLevel is only
                // re-read once the loop catches up with it, so the target is read fresh, and only when printing.
                $progress->report($level - 1, fn () => \CryptoNoises\GatewayTezos\Gateway::GetLastFullySyncedBlock(), 'synced');

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

            if (Time::Microtime() - $tsStart > Config::runTimeLimit() || Shutdown::Requested())
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

    //------------------------------------------------------------------------------------------------------------------
    // Account operations: delegations, staking, baker parameter changes, originations -> events

    // Cursor of analyzeNewAccountOperations(): the last level analyzed
    const kACCOUNT_OPS_CURSOR       = 'TezosAccountOpsAnalyzer.LastSyncedLevel';

    // Levels per pass. These operations are sparse (~174 per 10k levels near the head), so a pass is a few rows
    const kACCOUNT_OPS_CHUNK        = 10000;

    /**
     * Turns synced account operations into events, a range of levels at a time, on its own cursor.
     *
     * The range never goes past the level the rest of the indexer has fully synced. The account-operation sync is
     * cheap and can run far ahead - on a stack syncing from block 1, years ahead - and notifications about this
     * year's staking must not go out while every other notification is still walking through 2021.
     *
     * A pass that dies before moving the cursor is simply redone, at once: EventsFlow::insert() finds the events it
     * already saved by operation id and sub-id, and EventFlow::save() then notifies nobody again. That only holds
     * while the events are still in events_flow - about ten minutes, before Lifecycles::cleanUpEventsFlow() moves
     * them out - which an immediate redo is well within. A replay hours later would notify again.
     */
    public function analyzeNewAccountOperations(): void
    {
        $tsStart = Time::Microtime();

        set_time_limit(Config::runTimeLimit() + Config::kJOBS_TIMEOUT_TEZOS_TRANSACTION_ANALYZE);

        if (!$this->checkLockAndWaitUntilNoOtherInstanceRuns('command-lock.tezos-network.analyze-account-operations', Config::kJOBS_TIMEOUT_DEFAULT))
            return;

        $progress = new ProgressLog('analyze account operations');

        try
        {
            while (Time::Microtime() - $tsStart <= Config::runTimeLimit() && !Shutdown::Requested())
            {
                Vss::dbCloseAll();

                $cursor = $this->accountOperationsCursor();

                if ($cursor === null)
                {
                    $this->logMsg("The account-operation sync has not run yet. Wait 5 sec..");
                    sleep(5);
                    continue;
                }

                $synced  = \CryptoNoises\GatewayTezos\Gateway::GetLastAccountOperationsSyncedBlock();
                $indexer = \CryptoNoises\GatewayTezos\Gateway::GetLastFullySyncedBlock();

                if ($synced === null || $indexer === null)
                {
                    sleep(1);
                    continue;
                }

                $progress->report($cursor, min($synced, $indexer), 'synced');

                $levelTo = min($synced, $indexer, $cursor + self::kACCOUNT_OPS_CHUNK);

                if ($levelTo <= $cursor)
                {
                    sleep(1);
                    continue;
                }

                $count = $this->analyzeAccountOperations($cursor + 1, $levelTo);

                Models\Config::model()->setValue(self::kACCOUNT_OPS_CURSOR, (string) $levelTo);

                $this->logMsg("Account operations #".($cursor + 1)."..#$levelTo: $count event(s)");
            }

            $this->logMsg("END.");
        }
        catch (Exception $e)
        {
            $this->logErr("ERROR: " . $e->getMessage());

            // Without a pause, an error that persists becomes a restart loop under "restart: always"
            sleep(30);
        }
    }

    /**
     * Re-analyzes a level range by hand (level=<from>-<to>), without touching the cursor. Events already saved are
     * not saved or notified again.
     */
    public function analyzeRangeAccountOperations(): void
    {
        set_time_limit(3600);

        $range = explode('-', (string) $this->getCmdVar('level'));

        if ($range[0] === '')
        {
            $this->logErr("The level parameter is required: level=<from>-<to>");
            return;
        }

        $levelFrom = (int) $range[0];
        $levelTo   = (int) ($range[1] ?? $range[0]);

        $count = $this->analyzeAccountOperations($levelFrom, $levelTo);

        $this->logMsg("Account operations #$levelFrom..#$levelTo: $count event(s)", "\n", true);
    }

    private function analyzeAccountOperations(int $levelFrom, int $levelTo): int
    {
        $parser = new \CryptoNoises\Networks\Tezos\AccountOperationsParser();
        $parser->analyze(\CryptoNoises\GatewayTezos\Gateway::GetAccountOperations($levelFrom, $levelTo));

        return $parser->save();
    }

    /**
     * The cursor, initialized on first use to just before the level the account-operation sync started from:
     * an install that gains this feature starts where its sync starts, not at block 1.
     */
    private function accountOperationsCursor(): ?int
    {
        $cursor = Models\Config::model()->getValue(self::kACCOUNT_OPS_CURSOR);

        if ($cursor !== null && $cursor !== '')
            return (int) $cursor;

        $first = \CryptoNoises\GatewayTezos\Gateway::GetAccountOperationsFirstLevel();

        if ($first === null)
            return null;

        Models\Config::model()->setValue(self::kACCOUNT_OPS_CURSOR, (string) ($first - 1));

        return $first - 1;
    }
}
