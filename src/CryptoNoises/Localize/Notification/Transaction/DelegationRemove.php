<?php

namespace CryptoNoises\Localize\Notification\Transaction;

use CryptoNoises\Entities\User;
use CryptoNoises\Localize\NotificationButton;

/**
 * Your wallet stopped delegating.
 *
 * Placeholders are bound in AbstractBot_Notification_Events::bindAccountEvent(); a line whose value is unknown
 * there is dropped.
 */
class DelegationRemove extends AbstractTransaction
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
                '🥩 Your wallet no longer delegates to a baker',
                '- Previous baker: <a href="{###BAKER_URL###}">{###BAKER_NAME###}</a>',
                '- Balance: <b>{###AMOUNT_TOTAL###}</b>',
            ];
        }
        else if (self::IsDiscord())
        {
            $this->title = ['Your wallet no longer delegates to a baker'];
            $this->url   = '{###BAKER_URL###}';

            $this->description = [
                '- Previous baker: {###BAKER_NAME###}',
                '- Balance: {###AMOUNT_TOTAL###}',
            ];

            $this->buttons[] = [
                NotificationButton::Link('Previous baker', '{###BAKER_URL###}'),
            ];

            $this->color = self::COLOR_ORANGE;
        }
    }
}
