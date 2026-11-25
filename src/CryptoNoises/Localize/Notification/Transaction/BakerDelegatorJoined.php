<?php

namespace CryptoNoises\Localize\Notification\Transaction;

use CryptoNoises\Entities\User;
use CryptoNoises\Localize\NotificationButton;

/**
 * A wallet started delegating to the user's baker.
 *
 * Placeholders are bound in AbstractBot_Notification_Events::bindBakerEvent(); a line whose value is unknown there
 * is dropped.
 */
class BakerDelegatorJoined extends AbstractTransaction
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
                '🧑‍🍳 New delegator for <a href="{###BAKER_URL###}">{###BAKER_NAME###}</a>',
                '- Delegator: '.$this->presetSenderProfile(),
                '- Delegated balance: <b>{###AMOUNT_TOTAL###}</b>',
                '- Came from: {###OTHER_BAKER###}',
            ];
        }
        else if (self::IsDiscord())
        {
            $this->title = ['New delegator for {###BAKER_NAME###}'];
            $this->url   = '{###BAKER_URL###}';

            $this->description = [
                '- Delegator: '.$this->presetSenderProfile(),
                '- Delegated balance: {###AMOUNT_TOTAL###}',
                '- Came from: {###OTHER_BAKER###}',
            ];

            $this->color = self::COLOR_GREEN;
        }

        $this->buttons[] = [
                NotificationButton::Link('Delegator', '{###SENDER_PROFILE_URL###}'),
        ];
    }
}
