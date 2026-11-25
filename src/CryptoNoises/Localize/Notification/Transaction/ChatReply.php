<?php

namespace CryptoNoises\Localize\Notification\Transaction;

use CryptoCore\Setapp\TezCool;
use CryptoNoises\Entities\User;
use CryptoNoises\Localize\NotificationButton;

/**
 * Somebody replied to the wallet's message in the Trollbox (trollbox.tez.cool).
 *
 * The message text is not shown on purpose: any wallet can send one, and a bot notification must not carry what a
 * stranger wrote. The button opens the chat, where it is read.
 */
class ChatReply extends AbstractTransaction
{
    public static function I(?User $user): self
    {
        return new self($user);
    }

    public function __construct(?User $user)
    {
        parent::__construct($user);

        if (self::IsTelegram())
        {
            $this->description = [
                '💬 New reply to your message in the Trollbox',
                '- From: '.$this->presetSenderProfile(),
            ];
        }
        else if (self::IsDiscord())
        {
            $this->title = ['New reply to your message in the Trollbox'];
            $this->url   = TezCool::TrollboxUrl;

            $this->description = [
                '- From: '.$this->presetSenderProfile(),
            ];

            $this->color = self::COLOR_BLUE;
        }

        $this->buttons[] = [
            NotificationButton::Link('Open the Trollbox', TezCool::TrollboxUrl),
        ];

        $this->footer = []; // reset!
    }
}
