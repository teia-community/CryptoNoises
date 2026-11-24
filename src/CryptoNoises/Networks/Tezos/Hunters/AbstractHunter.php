<?php

namespace CryptoNoises\Networks\Tezos\Hunters;

use CryptoCore\Setapp;
use CryptoNoises\Core\CustomData;
use CryptoNoises\Core\Log;
use CryptoNoises\Entities\EventFlow;
use CryptoNoises\Entities\Token;
use CryptoNoises\Entities\TokenActions;
use CryptoNoises\Enum;
use CryptoNoises\Networks\Tezos\TezosNetwork;
use CryptoNoises\Networks\Tezos\TezosNetworkParser;
use Exception;

abstract class AbstractHunter
{
    protected TezosNetworkParser $parser;

    public function __construct(TezosNetworkParser $parser)
    {
        $this->parser = $parser;
    }

    //------------------------------------------------------------------------------------------------------------------

    abstract public static function GetMarketplaceContractByTokenContract(string $tokensContract): ?string;
    abstract public static function GetAllMarketContracts(): array;

    abstract public function parseTransaction(array $recInit, array $subRecords): bool;

    //------------------------------------------------------------------------------------------------------------------

    public static function SortRecordsByAmountDesc(array &$records): void
    {
        usort($records, function ($rec0, $rec1)
        {
            return (int)$rec1['amount'] <=> (int)$rec0['amount'];
        });
    }

    //------------------------------------------------------------------------------------------------------------------

    public static function ExtractInitRecord(array &$records): ?array
    {
        foreach ($records as $key => $record)
        {
            if ($record['initiator'] === null)
            {
                unset($records[$key]);
                return $record;
            }
        }

        return null;
    }

    public static function ExtractRecordByEntrypoint(array &$records, string $entrypoint): ?array
    {
        foreach ($records as $key => $record)
        {
            if ($record['entrypoint'] == $entrypoint)
            {
                unset($records[$key]);
                return $record;
            }
        }

        return null;
    }

    public static function ExtractRecordByTargetAddress(array &$records, string $address): ?array
    {
        foreach ($records as $key => $record)
        {
            if ($record['target'] == $address)
            {
                unset($records[$key]);
                return $record;
            }
        }

        return null;
    }

    public static function ExtractRecordWithHighestAmount(array &$records): ?array
    {
        $bestKey = null;

        foreach ($records as $key => $record)
        {
            if ($record['amount'] == 0)
                continue;

            if ($bestKey === null || $records[$bestKey]['amount'] < $record['amount'])
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

    //------------------------------------------------------------------------------------------------------------------

    /**
     * Sometimes money is transferred through special contracts (revenue redirect, store fee redirect),
     * a transfer is split between a few wallets (royalty split 1-to-N),
     * or one contract splits revenue and royalty in the same transaction.
     *
     * @param array $recInit
     * @param array $subRecords
     * @return array
     */
    protected static function RestructureRecordsByRemovingProxyContracts(array $recInit, array $subRecords): array
    {
        $result = [];

        $mainContract = $recInit['target'];

        $proxyContracts = [];
        $proxySubRecords = [];

        foreach ($subRecords as $record)
        {
            if ($record['sender'] == $mainContract)
            {
                if ($record['amount'] == 0)
                {   // This is not about money. Just save in result
                    $result[] = $record;
                    continue;
                }

                if (TezosNetwork::IsWalletAddressValid($record['target']))
                {   // This is direct transfer to wallet. Save in result
                    $result[] = $record;
                    continue;
                }

                if (TezosNetwork::IsContractAddressValid($record['target']))
                {
                    // This is potential "proxy-contract" with split/redirect money
                    // A proxy contract may appear several times, so collect all of its records
                    $proxyContracts[ $record['target'] ][] = $record;
                    continue;
                }
            }
            else
            {
                // Payout made by a proxy contract
                $proxySubRecords[] = $record;
                continue;
            }

            // Unknown state: return the records unchanged
            Log::Error('AbstractHunter', __FUNCTION__, 'Unknown state for #'.$record['id']);
            return $subRecords;
        }

        $proxyContractAddrs = array_keys($proxyContracts);
        $proxyContractUsed = [];

        foreach ($proxySubRecords as $record)
        {
            $address = $record['sender'];
            if (in_array($address, $proxyContractAddrs))
            {
                $record['sender'] = $mainContract;
                $proxyContractUsed[] = $address;        // mark as used
            }
            # else:
            #   keep as is

            $result[] = $record;
        }

        foreach ($proxyContracts as $address => $records)
        {
            // Ignore used proxy-contracts
            if (in_array($address, $proxyContractUsed))
                continue;

            // Others (unused): append to the result list
            foreach ($records as $record)
                $result[] = $record;
        }

        return $result;
    }

    //------------------------------------------------------------------------------------------------------------------

    protected function fixEventAsSkipped(array $recInit, array $subRecords): bool
    {
        $event = new EventFlow();
        $event->initInTezosNetworkByRecord($recInit);

        $event->event_type              = Enum\EventFlowType::Skipped;
        $event->sender_address          = $recInit['sender'];
        $event->target_address          = $recInit['target'];
        $event->quantity                = $recInit['amount'];

        $this->parser->pushEvent($event);
        return true; // Important to return TRUE
    }

    //------------------------------------------------------------------------------------------------------------------

    protected function analyzeStdTokenListEvent(array $recInit, array $subRecords, callable $funcGetListingDetails): bool
    {
        try
        {
            if (count($subRecords) != 1)
                return false;

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $recTransfer = self::ExtractRecordByEntrypoint($subRecords, 'transfer');

            if ($recTransfer == null)
                throw new Exception('Cannot find transfer-transaction');

            $transfers = $this->unpackTransfersEvents($recTransfer);

            if (empty($transfers))
                throw new Exception('Cannot detect transfer records');

            if (count($transfers) > 1)
                throw new Exception('Too many transfer records');

            /** @var TokenActions\Listing $listing */
            $listing = $funcGetListingDetails($recInit, $transfers[0]);

            if ($listing == null)
                throw new Exception('Cannot create token-list entity');

            //----------------------------------------------------------------------------------------------------------

            $event = new EventFlow();
            $event->initInTezosNetworkByRecord($recInit);

            $event->event_type              = Enum\EventFlowType::TokenList;
            $event->sender_address          = $recInit['sender'];
            $event->target_address          = $recInit['target'];
            $event->royalty_address         = $listing->creator;

            $event->price                   = $listing->price;
            $event->currency                = $listing->currency;
            $event->quantity                = $listing->quantity;

            $event->royalty_d4              = $listing->royalty_d4;

            $event->marketplace_address     = $recInit['target'];
            $event->token_address           = $listing->tokenAddress;
            $event->token_id                = $listing->tokenId;

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $customData = [];

            if ($listing->duration !== null)
                $customData[CustomData::kDURATION]      = $listing->duration;

            if ($listing->burnOnEnd !== null)
                $customData[CustomData::kBURN_ON_END]   = $listing->burnOnEnd;

            $event->custom_data = $customData ?: null;

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $this->parser->pushEvent($event);

            return true;
        }
        catch (Exception $e)
        {
            Log::Warning(get_class($this), __FUNCTION__, $e->getMessage()." #{$recInit['id']}");
            return false;
        }
    }

    //------------------------------------------------------------------------------------------------------------------

    protected function analyzeStdTokenPurchaseEvent(array $recInit, array $subRecords, ?string $storeRoyaltyWallet, ?string $eventDetails=null): bool
    {
        try
        {
            // At least 3 sub-records:
            //      1) transfer
            //     (2) store-fee    *may not exist      -> for Teia market
            //      3) revenue
            //     (4) royalty      *may not exist

            if (count($subRecords) < 3)
                return false;

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $subRecords = self::RestructureRecordsByRemovingProxyContracts($recInit, $subRecords);

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $recTransfer = self::ExtractRecordByEntrypoint($subRecords, 'transfer');
            if ($recTransfer == null)
                throw new Exception('Cannot find transfer-transaction');

            $recStore = null;

            if ($storeRoyaltyWallet !== null)
            {
                // for 'Dogami' project 'store-fee' + 'royalty' have the same address.
                // So, need "concat revenue" to the single record and then "royalty" will be empty!

                while ($rec = self::ExtractRecordByTargetAddress($subRecords, $storeRoyaltyWallet))
                {
                    if ($recStore === null)
                        $recStore = $rec;
                    else
                        $recStore['amount'] += $rec['amount'];
                }
                unset($rec);

                if ($recStore === null)
                    throw new Exception("Cannot find store-fee-transaction [$storeRoyaltyWallet]");
            }

            //----------------------------------------------------------------------------------------------------------

            $recRevenue = self::ExtractRecordWithHighestAmount($subRecords);

            if ($recRevenue == null)
            {
                if ($recStore)
                {
                    // This is means that "store fee" took all revenue transaction(s) to 'self'
                    // because 'seller' & 'store-fee' wallets has the same addresses!
                    // So, move 'store fee' to 'revenue'. And reset 'store-fee'

                    $recRevenue = $recStore;
                    $recStore   = null;
                }
                else
                {
                    // Unexpected: no revenue record
                    throw new Exception('Cannot find revenue-transaction');
                }
            }

            //----------------------------------------------------------------------------------------------------------
            // There may be more than one royalty record

            $royaltyAddressList = [];
            $royaltyTotalAmount = 0;

            foreach ($subRecords as $record)
            {
                $royaltyAddressList[$record['target']] = ($royaltyAddressList[$record['target']] ?? 0) + $record['amount'];
                $royaltyTotalAmount += $record['amount'];
            }

            //----------------------------------------------------------------------------------------------------------
            // 2: Transfer Data

            $transfers = $this->unpackTransfersEvents($recTransfer);

            if (empty($transfers))
                throw new Exception('Cannot detect transfers records');

            if (count($transfers) > 1)
                throw new Exception('More than one transfer item');

            foreach ($transfers as $transfer)
            {
                $event = new EventFlow();
                $event->initInTezosNetworkByRecord($recInit);

                $event->event_type              = Enum\EventFlowType::TokenPurchase;
                $event->event_details           = $eventDetails;

                $event->sender_address          = $recRevenue['target']; # Notice: it cannot be $transfer->sender, because that is the contract, not the seller
                $event->target_address          = $transfer->target;
                $event->royalty_address         = null;
                $event->royalty_address_list    = $royaltyAddressList;
                $event->fee_address             = $recStore ? $recStore['target'] : null;

                $event->revenue                 = $recRevenue['amount'];
                $event->royalty                 = $royaltyTotalAmount ?: null;
                $event->fee                     = $recStore ? $recStore['amount'] : null;
                $event->price                   = $event->revenue + $event->royalty + $event->fee;

                $event->currency                = TezosNetwork::kCURRENCY_ID;
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

    //------------------------------------------------------------------------------------------------------------------

    protected function analyzeStdTokenFreePurchaseBlock(array $recInit, array $subRecords, callable $funcGetSwapDetails): bool
    {
        if (count($subRecords) != 1)
            return false;

        try
        {
            $recTransfer = self::ExtractRecordByEntrypoint($subRecords, 'transfer');

            if ($recTransfer == null)
                throw new Exception('Cannot find record "transfer"');

            $transferEvents = $this->findTransferEvents($recTransfer);

            if (empty($transferEvents))
                throw new Exception('Should be transfer event(s) but cannot detect transfer params');

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $swapDetails = $funcGetSwapDetails($recInit['parameter']);

            if ($swapDetails == null)
                throw new Exception('Cannot detect swap details');

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            foreach ($transferEvents as $transferEvent)
            {
                $transferEvent->marketplace_address = $recInit['target'];

                $transferEventFrom = clone $transferEvent;
                $transferEventFrom->entity_subid   = 1;
                $transferEventFrom->sender_address = $swapDetails->seller;
                $transferEventFrom->target_address = null; // Allow group these events by sender and prevent send it to target

                $transferEventTo = clone $transferEvent;
                $transferEventTo->entity_subid   = 2;
                // sender_address and target_address stay as they are: the sender is the store contract
                // (the owner already gets the first notification)

                $this->parser->pushEvent($transferEventFrom);
                $this->parser->pushEvent($transferEventTo);
            }

            return true;
        }
        catch (Exception $e)
        {
            Log::Warning(get_class($this), __FUNCTION__, $e->getMessage()." #{$recInit['id']}");
            return false;
        }
    }

    //------------------------------------------------------------------------------------------------------------------

    /**
     * @param array $record
     * @return EventFlow[]
     */
    protected function findTransferEvents(array $record): array
    {
        $transfers = $this->unpackTransfersEvents($record);

        if (empty($transfers))
            return [];

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $events = [];

        foreach ($transfers as $index => $transfer)
        {
            $event = new EventFlow();
            $event->initInTezosNetworkByRecord($record, $index+1);

            $event->event_type              = Enum\EventFlowType::TokenTransfer;
            $event->sender_address          = $transfer->sender;
            $event->target_address          = $transfer->target;

            $event->quantity                = $transfer->quantity;

            $event->token_address           = $transfer->tokenAddress;
            $event->token_id                = $transfer->tokenId;

            $events[] = $event;
        }

        return $events;
    }

    //------------------------------------------------------------------------------------------------------------------

    /**
     * @param array|null $recTransfer
     * @return TokenActions\Transfer|null
     */
    protected function unpackSingleTransferEvent(?array $recTransfer): ?TokenActions\Transfer
    {
        if ($recTransfer === null)
            return null;

        $transfers = $this->unpackTransfersEvents($recTransfer);

        return count($transfers) == 1 ? $transfers[0] : null;
    }

    /**
     * @return TokenActions\Transfer[]
     */
    protected function unpackTransfersEvents(?array $recTransfer): array
    {
        if ($recTransfer === null)
            return [];

        // Four known transfer parameter layouts:
        //-------------------------------------------------
        // #1: {"entrypoint":"transfer","value":[{"txs":[{"to_":"%","amount":"%","token_id":"%"},...,...],"from_":"%"}]}
        // #2: {"entrypoint":"transfer","value":[{"list":[{"to":"%","amount":"%","token_id":"%"}],"address":"%"}]}
        // #3: {"entrypoint":"transfer","value":{"f":"%","t":"%","amount":"%"}}
        // #4: {"entrypoint":"transfer","value":{"to":"%","from":"%","value":"%"}}

        $paramValue = $recTransfer['parameter']['value'];

        if (!is_array($paramValue))
            return [];

        $tokenAddress = $recTransfer['target'];
        $transferEvents = [];

        if (count($paramValue) > 0 && isset($paramValue[0]) && isset($paramValue[0]['from_']) && isset($paramValue[0]['txs']))
        {   #1:
            //  "value":[
            //    {
            //      "txs":[
            //         {
            //            "to_":"%",
            //            "amount":"%",
            //            "token_id":"%"
            //         },
            //         ...
            //      ],
            //      "from_":"%"
            //    },
            //    {...}
            //  ]
            // There may be several transfers per transaction, even for HEN

            foreach ($paramValue as $paramBlock)
            {
                $sender = $paramBlock['from_'];

                if ($sender == null)
                {
                    Log::Warning(get_class($this), __FUNCTION__, 'Transfer layout #1: sender is null', $recTransfer);
                    continue;
                }

                foreach ($paramBlock['txs'] as $item)
                {
                    $target = $item['to_'];

                    if ($target == null)
                    {
                        Log::Warning(get_class($this), __FUNCTION__, 'Transfer layout #1: target is null', $recTransfer);
                        continue;
                    }

                    $transferData = new TokenActions\Transfer();
                    $transferData->sender        = $sender;
                    $transferData->target        = $target;

                    $transferData->quantity      = (float)(($item['amount'] ?? null) ?: 1);

                    $transferData->tokenAddress  = $tokenAddress;
                    $transferData->tokenId       = $item['token_id'] ?? 0;

                    $transferEvents[] = $transferData;
                }
            }
        }

        if(count($paramValue) > 0 && isset($paramValue[0]) && isset($paramValue[0]['address']) && isset($paramValue[0]['list']))
        {   #2:
            //  "value":[{
            //      "list":[
            //        {
            //           "to":"%",
            //           "amount":"%",
            //           "token_id":"%"
            //        }
            //     ],
            //     "address":"%"
            //  },
            //  {..},
            //  ..
            // ]
            // Never seen with more than one transfer per transaction

            foreach ($paramValue as $paramBlock)
            {
                $sender = $paramBlock['address'];

                if ($sender == null)
                {
                    Log::Warning(get_class($this), __FUNCTION__, 'Transfer layout #2: sender is null', $recTransfer);
                    continue;
                }

                foreach ($paramBlock['list'] as $item)
                {
                    if (!empty($item['to']))
                    {
                        $target = $item['to'];
                    }
                    else if (!empty($item['to_']))
                    {
                        // Some contracts use the key "to_" instead of "to"
                        // {"entrypoint":"transfer","value":[{"list":[{"to_":"<address>","amount":"1","token_id":"33"}],"address":"<address>"}]}
                        $target = $item['to_'];
                    }
                    else
                    {
                        Log::Warning(get_class($this), __FUNCTION__, 'Transfer layout #2: target is missing', $recTransfer);
                        continue;
                    }

                    $transferData = new TokenActions\Transfer();
                    $transferData->sender        = $sender;
                    $transferData->target        = $target;

                    $transferData->quantity      = (float)(($item['amount'] ?? null) ?: 1);

                    $transferData->tokenAddress  = $tokenAddress;
                    $transferData->tokenId       = $item['token_id'] ?? 0;

                    $transferEvents[] = $transferData;
                }
            }
        }

        if(isset($paramValue['f']) && isset($paramValue['t']))
        {   #3:
            //  "value":{
            //      "f":"%",
            //      "t":"%",
            //      "amount":"%"
            //  }

            $sender = $paramValue['f'];
            $target = $paramValue['t'];

            if ($sender == null)
                Log::Warning(get_class($this), __FUNCTION__, 'Transfer layout #3: sender is null', $recTransfer);

            if ($target == null)
                Log::Warning(get_class($this), __FUNCTION__, 'Transfer layout #3: target is null', $recTransfer);

            if ($sender && $target)
            {
                $transferData = new TokenActions\Transfer();
                $transferData->sender        = $sender;
                $transferData->target        = $target;

                $transferData->quantity      = (float)(($paramValue['amount'] ?? null) ?: 1);

                $transferData->tokenAddress  = $tokenAddress;
                $transferData->tokenId       = 0;

                $transferEvents[] = $transferData;
            }
        }

        if(isset($paramValue['from']) && isset($paramValue['to']))
        {   #4
            //  "value":{
            //      "to":"%",
            //      "from":"%",
            //      "value":"%"
            //  }

            $sender = $paramValue['from'];
            $target = $paramValue['to'];

            if ($sender == null)
                Log::Warning(get_class($this), __FUNCTION__, 'Transfer layout #4: sender is null', $recTransfer);

            if ($target == null)
                Log::Warning(get_class($this), __FUNCTION__, 'Transfer layout #4: target is null', $recTransfer);

            if ($sender && $target)
            {
                $transferData = new TokenActions\Transfer();
                $transferData->sender        = $sender;
                $transferData->target        = $target;

                $transferData->quantity      = (float)(($paramValue['value'] ?? null) ?: 1);

                $transferData->tokenAddress  = $tokenAddress;
                $transferData->tokenId       = 0;

                $transferEvents[] = $transferData;
            }
        }

        return $transferEvents;
    }

    //------------------------------------------------------------------------------------------------------------------

    public static function Convert_TokenToMarketplace(Token $token): ?string
    {
        $address = self::Convert_TokenAddressToMarketplace($token->tokenAddress);

        if ($address !== null)
            return $address;

        return $token->collectionGroup === \CryptoCore\Setapp\ObjktCom::COLLECTION_GROUP
            ? Setapp\ObjktCom_v1::MarketplaceKTID
            : null;
    }

    public static function Convert_TokenAddressToMarketplace(?string $address): ?string
    {
        foreach (TezosNetwork::$HunterClasses as $hunterClass)
        {
            $marketplaceContract = call_user_func("$hunterClass::GetMarketplaceContractByTokenContract", $address);

            if ($marketplaceContract !== null)
                return $marketplaceContract;
        }

        return null;
    }
}
