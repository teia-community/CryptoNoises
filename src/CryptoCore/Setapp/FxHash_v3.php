<?php

namespace CryptoCore\Setapp;

/**
 * fx(hash)'s current marketplace, live since 2022-09 and still trading.
 *
 * Same entrypoint names as v2 (listing, listing_accept, listing_cancel), but a listing is shaped differently:
 * the token is named by an `asset` record holding `{amount, asset_id, contract_id}`, where contract_id is a key
 * into this contract's own `asset_contracts` bigmap rather than an FA2 address. Only one of those entries is
 * enabled on chain, but the indirection is part of the contract, so it is resolved rather than assumed.
 *
 * The fee wallet is the same as v2's, which is visible in the payouts of any listing_accept.
 *
 * `offer`, `offer_accept` and `offer_cancel` exist on the contract and have never been called; the offers
 * bigmap is empty. They are recognised so they cannot show up as an unknown contract, and nothing more.
 */
class FxHash_v3 extends FxHash
{
    public const MarketplaceKTID                    = 'KT1M1NyU9X4usEimt2f3kDaijZnDMNBu42Ja';
    public const MarketplaceFeeWallet               = FxHash_v2::MarketplaceFeeWallet;

    public const Bigmap_Swaps                       = 283916;       # KT1M1NyU9X4usEimt2f3kDaijZnDMNBu42Ja / listings
    public const Bigmap_Offers                      = 283918;       # KT1M1NyU9X4usEimt2f3kDaijZnDMNBu42Ja / offers
    public const Bigmap_AssetContracts              = 283913;       # KT1M1NyU9X4usEimt2f3kDaijZnDMNBu42Ja / asset_contracts
}
