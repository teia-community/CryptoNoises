<?php

namespace CryptoCore\Setapp;

/**
 * aleatory.art: fully on-chain generative art. A factory deploys one generator contract per artwork; a generator is
 * its own FA2 and mints a fresh token to whoever pays its `mint` (ALEATORY-001: `mint :: bytes -> unit`, payable,
 * emits a `mint` event carrying the token id). A shared marketplace trades the tokens with escrowed listings and
 * offers.
 *
 * Generators are not listed here on purpose: there are two factories already (Oct 2026: 54 generators, then 62 and
 * counting) and more will follow. A generator is recognised by the interface it declares in its metadata,
 * ALEATORY-001 - see AleatoryHunter.
 */
class Aleatory
{
    public const Interface                          = 'ALEATORY-001';

    // The first generator was originated here: no ALEATORY-001 call can be older
    public const FirstLevel                         = 15189152;

    public const MarketplaceKTID                    = 'KT1QwCe5eY8SnvWKpg7foZ7pMvNmxikFZ9wR';

    public const PlatformWallet                     = 'tz1ZYEXg8CSqdLx5ykLgRvXWJ6sZAFRko4Yw';     // "Aleatory.art"
    public const RenderProviderKTID                 = 'KT1DGd6RcA9zKErjLurg9JGQ8txNCLoFHkmv';     // paid "render gas" on every mint
}
