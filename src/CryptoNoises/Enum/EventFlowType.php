<?php

namespace CryptoNoises\Enum;

abstract class EventFlowType
{
    const CoinTransfer                      = 'coin-transfer';

    const TokenTransfer                     = 'token-transfer';
    const TokenTransfer_Send                = 'token-transfer-send';            // This is like TokenTransfer but need to group by 'sender' address (+allow ignore 'target')
    const TokenTransfer_Receive             = 'token-transfer-receive';         // This is like TokenTransfer but need to group by 'target' address (+allow ignore 'sender')
    const TokenPurchase                     = 'token-purchase';
    const TokenOffer                        = 'token-offer';

    const TokenList                         = 'token-list';
    const TokenMint                         = 'token-mint';
    const TokenMintIncome                   = 'token-mint-income';              // This is for fx(hash) only!
    const TokenMintIncome_Ticket            = 'token-mint-income:ticket';       // This is for fx(hash) only!
    const TokenMintExpense                  = 'token-mint-expense';             // This is for fx(hash) only!
    const TokenMintExpense_Ticket           = 'token-mint-expense:ticket';      // This is for fx(hash) only!
    const TokenUpdateMetadata               = 'token-update-metadata';

    const TokenComment                      = 'token-comment';                 // somebody commented on a token
    const TokenCommentReply                 = 'token-comment-reply';            // ... answering another comment

    const AuctionStart                      = 'auction-start';
    const AuctionEndFailed                  = 'auction-failed';
    const AuctionCancel                     = 'auction-cancel';
    const AuctionBid                        = 'auction-bid';
    const AuctionOutBid                     = 'auction-outbid';

    const DomainExpires                     = 'domain-expires';
    const DomainExpired                     = 'domain-expired';

    const CollectionOffer                   = 'collection-offer';

    const ExchangeCoinsToToken              = 'exchange-coins-to-token';
    const ExchangeTokenToCoins              = 'exchange-token-to-coins';
    const ExchangeTokenToToken              = 'exchange-token-to-token';

    const LiquidityInvest                   = 'liquidity-invest';

    const Skipped                           = 'skipped';
}
