<?php

namespace CryptoNoises\Networks\Tezos\Hunters;

use CryptoNoises\Core\CustomData;
use CryptoNoises\Core\Log;
use CryptoNoises\Entities\EventFlow;
use CryptoNoises\Enum;
use CryptoNoises\Networks\Tezos\Markets\ObjktCom;
use CryptoNoises\Networks\Tezos\TezosNetwork;
use Exception;

class ObjktComV23Hunter_Auctions extends ObjktComV23Hunter
{
    public function parseTransaction(array $recInit, array $subRecords): bool
    {
        if (!$this->setupHunterAttrsByContract($recInit['target']))
            return false;

        $entrypoint = $recInit['entrypoint'];

        // Create @ English+Dutch
        if ($entrypoint == 'create_auction')
            return $this->analyzeAuctionStartEvent($recInit, $subRecords);

        // Bid @ English
        if ($entrypoint == 'bid')
            return $this->analyzeAuctionBidEvent($recInit, $subRecords);

        // Bid-n-Buy @ Dutch
        if ($entrypoint == 'buy')
            return $this->analyzeStdTokenPurchaseEvent($recInit, $subRecords, $this->kMarketplaceFeeWallet, Enum\EventFlowDetails::AuctionConclude);

        // Finish @ English
        if ($entrypoint == 'settle_auction')
            return $this->analyzeAuctionConcludeEvent($recInit, $subRecords);

        // Cancel @ English+Dutch
        if ($entrypoint == 'cancel_auction')
            return $this->analyzeAuctionCancelEvent($recInit, $subRecords);

        return false;
    }

    //==================================================================================================================

    private function analyzeAuctionStartEvent(array $recInit, array $subRecords): bool
    {
        try
        {
            $recValue = $recInit['parameter']['value'];

            $event = new EventFlow();
            $event->initInTezosNetworkByRecord($recInit);

            $event->event_type              = Enum\EventFlowType::AuctionStart;
            $event->sender_address          = $recInit['sender'];
            $event->target_address          = $recInit['target'];
            $event->royalty_address         = $this->getAddressWithHighestShareAmount($recValue['shares']);

            if ($recInit['target'] == $this->kEnglishAuctionsKTID)
            {
                $currency = $recValue['currency']['fa12'] ?? null;

                if (!is_string($currency) || !$this->isSupportedCurrencyToken($currency)) {
                    Log::Warning(get_class($this), __FUNCTION__, "Unsupported english-auction currency", $recInit);
                    return false;
                }

                $event->price                   = $recValue['reserve'];
                $event->currency_address        = $currency;
                $event->quantity                = 1;
            }
            else if($recInit['target'] == $this->kDutchAuctionsKTID)
            {
                // Only tez is handled for buys; the few token-priced dutch auctions are skipped
                if (!isset($recValue['currency']['tez'])) {
                    Log::Warning(get_class($this), __FUNCTION__, "Unsupported dutch-auction currency", $recInit);
                    return false;
                }

                $event->price                   = $recValue['start_price'];
                $event->currency                = TezosNetwork::kCURRENCY_ID;
                $event->quantity                = $recValue['editions'];
            }
            else
            {
                Log::Error(get_class($this), __FUNCTION__, "Unknown state", $recInit);
                return false;
            }

            $event->marketplace_address     = $recInit['target'];
            $event->token_address           = $recValue['token']['address'];
            $event->token_id                = $recValue['token']['token_id'];

            $event->custom_data             = [
                CustomData::kDURATION => strtotime($recValue['end_time']) - strtotime($recValue['start_time']),
            ];

            $this->parser->pushEvent($event);

            return true;
        }
        catch (Exception $e)
        {
            Log::Warning(get_class($this), __FUNCTION__, $e->getMessage()." #{$recInit['id']}", $recInit);
            return false;
        }
    }

    private function analyzeAuctionCancelEvent(array $recInit, array $subRecords): bool
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
            Log::Warning(get_class($this), __FUNCTION__, $e->getMessage()." #{$recInit['id']}", $recInit);
            return false;
        }
    }

    //==================================================================================================================

    private function analyzeAuctionBidEvent(array $recInit, array $subRecords): bool
    {
        try
        {
            $auctionId  = ($recInit['parameter']['value']['auction_id'] ?? null) ?: null;
            $currentBid = ($recInit['parameter']['value']['amount'] ?? null) ?: null;

            if (!is_numeric($auctionId) || !is_numeric($currentBid))
                throw new Exception("Bad auction params");

            $customData = [];
            $customData[CustomData::kAUCTION_ID] = $auctionId;

            $auctionDetails = ObjktCom::GetAuctionDetails($recInit['target'], $auctionId);

            if ($auctionDetails === null)
                throw new Exception("Cannot get auction details");

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $recCurrentBid = null;

            foreach ($subRecords as $key => $record)
            {
                // Same reason as in AbstractHunter::unpackTransfersEvents(): a sub-record without an entrypoint
                // has no parameter, and an auction settlement routinely contains plain transfers. The null check
                // is separate from the comparison because null == 0 is true on PHP 8, so a parameterless record
                // would otherwise match a current bid of zero.
                $recordValue = $record['parameter']['value']['value'] ?? null;

                if ($recordValue !== null && $recordValue == $currentBid)
                {
                    $recCurrentBid = $record;
                    unset($subRecords[$key]);
                    break;
                }
            }

            if ($recCurrentBid == null)
                throw new Exception("Cannot find current-bid record");

            if (count($subRecords) > 1)
                throw new Exception("Too many records left in sub-record");

            if (count($subRecords) == 1)
                $recPreviousBid = array_pop($subRecords);
            else
                $recPreviousBid = null;

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $recCurrentValue = $recCurrentBid['parameter']['value'];

            $event = new EventFlow();
            $event->initInTezosNetworkByRecord($recInit, 0);

            $event->event_type              = Enum\EventFlowType::AuctionBid;
            $event->sender_address          = $recCurrentValue['from'];
            $event->target_address          = $recCurrentValue['to'];
            $event->royalty_address         = $auctionDetails->creator;

            $event->price                   = $recCurrentValue['value'];
            $event->currency_address        = $this->auctionCurrency($recCurrentBid);

            $event->marketplace_address     = $recInit['target'];
            $event->token_address           = $auctionDetails->tokenAddress;
            $event->token_id                = $auctionDetails->tokenId;

            $event->custom_data             = $customData;

            $this->parser->pushEvent($event);

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            if ($recPreviousBid)
            {
                $recPreviousValue = $recPreviousBid['parameter']['value'];

                $customData[CustomData::kAUCTION_TOP_BID]   = $currentBid;
                $customData[CustomData::kAUCTION_TOP_OWNER] = $recInit['sender'];

                $event = new EventFlow();
                $event->initInTezosNetworkByRecord($recInit, 1);

                $event->event_type              = Enum\EventFlowType::AuctionOutBid;
                $event->sender_address          = $recPreviousValue['from'];
                $event->target_address          = $recPreviousValue['to'];
                $event->royalty_address         = $auctionDetails->creator;

                $event->price                   = $recPreviousValue['value'];
                $event->currency_address        = $this->auctionCurrency($recPreviousBid);

                $event->marketplace_address     = $recInit['target'];
                $event->token_address           = $auctionDetails->tokenAddress;
                $event->token_id                = $auctionDetails->tokenId;

                $event->custom_data             = $customData;

                $this->parser->pushEvent($event);
            }

            return true;
        }
        catch (Exception $e)
        {
            Log::Warning(get_class($this), __FUNCTION__, $e->getMessage(), $recInit);
            return false;
        }
    }

    //==================================================================================================================

    protected function analyzeAuctionConcludeEvent(array $recInit, array $subRecords): bool
    {
        try
        {
            // Sometimes I see additional record with `entrypoint = do_transfer`. So remove it!
            self::ExtractRecordByEntrypoint($subRecords, 'do_transfer');

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
            // Auction finished with some bid(s)

            if (count($subRecords) >= 3)
                if ($this->analyzeAuctionConcludeWithPurchaseEvent($recInit, $subRecords))
                    return true;

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
            // Next for "auction failed without bids"

            if (count($subRecords) != 1)
                throw new Exception("Bad sub-records count");

            $recTransfer = self::ExtractRecordByEntrypoint($subRecords, 'transfer');

            if ($recTransfer == null)
                throw new Exception("Cannot find transfer-record");

            $transferEvents = $this->findTransferEvents($recTransfer);

            if (empty($transferEvents))
                throw new Exception("Cannot unpack transfer-events");

            foreach ($transferEvents as $transferEvent)
            {
                // override/set some params
                $transferEvent->event_type          = Enum\EventFlowType::AuctionEndFailed;
                $transferEvent->marketplace_address = $recInit['target'];

                $this->parser->pushEvent($transferEvent);
            }
            return true;
        }
        catch (Exception $e)
        {
            Log::Warning(get_class($this), __FUNCTION__, $e->getMessage(), $recInit);
            return false;
        }
    }

    private function analyzeAuctionConcludeWithPurchaseEvent(array $recInit, array $subRecords): bool
    {
        try
        {
            $recTransfer = $this->extractTokenTransferRecord_englishAuction($subRecords);

            if ($recTransfer == null)
                throw new Exception('Cannot find transfer-transaction');

            $recStore = $this->extractRecordWithReceiverAddress_englishAuction($subRecords, $this->kMarketplaceFeeWallet);

            if ($recStore == null)
                throw new Exception('Cannot find store-fee-transaction for ['.$this->kMarketplaceFeeWallet.']');

            $recRevenue = $this->extractRecordWithHighestAmount_englishAuction($subRecords);

            //----------------------------------------------------------------------------------------------------------
            // There may be more than one royalty record (Sample #2)

            $royaltyAddressList = [];
            $royaltyTotalAmount = 0;

            foreach ($subRecords as $record)
            {
                $value = $record['parameter']['value'];

                // Some sub-records have no 'to' field: skip them
                if (!isset($value['to']))
                    continue;

                $royaltyAddressList[$value['to']] = ($royaltyAddressList[$value['to']] ?? 0) + $value['value'];
                $royaltyTotalAmount += $value['value'];
            }

            //----------------------------------------------------------------------------------------------------------
            // 2: Transfer Data

            $transfers = $this->unpackTransfersEvents($recTransfer);

            if (empty($transfers))
                throw new Exception('Cannot detect transfers records');

            foreach ($transfers as $transfer)
            {
                $event = new EventFlow();
                $event->initInTezosNetworkByRecord($recInit);

                $event->event_type              = Enum\EventFlowType::TokenPurchase;
                $event->event_details           = Enum\EventFlowDetails::AuctionConclude;

                $event->sender_address          = $recRevenue['parameter']['value']['to']; # Notice: it cannot be $transfer->sender, because it'll be contract, not seller
                $event->target_address          = $transfer->target;
                $event->royalty_address         = null;
                $event->royalty_address_list    = $royaltyAddressList;
                $event->fee_address             = $this->kMarketplaceFeeWallet;

                $event->revenue                 = $recRevenue['parameter']['value']['value'];
                $event->royalty                 = $royaltyTotalAmount;
                $event->fee                     = $recStore['parameter']['value']['value'];
                $event->price                   = $event->revenue + $event->royalty + $event->fee;

                $event->currency_address        = $this->auctionCurrency($recRevenue);
                $event->quantity                = $transfer->quantity;

                $event->marketplace_address     = $recInit['target'];
                $event->token_address           = $transfer->tokenAddress;
                $event->token_id                = $transfer->tokenId;

                $this->parser->pushEvent($event);
            }

            return true;
        }
        catch (Exception $e)
        {
            Log::Warning(get_class($this), __FUNCTION__, $e->getMessage()." #{$recInit['id']}", $recInit);
            return false;
        }
    }

    private function extractTokenTransferRecord_englishAuction(array &$records): ?array
    {
        // This is new format since Feb 23, 2022
        // {
        //   "entrypoint":"transfer",
        //   "value":[
        //      {
        //         "list":[
        //            {
        //               "to":"<address>",
        //               "amount":"1",
        //               "token_id":"43947"
        //            }
        //         ],
        //         "address":"<address>"
        //      }
        //   ]
        //}

        foreach ($records as $key => $record)
        {
            // Only a token transfer has a list here: the bid payouts and fees of the same operation do not
            $value0 = $record['parameter']['value'][0] ?? null;

            if (!is_array($value0))
                continue;

            if (isset($value0['address']) && $value0['address'] == $this->kEnglishAuctionsKTID &&
                isset($value0['list'][0]['token_id']))
            {
                if (count($record['parameter']['value']) > 1)
                    Log::Warning(get_class($this), __FUNCTION__, "Transfer record has more than 1 element");

                if (count($value0['list']) > 1)
                    Log::Warning(get_class($this), __FUNCTION__, "Inner list in transfer record has more than 1 element");

                unset($records[$key]);
                return $record;
            }
        }

        // This is old format
        // {
        //   "entrypoint":"transfer",
        //   "value":[
        //      {
        //         "txs":[
        //            {
        //               "to_":"<address>",
        //               "amount":"1",
        //               "token_id":"6"
        //            }
        //         ],
        //         "from_":"<address>"
        //      }
        //   ]
        //}

        foreach ($records as $key => $record)
        {
            // Only a token transfer has a list here: the bid payouts and fees of the same operation do not
            $value0 = $record['parameter']['value'][0] ?? null;

            if (!is_array($value0))
                continue;

            if (isset($value0['from_']) && $value0['from_'] == $this->kEnglishAuctionsKTID &&
                isset($value0['txs'][0]['token_id']))
            {
                if (count($record['parameter']['value']) > 1)
                    Log::Warning(get_class($this), __FUNCTION__, "Transfer record has more than 1 element (old)");

                if (count($value0['txs']) > 1)
                    Log::Warning(get_class($this), __FUNCTION__, "Inner list in transfer record has more than 1 element (old)");

                unset($records[$key]);
                return $record;
            }
        }

        return null;
    }

    private function extractRecordWithHighestAmount_englishAuction(array &$records): ?array
    {
        $bestKey = null;

        foreach ($records as $key => $record)
        {
            // Some records have no value: skip them
            if (!isset($record['parameter']['value']) || !isset($record['parameter']['value']['value']))
                continue;

            if ($bestKey === null || $records[$bestKey]['parameter']['value']['value'] < $record['parameter']['value']['value'])
                $bestKey = $key;
        }

        if ($bestKey !== null)
        {
            $record = $records[$bestKey];
            unset($records[$bestKey]);
            return $record;
        }

        return null;
    }

    /** English auctions pay in an FA1.2 token: the contract its transfer calls */
    private function auctionCurrency(?array $record): string
    {
        $currency = $record['target'] ?? null;

        if (!is_string($currency) || !$this->isSupportedCurrencyToken($currency))
            throw new Exception("Unsupported english-auction currency");

        return $currency;
    }

    private function extractRecordWithReceiverAddress_englishAuction(array &$records, string $address): ?array
    {
        foreach ($records as $key => $record)
        {
            if (isset($record['parameter']['value']['to']) && $record['parameter']['value']['to'] == $address)
            {
                unset($records[$key]);
                return $record;
            }
        }

        return null;
    }
}
