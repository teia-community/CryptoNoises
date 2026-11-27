<?php

namespace CryptoNoises\Networks\Tezos;

use CryptoNoises\Core\Log;
use CryptoNoises\Entities\Address;
use CryptoNoises\Entities\EventFlow;
use CryptoNoises\Models;
use CryptoNoises\Networks\Tezos\Hunters;

class TezosNetworkParser
{
    public bool $verbose = false;

    /** @var EventFlow[] */
    protected array $events = [];

    public function syncWithBlockchainByLevel($level): bool
    {
        $this->debug("TezosNetworkParser:syncWithBlockchainByLevel: Start level #$level");

        $this->openProfiler('GetTransactions');
        $transactions = \CryptoNoises\GatewayTezos\Gateway::GetTransactions($level);
        $this->closeProfiler();

        $this->debug("- found transactions: ".count($transactions));

        if (empty($transactions))
            return false;

        $this->analyzeWalletsToSync($transactions);
        $this->analyzeByHunters($transactions);

        $this->debug("TezosNetworkParser:syncWithBlockchainByLevel: Done");
        $this->debug("");
        return true;
    }

    //------------------------------------------------------------------------------------------------------------------

    protected function analyzeWalletsToSync(array $transactions): bool
    {
        $this->debug("Exec analyzeWalletsToSync:");
        $this->openProfiler('WalletsToSync');

        $addressList = [];

        foreach ($transactions as $transaction)
        {
            if (TezosNetwork::IsWalletAddressValid($transaction['target']))
                $addressList[] = $transaction['target'];

            if (TezosNetwork::IsWalletAddressValid($transaction['sender']))
                $addressList[] = $transaction['sender'];
        }

        $addressList = array_unique($addressList);
        $adids       = Address::GetAdids($addressList);

        foreach ($addressList as $address)
        {
            $adid = $adids[$address] ?? null;

            if ($adid == null)
                continue;

            if (Models\Wallets::model()->getWalletStatus($adid) === null)
                continue;

            Models\QueueWalletsSync::model()->push($adid, $address, Models\QueueWalletsSync::kPRIORITY_Normal);

            $this->debug("- add to sync: $adid / $address");
        }

        $this->closeProfiler();
        return true;
    }

    protected function analyzeByHunters(array $transactions): bool
    {
        $this->debug("Exec analyzeByHunters:");

        $this->openProfiler('Hunters:Init');

        /** @var Hunters\AbstractHunter[] $hunters */
        $hunters = [];

        foreach (TezosNetwork::$HunterClasses as $hunterClass)
        {
            $hunters[] = new $hunterClass($this);
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
        // Repack "transactions list" to "transactions blocks (groups)"

        $transactionsInBlocks = [];

        foreach ($transactions as $tr)
        {
            // Neither 'counter' nor 'hash' is unique within a level,
            // so group by counter + hash
            $transactionsInBlocks[$tr['counter'].':'.$tr['hash']][] = $tr;
        }

        $this->closeProfiler();

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        foreach ($transactionsInBlocks as $recordsBlock)
        {
            $recInit = Hunters\AbstractHunter::ExtractInitRecord($recordsBlock);

            if ($recInit == null)
            {
                Log::Error(get_class($this), __FUNCTION__, "Cannot extract Init Record", $recordsBlock);
                continue;
            }

            $this->debug("- analyze group with init counter=".$recInit['counter'].", hash=".$recInit['hash'], ' >> ');

            $found = false;
            foreach ($hunters as $hunter)
            {
                $this->openProfilerObject('Hunters', $hunter);
                $result = $hunter->parseTransaction($recInit, $recordsBlock);
                $this->closeProfiler();

                if ($result)
                {
                    $this->debug(get_class($hunter));
                    $found = true;
                    break;
                }
            }

            if (!$found)
                $this->debug('[?]');
        }

        return true;
    }

    //------------------------------------------------------------------------------------------------------------------

    /** @var array<string, int[]> EventFlow::groupKey() => indexes in $events; '' holds the events without a key */
    private array $eventGroups = [];

    public function pushEvent(EventFlow $event): bool
    {
        // Comparing with every event was quadratic: a dense block has hundreds. Only events with the same group
        // key can group, so only those are tried, in the order they were found.
        $key = $event->groupKey();

        if ($key === null)
            $candidates = array_keys($this->events);
        else
        {
            $candidates = array_merge($this->eventGroups[$key] ?? [], $this->eventGroups[''] ?? []);

            if (isset($this->eventGroups[$key], $this->eventGroups['']))
                sort($candidates);
        }

        foreach ($candidates as $i)
        {
            if ($this->events[$i]->tryGroupEqualEvents($event))
                return true;
        }

        $this->events[] = $event;
        $this->eventGroups[$key ?? ''][] = array_key_last($this->events);
        return true;
    }

    //------------------------------------------------------------------------------------------------------------------

    public function save(): int
    {
        $this->debug("TezosNetworkParser:save: Start");
        $this->openProfiler('Save');

        $count = count($this->events);

        foreach ($this->events as $idx => $event)
        {
            $event->save();
            $this->debug("TezosNetworkParser:save: #".($idx+1)."/$count saved");
        }
        EventFlow::LogExecAt_SaveData();

        $this->debug("TezosNetworkParser:save: Done");

        $this->closeProfiler();
        return $count;
    }

    //------------------------------------------------------------------------------------------------------------------

    public function debug($msg, $endchar="\n")
    {
        if (!$this->verbose)
            return;

        echo $msg.$endchar;
        ob_flush();
    }

    //------------------------------------------------------------------------------------------------------------------

    private array $profilerData = [];

    private ?string $activeProfilerName = null;
    private ?float $activeProfilerTimestamp = null;

    public function openProfiler(string $name): void
    {
        $this->activeProfilerName = $name;
        $this->activeProfilerTimestamp = microtime(true);
    }

    /** @var array<string, string> class => short name */
    private static array $shortClassNames = [];

    public function openProfilerObject(string $name, object $object): void
    {
        $class     = get_class($object);
        $className = self::$shortClassNames[$class] ??= substr(strrchr('\\'.$class, '\\'), 1);

        $this->activeProfilerName = $name.':'.$className;
        $this->activeProfilerTimestamp = microtime(true);
    }

    public function closeProfiler(): void
    {
        if ($this->activeProfilerName === null)
            return;

        $timeSpent = microtime(true) - $this->activeProfilerTimestamp;

        $this->profilerData[$this->activeProfilerName] = ($this->profilerData[$this->activeProfilerName] ?? 0) + $timeSpent;

        $this->activeProfilerName = null;
        $this->activeProfilerTimestamp = null;
    }

    public function getProfilerData(): string
    {
        $result = [];

        foreach ($this->profilerData as $key => $time)
        {
            if ($time < 0.1)
                continue;

            $result[$key] = number_format($time, 3);
        }

        return var_export($result, true);
    }
}
