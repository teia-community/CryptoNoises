<?php

namespace CryptoNoises\Localize\Notification\Transaction;

use CryptoCore\Setapp\TezCool;
use CryptoNoises\Entities\User;
use CryptoNoises\Localize\NotificationButton;

/**
 * A message sent to the wallet on Chad Chat (chat.tez.cool), delivered as an NFT.
 *
 * The message text is not shown on purpose: any wallet can send one, and a bot notification must not carry what a
 * stranger wrote. The button opens the chat, where it is read.
 */
class ChatMessage extends AbstractTransaction
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
                '💬 New message on Chad Chat',
                '- From: '.$this->presetSenderProfile(),
                '- {###ENCRYPTED###}',
            ];
        }
        else if (self::IsDiscord())
        {
            $this->title = ['New message on Chad Chat'];
            $this->url   = TezCool::ChadChatUrl;

            $this->description = [
                '- From: '.$this->presetSenderProfile(),
                '- {###ENCRYPTED###}',
            ];

            $this->color = self::COLOR_BLUE;
        }

        $this->buttons[] = [
            NotificationButton::Link('Read it on Chad Chat', TezCool::ChadChatUrl),
        ];

        $this->footer = []; // reset!
    }
}
