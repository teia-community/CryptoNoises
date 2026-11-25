<?php

namespace CryptoNoises\Localize\Notification\Transaction;

use CryptoNoises\Entities\User;
use CryptoNoises\Localize\NotificationButton;

/**
 * Your wallet asked to unstake tez. They stay frozen until the unlock cycle, then need finalizing.
 *
 * Placeholders are bound in AbstractBot_Notification_Events::bindAccountEvent(); a line whose value is unknown
 * there is dropped.
 */
class Unstake extends AbstractTransaction
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
                '🥩 Unstaking <b>{###AMOUNT_TOTAL###}</b> from <a href="{###BAKER_URL###}">{###BAKER_NAME###}</a>',
                '{###AMOUNT_TOTAL_CONVERTED###}',
                '- Spendable once finalized, from: {###UNLOCK###}',
            ];
        }
        else if (self::IsDiscord())
        {
            $this->title = ['Unstaking {###AMOUNT_TOTAL###} from {###BAKER_NAME###}'];
            $this->url   = '{###BAKER_URL###}';

            $this->description = [
                '{###AMOUNT_TOTAL_CONVERTED###}',
                '- Spendable once finalized, from: {###UNLOCK###}',
            ];

            $this->buttons[] = [
                NotificationButton::Link('Baker', '{###BAKER_URL###}'),
            ];

            $this->color = self::COLOR_ORANGE;
        }
    }
}
