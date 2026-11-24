<?php

namespace CryptoCore\Setapp;

/**
 * Teia's on-chain comments on tokens (teia.art), live since 2024-10.
 *
 * Every post_comment call carries the (fa2_address, token_id) it belongs to, and the contract checks with
 * balance_of on that FA2 that the sender holds at least one unit before it writes the comment, so a comment
 * always comes from a collector of the work. The comment text itself is not on chain: `content` holds the bytes
 * of an ipfs:// URI, and the JSON behind it carries the message.
 *
 * The `comments` bigmap keeps one row per comment:
 *   { fa2_address, token_id, sender, content, parent_id, hidden, timestamp, version }
 * which is what a reply needs in order to find the comment it answers.
 */
class TeiaComments
{
    public const CommentsKTID                       = 'KT1FXFxUcZvne1ApoSaeZfvmDR73u2BsuFUP';

    // Teia also runs comments on DAO polls (KT1FrjJUWKXpJ9nQaNJcwjqCkjMsCcYU5y9o) and channel messages
    // (KT19ooSLPFxJQ5mx3kR4Qo2UY4KJDcdMdng9). Neither belongs to a token or a wallet, so neither is a
    // notification this bot can route to anybody.

    public const Bigmap_Comments                    = 809635;       # KT1FXFxUcZvne1ApoSaeZfvmDR73u2BsuFUP / comments
}
