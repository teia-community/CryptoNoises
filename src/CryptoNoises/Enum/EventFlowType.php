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

    const DropPurchase                      = 'drop-purchase';                // bought from a drop, piece minted later

    const TokenComment                      = 'token-comment';                 // somebody commented on a token
    const TokenCommentReply                 = 'token-comment-reply';            // ... answering another comment

    const ChatMessage                       = 'chat-message';                   // a message sent to a wallet (Chad Chat)
    const ChatReply                         = 'chat-reply';                     // a reply to the wallet's chat message (Trollbox)

    const AuctionStart                      = 'auction-start';
    const AuctionEndFailed                  = 'auction-failed';
    const AuctionCancel                     = 'auction-cancel';
    const AuctionBid                        = 'auction-bid';
    const AuctionOutBid                     = 'auction-outbid';

    const DomainExpires                     = 'domain-expires';
    const DomainExpired                     = 'domain-expired';

    // Account-level operations (account_operations). event_details carries the variant: the delegation kind,
    // or the staking action. target_address is always the wallet the event is about.
    const Delegation                        = 'delegation';                     // set | change | remove
    const Staking                           = 'staking';                        // stake | unstake | finalize
    const BakerParameters                   = 'baker-parameters';               // one event per affected wallet
    const Origination                       = 'origination';
    const UnstakeFinalizable                = 'unstake-finalizable';          // reminder: one per unstake request and user

    // For bakers. target_address is the baker.
    const BakerDelegator                    = 'baker-delegator';                // joined | left
    const BakerStaker                       = 'baker-staker';                   // stake | unstake
    const BakerMissedRights                 = 'baker-missed-rights';            // missed attestations and blocks
    const BakerDal                          = 'baker-dal';                      // insufficient | recovered | denounced
    const Governance                        = 'governance';                     // period | proposal | vote-reminder

    const TeiaEvent                         = 'teia-event';
    const TeiaGovernance                    = 'teia-governance';                // multisig-proposal | multisig-reminder | dao-proposal | poll                     // a new event on teia.art/events, for everyone

    const CollectionOffer                   = 'collection-offer';

    const ExchangeCoinsToToken              = 'exchange-coins-to-token';
    const ExchangeTokenToCoins              = 'exchange-token-to-coins';
    const ExchangeTokenToToken              = 'exchange-token-to-token';

    const LiquidityInvest                   = 'liquidity-invest';

    const Skipped                           = 'skipped';
}
