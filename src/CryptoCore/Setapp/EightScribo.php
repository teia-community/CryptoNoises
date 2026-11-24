<?php

namespace CryptoCore\Setapp;

/**
 * 8scribo, a hic et nunc style marketplace for text pieces (8scribo.xyz).
 *
 * Same trading model as hic et nunc - swap, collect, cancel_swap, with the listing kept in a `swaps` bigmap -
 * except that it was written for more than one token contract, so each swap carries its own `fa2`.
 */
class EightScribo
{
    public const MarketplaceKTID                    = 'KT19vw7kh7dzTRxFUZNWu39773baauzNWtzj';

    public const Bigmap_Swaps                       = 226249;   # KT19vw7kh7dzTRxFUZNWu39773baauzNWtzj / swaps
}
