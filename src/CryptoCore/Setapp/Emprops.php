<?php

namespace CryptoCore\Setapp;

/**
 * EmProps' first marketplace ("Emprops Market Legacy", Dec 2022 - Feb 2025): fixed-price secondary sales of the
 * tokens of one FA2 contract.
 *
 * Unusual in one way that matters here: a sale moves the token inside the token contract's own ledger, with no
 * `transfer` call, so nothing generic sees the token change hands. The payment is split by "Emprops Minter 2":
 * EmProps' treasury, a royalty, and the seller (named as `owner_token` in its `share_royalties` call).
 */
class Emprops
{
    public const MarketplaceKTID                    = 'KT1AML4jv2jMLKESGTApRZVoga5V5cSAMD2E';
    public const TokenKTID                          = 'KT1MhSRKsujc4q5b5KsXvmsvkFyht9h4meZs';
    public const MinterKTID                         = 'KT1Lm8Unv2NVhErDzcFdfkt8zpwFSjU1Uei7';

    public const TreasuryWallet                     = 'tz29vU3rcMcgdJi3E2PtZ2wQ9KUUxzbhoFoR';
}
