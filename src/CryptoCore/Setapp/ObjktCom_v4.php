<?php

namespace CryptoCore\Setapp;

/**
 * objkt.com's current generation, live since 2024-11-05 and still taking the majority of its traffic.
 *
 * The names here continue this project's own v1/v2/v3 sequence. objkt.com calls these contracts
 * "marketplace v6.2" and "english auction v6.1", and its own version numbers do not line up with ours:
 *
 *   this project   objkt.com's name            contract
 *   v1             marketplace v1              KT1FvqJwEDWb1Gwc55Jd1jjTHRVWbYKUUpyq
 *   v2             marketplace v4              KT1WvzYHCNBvDSdwafTHv7nJ1dWmZ8GCYuuC
 *   v3a            marketplace v6              KT1CePTyk6fk4cFr6fasY5YXPGks6ttjSLp4
 *   v3             marketplace v6.1            KT1Xjap1TwmDR1d8yEd8ErkraAj2mbdMrPZY
 *   v4 (this file) marketplace v6.2            KT1SwbTqhSKF6Pdokiu1K4Fpi17ahPPzmt1X
 *
 * The parameters, the bigmap values and the fee wallet are the same as v3's; v6.2 only adds gallery
 * entrypoints (ask_gallery, fulfill_offer_gallery) and nullable extras (start_time, exhibition_id,
 * target) that the existing handlers ignore. That is why this is configuration and not another parser.
 *
 * There is no new dutch auction contract: objkt.com's current one is "dutch v6", which is
 * ObjktCom_v3::DutchAuctionsKTID.
 */
class ObjktCom_v4
{
    public const MarketplaceKTID                    = 'KT1SwbTqhSKF6Pdokiu1K4Fpi17ahPPzmt1X';
    public const EnglishAuctionsKTID                = 'KT1HFYHtPL2z6jWn6r7SQNSAx2rKRDuntuuG';
    public const MarketplaceFeeWallet               = 'tz1hFhmqKNB7hnHVHAFSk9wNqm7K9GgF2GDN';

    public const Bigmap_Swaps                       = 684371;       # KT1SwbTqhSKF6Pdokiu1K4Fpi17ahPPzmt1X / asks
    public const Bigmap_AuctionsEnglish             = 684374;       # KT1HFYHtPL2z6jWn6r7SQNSAx2rKRDuntuuG / auctions
    public const Bigmap_Offers                      = 684373;       # KT1SwbTqhSKF6Pdokiu1K4Fpi17ahPPzmt1X / offers

    // objkt.com's "project offers v1", live since 2026-07: offers made on a whole collection rather than one
    // token, on their own contract. An offer names its collection by (fa_contract, collection_id).
    public const ProjectOffersKTID                  = 'KT19NMJYBC4AiprtetMW1K2damXgGRCbJWD5';
    public const Bigmap_CollectionOffers            = 816255;       # KT19NMJYBC4AiprtetMW1K2damXgGRCbJWD5 / collection_offers
}
