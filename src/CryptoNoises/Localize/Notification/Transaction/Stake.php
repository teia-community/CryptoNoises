<?php

namespace CryptoNoises\Localize\Notification\Transaction;

use CryptoNoises\Entities\User;
use CryptoNoises\Localize\NotificationButton;

/**
 * Your wallet staked tez with its baker.
 *
 * Placeholders are bound in AbstractBot_Notification_Events::bindAccountEvent(); a line whose value is unknown
 * there is dropped.
 */
class Stake extends AbstractTransaction
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
                '🥩 Staked <b>{###AMOUNT_TOTAL###}</b> with <a href="{###BAKER_URL###}">{###BAKER_NAME###}</a>',
                '{###AMOUNT_TOTAL_CONVERTED###}',
            ];
        }
        else if (self::IsDiscord())
        {
            $this->title = ['Staked {###AMOUNT_TOTAL###} with {###BAKER_NAME###}'];
            $this->url   = '{###BAKER_URL###}';

            $this->description = [
                '{###AMOUNT_TOTAL_CONVERTED###}',
            ];

            $this->buttons[] = [
                NotificationButton::Link('Baker', '{###BAKER_URL###}'),
            ];

            $this->color = self::COLOR_GREEN;
        }
    }
}
