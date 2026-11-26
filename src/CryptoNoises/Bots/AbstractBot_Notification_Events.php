<?php

namespace CryptoNoises\Bots;

use CryptoCore\Time;
use CryptoNoises\Bots\Core\NotificationSendFailedException;
use CryptoNoises\Bots\Core\NotificationSendSkippedException;
use CryptoNoises\Config;
use CryptoNoises\Core\CustomData;
use CryptoNoises\Core\URL;
use CryptoNoises\Entities\CoinExchange;
use CryptoNoises\Entities\Marketplace;
use CryptoNoises\Entities\OptionsNotifier;
use CryptoNoises\Entities\Token;
use CryptoNoises\Entities\User;
use CryptoNoises\Enum;
use CryptoNoises\GatewayTezos;
use CryptoNoises\Helpers\Helper;
use CryptoNoises\Localize\Notification;
use CryptoNoises\Localize\NotificationButton;
use CryptoNoises\Localize\Phrase;
use CryptoNoises\Models;
use CryptoNoises\Networks\AbstractNetwork;
use CryptoNoises\Networks\Tezos\TezosHelper;

trait AbstractBot_Notification_Events
{
    /**
     * @param User $user
     * @param array $notificationItem
     * @param array $eventItem
     * @return Notification
     * @throws NotificationSendFailedException
     * @throws NotificationSendSkippedException
     */
    /**
     * One message for a digest user's due rows: the first line of each notification, with the wallet when the user
     * watches more than one. A single row is sent as its full notification.
     *
     * @return array{?Notification, array<int, array{string, string}>} the message (null when nothing is left to
     *         send), and per row id its status and note
     */
    public function doNotifyEventDigest(User $user, array $rows): array
    {
        $lines   = [];
        $results = [];
        $single  = null;
        $several = $user->getWalletsCount() > 1;

        foreach ($rows as $row)
        {
            $id = (int) $row['event_notification_id'];

            try
            {
                $event = Models\EventsFlow::model()->getEventById($row['event_flow_id']);

                if (empty($event))
                    throw new \Exception("Cannot find event-flow #{$row['event_flow_id']}");

                $notification = $this->doNotifyEventFlow($user, $row, $event);
                $line = self::FirstLine($notification);

                if ($line === null)
                    throw new NotificationSendSkippedException('Nothing to say');

                if ($several && $row['owner_adid'] && ($wallet = $user->getWalletByAdid((int) $row['owner_adid'])))
                    $line .= ' · '.Notification::SafeText($wallet->getWalletName());

                $lines[]      = $line;
                $single       = $notification;
                $results[$id] = [Models\EventsNotifications::kNotifyStatus_Done, ''];
            }
            catch (NotificationSendSkippedException $e)
            {
                $results[$id] = [Models\EventsNotifications::kNotifyStatus_Skipped, $e->getMessage()];
            }
            catch (\Throwable $e)
            {
                $results[$id] = [Models\EventsNotifications::kNotifyStatus_Error, $e->getMessage()];
            }
        }

        if (!$lines)
            return [null, $results];

        return [count($lines) == 1 ? $single : Notification\EventsDigest::I($user, $lines), $results];
    }

    private static function FirstLine(Notification $notification): ?string
    {
        foreach ([$notification->getTitle(), $notification->getDescription()] as $text)
            foreach (explode("\n", (string) $text) as $line)
                if (trim(strip_tags($line)) !== '')
                    return trim($line);

        return null;
    }

    public function doNotifyEventFlow(User $user, array $notificationItem, array $eventItem): Notification
    {
        /** @var Token $tokenEntity */
        $tokenEntity = null;

        if ($eventItem['token_address'] && $eventItem['token_id'] !== null)
        {
            $tokenEntity = GatewayTezos\Tokens::GetTokenDetails($eventItem['token_address'], $eventItem['token_id'], true);

            if ($tokenEntity === null)
                throw new NotificationSendFailedException("Cannot find token {$eventItem['token_address']}:{$eventItem['token_id']}");

            $tokenEntity->amount = $eventItem['quantity'];
        }

        //--------------------------------------------------------------------------------------------------------------

        $notificationType = $notificationItem['notification_type'];

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
        // PreFixes

        if ($notificationType == Enum\NotificationType::Liquidity_Invest)
        {   // Liquidity deposits are reported as a plain expense
            $notificationType = Enum\NotificationType::Expense;

            // Reset fields!
            $eventItem['revenue']           = null;
            $eventItem['currency_address']  = null;
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
        /** @var Notification $notification */

        if ($notificationType == Enum\NotificationType::Income)
        {
            $notifyFilter   = OptionsNotifier::kNotify_Income;
            $notification   = Notification\Transaction\CoinsIncome::ByQuantity($user, $eventItem['quantity']);
            $thresholdScope = OptionsNotifier::kThresholdTransaction;
        }
        else if ($notificationType == Enum\NotificationType::Expense)
        {
            $notifyFilter   = OptionsNotifier::kNotify_Expense;
            $notification   = Notification\Transaction\CoinsExpense::ByQuantity($user, $eventItem['quantity']);
            $thresholdScope = OptionsNotifier::kThresholdTransaction;
        }
        #~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
        else if ($notificationType == Enum\NotificationType::TokenBuy)
        {
            $notifyFilter   = OptionsNotifier::kNotify_TokenPurchased;
            switch ($eventItem['event_details'])
            {
                case Enum\EventFlowDetails::AuctionConclude:    $notification = Notification\Transaction\AuctionWin::I($user); break;
                case Enum\EventFlowDetails::OfferAccepted:      $notification = Notification\Transaction\NftOffer_Accepted_Purchased::I($user); break;
                default:                                        $notification = Notification\Transaction\NftPurchased::I($user); break;
            }
            $thresholdScope = OptionsNotifier::kThresholdTransaction;
        }
        else if ($notificationType == Enum\NotificationType::TokenSell)
        {
            $notifyFilter   = OptionsNotifier::kNotify_TokenSold;
            switch ($eventItem['event_details'])
            {
                case Enum\EventFlowDetails::AuctionConclude:    $notification = Notification\Transaction\AuctionSold::I($user); break;
                case Enum\EventFlowDetails::OfferAccepted:      $notification = Notification\Transaction\NftOffer_Accepted_Sold::I($user); break;
                default:                                        $notification = Notification\Transaction\NftSold::I($user); break;
            }
            $thresholdScope = OptionsNotifier::kThresholdTransaction;
        }
        else if ($notificationType == Enum\NotificationType::TokenRoyalty)
        {
            $notifyFilter   = OptionsNotifier::kNotify_TokenRoyalty;
            $notification   = Notification\Transaction\NftRoyalty::I($user);
            $thresholdScope = OptionsNotifier::kThresholdTransaction;
        }
        else if ($notificationType == Enum\NotificationType::TokenPurchaseStoreFee)
        {
            $notifyFilter   = OptionsNotifier::kNotify_TokenSold;
            $notification   = Notification\Transaction\NftPurchaseStoreFee::I($user);
            $thresholdScope = OptionsNotifier::kThresholdTransaction;
        }
        #~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
        else if ($notificationType == Enum\NotificationType::TokenOffer)
        {
            $notifyFilter   = OptionsNotifier::kNotify_TokenOffer;
            $notification   = $eventItem['currency_address'] !== null
                ? Notification\Transaction\NftOffer_InTokens::I($user)
                : Notification\Transaction\NftOffer_InCoins::I($user);
            $thresholdScope = OptionsNotifier::kThresholdOffer;
        }
        else if ($notificationType == Enum\NotificationType::CollectionOffer)
        {
            $notifyFilter   = OptionsNotifier::kNotify_TokenOffer;
            $notification   = $eventItem['currency_address'] !== null
                ? Notification\Transaction\CollectionOffer_InTokens::I($user)
                : Notification\Transaction\CollectionOffer_InCoins::I($user);
            $thresholdScope = OptionsNotifier::kThresholdOffer;
        }
        #~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
        else if ($notificationType == Enum\NotificationType::TokenList)
        {
            $notifyFilter   = OptionsNotifier::kNotify_TokenList;
            $notification   = Notification\Transaction\NftList::I($user);
            $thresholdScope = null;
        }
        #~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
        else if ($notificationType == Enum\NotificationType::TokenMint)
        {
            $notifyFilter   = OptionsNotifier::kNotify_TokenMint;
            $notification   = Notification\Transaction\NftMint::I($user);
            $thresholdScope = null;
        }
        else if ($notificationType == Enum\NotificationType::TokenMint_Income)
        {
            $notifyFilter   = OptionsNotifier::kNotify_TokenSold;
            $notification   = Notification\Transaction\NftMint_Income::I($user);
            $thresholdScope = OptionsNotifier::kThresholdTransaction;
        }
        else if ($notificationType == Enum\NotificationType::TokenMint_Expense)
        {
            $notifyFilter   = OptionsNotifier::kNotify_TokenPurchased;
            $notification   = Notification\Transaction\NftMint_Expense::I($user);
            $thresholdScope = OptionsNotifier::kThresholdTransaction;
        }
        else if ($notificationType == Enum\NotificationType::TokenMint_Income_Ticket)
        {
            $notifyFilter   = OptionsNotifier::kNotify_TokenSold;
            $notification   = Notification\Transaction\FxHashTicketMint_Income::I($user);
            $thresholdScope = OptionsNotifier::kThresholdTransaction;
        }
        else if ($notificationType == Enum\NotificationType::TokenMint_Expense_Ticket)
        {
            $notifyFilter   = OptionsNotifier::kNotify_TokenPurchased;
            $notification   = Notification\Transaction\FxHashTicketMint_Expense::I($user);
            $thresholdScope = OptionsNotifier::kThresholdTransaction;
        }
        #~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
        else if ($notificationType == Enum\NotificationType::TokenUpdateMetadata)
        {
            $notifyFilter   = OptionsNotifier::kNotify_TokenUpdateMetadata;
            $notification   = Notification\Transaction\NftUpdateMetadata::I($user);
            $thresholdScope = null;
        }
        #~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
        # A drop purchase is a purchase, so it goes under the switches a user already has for buying and
        # selling rather than asking them about a new kind of notification.
        else if ($notificationType == Enum\NotificationType::DropBuy)
        {
            $notifyFilter   = OptionsNotifier::kNotify_TokenPurchased;
            $notification   = Notification\Transaction\DropBuy::I($user);
            $thresholdScope = OptionsNotifier::kThresholdTransaction;
        }
        else if ($notificationType == Enum\NotificationType::DropSold)
        {
            $notifyFilter   = OptionsNotifier::kNotify_TokenSold;
            $notification   = Notification\Transaction\DropSold::I($user);
            $thresholdScope = OptionsNotifier::kThresholdTransaction;
        }
        #~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
        # Comments carry no amount, so no threshold can apply to them
        else if ($notificationType == Enum\NotificationType::TokenComment)
        {
            $notifyFilter   = OptionsNotifier::kNotify_TokenComment;
            $notification   = Notification\Transaction\NftComment::I($user);
            $thresholdScope = null;
        }
        else if ($notificationType == Enum\NotificationType::TokenCommentReply)
        {
            $notifyFilter   = OptionsNotifier::kNotify_TokenComment;
            $notification   = Notification\Transaction\NftCommentReply::I($user);
            $thresholdScope = null;
        }
        #~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
        # Delegation and staking: one switch for all of it. The amounts are not payments - a delegated balance, tez
        # moved in or out of a stake - so the transaction threshold does not apply to them.
        else if ($notificationType == Enum\NotificationType::DelegationSet)
        {
            $notifyFilter   = OptionsNotifier::kNotify_Staking;
            $notification   = Notification\Transaction\DelegationSet::I($user);
            $thresholdScope = null;
        }
        else if ($notificationType == Enum\NotificationType::DelegationChange)
        {
            $notifyFilter   = OptionsNotifier::kNotify_Staking;
            $notification   = Notification\Transaction\DelegationChange::I($user);
            $thresholdScope = null;
        }
        else if ($notificationType == Enum\NotificationType::DelegationRemove)
        {
            $notifyFilter   = OptionsNotifier::kNotify_Staking;
            $notification   = Notification\Transaction\DelegationRemove::I($user);
            $thresholdScope = null;
        }
        else if ($notificationType == Enum\NotificationType::Stake)
        {
            $notifyFilter   = OptionsNotifier::kNotify_Staking;
            $notification   = Notification\Transaction\Stake::I($user);
            $thresholdScope = null;
        }
        else if ($notificationType == Enum\NotificationType::Unstake)
        {
            $notifyFilter   = OptionsNotifier::kNotify_Staking;
            $notification   = Notification\Transaction\Unstake::I($user);
            $thresholdScope = null;
        }
        else if ($notificationType == Enum\NotificationType::UnstakeFinalized)
        {
            $notifyFilter   = OptionsNotifier::kNotify_Staking;
            $notification   = Notification\Transaction\UnstakeFinalized::I($user);
            $thresholdScope = null;
        }
        else if ($notificationType == Enum\NotificationType::UnstakeFinalizable)
        {
            $notifyFilter   = OptionsNotifier::kNotify_Staking;
            $notification   = Notification\Transaction\UnstakeFinalizable::I($user);
            $thresholdScope = null;
        }
        else if ($notificationType == Enum\NotificationType::BakerParameters)
        {
            $notifyFilter   = OptionsNotifier::kNotify_Staking;
            $notification   = Notification\Transaction\BakerParameters::I($user);
            $thresholdScope = null;
        }
        #~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
        # For bakers. Delegators and stakers carry an amount, so a busy baker can use the transaction threshold
        # to leave out dust delegations; the alerts have no amount.
        else if ($notificationType == Enum\NotificationType::BakerDelegatorJoined)
        {
            $notifyFilter   = OptionsNotifier::kNotify_BakerDelegators;
            $notification   = Notification\Transaction\BakerDelegatorJoined::I($user);
            $thresholdScope = OptionsNotifier::kThresholdTransaction;
        }
        else if ($notificationType == Enum\NotificationType::BakerDelegatorLeft)
        {
            $notifyFilter   = OptionsNotifier::kNotify_BakerDelegators;
            $notification   = Notification\Transaction\BakerDelegatorLeft::I($user);
            $thresholdScope = OptionsNotifier::kThresholdTransaction;
        }
        else if ($notificationType == Enum\NotificationType::BakerStakerStake)
        {
            $notifyFilter   = OptionsNotifier::kNotify_BakerDelegators;
            $notification   = Notification\Transaction\BakerStakerStake::I($user);
            $thresholdScope = OptionsNotifier::kThresholdTransaction;
        }
        else if ($notificationType == Enum\NotificationType::BakerStakerUnstake)
        {
            $notifyFilter   = OptionsNotifier::kNotify_BakerDelegators;
            $notification   = Notification\Transaction\BakerStakerUnstake::I($user);
            $thresholdScope = OptionsNotifier::kThresholdTransaction;
        }
        else if ($notificationType == Enum\NotificationType::TeiaMultisigProposal)
        {
            $notifyFilter   = OptionsNotifier::kNotify_TeiaGovernance;
            $notification   = Notification\TeiaMultisigProposal::I($user);
            $thresholdScope = null;
        }
        else if ($notificationType == Enum\NotificationType::TeiaMultisigReminder)
        {
            $notifyFilter   = OptionsNotifier::kNotify_TeiaGovernance;
            $notification   = Notification\TeiaMultisigReminder::I($user);
            $thresholdScope = null;
        }
        else if ($notificationType == Enum\NotificationType::TeiaPoll)
        {
            $notifyFilter   = OptionsNotifier::kNotify_TeiaGovernance;
            $notification   = Notification\TeiaPoll::I($user);
            $thresholdScope = null;
        }
        else if ($notificationType == Enum\NotificationType::TeiaDaoProposal)
        {
            $notifyFilter   = OptionsNotifier::kNotify_TeiaGovernance;
            $notification   = Notification\TeiaDaoProposal::I($user);
            $thresholdScope = null;
        }
        else if ($notificationType == Enum\NotificationType::TeiaEvent)
        {
            $notifyFilter   = OptionsNotifier::kNotify_TeiaEvents;
            $notification   = Notification\TeiaEvent::I($user);
            $thresholdScope = null;
        }
        else if ($notificationType == Enum\NotificationType::BakerMissedRights)
        {
            $notifyFilter   = OptionsNotifier::kNotify_BakerAlerts;
            $notification   = Notification\Transaction\BakerMissedRights::I($user);
            $thresholdScope = null;
        }
        else if ($notificationType == Enum\NotificationType::BakerDalInsufficient)
        {
            $notifyFilter   = OptionsNotifier::kNotify_BakerAlerts;
            $notification   = Notification\Transaction\BakerDalInsufficient::I($user);
            $thresholdScope = null;
        }
        else if ($notificationType == Enum\NotificationType::BakerDalRecovered)
        {
            $notifyFilter   = OptionsNotifier::kNotify_BakerAlerts;
            $notification   = Notification\Transaction\BakerDalRecovered::I($user);
            $thresholdScope = null;
        }
        else if ($notificationType == Enum\NotificationType::BakerDalDenounced)
        {
            $notifyFilter   = OptionsNotifier::kNotify_BakerAlerts;
            $notification   = Notification\Transaction\BakerDalDenounced::I($user);
            $thresholdScope = null;
        }
        else if ($notificationType == Enum\NotificationType::GovernancePeriod)
        {
            $notifyFilter   = OptionsNotifier::kNotify_BakerAlerts;
            $notification   = Notification\Transaction\GovernancePeriod::I($user);
            $thresholdScope = null;
        }
        else if ($notificationType == Enum\NotificationType::GovernanceProposal)
        {
            $notifyFilter   = OptionsNotifier::kNotify_BakerAlerts;
            $notification   = Notification\Transaction\GovernanceProposal::I($user);
            $thresholdScope = null;
        }
        else if ($notificationType == Enum\NotificationType::GovernanceVoteReminder)
        {
            $notifyFilter   = OptionsNotifier::kNotify_BakerAlerts;
            $notification   = Notification\Transaction\GovernanceVoteReminder::I($user);
            $thresholdScope = null;
        }
        else if ($notificationType == Enum\NotificationType::ChatMessage)
        {
            $notifyFilter   = OptionsNotifier::kNotify_Messages;
            $notification   = Notification\Transaction\ChatMessage::I($user);
            $thresholdScope = null;
        }
        else if ($notificationType == Enum\NotificationType::ChatReply)
        {
            $notifyFilter   = OptionsNotifier::kNotify_Messages;
            $notification   = Notification\Transaction\ChatReply::I($user);
            $thresholdScope = null;
        }
        else if ($notificationType == Enum\NotificationType::Origination)
        {
            $notifyFilter   = OptionsNotifier::kNotify_Origination;
            $notification   = Notification\Transaction\Origination::I($user);
            $thresholdScope = null;
        }
        #~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
        # Token Receive/Send may be as: { coin | nft | undefined-token }
        else if ($notificationType == Enum\NotificationType::TokenReceive)
        {
            if ($tokenEntity === null)
                throw new NotificationSendFailedException('Token transfer without a token');

            if ($tokenEntity->isNftToken())
            {
                $notifyFilter   = OptionsNotifier::kNotify_TokenReceive;
                $notification   = Notification\Transaction\NftTransfer_Receive::I($user);
            }
            else if ($tokenEntity->isCoinToken())
            {
                $notifyFilter   = OptionsNotifier::kNotify_Income;
                $notification   = Notification\Transaction\TokenTransfer_Receive::I($user);
            }
            else if ($tokenEntity->isDomainToken())
            {
                $notifyFilter   = null;
                $notification   = Notification\Transaction\DomainTransfer_Receive::I($user);
            }
            else
            {
                $notifyFilter   = OptionsNotifier::kNotify_TokenReceive;
                $notification   = Notification\Transaction\TokenTransfer_Receive_Undefined::I($user);
            }
            $thresholdScope = null;
        }
        else if ($notificationType == Enum\NotificationType::TokenSend)
        {
            if ($tokenEntity === null)
                throw new NotificationSendFailedException('Token transfer without a token');

            if($tokenEntity->isNftToken())
            {
                $notifyFilter   = OptionsNotifier::kNotify_TokenSend;
                $notification   = Notification\Transaction\NftTransfer_Send::I($user);
            }
            else if ($tokenEntity->isCoinToken())
            {
                $notifyFilter   = OptionsNotifier::kNotify_Expense;
                $notification   = Notification\Transaction\TokenTransfer_Send::I($user);
            }
            else if($tokenEntity->isDomainToken())
            {
                $notifyFilter   = null;
                $notification   = Notification\Transaction\DomainTransfer_Send::I($user);
            }
            else
            {
                $notifyFilter   = OptionsNotifier::kNotify_TokenSend;
                $notification   = Notification\Transaction\TokenTransfer_Send_Undefined::I($user);
            }
            $thresholdScope = null;
        }
        #~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
        else if ($notificationType == Enum\NotificationType::AuctionStart)
        {
            $notifyFilter   = OptionsNotifier::kNotify_Auction;
            $notification   = Notification\Transaction\AuctionStart::I($user);
            $thresholdScope = null;
        }
        else if ($notificationType == Enum\NotificationType::AuctionEnd_NoWinner)
        {
            $notifyFilter   = OptionsNotifier::kNotify_Auction;
            $notification   = Notification\Transaction\AuctionEnd_NoWinner::I($user);
            $thresholdScope = null;
        }
        else if ($notificationType == Enum\NotificationType::AuctionCancel)
        {
            $notifyFilter   = OptionsNotifier::kNotify_Auction;
            $notification   = Notification\Transaction\AuctionCancel::I($user);
            $thresholdScope = null;
        }
        else if ($notificationType == Enum\NotificationType::AuctionBid)
        {
            $notifyFilter   = OptionsNotifier::kNotify_Auction;
            $notification   = Notification\Transaction\AuctionBid::I($user);
            $thresholdScope = null;
        }
        else if ($notificationType == Enum\NotificationType::AuctionOutBid)
        {
            $notifyFilter   = OptionsNotifier::kNotify_Auction;
            $notification   = Notification\Transaction\AuctionOutBid::I($user);
            $thresholdScope = null;
        }
        else if ($notificationType == Enum\NotificationType::AuctionOwnerInfo)
        {
            $notifyFilter   = OptionsNotifier::kNotify_Auction;
            $notification   = Notification\Transaction\AuctionOwnerInfo::I($user);
            $thresholdScope = null;
        }
        #~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
        else if ($notificationType == Enum\NotificationType::DomainExpires)
        {
            $notifyFilter   = OptionsNotifier::kNotify_Domains;
            $notification   = Notification\DomainExpires::I($user);
            $thresholdScope = null;
        }
        else if ($notificationType == Enum\NotificationType::DomainExpired)
        {
            $notifyFilter   = OptionsNotifier::kNotify_Domains;
            $notification   = Notification\DomainExpired::I($user);
            $thresholdScope = null;
        }
        #~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
        else if ($notificationType == Enum\NotificationType::Exchange_CoinsToToken)
        {
            $notifyFilter   = OptionsNotifier::kNotify_Expense;
            $notification   = Notification\Transaction\Exchange_CoinsToToken::I($user);
            $thresholdScope = OptionsNotifier::kThresholdTransaction;
        }
        else if ($notificationType == Enum\NotificationType::Exchange_TokenToCoins)
        {
            $notifyFilter   = OptionsNotifier::kNotify_Income;
            $notification   = Notification\Transaction\Exchange_TokenToCoins::I($user);
            $thresholdScope = OptionsNotifier::kThresholdTransaction;
        }
        else if ($notificationType == Enum\NotificationType::Exchange_TokenToToken)
        {
            $notifyFilter   = [OptionsNotifier::kNotify_Expense, OptionsNotifier::kNotify_Income];
            $notification   = Notification\Transaction\Exchange_TokenToToken::I($user);
            $thresholdScope = null;
        }
        #~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
        else
            throw new NotificationSendFailedException("Cannot detect config by type [$notificationType]");

        //--------------------------------------------------------------------------------------------------------------

        $network = AbstractNetwork::GetNetworkById($eventItem['network_id']);

        if ($network === null)
            throw new NotificationSendFailedException("Cannot detect network [{$eventItem['network_id']}]");

        if ($notificationItem['owner_adid'])
        {
            $wallet = $user->getWalletByAdid($notificationItem['owner_adid']);

            // @Notice: this may be in situation when:
            // - notification queue already generated and all items has 'idle' state
            // - user delete wallet
            // - and after notifications try to send to the user
            // So need just skip this notifications

            if ($wallet == null)
                throw new NotificationSendSkippedException('Wallet was deleted by the user');

            $wallet->syncBalanceIfOutOfDate();

            $coinsExchanger = CoinExchange::CreateByWallet($wallet);
        }
        else
        {
            $wallet = null;
            $coinsExchanger = null;
        }

        //--------------------------------------------------------------------------------------------------------------
        // Check whether this notification type is enabled

        if ($notifyFilter !== null)
        {
            if (!is_array($notifyFilter))
                $notifyFilter = [$notifyFilter];

            foreach ($notifyFilter as $notifyFilterId)
            {
                if (!$user->options()->isNotify($notifyFilterId))
                    throw new NotificationSendSkippedException("Filter-by-type rule (user-level) #$notifyFilterId");

                if ($wallet && !$wallet->options()->isNotify($notifyFilterId))
                    throw new NotificationSendSkippedException("Filter-by-type rule (wallet-level) #$notifyFilterId");
            }
        }

        //--------------------------------------------------------------------------------------------------------------

        $customData = $eventItem['custom_data'] ? json_decode($eventItem['custom_data'], true) : null;

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
        // Pre-update royalty-values for special states

        if ($notificationType == Enum\NotificationType::TokenSell ||
            $notificationType == Enum\NotificationType::TokenRoyalty)
        {
            if ($eventItem['royalty_address'] === null)
            {
                $address = $notificationItem['owner_address'];

                if (isset($customData[CustomData::kROYALTIES][$address]))
                {
                    $eventItem['royalty_address'] = $address;
                    $eventItem['royalty']         = $customData[CustomData::kROYALTIES][$address];
                }
            }
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $amountInCoins          = null;

        /** @var Token $amountInTokenEntity */
        $amountInTokenEntity    = null;

        /** @var Token $amountInTokenEntity1st */
        $amountInTokenEntity1st = null;

        /** @var Token $amountInTokenEntity2nd */
        $amountInTokenEntity2nd = null;

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        switch ($notificationType)
        {
            case Enum\NotificationType::Income:
            case Enum\NotificationType::Expense:

            case Enum\NotificationType::TokenList:
            case Enum\NotificationType::TokenMint_Income:
            case Enum\NotificationType::TokenMint_Income_Ticket:
            case Enum\NotificationType::TokenMint_Expense:
            case Enum\NotificationType::TokenMint_Expense_Ticket:

            case Enum\NotificationType::TokenBuy:
            case Enum\NotificationType::TokenOffer:

            case Enum\NotificationType::CollectionOffer:

            case Enum\NotificationType::DropBuy:
            case Enum\NotificationType::DropSold:

            case Enum\NotificationType::AuctionStart:
            case Enum\NotificationType::AuctionEnd_NoWinner:
            case Enum\NotificationType::AuctionCancel:
            case Enum\NotificationType::AuctionBid:
            case Enum\NotificationType::AuctionOutBid:
            case Enum\NotificationType::AuctionOwnerInfo:
                list ($amountInCoins, $amountInTokenEntity) = $this->initializeAmounts($eventItem, $eventItem['price']);
                break;

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            case Enum\NotificationType::TokenSell:
                $amount = $eventItem['revenue'];

                if ($eventItem['sender_address'] == $eventItem['royalty_address'])
                    $amount += $eventItem['royalty'];

                list ($amountInCoins, $amountInTokenEntity) = $this->initializeAmounts($eventItem, $amount);

                unset($amount);
                break;

            case Enum\NotificationType::TokenRoyalty:
                if ($eventItem['royalty'] == null)
                    throw new NotificationSendFailedException('Royalty value is empty');

                list ($amountInCoins, $amountInTokenEntity) = $this->initializeAmounts($eventItem, $eventItem['royalty']);
                break;

            case Enum\NotificationType::TokenPurchaseStoreFee:
                list ($amountInCoins, $amountInTokenEntity) = $this->initializeAmounts($eventItem, $eventItem['fee']);
                break;

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            case Enum\NotificationType::Exchange_CoinsToToken:
                $amountInCoins       = $eventItem['price'];
                $amountInTokenEntity = $this->getTokenCoinEntity($eventItem['network_id'], $eventItem['currency_address']);
                $amountInTokenEntity->amount = $eventItem['revenue'];
                break;

            case Enum\NotificationType::Exchange_TokenToCoins:
                $amountInCoins       = $eventItem['revenue'];
                $amountInTokenEntity = $this->getTokenCoinEntity($eventItem['network_id'], $eventItem['currency_address']);
                $amountInTokenEntity->amount = $eventItem['price'];
                break;

            case Enum\NotificationType::Exchange_TokenToToken:
                $amountInTokenEntity1st = $this->getTokenCoinEntity($eventItem['network_id'], $eventItem['currency_address']);
                $amountInTokenEntity1st->amount = $eventItem['price'];

                $amountInTokenEntity2nd = $this->getTokenCoinEntity($eventItem['network_id'], $eventItem['currency_address_2nd']);
                $amountInTokenEntity2nd->amount = $eventItem['revenue'];
                break;

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            case Enum\NotificationType::TokenMint:

            case Enum\NotificationType::DomainExpires:
            case Enum\NotificationType::DomainExpired:

            case Enum\NotificationType::TokenSend:
            case Enum\NotificationType::TokenReceive:
            case Enum\NotificationType::TokenUpdateMetadata:

            case Enum\NotificationType::TokenComment:
            case Enum\NotificationType::TokenCommentReply:

            // A baker's terms, an origination and a chat message carry no amount worth showing
            case Enum\NotificationType::BakerParameters:
            case Enum\NotificationType::Origination:
            case Enum\NotificationType::ChatMessage:
            case Enum\NotificationType::ChatReply:
            case Enum\NotificationType::BakerMissedRights:
            case Enum\NotificationType::BakerDalInsufficient:
            case Enum\NotificationType::BakerDalRecovered:
            case Enum\NotificationType::BakerDalDenounced:
            case Enum\NotificationType::GovernancePeriod:
            case Enum\NotificationType::GovernanceProposal:
            case Enum\NotificationType::GovernanceVoteReminder:
            case Enum\NotificationType::TeiaEvent:
            case Enum\NotificationType::TeiaMultisigProposal:
            case Enum\NotificationType::TeiaMultisigReminder:
            case Enum\NotificationType::TeiaDaoProposal:
            case Enum\NotificationType::TeiaPoll:
                break;

            // The delegated balance, or the tez staked / unstaked
            case Enum\NotificationType::BakerDelegatorJoined:
            case Enum\NotificationType::BakerDelegatorLeft:
            case Enum\NotificationType::BakerStakerStake:
            case Enum\NotificationType::BakerStakerUnstake:
                list ($amountInCoins, $amountInTokenEntity) = $this->initializeAmounts($eventItem, $eventItem['price']);
                break;

            // The delegated balance, or the tez moved in or out of a stake
            case Enum\NotificationType::DelegationSet:
            case Enum\NotificationType::DelegationChange:
            case Enum\NotificationType::DelegationRemove:
            case Enum\NotificationType::Stake:
            case Enum\NotificationType::Unstake:
            case Enum\NotificationType::UnstakeFinalized:
            case Enum\NotificationType::UnstakeFinalizable:
                list ($amountInCoins, $amountInTokenEntity) = $this->initializeAmounts($eventItem, $eventItem['price']);
                break;

            default:
                throw new NotificationSendFailedException("Unknown notification type [{$notificationItem['notification_type']}]");
        }

        //--------------------------------------------------------------------------------------------------------------
        // Check filters by threshold

        if ($thresholdScope !== null)
        {
            $amountInXtz = $amountInCoins;

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            if ($amountInXtz == 0 && $amountInTokenEntity)
            {
                $amountInXtz = $amountInTokenEntity->convertTokenPriceToXtz();
            }

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            if ($amountInXtz > 0)
            {
                if ($amountInXtz < $user->options()->getThreshold($thresholdScope))
                    throw new NotificationSendSkippedException("Threshold-$thresholdScope (user-level) [$amountInXtz < ".$user->options()->getThreshold($thresholdScope)."]");

                if ($wallet && $amountInXtz < $wallet->options()->getThreshold($thresholdScope))
                    throw new NotificationSendSkippedException("Threshold-$thresholdScope (wallet-level) [$amountInXtz < ".$wallet->options()->getThreshold($thresholdScope)."]");
            }

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            unset($amountInXtz);
        }

        //--------------------------------------------------------------------------------------------------------------
        // Resolve the marketplace, if any

        $marketplace = TezosHelper::GetMarketplace($eventItem['marketplace_address'], $eventItem['token_address']);

        $notification->bindWallet($wallet);

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        if ($notificationType == Enum\NotificationType::TokenMint ||
            $notificationType == Enum\NotificationType::TokenList ||
            $notificationType == Enum\NotificationType::TokenUpdateMetadata ||
            $notificationType == Enum\NotificationType::TokenComment ||
            $notificationType == Enum\NotificationType::TokenCommentReply)
        {
            if ($tokenEntity && $tokenEntity->isNftToken())
            {
                $notification->appendNftTags($tokenEntity);
                $notification->appendNftAttributes($tokenEntity);
            }
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        if ($notificationType == Enum\NotificationType::TokenMint ||
            $notificationType == Enum\NotificationType::TokenList)
        {
            // royalty_percent stored as decimals=4
            $notification->bind('{###ROYALTY_PERCENT###}',
                $eventItem['royalty_percent']
                ? Helper::TrimRightZerosInNumber($eventItem['royalty_percent']/100).'%'
                : Notification::HIDE_ME);

            $notification->bind('{###EDITIONS###}',
                $notification->getFriendlyQuantityValue($tokenEntity->getCurrentEditions()));
        }

        //--------------------------------------------------------------------------------------------------------------

        // fx(hash) iteration index: each mint shows its own
        if ($notificationType == Enum\NotificationType::TokenMint_Expense &&
            isset($customData[CustomData::kITERATION_IDX]))
        {
            $iterationIndex        = $customData[CustomData::kITERATION_IDX];

            $iterationMarketUrlId  = (string) $customData[CustomData::kITERATION_MARKET_URL_ID];
            $iterationTokenAddress = '';
            $iterationTokenId      = (string) $customData[CustomData::kITERATION_TOKEN_ID];

            $iterationMarketUrlItem = Models\Shared\MarketUrls::model()->getItemById($iterationMarketUrlId);

            if ($iterationMarketUrlItem == null)
                throw new NotificationSendFailedException("Cannot find marketUrlItem by id=[$iterationMarketUrlId]");

            $iterationRedirectUrl = URL::InnerRedirectToToken($iterationMarketUrlItem, $iterationTokenAddress, $iterationTokenId);

            $notification->bind('{###ITERATION_IDX###}', $iterationIndex);
            $notification->bind('{###ITERATION_URL###}', $iterationRedirectUrl);

            // These variables are used again below (details buttons), so they are not unset
        }
        else
        {
            // Mints without an iteration (objkt's generative module, any non-fx(hash) mint) drop the line rather
            // than leaving the placeholder unbound, which logs a warning per notification
            $notification->bind('{###ITERATION_IDX###}', Notification::HIDE_ME);
            $notification->bind('{###ITERATION_URL###}', Notification::HIDE_ME);
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        if ($eventItem['sender_address'])
        {
            $notification->bind('{###SENDER_ADDRESS###}',       Notification::SafeText($network->getWalletPublicName($eventItem['sender_address'])));
            $notification->bind('{###SENDER_PROFILE_URL###}',   $this->getUserProfileUrl($network, $marketplace, $eventItem['sender_address']));
        }
        else
        {
            $notification->bind('{###SENDER_ADDRESS###}',       Notification::HIDE_ME);
            $notification->bind('{###SENDER_PROFILE_URL###}',   Notification::HIDE_ME);
        }

        if ($eventItem['target_address'])
        {
            $notification->bind('{###TARGET_ADDRESS###}',       Notification::SafeText($network->getWalletPublicName($eventItem['target_address'])));
            $notification->bind('{###TARGET_PROFILE_URL###}',   $this->getUserProfileUrl($network, $marketplace, $eventItem['target_address']));
        }
        else
        {
            $notification->bind('{###TARGET_ADDRESS###}',       Notification::HIDE_ME);
            $notification->bind('{###TARGET_PROFILE_URL###}',   Notification::HIDE_ME);
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        if ($tokenEntity && $tokenEntity->isCoinToken())
        {
            // The same placeholder is bound again below for token amounts; the two cases never meet in one notification
            $notification->bind('{###TOKEN_AMOUNT_WITH_CURRENCY###}', Notification::SafeText($tokenEntity->getFriendlyCoinsAmount()));
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        if ($amountInCoins !== null)
        {
            // Always bind the value, even 0: the placeholders share lines with other text, so HIDE_ME would drop the
            // whole line. For the same reason an empty AMOUNT_TOTAL_CONVERTED is bound as '', not HIDE_ME.

            $notification->bind('{###AMOUNT_TOTAL###}',             $network->getCoinsFriendlyFullValue($amountInCoins));
            $notification->bind('{###AMOUNT_TOTAL_CONVERTED###}',   $coinsExchanger ? $coinsExchanger->convertToString($amountInCoins) : '');

            $notification->bind('{###QUANTITY###}',                 $notification->getFriendlyQuantityValue($eventItem['quantity']));
        }

        if ($amountInTokenEntity)
        {
            $notification->bind('{###TOKEN_AMOUNT_WITH_CURRENCY###}',   Notification::SafeText($amountInTokenEntity->getFriendlyCoinsAmount()));

            if ($amountInCoins === null)
            {   // same values as for coins, but in tokens
                $notification->bind('{###AMOUNT_TOTAL###}',             Notification::SafeText($amountInTokenEntity->getFriendlyCoinsAmount()));
                $notification->bind('{###AMOUNT_TOTAL_CONVERTED###}',   '');

                $notification->bind('{###QUANTITY###}',                 Notification::HIDE_ME);
            }
        }

        if ($amountInTokenEntity1st)
        {
            $notification->bind('{###TOKEN_AMOUNT_1ST_WITH_CURRENCY###}',   Notification::SafeText($amountInTokenEntity1st->getFriendlyCoinsAmount()));
        }

        if ($amountInTokenEntity2nd)
        {
            $notification->bind('{###TOKEN_AMOUNT_2ND_WITH_CURRENCY###}',   Notification::SafeText($amountInTokenEntity2nd->getFriendlyCoinsAmount()));
        }

        if ($amountInCoins === null && $amountInTokenEntity === null)
        {   // For example for mint events
            $notification->bind('{###AMOUNT_TOTAL###}',                 Notification::HIDE_ME);
            $notification->bind('{###AMOUNT_TOTAL_CONVERTED###}',       Notification::HIDE_ME);
            $notification->bind('{###QUANTITY###}',                     $notification->getFriendlyQuantityValue($eventItem['quantity']));
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        if ($notificationType == Enum\NotificationType::TokenRoyalty ||
            $notificationType == Enum\NotificationType::TokenPurchaseStoreFee)
        {
            list ($fullPriceInCoins, $fullPriceInTokenEntity) = $this->initializeAmounts($eventItem, $eventItem['price']);

            if ($fullPriceInCoins !== null)
            {
                $notification->bind('{###FULL_SELL_PRICE###}',              $network->getCoinsFriendlyFullValue($fullPriceInCoins));
                $notification->bind('{###FULL_SELL_PRICE_CONVERTED###}',    $coinsExchanger ? $coinsExchanger->convertToString($fullPriceInCoins) : null);
            }
            else if ($fullPriceInTokenEntity)
            {
                $notification->bind('{###FULL_SELL_PRICE###}',              Notification::SafeText($fullPriceInTokenEntity->getFriendlyCoinsAmount()));
                $notification->bind('{###FULL_SELL_PRICE_CONVERTED###}',    '');
            }
            else
                throw new NotificationSendFailedException('Cannot detect full-sell-price for royalty notification');
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
        // @notice: $tokenEntity may be null, but token_address & token_id may be defined!

        if ($eventItem['token_address'] && $eventItem['token_id'] !== null)
        {
            $notification->bind('{###TOKEN_CONTRACT_NAME###}',      $network->getContractShortName($eventItem['token_address']));
            $notification->bind('{###TOKEN_CONTRACT_URL###}',       $network->getContractPublicUrl($eventItem['token_address']));

            $notification->bind('{###TOKEN_ID###}',                 $eventItem['token_id']);

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            if ($tokenEntity && $tokenEntity->isCoinToken())
            {
                // Some tokens have `tokenType IS NULL` and `decimals > 0`, so they are coins. Their mint events
                // still need the token data bound, otherwise some fields stay empty and the notification is broken.

                if ($notificationType == Enum\NotificationType::TokenMint)
                    $this->bindTokenDataByEntity($notification, $network, $marketplace, $tokenEntity);
            }
            else if ($tokenEntity && ($tokenEntity->isNftToken() || $tokenEntity->isDomainToken()))
            {
                $this->bindTokenDataByEntity($notification, $network, $marketplace, $tokenEntity);
            }
            else
            {
                $this->bindTokenDataByEventItem($notification, $network, $marketplace, $eventItem);
            }
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
        // Spec data

        if (isset($customData[CustomData::kAUCTION_ID]))
        {
            // Needed only when the token of the auction cannot be resolved
            $auctionName = '#'.$customData[CustomData::kAUCTION_ID];

            $notification->bind('{###AUCTION_NAME###}',             $auctionName);
            $notification->bind('{###AUCTION_NAME_ESCAPED###}',     htmlspecialchars($auctionName, ENT_QUOTES));

            if (!empty($customData[CustomData::kAUCTION_TOP_BID]))
            {
                $topBid   = $customData[CustomData::kAUCTION_TOP_BID];
                $topOwner = $customData[CustomData::kAUCTION_TOP_OWNER];

                if ($amountInTokenEntity)
                {
                    $notification->bind('{###AMOUNT_TOP_BID###}',           Notification::SafeText($amountInTokenEntity->getFriendlyCoinsAmount($topBid)));
                    $notification->bind('{###AMOUNT_TOP_BID_CONVERTED###}', Notification::HIDE_ME);
                }
                else
                {
                    $notification->bind('{###AMOUNT_TOP_BID###}',           $network->getCoinsFriendlyFullValue($topBid));
                    $notification->bind('{###AMOUNT_TOP_BID_CONVERTED###}', $coinsExchanger ? $coinsExchanger->convertToString($topBid) : null);
                }

                $notification->bind('{###TOP_OWNER_ADDRESS###}',            Notification::SafeText($network->getWalletPublicName($topOwner)));
                $notification->bind('{###TOP_OWNER_PROFILE_URL###}',        $this->getUserProfileUrl($network, $marketplace, $topOwner));

                unset($topBid, $topOwner);
            }

            unset($auctionName);
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        if (!empty($customData[CustomData::kAUCTION_DIRECT_PRICE]))
            $notification->bind('{###AUCTION_DIRECT_PRICE###}', $network->getCoinsFriendlyFullValue($customData[CustomData::kAUCTION_DIRECT_PRICE]));
        else
            $notification->bind('{###AUCTION_DIRECT_PRICE###}', Notification::HIDE_ME);

        if (!empty($customData[CustomData::kAUCTION_BID_INC_PCT]))
            $notification->bind('{###AUCTION_BID_INC_PCT###}', $customData[CustomData::kAUCTION_BID_INC_PCT].'%');
        else
            $notification->bind('{###AUCTION_BID_INC_PCT###}', Notification::HIDE_ME);

        if (!empty($customData[CustomData::kDURATION]))
            $notification->bind('{###DURATION###}', Phrase::FriendlyDuration($customData[CustomData::kDURATION]));
        else
            $notification->bind('{###DURATION###}', Notification::HIDE_ME);

        if (!empty($customData[CustomData::kBURN_ON_END]))
            $notification->bind('{###BURN_ON_END###}',  Phrase::I()->yes);
        else
            $notification->bind('{###BURN_ON_END###}',  Notification::HIDE_ME);

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        if (isset($customData[CustomData::kDOMAIN]))
        {
            $expireDateTime = $customData[CustomData::kEXPIRED];
            $expireDuration = strtotime($expireDateTime) - Time::Time();

            $notification->bind('{###DOMAIN###}',           Notification::SafeText((string) $customData[CustomData::kDOMAIN]));
            $notification->bind('{###DOMAIN_RENEW_URL###}', $customData[CustomData::kDOMAIN_RENEW_URL]);

            $notification->bind('{###EXPIRE_DATETIME###}',  Phrase::FriendlyDateTime($expireDateTime));
            $notification->bind('{###EXPIRE_DURATION###}',  Phrase::FriendlyDuration($expireDuration));
        }

        if (in_array($notificationType, [
            Enum\NotificationType::BakerDelegatorJoined, Enum\NotificationType::BakerDelegatorLeft,
            Enum\NotificationType::BakerStakerStake, Enum\NotificationType::BakerStakerUnstake,
            Enum\NotificationType::BakerMissedRights, Enum\NotificationType::GovernancePeriod,
            Enum\NotificationType::BakerDalInsufficient, Enum\NotificationType::BakerDalRecovered, Enum\NotificationType::BakerDalDenounced,
            Enum\NotificationType::GovernanceProposal, Enum\NotificationType::GovernanceVoteReminder]))
        {
            $this->bindBakerEvent($notification, $network, $eventItem, $customData ?? []);
        }

        if ($notificationType == Enum\NotificationType::TeiaEvent)
        {
            $e = $customData ?? [];
            $text = fn ($key, $max) => !empty($e[$key]) ? Notification::SafeText(mb_strimwidth(trim((string) $e[$key]), 0, $max, '…')) : Notification::HIDE_ME;

            $notification->bind('{###EVENT_TITLE###}',       $text('title', 120));
            $notification->bind('{###EVENT_SUBTITLE###}',    $text('subtitle', 200));
            $notification->bind('{###EVENT_DESCRIPTION###}', $text('description', 600));

            $buttons = [];

            if (!empty($e['link']) && preg_match('#^https://#', $e['link']))
                $buttons[] = NotificationButton::Link('Event page', $e['link']);

            $buttons[] = NotificationButton::Link('All Teia events', \CryptoCore\Setapp\TeiaEvents::EventsPageUrl);
            $notification->appendButtonsRow($buttons);

            if (!empty($e['screenshot']) && preg_match('#^https://#', $e['screenshot']))
                $notification->setPreviewThumbnailUrl($e['screenshot']);
        }

        if (in_array($notificationType, [Enum\NotificationType::TeiaMultisigProposal, Enum\NotificationType::TeiaMultisigReminder,
                                         Enum\NotificationType::TeiaDaoProposal, Enum\NotificationType::TeiaPoll]))
        {
            $g = $customData ?? [];
            $orHide = fn ($v) => ($v === null || $v === '') ? Notification::HIDE_ME : $v;

            $notification->bind('{###PROPOSAL_ID###}',      (string) ($g['proposal_id'] ?? '?'));
            $notification->bind('{###PROPOSAL###}',         $orHide(isset($g['summary']) ? Notification::SafeText($g['summary']) : null));
            $notification->bind('{###PROPOSAL_TITLE###}',   $orHide(isset($g['title']) ? Notification::SafeText(mb_strimwidth($g['title'], 0, 200, '…')) : null));
            $notification->bind('{###PROPOSAL_TEXT###}',    $orHide(isset($g['text']) ? Notification::SafeText(mb_strimwidth($g['text'], 0, 500, '…')) : null));
            $notification->bind('{###ISSUER###}',           $orHide(isset($g['issuer']) ? Notification::SafeText(\CryptoNoisesTezos\Vendors\Tzkt::GetAlias($g['issuer']) ?? $network->getWalletShortName($g['issuer'])) : null));
            $notification->bind('{###OPTIONS###}',          $orHide(isset($g['options']) && $g['options'] !== '' ? Notification::SafeText($g['options']) : null));
            $notification->bind('{###VOTES###}',            $orHide(isset($g['votes']) ? "{$g['votes']} of {$g['min_votes']}" : null));
            $notification->bind('{###ENDS###}',             $orHide(!empty($g['ends_at']) ? self::FriendlyFutureTime($g['ends_at'], null) : null));

            $buttons = [match ($notificationType) {
                Enum\NotificationType::TeiaDaoProposal => NotificationButton::Link('Vote on teia.art', \CryptoCore\Setapp\TeiaGovernance::DaoUrl),
                Enum\NotificationType::TeiaPoll        => NotificationButton::Link('Vote on teia.art', \CryptoCore\Setapp\TeiaGovernance::PollUrl.($g['proposal_id'] ?? '')),
                default                                => NotificationButton::Link('Open the multisig', \CryptoCore\Setapp\TeiaGovernance::MultisigUrl),
            }];

            $details = !empty($g['details_uri']) ? \CryptoNoisesTezos\Vendors\Ipfs::ConvertUriToUrl($g['details_uri']) : null;

            if ($details && preg_match('#^https://#', $details))
                $buttons[] = NotificationButton::Link('Details', $details);

            $notification->appendButtonsRow($buttons);
        }

        if ($notificationType == Enum\NotificationType::ChatMessage)
        {
            $notification->bind('{###ENCRYPTED###}', !empty($customData['encrypted']) ? '🔒 End-to-end encrypted' : Notification::HIDE_ME);
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
        // Account events (delegation, staking, baker parameters, origination). Every placeholder their templates
        // use is bound here, HIDE_ME when there is nothing to say, which drops the line

        if (in_array($notificationType, [
            Enum\NotificationType::DelegationSet, Enum\NotificationType::DelegationChange, Enum\NotificationType::DelegationRemove,
            Enum\NotificationType::Stake, Enum\NotificationType::Unstake, Enum\NotificationType::UnstakeFinalized,
            Enum\NotificationType::UnstakeFinalizable,
            Enum\NotificationType::BakerParameters, Enum\NotificationType::Origination]))
        {
            $this->bindAccountEvent($notification, $network, $eventItem, $customData ?? []);
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
        // NFT Marketplace

        if ($marketplace)
        {
            if ($marketplace->name)
                $notification->bind('{###MARKETPLACE_NAME###}', $marketplace->name);
            else
                $notification->bind('{###MARKETPLACE_NAME###}', Notification::HIDE_ME);

            if ($eventItem['token_address'] && $eventItem['token_id'] !== null)
            {
                $detailsButtons = [];

                [$marketplaceTokenUrl, $buttonCaption] = $marketplace->getTokenUrlAndButtonCaption(0,
                    $eventItem['token_address'], $eventItem['token_id']);

                if ($marketplaceTokenUrl)
                    $notification->bind('{###TOKEN_STORE_URL###}', $marketplaceTokenUrl);

                if ($marketplaceTokenUrl && $buttonCaption)
                    $detailsButtons[] = NotificationButton::Link($buttonCaption, $marketplaceTokenUrl);

                // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

                // fx(hash) iteration index: link to the minted iteration
                if (isset($iterationMarketUrlItem) && isset($iterationRedirectUrl))
                {
                    $detailsButtons[] = NotificationButton::Link($iterationMarketUrlItem['button_caption'],
                        $iterationRedirectUrl
                    );
                }

                // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
                // Extra links

                for ($extraIndex=1; $extraIndex<=4; $extraIndex++)
                {
                    [$marketplaceTokenUrl, $buttonCaption] = $marketplace->getTokenUrlAndButtonCaption($extraIndex,
                        $eventItem['token_address'], $eventItem['token_id']);

                    if ($marketplaceTokenUrl && $buttonCaption)
                        $detailsButtons[] = NotificationButton::Link($buttonCaption, $marketplaceTokenUrl);
                }

                // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

                if (!empty($detailsButtons))
                    $notification->prependButtonsAsRows($detailsButtons, Config::cfg()->uiNftDetailsButtonsPerRow);

                unset($detailsButtons);
            }
        }
        else
        {
            $notification->bind('{###MARKETPLACE_NAME###}', Notification::HIDE_ME);
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        if ($tokenEntity && ($tokenEntity->isNftToken() || $tokenEntity->isDomainToken()))
        {
            $notification->setPreviewThumbnailUrl($tokenEntity->thumbnailUrl);
            $notification->setPreviewAnimationUrl($tokenEntity->animationUrl);
            $notification->setPreviewMovieClipUrl($tokenEntity->movieClipUrl);
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $hashes = $eventItem['transaction_hash'] ?? null;
        if (is_string($hashes) && str_starts_with($hashes, '['))
            $hashes = json_decode($hashes, true);
        $notification->addTransactionLinks(array_map(
            fn ($hash) => is_string($hash) ? $network->getTransactionPublicUrl($hash) : null, (array) $hashes));

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $notification->bindDebugClassName()
            ->bindDebugArray('eventItem',                   $eventItem,                 'event_flow_id')
            ->bindDebugArray('notificationItem',            $notificationItem,          'event_notification_id')
            ->bindDebugToken('tokenEntity',                 $tokenEntity)
            ->bindDebugToken('amountInTokenEntity',         $amountInTokenEntity)
            ->bindDebugToken('amountInTokenEntity1st',      $amountInTokenEntity1st)
            ->bindDebugToken('amountInTokenEntity2nd',      $amountInTokenEntity2nd)
            ->bindDebugMarketplace($marketplace)
            ->logDebugLine()
            ;

        return $notification;
    }

    //==================================================================================================================

    /**
     * @param string $networkId
     * @param string $tokenAddressWithTokenId
     * @return Token
     * @throws NotificationSendFailedException
     */
    private function getTokenCoinEntity(string $networkId, string $tokenAddressWithTokenId): Token
    {
        // Extract tokenId from address like 'ADDRESS:tokenId'
        list ($tokenAddress, $tokenId) = array_pad(explode(':', $tokenAddressWithTokenId, 2), 2, null);

        $tokenId = (int) $tokenId;

        $token = GatewayTezos\Tokens::GetTokenDetails($tokenAddress, $tokenId);

        if ($token === null)
            throw new NotificationSendFailedException("Cannot detect token-entity #$tokenAddressWithTokenId");

        return $token;
    }

    /**
     * @param array $eventItem
     * @param $amountValue
     * @return array[$amountInCoins, $amountInTokenEntity]
     * @throws NotificationSendFailedException
     */
    private function initializeAmounts(array $eventItem, $amountValue): array
    {
        $amountInCoins       = null;
        $amountInTokenEntity = null;

        if ($eventItem['currency_address'] !== null)
        {
            $amountInTokenEntity = $this->getTokenCoinEntity($eventItem['network_id'], $eventItem['currency_address']);
            $amountInTokenEntity->amount = $amountValue;
        }
        else
        {
            $amountInCoins = $amountValue;
        }

        return [$amountInCoins, $amountInTokenEntity];
    }

    //==================================================================================================================

    private function bindTokenDataByEntity(Notification $notification, AbstractNetwork $network, ?Marketplace $marketplace, Token $tokenEntity): void
    {
        $notification->bind('{###TOKEN_NAME###}',                       $tokenEntity->name);
        $notification->bind('{###TOKEN_NAME_ESCAPED###}',               htmlspecialchars($tokenEntity->name, ENT_QUOTES));

        if ($tokenEntity->creatorAddress)
        {
            $notification->bind('{###TOKEN_CREATOR_ADDRESS###}',        Notification::SafeText($network->getWalletPublicName($tokenEntity->creatorAddress)));
            $notification->bind('{###TOKEN_CREATOR_PROFILE_URL###}',    $this->getUserProfileUrl($network, $marketplace, $tokenEntity->creatorAddress));
        }
        else
        {
            $notification->bind('{###TOKEN_CREATOR_ADDRESS###}',        Notification::HIDE_ME);
            $notification->bind('{###TOKEN_CREATOR_PROFILE_URL###}',    Notification::HIDE_ME);
        }

        if ($tokenEntity->collectionName)
        {
            $tokensCollectionUrl = null;

            if ($marketplace)
                $tokensCollectionUrl = $marketplace->getCollectionUrl($tokenEntity->tokenAddress, $tokenEntity->collectionGroup, $tokenEntity->collectionId);

            if ($tokensCollectionUrl == null)
                $tokensCollectionUrl = $network->getTokenCollectionUrl($tokenEntity->tokenAddress);

            $notification->bind('{###TOKENS_COLLECTION_NAME###}',       Notification::SafeText($tokenEntity->collectionName));
            $notification->bind('{###TOKENS_COLLECTION_URL###}',        $tokensCollectionUrl);
        }
        else
        {
            $notification->bind('{###TOKENS_COLLECTION_NAME###}',       Notification::HIDE_ME);
            $notification->bind('{###TOKENS_COLLECTION_URL###}',        Notification::HIDE_ME);
        }

        if ($tokenEntity->collectionId)
            $notification->bind('{###TOKENS_COLLECTION_ID###}',         $tokenEntity->collectionId);
        else
            $notification->bind('{###TOKENS_COLLECTION_ID###}',         Notification::HIDE_ME);

        // warning! This value may be overwritten by marketplace URL-pattern
        $notification->bind('{###TOKEN_STORE_URL###}',                  $tokenEntity->tokenMarketUrl);
    }

    private function bindTokenDataByEventItem(Notification $notification, AbstractNetwork $network, ?Marketplace $marketplace, array $eventItem): void
    {
        $name = $network->getContractShortName($eventItem['token_address']).':'.$eventItem['token_id'];

        $notification->bind('{###TOKEN_NAME###}',                       $name);
        $notification->bind('{###TOKEN_NAME_ESCAPED###}',               htmlspecialchars($name, ENT_QUOTES));

        if ($eventItem['royalty_address'])
        {
            $notification->bind('{###TOKEN_CREATOR_ADDRESS###}',        Notification::SafeText($network->getWalletPublicName($eventItem['royalty_address'])));
            $notification->bind('{###TOKEN_CREATOR_PROFILE_URL###}',    $this->getUserProfileUrl($network, $marketplace, $eventItem['royalty_address']));
        }
        else
        {
            $notification->bind('{###TOKEN_CREATOR_ADDRESS###}',        Notification::HIDE_ME);
            $notification->bind('{###TOKEN_CREATOR_PROFILE_URL###}',    Notification::HIDE_ME);
        }

        $notification->bind('{###TOKENS_COLLECTION_NAME###}',           $network->getContractShortName($eventItem['token_address']));
        $notification->bind('{###TOKENS_COLLECTION_URL###}',            $network->getContractPublicUrl($eventItem['token_address']));
        $notification->bind('{###TOKENS_COLLECTION_ID###}',             Notification::HIDE_ME);

        // warning! This value may be overwritten by marketplace URL-pattern
        $notification->bind('{###TOKEN_STORE_URL###}',                  $network->getTokenDefaultDetailsUrl($eventItem['token_address'], $eventItem['token_id']));
    }

    //==================================================================================================================

    /**
     * Binds the placeholders of the account-event templates: delegation, staking, baker parameters, origination.
     * The sender is the counterparty (the baker, or a factory contract); names prefer the TzKT alias stored with
     * the operation, since bakers and finalizing services are rarely in the profile cache.
     */
    private function bindAccountEvent(Notification $notification, AbstractNetwork $network, array $eventItem, array $customData): void
    {
        $sender = $eventItem['sender_address'];
        $target = $eventItem['target_address'];

        $name = function (?string $alias, ?string $address) use ($network): ?string
        {
            if ($alias !== null && $alias !== '')
                return Notification::SafeText($alias);

            return $address ? Notification::SafeText($network->getWalletPublicName($address)) : null;
        };

        $bindOrHide = function (string $placeholder, ?string $value) use ($notification): void
        {
            $notification->bind($placeholder, ($value === null || $value === '') ? Notification::HIDE_ME : $value);
        };

        $isOrigination = $eventItem['event_type'] === Enum\EventFlowType::Origination;

        // The baker: the sender of every account event except an origination
        $bindOrHide('{###BAKER_NAME###}', $isOrigination ? null : $name($customData[CustomData::kBAKER_NAME] ?? null, $sender));
        // Explorer pages, not getAddressPublicUrl(): for a tz1 that is the default user profile, an NFT marketplace
        // page - wrong for a baker. The default contract URL is a block explorer and works for any address.
        $bindOrHide('{###BAKER_URL###}',  $isOrigination || !$sender ? null : $network->getContractPublicUrl($sender));

        $prevBaker = $customData[CustomData::kPREV_BAKER] ?? null;
        $bindOrHide('{###PREV_BAKER_NAME###}', $prevBaker ? $name($customData[CustomData::kPREV_BAKER_NAME] ?? null, $prevBaker) : null);
        $bindOrHide('{###PREV_BAKER_URL###}',  $prevBaker ? $network->getContractPublicUrl($prevBaker) : null);

        // Who sent it, when it was not the wallet itself: a service finalizing for the staker ("Finn"), or the
        // factory contract a wallet deployed through
        $operatedBy = $customData[CustomData::kOPERATED_BY] ?? ($isOrigination && $sender !== $target ? $sender : null);
        $operatedByName = $customData[CustomData::kOPERATED_BY_NAME] ?? null;

        if ($operatedBy !== null && ($operatedByName === null || $operatedByName === '') && $isOrigination)
            $operatedByName = $network->getContractShortName($operatedBy);

        $bindOrHide('{###OPERATED_BY###}', $operatedBy !== null ? $name($operatedByName, $operatedBy) : null);

        // When unstaked tez can be finalized
        $unlockTime  = $customData[CustomData::kUNLOCK_TIME] ?? null;
        $unlockCycle = $customData[CustomData::kUNLOCK_CYCLE] ?? null;
        $bindOrHide('{###UNLOCK###}', $unlockTime ? self::FriendlyFutureTime($unlockTime, $unlockCycle) : null);

        // A baker's terms
        $edge      = $customData[CustomData::kEDGE] ?? null;
        $edgePrev  = $customData[CustomData::kEDGE_PREV] ?? null;
        $limit     = $customData[CustomData::kLIMIT] ?? null;
        $limitPrev = $customData[CustomData::kLIMIT_PREV] ?? null;

        $bindOrHide('{###EDGE_CHANGE###}',  $edge !== null ? self::FriendlyChange($edge, $edgePrev, [self::class, 'FriendlyEdge']) : null);
        $bindOrHide('{###LIMIT_CHANGE###}', $limit !== null ? self::FriendlyChange($limit, $limitPrev, [self::class, 'FriendlyLimit']) : null);

        $activationCycle = $customData[CustomData::kACTIVATION_CYCLE] ?? null;
        $activationTime  = $customData[CustomData::kACTIVATION_TIME] ?? null;
        $bindOrHide('{###ACTIVATION###}', $activationCycle === null ? null
            // Only a future cycle's start is an estimate (Tzkt::EstimateCycleStartTime); a past one is TzKT's own
            : ($activationTime ? self::FriendlyFutureTime($activationTime, $activationCycle,
                strtotime($activationTime) > Time::Time() ? 'around ' : '') : "cycle $activationCycle"));

        // An originated contract
        $contract = $customData[CustomData::kCONTRACT] ?? null;
        $bindOrHide('{###CONTRACT###}',     $contract ? Notification::SafeText($network->getContractShortName($contract)) : null);
        $bindOrHide('{###CONTRACT_URL###}', $contract ? $network->getContractPublicUrl($contract) : null);
    }

    /**
     * Placeholders of the bakers' templates. The baker is target_address; a delegator or staker is the sender, bound
     * by the common code ({###SENDER_ADDRESS###}).
     */
    private function bindBakerEvent(Notification $notification, AbstractNetwork $network, array $eventItem, array $customData): void
    {
        $bindOrHide = function (string $placeholder, $value) use ($notification): void
        {
            $notification->bind($placeholder, ($value === null || $value === '') ? Notification::HIDE_ME : (string) $value);
        };

        $baker = $eventItem['target_address'];
        $name  = $network->getWalletPublicName($baker);

        // A baker rarely has a profile here, but always has a TzKT alias when it has a name at all
        if ($name === $network->getWalletShortName($baker))
            $name = \CryptoNoisesTezos\Vendors\Tzkt::GetAlias($baker) ?? $name;

        $bindOrHide('{###BAKER_NAME###}', Notification::SafeText($name));
        $bindOrHide('{###BAKER_URL###}',  $network->getContractPublicUrl($baker));

        // Where a delegator came from, or went to
        $other = $customData[CustomData::kPREV_BAKER] ?? null;
        $bindOrHide('{###OTHER_BAKER###}', $other ? Notification::SafeText($customData[CustomData::kPREV_BAKER_NAME] ?? $network->getWalletShortName($other)) : null);

        // Missed rights
        $attestations = (int) ($customData['missed_attestations'] ?? 0);
        $slots        = (int) ($customData['missed_slots'] ?? 0);
        $blocks       = (int) ($customData['missed_blocks'] ?? 0);

        $bindOrHide('{###MISSED_ATTESTATIONS###}', $attestations ? $attestations.' ('.$slots.' slot'.($slots == 1 ? '' : 's').')' : null);
        $bindOrHide('{###MISSED_BLOCKS###}',       $blocks ?: null);

        $first = $customData['level_first'] ?? null;
        $last  = $customData['level_last'] ?? null;
        $bindOrHide('{###LEVELS###}', $first === null ? null : ($first == $last ? "$first" : "$first – $last"));

        // DAL participation in the cycle
        if (isset($customData['dal_attestable']))
        {
            $attested   = (int) $customData['dal_attested'];
            $attestable = (int) $customData['dal_attestable'];
            $pct        = $attestable > 0 ? round(100 * $attested / $attestable) : 0;

            $bindOrHide('{###DAL_PARTICIPATION###}', "$attested of $attestable attestable slots ($pct%)");
            $bindOrHide('{###DAL_REQUIRED###}',      isset($customData['dal_required']) ? $customData['dal_required'].'%' : null);
            $bindOrHide('{###DAL_REWARDS###}',       !empty($customData['dal_rewards']) ? $network->getCoinsFriendlyFullValue($customData['dal_rewards']) : null);
        }

        $bindOrHide('{###CYCLE###}', $customData['cycle'] ?? null);

        // Governance
        $kinds = ['proposal' => 'Proposal', 'exploration' => 'Exploration vote', 'cooldown' => 'Cooldown',
                  'promotion' => 'Promotion vote', 'adoption' => 'Adoption', 'testing' => 'Testing'];

        $kind = $customData['period_kind'] ?? null;
        $bindOrHide('{###PERIOD_KIND###}', $kind ? ($kinds[$kind] ?? ucfirst($kind)) : null);
        // "Dec 2, 23:54 (in 7 days, end of cycle 1431, level 16,075,488)": bakers plan by cycle and level, not clock
        $endAt = [];

        if (isset($customData['period_end_cycle']))
            $endAt[] = 'end of cycle '.$customData['period_end_cycle'];

        if (isset($customData['period_end_level']))
            $endAt[] = 'level '.number_format($customData['period_end_level']);

        $bindOrHide('{###PERIOD_END###}', !empty($customData['period_end']) ? self::FriendlyFutureTime($customData['period_end'], null, '', $endAt) : null);

        $hash = $customData['proposal'] ?? null;
        $bindOrHide('{###PROPOSAL###}', $hash === null ? null
            : Notification::SafeText(($customData['proposal_name'] ?? 'Unnamed proposal').' ('.substr($hash, 0, 10).'…)'));

        $initiator = $customData['initiator'] ?? null;
        $bindOrHide('{###INITIATOR###}', $initiator ? Notification::SafeText($customData['initiator_name'] ?? $network->getWalletShortName($initiator)) : null);

        $count = $customData['proposals_count'] ?? null;
        $bindOrHide('{###PROPOSALS_COUNT###}', $count ? $count.' proposal'.($count == 1 ? '' : 's').' to upvote' : null);

        $bindOrHide('{###GOVERNANCE_URL###}', $customData['proposal_url'] ?? \CryptoNoises\Commands\BakerAlerts::kGOVERNANCE_URL);
    }

    /** "Nov 27, 2026, 23:46 UTC (in 2 days, cycle 1427)" */
    private static function FriendlyFutureTime(string $isoTime, $cycle, string $prefix = '', array $more = []): string
    {
        $seconds = strtotime($isoTime) - Time::Time();
        $details = [];

        if ($seconds > 0)
            $details[] = 'in '.Phrase::FriendlyDuration($seconds);
        else if ($seconds < -60)
            $details[] = Phrase::FriendlyDuration(-$seconds).' ago';

        if ($cycle !== null)
            $details[] = "cycle $cycle";

        $details = array_merge($details, $more);

        return $prefix.Phrase::FriendlyDateTime($isoTime).($details ? ' ('.join(', ', $details).')' : '');
    }

    /** "100% → 0%", "9× (unchanged)", or the new value alone when the previous one is not known */
    private static function FriendlyChange($value, $prev, callable $format): string
    {
        if ($prev === null)
            return $format($value);

        if ((int) $prev === (int) $value)
            return $format($value).' (unchanged)';

        return $format($prev).' → '.$format($value);
    }

    /** Edge of baking over staking, in billionths: the share of its stakers' rewards the baker keeps */
    public static function FriendlyEdge($billionths): string
    {
        return Helper::TrimRightZerosInNumber(round(((int) $billionths) / 1e7, 2)).'%';
    }

    /** Limit of staking over baking, in millionths: how much external stake the baker accepts, per tez of its own */
    public static function FriendlyLimit($millionths): string
    {
        $millionths = (int) $millionths;

        if ($millionths == 0)
            return '0× (no new stakers)';

        // Bakers who mean "no limit" set absurd values; the protocol caps the effective one anyway
        if ($millionths >= 1000 * 1000000)
            return 'no limit set';

        return Helper::TrimRightZerosInNumber(round($millionths / 1e6, 2)).'×';
    }

    private function getUserProfileUrl(AbstractNetwork $network, ?Marketplace $marketplace, string $address): string
    {
        if ($marketplace)
        {
            $profileUrl = $marketplace->getUserProfileUrl($address);

            if ($profileUrl)
                return $profileUrl;
        }

        return $network->getAddressPublicUrl($address);
    }
}
