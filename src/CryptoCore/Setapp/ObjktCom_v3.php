<?php

namespace CryptoCore\Setapp;

class ObjktCom_v3
{
    public const MarketplaceKTID                    = 'KT1Xjap1TwmDR1d8yEd8ErkraAj2mbdMrPZY';
    public const EnglishAuctionsKTID                = 'KT18iSHoRW1iogamADWwQSDoZa3QkN4izkqj';
    public const DutchAuctionsKTID                  = 'KT1S2Yz1a2Qn6nwLxozsyAiRwwcSRE8Q5E7E';
    public const MarketplaceFeeWallet               = 'tz1hFhmqKNB7hnHVHAFSk9wNqm7K9GgF2GDN';

    // objkt.com's english auction v5 and v5.1, the two before english v6. Same entrypoints and the same bigmap
    // values as v6 (shares as an address => amount map), so they read through the same handlers.
    public const EnglishAuctionsKTID_v5             = 'KT1M6r2gRigUYP3tCSEjEptRnNG8qRLSRqcT';
    public const EnglishAuctionsKTID_v51            = 'KT1FJESNMf1WzRQva9Y5DPerKpgsDgRXYqyK';

    public const Bigmap_AuctionsEnglish_v5          = 384417;       # KT1M6r2gRigUYP3tCSEjEptRnNG8qRLSRqcT / auctions
    public const Bigmap_AuctionsEnglish_v51         = 471689;       # KT1FJESNMf1WzRQva9Y5DPerKpgsDgRXYqyK / auctions

    public const Bigmap_Swaps                       = 591024;       # KT1Xjap1TwmDR1d8yEd8ErkraAj2mbdMrPZY / asks
    public const Bigmap_AuctionsEnglish             = 574016;       # KT18iSHoRW1iogamADWwQSDoZa3QkN4izkqj / auctions
    public const Bigmap_AuctionsDutch               = 574018;       # KT1S2Yz1a2Qn6nwLxozsyAiRwwcSRE8Q5E7E / auctions
    public const Bigmap_Offers                      = 591026;       # KT1Xjap1TwmDR1d8yEd8ErkraAj2mbdMrPZY / offers
}
