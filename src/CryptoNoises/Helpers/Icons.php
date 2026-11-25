<?php

namespace CryptoNoises\Helpers;

use CryptoNoises\Config;

class Icons
{
    public static function GetWalletIcons(): array
    {
        if (Config::cfg()->usesTelegramLayout())
        {
            return [
                // Should be 8
                'empty'     => ' ',
                'digit1'    => '1️⃣',
                'digit2'    => '2️⃣',
                'digit3'    => '3️⃣',
                'digit4'    => '4️⃣',
                'digit5'    => '5️⃣',
                'red-a'     => '🅰️',
                'red-b'     => '🅱️',

                // Should be 8
                'square1'   => '🟥',
                'square2'   => '🟧',
                'square3'   => '🟨',
                'square4'   => '🟩',
                'square5'   => '🟦',
                'square6'   => '🟪',
                'square7'   => '⬜️',
                'square8'   => '⬛️',

                // Should be 8
                'heart1'    => '❤️',
                'heart2'    => '🧡',
                'heart3'    => '💛',
                'heart4'    => '💚',
                'heart5'    => '💙',
                'heart6'    => '💜',
                'heart7'    => '🤍',
                'heart8'    => '🖤',

                // Should be 8
                'fire'      => '🔥',
                'star'      => '⭐️',
                'light'     => '⚡️',
                'diamond'   => '💎',
                'leaf'      => '🍀',
                'fox'       => '🦊',
                'arcade'    => '👾',
                'treble'    => '🎼️',
            ];
        }
        if (Config::cfg()->isDiscordPlatform())
        {
            return [
                // Should be 5
                'empty'     => ' ',
                'digit1'    => '1️⃣',
                'digit2'    => '2️⃣',
                'digit3'    => '3️⃣',
                'digit4'    => '4️⃣',

                // Should be 5
                'square1'   => '🟥',
                'square2'   => '🟧',
                'square3'   => '🟨',
                'square4'   => '🟩',
                'square5'   => '🟦',

                // Should be 5
                'heart1'    => '❤️',
                'heart2'    => '🧡',
                'heart3'    => '💛',
                'heart4'    => '💚',
                'heart5'    => '💙',

                // Should be 5
                'fire'      => '🔥',
                'star'      => '⭐️',
                'light'     => '⚡️',
                'diamond'   => '💎',
                'leaf'      => '🍀',

                // Should be 4
                'fox'       => '🦊',
                'arcade'    => '👾',
                'robot'     => '🤖',
                'treble'    => '🎼️',
                // 5th will be "back"
            ];
        }

        return [];
    }

    public static function GetWalletIcon($iconId): ?string
    {
        return self::GetWalletIcons()[$iconId] ?? null;
    }
}
