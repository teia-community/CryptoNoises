<?php

namespace CryptoNoises\Enum;

abstract class NotificationType
{
    //                                      = '12345678901234567890123456789012' (len = 32)
    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    const Income                            = 'income';
    const Expense                           = 'expense';

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    const TokenMint                         = 'token-mint';
    const TokenMint_Income                  = 'token-mint-income';
    const TokenMint_Income_Ticket           = 'token-mint-income:ticket';
    const TokenMint_Expense                 = 'token-mint-expense';
    const TokenMint_Expense_Ticket          = 'token-mint-expense:ticket';

    const TokenList                         = 'token-list';

    const TokenUpdateMetadata               = 'token-update-metadata';

    const DropBuy                           = 'drop-buy';
    const DropSold                          = 'drop-sold';

    const TokenComment                      = 'token-comment';
    const TokenCommentReply                 = 'token-comment-reply';

    const ChatMessage                       = 'chat-message';
    const ChatReply                         = 'chat-reply';

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    const TokenBuy                          = 'token-buy';
    const TokenSell                         = 'token-sell';
    const TokenRoyalty                      = 'token-royalty';
    const TokenPurchaseStoreFee             = 'token-purchase-store-fee';

    const TokenReceive                      = 'token-receive';
    const TokenSend                         = 'token-send';

    const TokenOffer                        = 'token-offer';
    const CollectionOffer                   = 'collection-offer';

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    const AuctionStart                      = 'auction-start';
    const AuctionEnd_NoWinner               = 'auction-end-no-winner';
    const AuctionCancel                     = 'auction-cancel';
    const AuctionBid                        = 'auction-bid';
    const AuctionOutBid                     = 'auction-outbid';
    const AuctionOwnerInfo                  = 'auction-owner-info';

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    const DomainExpires                     = 'domain-expires';
    const TeiaEvent                         = 'teia-event';
    const TeiaMultisigProposal              = 'teia-multisig-proposal';
    const TeiaMultisigReminder              = 'teia-multisig-reminder';
    const TeiaDaoProposal                   = 'teia-dao-proposal';
    const TeiaPoll                          = 'teia-poll';

    /** Routine wallet activity a digest may bundle; alerts, reminders, messages and outbids always go out at once */
    public static function IsDigestible(string $type): bool
    {
        return in_array($type, [
            self::Income, self::Expense,
            self::TokenMint, self::TokenMint_Income, self::TokenMint_Income_Ticket, self::TokenMint_Expense, self::TokenMint_Expense_Ticket,
            self::TokenList, self::TokenUpdateMetadata, self::DropBuy, self::DropSold,
            self::TokenBuy, self::TokenSell, self::TokenRoyalty, self::TokenPurchaseStoreFee,
            self::TokenReceive, self::TokenSend, self::TokenOffer, self::CollectionOffer,
            self::AuctionStart, self::AuctionEnd_NoWinner, self::AuctionCancel, self::AuctionBid, self::AuctionOwnerInfo,
            self::BakerDelegatorJoined, self::BakerDelegatorLeft, self::BakerStakerStake, self::BakerStakerUnstake,
        ], true);
    }
    const DomainExpired                     = 'domain-expired';

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    const DelegationSet                     = 'delegation-set';
    const DelegationChange                  = 'delegation-change';
    const DelegationRemove                  = 'delegation-remove';

    const Stake                             = 'stake';
    const Unstake                           = 'unstake';
    const UnstakeFinalized                  = 'unstake-finalized';
    const UnstakeFinalizable                = 'unstake-finalizable';

    const BakerParameters                   = 'baker-parameters';

    const Origination                       = 'origination';

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
    // For bakers

    const BakerDelegatorJoined              = 'baker-delegator-joined';
    const BakerDelegatorLeft                = 'baker-delegator-left';
    const BakerStakerStake                  = 'baker-staker-stake';
    const BakerStakerUnstake                = 'baker-staker-unstake';
    const BakerMissedRights                 = 'baker-missed-rights';
    const BakerDalInsufficient              = 'baker-dal-insufficient';
    const BakerDalRecovered                 = 'baker-dal-recovered';
    const BakerDalDenounced                 = 'baker-dal-denounced';
    const GovernancePeriod                  = 'governance-period';
    const GovernanceProposal                = 'governance-proposal';
    const GovernanceVoteReminder            = 'governance-vote-reminder';

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    const Exchange_CoinsToToken             = 'exchange-coins-to-token';
    const Exchange_TokenToCoins             = 'exchange-token-to-coins';
    const Exchange_TokenToToken             = 'exchange-token-to-token';

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    const Liquidity_Invest                  = 'liquidity-invest';
}
