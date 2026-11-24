<?php

namespace CryptoNoises\Entities;

use CryptoCore\Setapp;
use CryptoNoises\Config;
use CryptoNoises\Core\CustomData;
use CryptoNoises\Core\Log;
use CryptoNoises\Enum;
use CryptoNoises\Enum\NotificationType;
use CryptoNoises\GatewayTezos;
use CryptoNoises\Models;
use CryptoNoises\Networks\Tezos\TezosNetwork;
use Exception;

class EventFlow
{
    const UNDEFINED_NETWORK_BLOCK               = 0;

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    // These fields cannot be null: together they form the unique key
    public string $network_id;
    public int $network_block;
    public string $event_type;
    public int $entity_uid;
    public int $entity_subid                    = 0;

    public ?string $event_details               = null;

    public /*?|string|array*/ $sender_address   = null;
    public /*?|string|array*/ $target_address   = null;
    public ?string $royalty_address             = null;
    public ?array  $royalty_address_list        = null; // key=address, value=royalty
    public ?string $fee_address                 = null;

    public ?array  $offer_owners_addresses      = null;

    public ?float $price                        = null;
    public ?float $revenue                      = null;
    public ?float $royalty                      = null;
    public ?float $fee                          = null;
    public ?string $currency                    = null;
    public ?string $currency_address            = null;
    public ?string $currency_address_2nd        = null;
    public float $quantity                      = 1;

    public ?int $royalty_d4                     = null; // used for Listing & Minting. 25% => decimal 4 => 2500

    public ?string $marketplace_address         = null;
    public ?string $token_address               = null;
    public ?string $token_id                    = null;

    public ?int $transaction_counter            = null;
    public /*?|string|array*/ $transaction_hash = null;
    public ?string $transaction_event           = null;
    public ?string $transaction_entrypoint      = null;

    public ?array $custom_data                  = null;

    public ?array $broadcastChannels_userIds         = null;
    public ?array $userIds                      = null;

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    private ?string $exec_at_file               = null;
    private ?string $exec_at_class              = null;
    private ?string $exec_at_method             = null;
    private ?int $exec_at_count                 = null;
    private $exec_at_data                       = null;

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public function __construct()
    {
        $this->exec_at_data = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);

        foreach ($this->exec_at_data as $record)
        {
            if (str_contains($record['class'], 'EventFlow') && $record['function'] == '__construct')
                continue;

            if (str_contains($record['class'], 'DefaultEvents') && $record['function'] == 'analyzeEvent')
                continue;

            $this->exec_at_file   = $record['file'];
            $this->exec_at_class  = $record['class'];
            $this->exec_at_method = $record['function'];
            $this->exec_at_count  = 1;

            $this->exec_at_data   = null; // reset!
            break;
        }
    }

    public function initInTezosNetwork($networkBlock, $uniqueId, $uniqueSubId)
    {
        $this->network_id               = Config::kNETWORK_Tezos;
        $this->network_block            = $networkBlock;
        $this->entity_uid               = $uniqueId;
        $this->entity_subid             = $uniqueSubId;
    }

    public function initInTezosNetworkByRecord($record, $uniqueSubId=0)
    {
        $this->network_id               = Config::kNETWORK_Tezos;
        $this->network_block            = (int) $record['level'];
        $this->entity_uid               = (int) $record['id'];
        $this->entity_subid             = $uniqueSubId;

        $this->transaction_counter      = $record['counter'];
        $this->transaction_hash         = $record['hash'];
        $this->transaction_event        = $record['timestamp'];
        $this->transaction_entrypoint   = $record['entrypoint'];
    }

    /**
     * Notice: if events allow grouping, then
     *      $this-event     -> will be kept
     *      $second-event   -> will be dropped
     */
    public function tryGroupEqualEvents(EventFlow $second): bool
    {
        // Not compared: entity ids, amounts, quantity, transaction counter / hash / event
        $keysToCompare = array(
            'network_id',
            'network_block',
            'event_type',

            'event_details',

            'sender_address',
            'target_address',
            'royalty_address',
            'fee_address',

            'currency',
            'currency_address',
            'currency_address_2nd',
            'royalty_d4',

            'marketplace_address',
            'token_address',
            'token_id',

            'transaction_entrypoint',

            'exec_at_file',
            'exec_at_class',
            'exec_at_method',
        );

        $keysAllowToGroup = [];

        switch ($this->event_type)
        {
            case Enum\EventFlowType::CoinTransfer:
            case Enum\EventFlowType::TokenTransfer:
            case Enum\EventFlowType::TokenPurchase:
            case Enum\EventFlowType::TokenMintIncome:
            case Enum\EventFlowType::TokenMintIncome_Ticket:
                // Allow stack -> Execute compare logic...
                break;

            case Enum\EventFlowType::TokenTransfer_Send:
                $keysAllowToGroup = [
                    'target_address',
                ];
                break;

            case Enum\EventFlowType::TokenTransfer_Receive:
                $keysAllowToGroup = [
                    'sender_address',
                ];
                break;

            case Enum\EventFlowType::TokenOffer:
            case Enum\EventFlowType::CollectionOffer:

            case Enum\EventFlowType::TokenMint:
            case Enum\EventFlowType::TokenMintExpense:              # no grouping: each fx(hash) mint shows its own iteration index
            case Enum\EventFlowType::TokenMintExpense_Ticket:       # no grouping: each fx(hash) mint shows its own iteration index
            case Enum\EventFlowType::TokenUpdateMetadata:

            case Enum\EventFlowType::TokenComment:
            case Enum\EventFlowType::TokenCommentReply:

            case Enum\EventFlowType::TokenList:
            case Enum\EventFlowType::AuctionStart:
            case Enum\EventFlowType::AuctionEndFailed:
            case Enum\EventFlowType::AuctionCancel:
            case Enum\EventFlowType::AuctionBid:
            case Enum\EventFlowType::AuctionOutBid:
            case Enum\EventFlowType::DomainExpires:
            case Enum\EventFlowType::DomainExpired:
            case Enum\EventFlowType::ExchangeCoinsToToken:
            case Enum\EventFlowType::ExchangeTokenToCoins:
            case Enum\EventFlowType::ExchangeTokenToToken:
            case Enum\EventFlowType::LiquidityInvest:
            case Enum\EventFlowType::Skipped:
            default:
                // Cannot be stacked!
                return false;
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
        // Compare

        // Don't stack items with custom data
        if ($this->custom_data || $second->custom_data)
            return false;

        foreach ($keysToCompare as $key)
        {
            if (in_array($key, $keysAllowToGroup))
                continue; // not compared here: grouped in the next step if needed

            if ($this->$key != $second->$key)
                return false;
        }

        // Royalty receivers must be the same; their amounts are summed below
        $royaltyReceivers1 = array_keys($this->royalty_address_list ?? []);
        $royaltyReceivers2 = array_keys($second->royalty_address_list ?? []);
        sort($royaltyReceivers1);
        sort($royaltyReceivers2);

        if ($royaltyReceivers1 != $royaltyReceivers2)
            return false;

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
        // Group

        $this->entity_uid          = min($this->entity_uid,                 $second->entity_uid);
        $this->entity_subid        = min($this->entity_subid,               $second->entity_subid);

        $this->price               = $this->sumTwoAmounts($this->price,     $second->price);
        $this->revenue             = $this->sumTwoAmounts($this->revenue,   $second->revenue);
        $this->royalty             = $this->sumTwoAmounts($this->royalty,   $second->royalty);
        $this->fee                 = $this->sumTwoAmounts($this->fee,       $second->fee);
        $this->quantity           += $second->quantity;

        foreach ($second->royalty_address_list ?? [] as $address => $amount)
            $this->royalty_address_list[$address] = $this->sumTwoAmounts($this->royalty_address_list[$address], $amount);

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $this->transaction_counter = null;

        if (!empty($second->transaction_hash))
        {
            if (is_array($this->transaction_hash))
            {
                if (!in_array($second->transaction_hash, $this->transaction_hash))
                    $this->transaction_hash[] = $second->transaction_hash;
            }
            else
            {
                if (empty($this->transaction_hash))
                {
                    $this->transaction_hash = $second->transaction_hash;
                }
                else if ($this->transaction_hash != $second->transaction_hash)
                {
                    $this->transaction_hash = [$this->transaction_hash, $second->transaction_hash];
                }
            }
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        foreach ($keysAllowToGroup as $key)
        {
            if ($second->$key === null)
                continue;

            if (!is_array($this->$key))
                $this->$key = $this->$key !== null ? [$this->$key] : [];

            $this->$key[] = $second->$key;
        }

        $this->exec_at_count += $second->exec_at_count;
        return true;
    }

    private function sumTwoAmounts($amount1, $amount2)
    {
        if ($amount1 === null && $amount2 === null)
            return null;

        return $amount1 + $amount2;
    }

    // TODO: this method is heavy: 500 transactions / saves take about 120-150 seconds.
    public function save(): bool
    {
        $senderAddress = $this->sender_address;
        $targetAddress = $this->target_address;
        $feeAddress    = $this->fee_address;

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $tokenEntity = GatewayTezos\Tokens::GetTokenDetails($this->token_address, $this->token_id, true);

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
        // Special fixes for Address(es) values

        if ($this->event_type == Enum\EventFlowType::TokenTransfer_Send)
        {
            // Ignore this value if it's an array!
            if (is_array($targetAddress))
                $targetAddress = null;
        }
        else if ($this->event_type == Enum\EventFlowType::TokenTransfer_Receive)
        {
            // Ignore this value if it's an array!
            if (is_array($senderAddress))
                $senderAddress = null;
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
        // Optimize royalty-address data

        if (!empty($this->royalty_address_list))
        {
            if ($this->royalty_address !== null)
            {
                Log::Error(get_class($this), __FUNCTION__, "Both royalty_address and royalty_address_list are not empty", $this);
                return false;
            }

            if (count($this->royalty_address_list) == 1)
            {
                $this->royalty_address      = array_keys($this->royalty_address_list)[0];
                $this->royalty              = $this->royalty_address_list[$this->royalty_address];

                $this->royalty_address_list = null;
            }
            else
            {
                $this->custom_data[CustomData::kROYALTIES] = $this->royalty_address_list;
            }
        }

        $royaltyAddress = $this->royalty_address;   // string|null

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $item = [
            'network_id'                => $this->network_id,
            'network_block'             => $this->network_block,
            'event_type'                => $this->event_type,
            'entity_uid'                => $this->entity_uid,
            'entity_subid'              => $this->entity_subid,

            'event_details'             => $this->event_details,

            'sender_address'            => $senderAddress,
            'target_address'            => $targetAddress,
            'royalty_address'           => $royaltyAddress,
            'fee_address'               => $feeAddress,

            'price'                     => $this->price,
            'revenue'                   => $this->revenue,
            'royalty'                   => $this->royalty,
            'fee'                       => $this->fee,
            'currency'                  => $this->currency,
            'currency_address'          => $this->currency_address,
            'currency_address_2nd'      => $this->currency_address_2nd,
            'quantity'                  => $this->quantity,
            'royalty_percent'           => $this->royalty_d4,

            'marketplace_address'       => $this->marketplace_address,
            'token_address'             => $this->token_address,
            'token_id'                  => $this->token_id,

            'transaction_counter'       => $this->transaction_counter,
            'transaction_hash'          => is_array($this->transaction_hash) ? json_encode($this->transaction_hash) : $this->transaction_hash,
            'transaction_event'         => $this->transaction_event,
            'transaction_entrypoint'    => $this->transaction_entrypoint,

            'custom_data'               => $this->custom_data !== null ? json_encode($this->custom_data) : null,
        ];

        $eventFlowId = Models\EventsFlow::model()->insert($item);

        if ($eventFlowId < 0)
        {
            return false;
        }

        if ($eventFlowId == 0)
        {
            Log::Warning(get_class($this), __FUNCTION__, "Event not inserted: returned event_flow_id is 0", $item);
            return false;
        }

        //--------------------------------------------------------------------------------------------------------------

        $senderAdid  = Address::GetOrCreateAdid($senderAddress);
        $targetAdid  = Address::GetOrCreateAdid($targetAddress);
        $royaltyAdid = Address::GetOrCreateAdid($royaltyAddress);
        $feeAdid     = Address::GetOrCreateAdid($feeAddress);

        //===[ COINS EVENTS ]===========================================================================================

        if ($this->event_type == Enum\EventFlowType::CoinTransfer)
        {
            $this->pushEventNotificationByOwnerAdid($senderAdid, $senderAddress, $eventFlowId, NotificationType::Expense);

            $this->pushEventNotificationByOwnerAdid($targetAdid, $targetAddress, $eventFlowId, NotificationType::Income);
        }

        //===[ TOKEN EVENTS ]===========================================================================================

        else if ($this->event_type == Enum\EventFlowType::TokenPurchase)
        {
            $this->pushEventNotificationByOwnerAdid($senderAdid, $senderAddress, $eventFlowId, NotificationType::TokenSell);

            if ($senderAdid != $royaltyAdid)
            {
                if ($royaltyAddress)
                {
                    $this->pushEventNotificationByOwnerAdid($royaltyAdid, $royaltyAddress, $eventFlowId, NotificationType::TokenRoyalty);
                }
                else if ($this->royalty_address_list)
                {
                    foreach ($this->royalty_address_list as $subRoyaltyAddress => $subRoyaltyAmount)
                    {
                        // If the user is in the royalty list but is also the seller, skip it.
                        // In this case revenue+royalty will be calculated on generate notification message data.
                        if ($subRoyaltyAddress == $senderAddress)
                            continue;

                        $subRoyaltyAdid = Address::GetOrCreateAdid($subRoyaltyAddress);
                        $this->pushEventNotificationByOwnerAdid($subRoyaltyAdid, $subRoyaltyAddress, $eventFlowId, NotificationType::TokenRoyalty);
                    }
                }
            }

            $this->pushEventNotificationByOwnerAdid($targetAdid, $targetAddress, $eventFlowId, NotificationType::TokenBuy);

            $this->pushEventNotificationByOwnerAdid($feeAdid, $feeAddress, $eventFlowId, NotificationType::TokenPurchaseStoreFee);
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        else if ($this->event_type == Enum\EventFlowType::TokenTransfer)
        {
            $this->pushEventNotificationByOwnerAdid($senderAdid, $senderAddress, $eventFlowId, NotificationType::TokenSend);

            $this->pushEventNotificationByOwnerAdid($targetAdid, $targetAddress, $eventFlowId, NotificationType::TokenReceive);
        }
        else if ($this->event_type == Enum\EventFlowType::TokenTransfer_Send)
        {
            $this->pushEventNotificationByOwnerAdid($senderAdid, $senderAddress, $eventFlowId, NotificationType::TokenSend);
        }
        else if ($this->event_type == Enum\EventFlowType::TokenTransfer_Receive)
        {
            $this->pushEventNotificationByOwnerAdid($targetAdid, $targetAddress, $eventFlowId, NotificationType::TokenReceive);
        }

        //===[ OFFER EVENTS ]===========================================================================================

        else if ($this->event_type == Enum\EventFlowType::TokenOffer)
        {
            $this->pushTokenOfferEventNotifications($eventFlowId);
        }

        else if ($this->event_type == Enum\EventFlowType::CollectionOffer)
        {
            $this->pushCollectionOfferEventNotifications($eventFlowId);
        }

        //===[ MINT EVENTS ]============================================================================================

        else if ($this->event_type == Enum\EventFlowType::TokenMint)
        {
            // Followers are not notified about fx(hash) generative mints (only the broadcast channels are)
            $isFxHashGenerative = $tokenEntity && in_array($tokenEntity->tokenAddress, [
                Setapp\FxHash_v2::TokensKTID, Setapp\FxHash_v1::TokensKTID, Setapp\FxHash_v0::TokensKTID]);

            if ($senderAdid && $tokenEntity && !$isFxHashGenerative)
            {
                try
                {
                    $userIds = Models\UsersFollow::model()->selectUserIdsFollowersToAdid($senderAdid);

                    $this->pushEventNotificationForUserIds($userIds, $eventFlowId, NotificationType::TokenMint, Models\EventsNotifications::kPRIORITY_INDEX_Low);
                }
                catch (Exception $e)
                {
                    Log::Warning("EventFlow", "event.token-mint.followers", $e->getMessage());
                }
            }

            $this->pushEventNotificationForUserIds($this->broadcastChannels_userIds, $eventFlowId, NotificationType::TokenMint, Models\EventsNotifications::kPRIORITY_INDEX_Background);
        }

        else if ($this->event_type == Enum\EventFlowType::TokenMintExpense)
        {
            $this->pushEventNotificationByOwnerAdid($senderAdid, $senderAddress, $eventFlowId, NotificationType::TokenMint_Expense);
        }
        else if ($this->event_type == Enum\EventFlowType::TokenMintIncome)
        {
            $this->pushEventNotificationByOwnerAdid($targetAdid, $targetAddress, $eventFlowId, NotificationType::TokenMint_Income);
        }

        else if ($this->event_type == Enum\EventFlowType::TokenMintExpense_Ticket)
        {
            $this->pushEventNotificationByOwnerAdid($senderAdid, $senderAddress, $eventFlowId, NotificationType::TokenMint_Expense_Ticket);
        }
        else if ($this->event_type == Enum\EventFlowType::TokenMintIncome_Ticket)
        {
            $this->pushEventNotificationByOwnerAdid($targetAdid, $targetAddress, $eventFlowId, NotificationType::TokenMint_Income_Ticket);
        }

        else if ($this->event_type == Enum\EventFlowType::TokenUpdateMetadata)
        {
            $this->pushEventNotificationByOwnerAdid($targetAdid, $targetAddress, $eventFlowId, NotificationType::TokenUpdateMetadata);

            $this->pushEventNotificationForUserIds($this->broadcastChannels_userIds, $eventFlowId, NotificationType::TokenUpdateMetadata, Models\EventsNotifications::kPRIORITY_INDEX_Background);
        }

        //===[ COMMENT EVENTS ]=========================================================================================

        // The hunter already worked out who the comment is addressed to - the artist, or the author of the
        // comment being answered - and put them in target_address, so there is one recipient either way.
        else if ($this->event_type == Enum\EventFlowType::TokenComment)
        {
            $this->pushEventNotificationByOwnerAdid($targetAdid, $targetAddress, $eventFlowId, NotificationType::TokenComment);
        }

        else if ($this->event_type == Enum\EventFlowType::TokenCommentReply)
        {
            $this->pushEventNotificationByOwnerAdid($targetAdid, $targetAddress, $eventFlowId, NotificationType::TokenCommentReply);
        }

        //===[ LIST/UNLIST EVENTS ]=====================================================================================

        else if ($this->event_type == Enum\EventFlowType::TokenList)
        {
            // Followers are notified only when the creator lists their own token
            if ($tokenEntity === null)
                Log::Notice("EventFlow", "event.token-list.ignore", 'Token not found');
            else if ($tokenEntity->creatorAddress === null)
                Log::Notice("EventFlow", "event.token-list.ignore", "Creator not defined $tokenEntity->tokenAddress $tokenEntity->tokenId", $tokenEntity);
            else if ($tokenEntity->creatorAddress === $senderAddress)
            {
                try
                {
                    $userIds = Models\UsersFollow::model()->selectUserIdsFollowersToAdid($senderAdid);

                    $this->pushEventNotificationForUserIds($userIds, $eventFlowId, NotificationType::TokenList, Models\EventsNotifications::kPRIORITY_INDEX_Low);
                }
                catch (Exception $e)
                {
                    Log::Warning("EventFlow", "event.token-list.followers", $e->getMessage());
                }
            }
        }

        //===[ AUCTION EVENTS ]=========================================================================================

        else if ($this->event_type == Enum\EventFlowType::AuctionStart)
        {
            $this->pushEventNotificationByOwnerAdid($senderAdid, $senderAddress, $eventFlowId, NotificationType::AuctionStart);

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
            // Notify as "list event"

            // Followers are notified only when the creator lists their own token
            if ($tokenEntity === null)
                Log::Notice("EventFlow", "event.auction-start.ignore", 'Token not found');
            else if ($tokenEntity->creatorAddress === null)
                Log::Notice("EventFlow", "event.auction-start.ignore", "Creator not defined $tokenEntity->tokenAddress $tokenEntity->tokenId", $tokenEntity);
            else if ($tokenEntity->creatorAddress === $senderAddress)
            {
                try
                {
                    $userIds = Models\UsersFollow::model()->selectUserIdsFollowersToAdid($senderAdid);

                    $this->pushEventNotificationForUserIds($userIds, $eventFlowId, NotificationType::AuctionStart);
                }
                catch (Exception $e)
                {
                    Log::Warning("EventFlow", "event.auction-start.followers", $e->getMessage());
                }
            }
        }
        else if ($this->event_type == Enum\EventFlowType::AuctionEndFailed)
        {
            $this->pushEventNotificationByOwnerAdid($targetAdid, $targetAddress, $eventFlowId, NotificationType::AuctionEnd_NoWinner);
        }
        else if ($this->event_type == Enum\EventFlowType::AuctionCancel)
        {
            $this->pushEventNotificationByOwnerAdid($targetAdid, $targetAddress, $eventFlowId, NotificationType::AuctionCancel);
        }
        else if ($this->event_type == Enum\EventFlowType::AuctionBid)
        {
            $this->pushEventNotificationByOwnerAdid($senderAdid, $senderAddress, $eventFlowId, NotificationType::AuctionBid);

            // Here 'royalty' is auction-creator
            $this->pushEventNotificationByOwnerAdid($royaltyAdid, $royaltyAddress, $eventFlowId, NotificationType::AuctionOwnerInfo);
        }
        else if ($this->event_type == Enum\EventFlowType::AuctionOutBid)
        {
            $this->pushEventNotificationByOwnerAdid($targetAdid, $targetAddress, $eventFlowId, NotificationType::AuctionOutBid);
        }

        //===[ DOMAIN EVENTS ]==========================================================================================

        else if ($this->event_type == Enum\EventFlowType::DomainExpires)
        {
            if (!empty($this->userIds))
            {
                $this->pushEventNotificationForUserIds($this->userIds, $eventFlowId, NotificationType::DomainExpires);
            }
        }
        else if ($this->event_type == Enum\EventFlowType::DomainExpired)
        {
            if (!empty($this->userIds))
            {
                $this->pushEventNotificationForUserIds($this->userIds, $eventFlowId, NotificationType::DomainExpired);
            }
        }

        //===[ EXCHANGE EVENTS ]========================================================================================

        else if ($this->event_type == Enum\EventFlowType::ExchangeCoinsToToken)
        {
            $this->pushEventNotificationByOwnerAdid($senderAdid, $senderAddress, $eventFlowId, NotificationType::Exchange_CoinsToToken);
            $this->pushEventNotificationByOwnerAdid($targetAdid, $targetAddress, $eventFlowId, NotificationType::Exchange_CoinsToToken);
        }
        else if ($this->event_type == Enum\EventFlowType::ExchangeTokenToCoins)
        {
            $this->pushEventNotificationByOwnerAdid($senderAdid, $senderAddress, $eventFlowId, NotificationType::Exchange_TokenToCoins);
            $this->pushEventNotificationByOwnerAdid($targetAdid, $targetAddress, $eventFlowId, NotificationType::Exchange_TokenToCoins);
        }
        else if ($this->event_type == Enum\EventFlowType::ExchangeTokenToToken)
        {
            $this->pushEventNotificationByOwnerAdid($senderAdid, $senderAddress, $eventFlowId, NotificationType::Exchange_TokenToToken);
            $this->pushEventNotificationByOwnerAdid($targetAdid, $targetAddress, $eventFlowId, NotificationType::Exchange_TokenToToken);
        }

        //===[ LIQUIDITY EVENTS ]========================================================================================

        else if ($this->event_type == Enum\EventFlowType::LiquidityInvest)
        {
            $this->pushEventNotificationByOwnerAdid($senderAdid, $senderAddress, $eventFlowId, NotificationType::Liquidity_Invest);
        }

        //===[ OTHERS ]=================================================================================================

        else if ($this->event_type == Enum\EventFlowType::Skipped)
        {
            // Do nothing!
        }
        else
        {
            Log::Error(get_class($this), __FUNCTION__, "Unknown event_type [$this->event_type]. Cannot detect notify logic");
        }

        //--------------------------------------------------------------------------------------------------------------
        // Log exec_at

        self::LogExecAt_FixData($this);

        return true;
    }

    //==================================================================================================================

    /**
     * @param int|array|null $userIds
     */
    private function pushEventNotificationForUserIds($userIds, int $eventFlowId, string $notificationType, int $priorityIndex=Models\EventsNotifications::kPRIORITY_INDEX_Normal): bool
    {
        if (empty($userIds))
            return false;

        if (!is_array($userIds))
            $userIds = [$userIds];

        foreach ($userIds as $userId)
        {
            $userItem = Models\Users::model()->getUserItemById($userId);

            if ($userItem === null)
            {
                Log::Error(get_class($this), __FUNCTION__, "Cannot detect userItem #$userId", $this);
                continue;
            }

            if ($userItem['status'] == Models\Users::kSTATUS_Frozen)
                continue;

            Models\EventsNotifications::model()->insertNotifyTask($userItem['platform'], $userId,
                null, null,
                $eventFlowId,
                $priorityIndex,
                $notificationType);
        }
        return true;
    }

    private function pushEventNotificationByOwnerAdid(?int $adid, ?string $address, int $eventFlowId, string $notificationType, int $priorityIndex=Models\EventsNotifications::kPRIORITY_INDEX_Normal): bool
    {
        if (!TezosNetwork::IsWalletAddressValid($address))
            return false;

        if (!UserWallet::IsActiveWalletByAdid($adid))
            return false;

        $userItems = Models\Users::model()->getActiveUsersByWalletAdid($adid);

        if (empty($userItems))
        {
            // Happens when the wallet is active but the user is frozen (stopped the bot): nobody to notify
            return false;
        }

        foreach ($userItems as $userItem)
        {
            Models\EventsNotifications::model()->insertNotifyTask($userItem['platform'], $userItem['user_id'],
                $adid, $address,
                $eventFlowId,
                $priorityIndex,
                $notificationType);
        }
        return true;
    }

    //==================================================================================================================

    private function pushTokenOfferEventNotifications(int $eventFlowId): void
    {
        $this->pushOfferEventNotifications($eventFlowId, NotificationType::TokenOffer);
    }

    private function pushCollectionOfferEventNotifications(int $eventFlowId): void
    {
        $this->pushOfferEventNotifications($eventFlowId, NotificationType::CollectionOffer);
    }

    private function pushOfferEventNotifications(int $eventFlowId, string $notificationType): void
    {
        if (empty($this->offer_owners_addresses))
            return;

        // An offer on a token / collection with many holders would notify all of them: such offers are not sent
        if (count($this->offer_owners_addresses) > Config::cfg()->limitOffersMaxHolders)
            return;

        // If ONE user has TWO wallets with the same token on each wallet than it'll receive 2 notifications!
        // So, need apply only 1 notification for 1 user!
        $userIdsAlreadyUsed = [];

        foreach ($this->offer_owners_addresses as $ownerAddress)
        {
            if (!TezosNetwork::IsWalletAddressValid($ownerAddress))
                continue;

            $ownerAdid = Address::GetAdid($ownerAddress);

            if (!UserWallet::IsActiveWalletByAdid($ownerAdid))
                continue;

            $userItems = Models\Users::model()->getActiveUsersByWalletAdid($ownerAdid);

            foreach ($userItems as $userItem)
            {
                $userId = $userItem['user_id'];

                if (in_array($userId, $userIdsAlreadyUsed))
                    continue;

                Models\EventsNotifications::model()->insertNotifyTask($userItem['platform'], $userId,
                    $ownerAdid, $ownerAddress,
                    $eventFlowId,
                    Models\EventsNotifications::kPRIORITY_INDEX_Normal,
                    $notificationType);

                $userIdsAlreadyUsed[] = $userId;
            }
        }
    }

    //==================================================================================================================

    private static array $exec_at_write_events = [];

    public static function LogExecAt_FixData(self $eventFlow): void
    {
        $key = $eventFlow->exec_at_file.'::'.$eventFlow->exec_at_class.'::'.$eventFlow->exec_at_method;

        if (isset(self::$exec_at_write_events[$key]))
        {
            self::$exec_at_write_events[$key]['count'] += $eventFlow->exec_at_count;
        }
        else
        {
            self::$exec_at_write_events[$key] = [
                'file'   => $eventFlow->exec_at_file,
                'class'  => $eventFlow->exec_at_class,
                'method' => $eventFlow->exec_at_method,
                'count'  => $eventFlow->exec_at_count,
                'data'   => $eventFlow->exec_at_data ? var_export($eventFlow->exec_at_data, 1) : null,
            ];
        }
    }

    public static function LogExecAt_SaveData(): void
    {
        foreach (self::$exec_at_write_events as $item)
            Models\Log\EventsFlowExecAt::model()->save($item);

        self::$exec_at_write_events = [];
    }
}
