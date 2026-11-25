<?php

namespace CryptoCore\Setapp;

/**
 * objkt.com's generative mint module ("objkt.com Gallery - Generative Mint Module").
 *
 * A collector calls the module's `buy` with a project id; the module asks the project's pricing handler to split the
 * payment (artists, split contracts, objkt's treasury), then creates and mints a fresh token in the project's own
 * FA2 contract. So a `buy` here is a primary sale - a mint - not a trade of an existing token.
 *
 * Two generations, both dormant since: v1.0 (Jul 2024 - Feb 2025, 938 mints) and v1.3 (Mar 2025 - Jan 2026,
 * 804 mints). objkt lists the pricing handlers as marketplaces; collectors never call those directly.
 * A third registered handler, KT1KzmnX6Ffip7zVgGiCUV6ygqDU8hhGsMAy, has never been called.
 */
class ObjktComGenerative
{
    public const ModuleV1KTID                       = 'KT1HDNNQ3s9mgWoX25GqkUmEzbwWme7o426L';
    public const ModuleV13KTID                      = 'KT1QDeUhDN6ha95nBjxEynkCApQagXaQNykQ';

    public const PricingHandlerV1KTID               = 'KT1NiZkkW82wsTKP95x8FefdiseDyU9vX66W';
    public const PricingHandlerV11KTID              = 'KT1UwGGfWS91Z76Z6yq7WyDKWBVzWAcmhpnK';

    public const TreasuryWallet                     = 'tz1hFhmqKNB7hnHVHAFSk9wNqm7K9GgF2GDN';
}
