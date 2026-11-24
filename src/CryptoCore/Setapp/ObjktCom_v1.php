<?php

namespace CryptoCore\Setapp;

class ObjktCom_v1
{
    public const MinterKTID                         = 'KT1Aq4wWmVanpQhq4TTfjZXB5AjFpx15iQMM';

    public const MarketplaceKTID                    = 'KT1FvqJwEDWb1Gwc55Jd1jjTHRVWbYKUUpyq';
    public const EnglishAuctionsKTID                = 'KT1XjcRq5MLAzMKQ3UHsrue2SeU2NbxUrzmU';
    public const DutchAuctionsKTID                  = 'KT1QJ71jypKGgyTNtXjkCAYJZNhCKWiHuT2r';
    public const MarketplaceFeeWallet               = 'tz1hFhmqKNB7hnHVHAFSk9wNqm7K9GgF2GDN';

    // objkt.com's very first auction contracts, both deployed 2021-06-30, five days before the pair above and
    // with the same entrypoints and the same bigmap values. They stopped being used in 2022 (english) and
    // 2022-03 (dutch), but they carry Teia/hic et nunc history a sync from block 1 would otherwise miss:
    // around 500 HEN events on the english one, 119 on the dutch one worth 22,047 tez.
    public const EnglishAuctionsKTID_pre            = 'KT1Wvk8fon9SgNEPQKewoSL2ziGGuCQebqZc';
    public const DutchAuctionsKTID_pre              = 'KT1ET45vnyEFMLS9wX1dYHEs9aCN3twDEiQw';

    // objkt.com's dutch auction v2, same generation and same entrypoints as the two above. One call in its
    // whole life and an empty auctions bigmap, but it is wired so the address is never an unknown contract.
    public const DutchAuctionsKTID_v2               = 'KT1W8PqJsZcpcAgDQH9SKQSZKvjVbpjUk8Sc';
    public const Bigmap_AuctionsDutch_v2            = 6015;         # KT1W8PqJsZcpcAgDQH9SKQSZKvjVbpjUk8Sc / auctions

    public const Bigmap_AuctionsEnglish_pre         = 5904;         # KT1Wvk8fon9SgNEPQKewoSL2ziGGuCQebqZc / auctions
    public const Bigmap_AuctionsDutch_pre           = 5907;         # KT1ET45vnyEFMLS9wX1dYHEs9aCN3twDEiQw / auctions

    public const Bigmap_Swaps                       = 5909;         # KT1FvqJwEDWb1Gwc55Jd1jjTHRVWbYKUUpyq / asks
    public const Bigmap_AuctionsEnglish             = 6210;         # KT1XjcRq5MLAzMKQ3UHsrue2SeU2NbxUrzmU / auctions
    public const Bigmap_AuctionsDutch               = 6212;         # KT1QJ71jypKGgyTNtXjkCAYJZNhCKWiHuT2r / auctions
    public const Bigmap_Offers                      = 5910;         # KT1FvqJwEDWb1Gwc55Jd1jjTHRVWbYKUUpyq / bids
}
