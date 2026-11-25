<?php

namespace CryptoNoises\Localize\Notification\Transaction;

use CryptoNoises\Entities\User;
use CryptoNoises\Localize\NotificationButton;

/**
 * Someone staked tez with the user's baker.
 *
 * Placeholders are bound in AbstractBot_Notification_Events::bindBakerEvent(); a line whose value is unknown there
 * is dropped.
 */
class BakerStakerStake extends AbstractTransaction
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
                '🥩 New stake with <a href="{###BAKER_URL###}">{###BAKER_NAME###}</a>',
                '- Staker: '.$this->presetSenderProfile(),
                '- Staked: <b>{###AMOUNT_TOTAL###}</b>',
            ];
        }
        else if (self::IsDiscord())
        {
            $this->title = ['New stake with {###BAKER_NAME###}'];
            $this->url   = '{###BAKER_URL###}';

            $this->description = [
                '- Staker: '.$this->presetSenderProfile(),
                '- Staked: {###AMOUNT_TOTAL###}',
            ];

            $this->color = self::COLOR_GREEN;
        }

        $this->buttons[] = [
                NotificationButton::Link('Staker', '{###SENDER_PROFILE_URL###}'),
        ];
    }
}
