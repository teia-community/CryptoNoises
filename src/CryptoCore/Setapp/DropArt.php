<?php

namespace CryptoCore\Setapp;

/**
 * drop.art, a primary-market drop contract: you buy from a project and your piece is minted to you shortly
 * afterwards, rather than being transferred from a seller.
 *
 * That shape is why a buy needs its own handling. The operation carries no token at all:
 *
 *   buy           -> drop.art            14.100000 tez   from the buyer
 *   (tez)         -> a service wallet     0.100000 tez
 *   execute_sale  -> the sale contract   14.000000 tez
 *   (tez)         -> the creator         11.200000 tez
 *   (tez)         -> the platform         2.800000 tez
 *
 * What *is* knowable at buy time is the project, and through it the token contract and the creator: the buy
 * names a project_id, and the `projects` bigmap maps that to {fa2, creator, n_tokens, n_sold}. The creator there
 * is the same wallet that receives the largest payout, which is how the split above is attributed.
 *
 * The one thing that is not knowable is which piece: the mint happens a few levels later (measured: 11 levels,
 * about three minutes) and only then does a token id exist. The buyer hears about that separately, through the
 * ordinary mint notification, with the artwork - so a drop purchase names the collection and says the piece is
 * assigned on mint rather than pretending to know it.
 */
class DropArt
{
    public const MarketplaceKTID                    = 'KT1NzD6ALgjzr86pZ2uiHN762Aizx3QbRdDE';

    public const Bigmap_Projects                    = 785719;   # KT1NzD6ALgjzr86pZ2uiHN762Aizx3QbRdDE / projects
    public const Bigmap_PurchaseQueue               = 785720;   # KT1NzD6ALgjzr86pZ2uiHN762Aizx3QbRdDE / purchase_queue
}
