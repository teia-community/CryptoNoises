<?php

namespace CryptoNoises\Commands;

use CryptoCore\Setapp;
use CryptoNoises\Config;
use CryptoNoises\Entities;
use CryptoNoises\Entities\EventFlow;
use CryptoNoises\Enum;
use CryptoNoises\GatewayTezos;
use CryptoNoises\Networks\Tezos\Hunters;
use CryptoNoises\Networks\Tezos\TezosNetworkParser;
use CryptoCore\Time;
use CryptoCore\Vss\Vss;
class EventsTokensMint extends AbstractCommand
{
    public function tezos(): void
    {
        $tsStart = Time::Microtime();

        $limit = $this->getCmdVar('limit', 1000);

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        set_time_limit(Config::runTimeLimit() + Config::kJOBS_TIMEOUT_EVENTS_TOKENS);

        if (!$this->checkLockAndWaitUntilNoOtherInstanceRuns('command-lock.events-tokens-mint.tezos', Config::kJOBS_TIMEOUT_DEFAULT))
            return;

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $uniqueId = Time::Time();
        $uniqueSubId = 0;

        while (true)
        {
            if (Time::Microtime() - $tsStart > Config::runTimeLimit())
                break;

            $this->logMsg("Sync new TOKENS mint");

            $tokenEntity = GatewayTezos\TokenEvents::GetEvent_NewTokenDidMint();

            if ($tokenEntity === null)
            {
                Vss::dbCloseAll();
                $this->logMsg("No task: sleep for 1 sec");
                sleep(1);
                continue;
            }

            $this->analyzeTokenEntity($tokenEntity, $uniqueId, $uniqueSubId);

            Vss::dbCloseAll();
            $this->logMsg("");

            if (--$limit <= 0)
                break;
        }

        $this->logMsg("Done");
    }

    private function analyzeTokenEntity(Entities\Token $tokenEntity, int &$uniqueId, int &$uniqueSubId): void
    {
        $this->logMsg("- ".$tokenEntity->tokenAddress.':'.$tokenEntity->tokenId);

        //--------------------------------------------------------------------------------------------------------------
        // Global filters

        // Only the first edition is announced (not only for Kalamint)
        if ($tokenEntity->editionIndex > 1)
        {
            $this->logMsg("IGNORE: Editions 2,3,4....");
            return;
        }

        $channels_userIds = [Config::cfg()->userId_ChannelTezosGlobal];

        //--------------------------------------------------------------------------------------------------------------
        // Marketplaces

        $marketAddressToChannels = [
            Setapp\FxHash_v2::IssuerKTID                    => Config::cfg()->userId_ChannelFxHash,
            Hunters\KalamintHunter::kMarketplaceKTID        => Config::cfg()->userId_ChannelKalamint,
            Hunters\RaribleHunter::kMarketplaceKTID         => Config::cfg()->userId_ChannelRaribleTez,
            Hunters\TeiaHunter::kMarketplaceKTID            => Config::cfg()->userId_ChannelTeia,
            Setapp\Versum::MarketplaceKTID                  => Config::cfg()->userId_ChannelVersum,
            Setapp\ObjktCom_v1::MarketplaceKTID             => Config::cfg()->userId_ChannelObjktCom,
        ];

        // @notice: $marketAddress may be also NULL
        $marketAddress = Hunters\AbstractHunter::Convert_TokenToMarketplace($tokenEntity);

        if ($marketAddress && !empty($marketAddressToChannels[$marketAddress]))
            $channels_userIds[] = $marketAddressToChannels[$marketAddress];

        $this->logMsg("- Marketplace #[$marketAddress]");

        //--------------------------------------------------------------------------------------------------------------

        $channels_userIds = array_filter($channels_userIds, function($v)
        {
            return $v > 0;
        });

        if (empty($channels_userIds))
            $channels_userIds = null;

        //--------------------------------------------------------------------------------------------------------------

        $event = new EventFlow();
        $event->initInTezosNetwork(EventFlow::UNDEFINED_NETWORK_BLOCK, $uniqueId, ++$uniqueSubId);
        $event->transaction_event       = Time::Now();

        $event->event_type              = Enum\EventFlowType::TokenMint;

        $event->sender_address          = $tokenEntity->creatorAddress;
        $event->target_address          = $tokenEntity->tokenAddress;

        $event->price                   = null;
        $event->quantity                = $tokenEntity->editions ?: 0;

        $event->royalty_d4              = $tokenEntity->creatorRoyalty_d4 ?: null;

        $event->marketplace_address     = $marketAddress;
        $event->token_address           = $tokenEntity->tokenAddress;
        $event->token_id                = $tokenEntity->tokenId;

        $event->broadcastChannels_userIds    = $channels_userIds;

        //--------------------------------------------------------------------------------------------------------------
        // The event must be pushed even when there are no channel user ids

        $parser = new TezosNetworkParser();
        $parser->verbose = $this->verbose;
        $parser->pushEvent($event);
        $parser->save();

        //--------------------------------------------------------------------------------------------------------------

        $this->logMsg("- Done. UserIds [".($channels_userIds ? join(',', $channels_userIds) : 'EMPTY')."]");
        $this->logMsg("");
    }
}
