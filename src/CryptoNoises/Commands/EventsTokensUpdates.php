<?php

namespace CryptoNoises\Commands;

use CryptoNoises\Config;
use CryptoNoises\Entities;
use CryptoNoises\Entities\EventFlow;
use CryptoNoises\Enum;
use CryptoNoises\Networks\Tezos\Hunters;
use CryptoNoises\Networks\Tezos\TezosHelper;
use CryptoNoises\Networks\Tezos\TezosNetworkParser;
use CryptoCore\Time;
use CryptoCore\Vss\Vss;

class EventsTokensUpdates extends AbstractCommand
{
    public function tezos(): void
    {
        $tsStart = Time::Microtime();

        $limit = $this->getCmdVar('limit', 1000);

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        set_time_limit(Config::kJOBS_TIMEOUT_EVENTS_TOKENS);

        if (!$this->checkLockAndWaitUntilNoOtherInstanceRuns('command-lock.events-tokens-updates.tezos', Config::kJOBS_TIMEOUT_DEFAULT))
            return;

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $uniqueId = Time::Time();
        $uniqueSubId = 0;

        while (true)
        {
            if (Time::Microtime() - $tsStart > Config::runTimeLimit())
                break;

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $tokenEntity = \CryptoNoises\GatewayTezos\TokenEvents::GetEvent_MetadataDidUpdate();

            if ($tokenEntity === null)
            {
                Vss::dbCloseAll();
                $this->logMsg('-','');
                sleep(1);
                continue;
            }
            else
            {
                $this->logMsg('');
            }

            $this->analyzeTokenEntity($tokenEntity, $uniqueId, $uniqueSubId);

            Vss::dbCloseAll();

            if (--$limit <= 0)
                break;
        }

        $this->logMsg("Done");
    }

    private function analyzeTokenEntity(Entities\Token $tokenEntity, int &$uniqueId, int &$uniqueSubId): void
    {
        $this->logMsg("- ".$tokenEntity->tokenAddress.':'.$tokenEntity->tokenId);

        $marketAddress = Hunters\AbstractHunter::Convert_TokenToMarketplace($tokenEntity);

        //--------------------------------------------------------------------------------------------------------------

        $tokenHolders = TezosHelper::GetTokenHolders($tokenEntity->tokenAddress, $tokenEntity->tokenId);

        if ($tokenHolders == null)
            return;

        $parser = new TezosNetworkParser();
        $parser->verbose = $this->verbose;

        foreach ($tokenHolders as $holderAddress)
        {
            $event = new EventFlow();
            $event->initInTezosNetwork(EventFlow::UNDEFINED_NETWORK_BLOCK, $uniqueId, ++$uniqueSubId);
            $event->transaction_event       = Time::Now();

            $event->event_type              = Enum\EventFlowType::TokenUpdateMetadata;

            $event->sender_address          = null;
            $event->target_address          = $holderAddress;

            $event->marketplace_address     = $marketAddress;
            $event->token_address           = $tokenEntity->tokenAddress;
            $event->token_id                = $tokenEntity->tokenId;

            if (Config::cfg()->userId_ChannelMetadataUpdates)
                $event->broadcastChannels_userIds = [Config::cfg()->userId_ChannelMetadataUpdates];

            $parser->pushEvent($event);

            $this->logMsg("  - Added event for wallet $holderAddress");
        }

        $parser->save();

        //--------------------------------------------------------------------------------------------------------------

        $this->logMsg("");
    }
}
