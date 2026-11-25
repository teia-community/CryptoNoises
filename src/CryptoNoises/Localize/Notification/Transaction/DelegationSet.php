<?php

namespace CryptoNoises\Localize\Notification\Transaction;

use CryptoNoises\Entities\User;
use CryptoNoises\Localize\NotificationButton;

/**
 * Your wallet started delegating to a baker.
 *
 * Placeholders are bound in AbstractBot_Notification_Events::bindAccountEvent(); a line whose value is unknown
 * there is dropped.
 */
class DelegationSet extends AbstractTransaction
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
                '🥩 Your wallet now delegates to <b><a href="{###BAKER_URL###}">{###BAKER_NAME###}</a></b>',
                '- Delegated balance: <b>{###AMOUNT_TOTAL###}</b>',
            ];
        }
        else if (self::IsDiscord())
        {
            $this->title = ['Your wallet now delegates to {###BAKER_NAME###}'];
            $this->url   = '{###BAKER_URL###}';

            $this->description = [
                '- Delegated balance: {###AMOUNT_TOTAL###}',
            ];

            $this->buttons[] = [
                NotificationButton::Link('Baker', '{###BAKER_URL###}'),
            ];

            $this->color = self::COLOR_BLUE;
        }
    }
}
