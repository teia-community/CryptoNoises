<?php

namespace CryptoNoises\Localize\Notification\Transaction;

use CryptoNoises\Entities\User;
use CryptoNoises\Localize\NotificationButton;

/**
 * Reminder: unstaked tez unlocked a while ago and are still not finalized. Sent once per unstake request, after
 * the delay the user chose in Settings ("Finalize reminder"), and only if nobody has finalized them since.
 *
 * Placeholders are bound in AbstractBot_Notification_Events::bindAccountEvent(); a line whose value is unknown
 * there is dropped.
 */
class UnstakeFinalizable extends AbstractTransaction
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
                '⏰ <b>{###AMOUNT_TOTAL###}</b> unstaked from <a href="{###BAKER_URL###}">{###BAKER_NAME###}</a> can be finalized',
                '{###AMOUNT_TOTAL_CONVERTED###}',
                '- Unlocked: {###UNLOCK###}',
                '- Until they are finalized they cannot be spent',
            ];
        }
        else if (self::IsDiscord())
        {
            $this->title = ['{###AMOUNT_TOTAL###} unstaked from {###BAKER_NAME###} can be finalized'];
            $this->url   = '{###BAKER_URL###}';

            $this->description = [
                '{###AMOUNT_TOTAL_CONVERTED###}',
                '- Unlocked: {###UNLOCK###}',
                '- Until they are finalized they cannot be spent',
            ];

            $this->buttons[] = [
                NotificationButton::Link('Baker', '{###BAKER_URL###}'),
            ];

            $this->color = self::COLOR_ORANGE;
        }

        // Finalizing takes a wallet signature; Tezos' own staking page does it for any staker
        $this->buttons[] = [
            NotificationButton::Link('Finalize on stake.tezos.com', self::kSTAKING_URL),
        ];
    }

    const kSTAKING_URL = 'https://stake.tezos.com/';
}
