<?php

namespace CryptoNoises\Localize\Notification\Transaction;

use CryptoNoises\Entities\User;
use CryptoNoises\Localize\NotificationButton;

/**
 * Someone unstaked tez from the user's baker.
 *
 * Placeholders are bound in AbstractBot_Notification_Events::bindBakerEvent(); a line whose value is unknown there
 * is dropped.
 */
class BakerStakerUnstake extends AbstractTransaction
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
                '🥩 Unstake from <a href="{###BAKER_URL###}">{###BAKER_NAME###}</a>',
                '- Staker: '.$this->presetSenderProfile(),
                '- Unstaked: <b>{###AMOUNT_TOTAL###}</b>',
            ];
        }
        else if (self::IsDiscord())
        {
            $this->title = ['Unstake from {###BAKER_NAME###}'];
            $this->url   = '{###BAKER_URL###}';

            $this->description = [
                '- Staker: '.$this->presetSenderProfile(),
                '- Unstaked: {###AMOUNT_TOTAL###}',
            ];

            $this->color = self::COLOR_ORANGE;
        }

        $this->buttons[] = [
                NotificationButton::Link('Staker', '{###SENDER_PROFILE_URL###}'),
        ];
    }
}
