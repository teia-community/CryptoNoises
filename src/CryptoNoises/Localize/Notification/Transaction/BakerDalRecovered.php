<?php

namespace CryptoNoises\Localize\Notification\Transaction;

use CryptoNoises\Entities\User;
use CryptoNoises\Localize\NotificationButton;

/**
 * The user's baker is back above the DAL participation threshold this cycle (BakerAlerts).
 *
 * Placeholders are bound in AbstractBot_Notification_Events::bindBakerEvent(); a line whose value is unknown there
 * is dropped.
 */
class BakerDalRecovered extends AbstractTransaction
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
                '✅ <a href="{###BAKER_URL###}">{###BAKER_NAME###}</a> is back above the DAL participation threshold',
                '- Cycle {###CYCLE###}: attested {###DAL_PARTICIPATION###}',
                '- Required to earn DAL rewards: {###DAL_REQUIRED###}',
                '- Expected DAL rewards: {###DAL_REWARDS###}',
            ];
        }
        else if (self::IsDiscord())
        {
            $this->title = ['{###BAKER_NAME###} is back above the DAL participation threshold'];
            $this->url   = '{###BAKER_URL###}';

            $this->description = [
                '- Cycle {###CYCLE###}: attested {###DAL_PARTICIPATION###}',
                '- Required to earn DAL rewards: {###DAL_REQUIRED###}',
                '- Expected DAL rewards: {###DAL_REWARDS###}',
            ];

            $this->color = self::COLOR_GREEN;
        }

        $this->buttons[] = [
                NotificationButton::Link('Baker on TzKT', '{###BAKER_URL###}'),
        ];
    }
}
