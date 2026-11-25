<?php

namespace CryptoNoises\Commands;

use CryptoCore\Process\Shutdown;
use CryptoCore\Setapp;
use CryptoCore\Time;
use CryptoNoises\Config;
use CryptoNoises\Core\Log;
use CryptoNoises\Entities\EventFlow;
use CryptoNoises\Enum;
use CryptoNoises\Models;
use CryptoNoises\Networks\Tezos\TezosNetworkParser;
use CryptoNoisesTezos\Core\Http\SafeCurl;
use CryptoNoisesTezos\Vendors\Ipfs;
use CryptoNoisesTezos\Vendors\Tzkt;
use Exception;

/**
 * Announces new events from the Teia events contract (Setapp\TeiaEvents) to every user who has Teia events on.
 * Polls its `events` bigmap; each visible event is announced once (ids kept in config). The first run only records
 * the events that already exist.
 */
class TeiaEvents extends AbstractCommand
{
    const kPASS_INTERVAL_SEC        = 300;
    const kCFG_ANNOUNCED            = 'TeiaEvents.Announced';      // JSON list of event ids
    const kMAX_DOCUMENT_BYTES       = 256 * 1024;

    public function run(): void
    {
        $tsStart = Time::Microtime();
        set_time_limit(Config::runTimeLimit() + Config::kJOBS_TIMEOUT_DEFAULT);

        if (!$this->checkLockAndWaitUntilNoOtherInstanceRuns('command-lock.teia-events', Config::kJOBS_TIMEOUT_DEFAULT))
            return;

        while (!Shutdown::Requested() && Time::Microtime() - $tsStart <= Config::runTimeLimit())
        {
            try
            {
                $this->logMsg(gmdate('H:i:s').' teia events: '.$this->passOnce().' announced', "\n", true);
            }
            catch (Exception $e)
            {
                $this->logErr('ERROR: '.$e->getMessage());
            }

            for ($i = 0; $i < self::kPASS_INTERVAL_SEC && !Shutdown::Requested(); $i++)
                sleep(1);
        }
    }

    public function runOnce(): void
    {
        $this->logMsg('Teia events announced: '.$this->passOnce(), "\n", true);
    }

    /** @return int events announced */
    public function passOnce(): int
    {
        $events = Tzkt::GetContractBigmapKeys(Setapp\TeiaEvents::ContractKTID, 'events');

        if ($events === null)
            throw new Exception('TzKT did not answer: Teia events');

        $stored    = Models\Config::model()->getValue(self::kCFG_ANNOUNCED);
        $announced = json_decode((string) $stored, true);

        if (!is_array($announced))
        {
            Models\Config::model()->setValue(self::kCFG_ANNOUNCED, json_encode(array_map('strval', array_keys($events))));
            return 0;
        }

        $parser = new TezosNetworkParser();
        $count  = 0;

        foreach ($events as $id => $event)
        {
            $id = (string) $id;

            // Hidden ones are announced if they are shown later
            if (in_array($id, $announced, true) || !empty($event['hidden']) || empty($event['current_cid']))
                continue;

            $document = self::Document($event['current_cid']);

            if ($document === null)
                continue;   // not reachable yet: next pass

            $flow = new EventFlow();
            $flow->initInTezosNetwork(EventFlow::UNDEFINED_NETWORK_BLOCK, (int) $id, 1);
            $flow->event_type        = Enum\EventFlowType::TeiaEvent;
            $flow->transaction_event = Time::Now();
            $flow->custom_data       = array_intersect_key($document, array_flip(['title', 'subtitle', 'description', 'link', 'screenshot']));

            $parser->pushEvent($flow);

            $announced[] = $id;
            $count++;
        }

        if ($count)
        {
            $parser->save();
            Models\Config::model()->setValue(self::kCFG_ANNOUNCED, json_encode($announced));
        }

        return $count;
    }

    /** An event's JSON document: Teia's events node first, then the usual gateways */
    private static function Document(string $uri): ?array
    {
        $cid  = preg_replace('#^ipfs://#', '', $uri);
        $urls = array_unique(array_merge([Setapp\TeiaEvents::IpfsGateway.$cid], Ipfs::FetchUrls('ipfs://'.$cid)));

        foreach ($urls as $url)
        {
            $response = SafeCurl::Fetch($url, 20, self::kMAX_DOCUMENT_BYTES);
            $document = $response->isOk() ? json_decode((string) $response->body, true) : null;

            if (is_array($document) && !empty($document['title']))
                return $document;
        }

        Log::Warning(self::class, __FUNCTION__, "Teia event document not reachable: $uri");
        return null;
    }
}
