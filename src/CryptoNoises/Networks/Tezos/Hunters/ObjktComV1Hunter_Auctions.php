<?php

namespace CryptoNoises\Networks\Tezos\Hunters;

use CryptoNoises\Core\CustomData;
use CryptoNoises\Core\Log;
use CryptoNoises\Entities\EventFlow;
use CryptoNoises\Enum;
use CryptoNoises\Networks\Tezos\Markets\ObjktCom;
use CryptoNoises\Networks\Tezos\TezosNetwork;
use Exception;

class ObjktComV1Hunter_Auctions extends ObjktComV1Hunter
{
    public function parseTransaction(array $recInit, array $subRecords): bool
    {
        if ($recInit['target'] != self::kEnglishAuctionsKTID &&
            $recInit['target'] != self::kDutchAuctionsKTID &&
            $recInit['target'] != self::kEnglishAuctionsKTID_pre &&
            $recInit['target'] != self::kDutchAuctionsKTID_pre)
            return false;

        $entrypoint = $recInit['entrypoint'];

        if ($entrypoint == 'create_auction')                                # English + Dutch       : create
            return $this->analyzeAuctionStartEvent($recInit, $subRecords);

        if ($entrypoint == 'bid')                                           # English               : bid
            return $this->analyzeAuctionBidEvent($recInit, $subRecords);

        if ($entrypoint == 'conclude_auction')                              # English               : finished
            return $this->analyzeAuctionConcludeEvent($recInit, $subRecords);

        if ($entrypoint == 'buy')                                           #           Dutch       : on make a bid-and-buy
            return $this->analyzeAuctionConcludeEvent($recInit, $subRecords);

        if ($entrypoint == 'cancel_auction')                                # English + Dutch       : cancel
            return $this->analyzeAuctionCancelEvent($recInit, $subRecords);

        return false;
    }

    //==================================================================================================================

    protected function analyzeAuctionStartEvent(array $recInit, array $subRecords): bool
    {
        try
        {
            $recValue = $recInit['parameter']['value'];

            $event = new EventFlow();
            $event->initInTezosNetworkByRecord($recInit);

            $event->event_type              = Enum\EventFlowType::AuctionStart;
            $event->sender_address          = $recInit['sender'];
            $event->target_address          = $recInit['target'];
            $event->royalty_address         = $recValue['artist'];

            $event->price                   = $recValue['reserve'];
            $event->currency                = TezosNetwork::kCURRENCY_ID;

            $event->marketplace_address     = $recInit['target'];
            $event->token_address           = $recValue['fa2'];
            $event->token_id                = $recValue['objkt_id'];

            $event->custom_data             = [
                CustomData::kDURATION => strtotime($recValue['end_time']) - strtotime($recValue['start_time']),
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

    //==================================================================================================================

    protected function analyzeAuctionBidEvent(array $recInit, array $subRecords): bool
    {
        try
        {
            $auctionId = $recInit['parameter']['value'] ?? null;

            if (!is_numeric($auctionId))
                throw new Exception("AuctionId is not defined");

            $customData = [];
            $customData[CustomData::kAUCTION_ID] = $auctionId;

            $auctionDetails = ObjktCom::GetAuctionDetails($recInit['target'], $auctionId);

            if ($auctionDetails === null)
                throw new Exception("Cannot get auction details");

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

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

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

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

    private function analyzeAuctionConcludeEvent(array $recInit, array $subRecords): bool
    {
        // Variant 1:
        // A customer won the auction:

        if ($this->analyzeStdTokenPurchaseEvent($recInit, $subRecords, self::kMarketplaceFeeWallet, Enum\EventFlowDetails::AuctionConclude))
            return true;

        // Variant 2:
        // Nobody made a bid (the token is transferred back to the owner):

        if (count($subRecords) == 1)
        {
            $recTransfer = self::ExtractRecordByEntrypoint($subRecords, 'transfer');

            if ($recTransfer)
            {
                $transferEvents = $this->findTransferEvents($recTransfer);

                if (!empty($transferEvents))
                {
                    foreach ($transferEvents as $transferEvent)
                    {
                        // override/set some params
                        $transferEvent->event_type          = Enum\EventFlowType::AuctionEndFailed;
                        $transferEvent->marketplace_address = $recInit['target'];

                        $this->parser->pushEvent($transferEvent);
                    }

                    return true;
                }
            }
        }

        return false;
    }

    protected function analyzeAuctionCancelEvent(array $recInit, array $subRecords): bool
    {
        try
        {
            $auctionId = $recInit['parameter']['value'] ?? null;

            if (!is_numeric($auctionId))
                throw new Exception("AuctionId is not defined");

            $customData = [];
            $customData[CustomData::kAUCTION_ID] = $auctionId;

            $auctionDetails = ObjktCom::GetAuctionDetails($recInit['target'], $auctionId);

            if ($auctionDetails === null)
                throw new Exception("Cannot get auction details");

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

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
}
