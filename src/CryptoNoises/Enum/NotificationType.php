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

    const Exchange_CoinsToToken             = 'exchange-coins-to-token';
    const Exchange_TokenToCoins             = 'exchange-token-to-coins';
    const Exchange_TokenToToken             = 'exchange-token-to-token';

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    const Liquidity_Invest                  = 'liquidity-invest';
}
