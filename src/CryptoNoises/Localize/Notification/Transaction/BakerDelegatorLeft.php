<?php

namespace CryptoNoises\Localize\Notification\Transaction;

use CryptoNoises\Entities\User;
use CryptoNoises\Localize\NotificationButton;

/**
 * A delegator stopped delegating to the user's baker, or moved to another one.
 *
 * Placeholders are bound in AbstractBot_Notification_Events::bindBakerEvent(); a line whose value is unknown there
 * is dropped.
 */
class BakerDelegatorLeft extends AbstractTransaction
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
                '🧑‍🍳 A delegator left <a href="{###BAKER_URL###}">{###BAKER_NAME###}</a>',
                '- Delegator: '.$this->presetSenderProfile(),
                '- Balance: <b>{###AMOUNT_TOTAL###}</b>',
                '- Now delegates to: {###OTHER_BAKER###}',
            ];
        }
        else if (self::IsDiscord())
        {
            $this->title = ['A delegator left {###BAKER_NAME###}'];
            $this->url   = '{###BAKER_URL###}';

            $this->description = [
                '- Delegator: '.$this->presetSenderProfile(),
                '- Balance: {###AMOUNT_TOTAL###}',
                '- Now delegates to: {###OTHER_BAKER###}',
            ];

            $this->color = self::COLOR_ORANGE;
        }

        $this->buttons[] = [
                NotificationButton::Link('Delegator', '{###SENDER_PROFILE_URL###}'),
        ];
    }
}
