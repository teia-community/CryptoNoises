<?php

namespace CryptoNoises\Localize\NotificationMatrix;

use CryptoNoises\Entities\User;
use CryptoNoises\Localize\Notification;

/**
 * What the Matrix bot does and its commands: notifications about watched wallets, managed with a few text commands
 * (Matrix has no bot menus like Telegram's buttons).
 */
class Help extends Notification
{
    public static function I(?User $user, bool $welcome = false): self
    {
        return new self($user, $welcome);
    }

    public function __construct(?User $user, bool $welcome = false)
    {
        parent::__construct($user);

        $this->title = [$welcome ? '👋 <b>Hi!</b> I notify you about your Tezos wallets: sales, offers, transfers, staking and more.'
                                 : '🤖 <b>What I can do</b>'];

        $this->description = [
            '<code>!add tz1… [name]</code> - watch a wallet (several: one per line)',
            '<code>!wallets</code> - the wallets you watch',
            '<code>!summary</code> - their balances',
            '<code>!remove</code> - stop watching a wallet',
            '<code>!settings</code> - notification types, digest, minimum amounts, reminders',
            '<code>!stop</code> - pause all notifications (send anything to resume)',
            '<code>!help</code> - this list',
        ];
    }
}
