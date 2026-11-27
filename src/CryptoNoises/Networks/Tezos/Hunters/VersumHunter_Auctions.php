<?php

namespace CryptoNoises\Networks\Tezos\Hunters;

use CryptoNoises\Core\CustomData;
use CryptoNoises\Core\Log;
use CryptoNoises\Entities\EventFlow;
use CryptoNoises\Enum;
use CryptoNoises\Networks\Tezos\Markets\Versum;
use CryptoNoises\Networks\Tezos\TezosNetwork;
use CryptoCore\Time;
use Exception;

class VersumHunter_Auctions extends VersumHunter
{
    public function parseTransaction(array $recInit, array $subRecords): bool
    {
        if ($recInit['target'] != self::kMarketplaceKTID)
            return false;

        if ($recInit['entrypoint'] == 'create_auction')
            return $this->analyzeAuctionStartEvent($recInit, $subRecords);

        if ($recInit['entrypoint'] == 'withdraw')
            return $this->analyzeAuctionWithdrawEvent($recInit, $subRecords);

        if ($recInit['entrypoint'] == 'bid')
            return $this->analyzeAuctionBidEvent($recInit, $subRecords);

        return false;
    }

    //==================================================================================================================

    protected function analyzeAuctionStartEvent(array $recInit, array $subRecords): bool
    {
        try
        {
            $recValue = $recInit['parameter']['value'];

            if (!in_array($recValue['token']['address'], self::GetSupportedCollectionKTIDs()))
                throw new Exception("Unsupported collection contract ".$recValue['token']['address']);

            if ($recValue['currency'] !== null)
                throw new Exception('Key currency is not NULL');

            // From the operation, not now: the indexer may be catching up on history
            $duration = strtotime($recValue['end_timestamp']) - strtotime($recInit['timestamp'].' UTC');

            $event = new EventFlow();
            $event->initInTezosNetworkByRecord($recInit);

            $event->event_type              = Enum\EventFlowType::AuctionStart;
            $event->sender_address          = $recInit['sender'];
            $event->target_address          = $recInit['target'];
            $event->royalty_address         = null;

            $event->price                   = $recValue['bid_amount'];
            $event->currency                = TezosNetwork::kCURRENCY_ID;
            $event->quantity                = $recValue['token_amount'];

            $event->marketplace_address     = $recInit['target'];
            $event->token_address           = $recValue['token']['address'];
            $event->token_id                = $recValue['token']['nat'];

            $event->custom_data             = [
                CustomData::kDURATION => $duration,
            ];

            $this->parser->pushEvent($event);

            return true;
        }
        catch (Exception $e)
        {
            Log::Warning(get_class($this), __FUNCTION__, $e->getMessage()." #{$recInit['id']}");
            return false;
        }
    }

    protected function analyzeAuctionWithdrawEvent(array $recInit, array $subRecords): bool
    {
        // Variant 1:
        // Withdraw auction when someone won auction:

        if (count($subRecords) > 1)
        {
            if ($this->analyzePurchaseEvent($recInit, $subRecords, Enum\EventFlowDetails::AuctionConclude))
                return true;

            Log::Warning(get_class($this), __FUNCTION__, "SubRecords count more than 1, but cannot detect purchase state. #{$recInit['id']}");
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
        // Variant 2:
        // Withdraw auction when nobody made a bid:

        try
        {
            $auctionId = $recInit['parameter']['value'] ?? null;

            if (!is_numeric($auctionId))
                throw new Exception("AuctionId is not defined");

            $customData = [];
            $customData[CustomData::kAUCTION_ID] = $auctionId;

            $auctionDetails = Versum::GetAuctionDetails($auctionId);

            if ($auctionDetails === null)
                throw new Exception("Cannot get auction details");

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $event = new EventFlow();
            $event->initInTezosNetworkByRecord($recInit);

            $event->event_type              = Enum\EventFlowType::AuctionCancel;
            $event->sender_address          = $recInit['target']; # intentionally swapped: the contract is the sender
            $event->target_address          = $recInit['sender']; # intentionally swapped: the owner is the target

            $event->marketplace_address     = $recInit['target'];
            $event->token_address           = $auctionDetails->tokenAddress;
            $event->token_id                = $auctionDetails->tokenId;

            $event->custom_data             = $customData;

            $this->parser->pushEvent($event);

            return true;
        }
        catch (Exception $e)
        {
            Log::Warning(get_class($this), __FUNCTION__, $e->getMessage()." #{$recInit['id']}");
            return false;
        }
    }

    //==================================================================================================================

    protected function analyzeAuctionBidEvent(array $recInit, array $subRecords): bool
    {
        try
        {
            $auctionId = $recInit['parameter']['value']['auction_id'] ?? null;

            if (!is_numeric($auctionId))
                throw new Exception("AuctionId is not defined");

            $customData = [];
            $customData[CustomData::kAUCTION_ID] = $auctionId;

            $auctionDetails = Versum::GetAuctionDetails($auctionId);

            if ($auctionDetails === null)
                throw new Exception("Cannot get auction details");

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $event = new EventFlow();
            $event->initInTezosNetworkByRecord($recInit);

            $event->event_type              = Enum\EventFlowType::AuctionBid;
            $event->sender_address          = $recInit['sender'];
            $event->target_address          = $recInit['target'];
            $event->royalty_address         = $auctionDetails->creator;

            $event->price                   = $recInit['amount'];
            $event->currency                = TezosNetwork::kCURRENCY_ID;

            $event->marketplace_address     = $recInit['target'];
            $event->token_address           = $auctionDetails->tokenAddress;
            $event->token_id                = $auctionDetails->tokenId;

            $event->custom_data             = $customData;

            $this->parser->pushEvent($event);

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            if (count($subRecords) > 0)
            {
                $customData[CustomData::kAUCTION_TOP_BID]   = $recInit['amount'];
                $customData[CustomData::kAUCTION_TOP_OWNER] = $recInit['sender'];

                if (count($subRecords) > 1)
                    Log::Warning(get_class($this), __FUNCTION__, 'More than one auction outbid record');

                foreach ($subRecords as $record)
                {
                    $event = new EventFlow();
                    $event->initInTezosNetworkByRecord($record);

                    $event->event_type              = Enum\EventFlowType::AuctionOutBid;
                    $event->sender_address          = $record['sender'];
                    $event->target_address          = $record['target'];
                    $event->royalty_address         = $auctionDetails->creator;

                    $event->price                   = $record['amount'];
                    $event->currency                = TezosNetwork::kCURRENCY_ID;

                    $event->marketplace_address     = $recInit['target'];
                    $event->token_address           = $auctionDetails->tokenAddress;
                    $event->token_id                = $auctionDetails->tokenId;

                    $event->custom_data             = $customData;

                    $this->parser->pushEvent($event);
                }
            }

            return true;
        }
        catch (Exception $e)
        {
            Log::Warning(get_class($this), __FUNCTION__, $e->getMessage()." #{$recInit['id']}");
            return false;
        }
    }
}
