<?php

namespace CryptoCore\Setapp;

/**
 * Two on-chain chats from tez.cool.
 *
 * Chad Chat (chat.tez.cool) delivers a message to a wallet as an NFT minted to it - public, or end-to-end encrypted
 * in the token metadata. Its "stealth" mode deliberately leaves no NFT, so those messages cannot be seen here. A mint
 * to oneself is the sender publishing their encryption keys, not a message.
 *
 * The Trollbox (trollbox.tez.cool) is one public room: `post_message(message, reply_to?)`, each message stored in the
 * `messages` bigmap with its author. A room message is addressed to nobody; a reply is addressed to the author of the
 * message it answers.
 */
class TezCool
{
    public const ChadChatKTID                       = 'KT1DenRaZdNgm1WM4vREJ2Zgx5EiH3aogDYZ';
    public const ChadChatUrl                        = 'https://chat.tez.cool/';

    public const TrollboxKTID                       = 'KT1Ek4Nxu4XpQykpGLwaNtUJSWKuVpXFUJoy';
    public const TrollboxUrl                        = 'https://trollbox.tez.cool/';
    public const Bigmap_TrollboxMessages            = 743755;   # KT1Ek4Nxu4XpQykpGLwaNtUJSWKuVpXFUJoy / messages
}
