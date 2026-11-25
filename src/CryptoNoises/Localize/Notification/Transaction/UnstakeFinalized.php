<?php

namespace CryptoNoises\Localize\Notification\Transaction;

use CryptoNoises\Entities\User;
use CryptoNoises\Localize\NotificationButton;

/**
 * Unstaked tez were finalized and are spendable again - by the wallet itself, or by
 * someone finalizing for it (the "Finalized by" line appears only then).
 *
 * Placeholders are bound in AbstractBot_Notification_Events::bindAccountEvent(); a line whose value is unknown
 * there is dropped.
 */
class UnstakeFinalized extends AbstractTransaction
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
                '✅ <b>{###AMOUNT_TOTAL###}</b> unstaked from <a href="{###BAKER_URL###}">{###BAKER_NAME###}</a> are spendable again',
                '{###AMOUNT_TOTAL_CONVERTED###}',
                '- Finalized by: {###OPERATED_BY###}',
            ];
        }
        else if (self::IsDiscord())
        {
            $this->title = ['{###AMOUNT_TOTAL###} unstaked from {###BAKER_NAME###} are spendable again'];
            $this->url   = '{###BAKER_URL###}';

            $this->description = [
                '{###AMOUNT_TOTAL_CONVERTED###}',
                '- Finalized by: {###OPERATED_BY###}',
            ];

            $this->buttons[] = [
                NotificationButton::Link('Baker', '{###BAKER_URL###}'),
            ];

            $this->color = self::COLOR_GREEN;
        }
    }
}
