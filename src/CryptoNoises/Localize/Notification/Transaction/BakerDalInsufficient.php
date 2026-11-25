<?php

namespace CryptoNoises\Localize\Notification\Transaction;

use CryptoNoises\Entities\User;
use CryptoNoises\Localize\NotificationButton;

/**
 * The user's baker attests too few DAL slots this cycle: at this rate it loses the cycle's DAL rewards (BakerAlerts).
 *
 * Placeholders are bound in AbstractBot_Notification_Events::bindBakerEvent(); a line whose value is unknown there
 * is dropped.
 */
class BakerDalInsufficient extends AbstractTransaction
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
                '⚠️ <a href="{###BAKER_URL###}">{###BAKER_NAME###}</a> is below the DAL participation threshold',
                '- Cycle {###CYCLE###}: attested {###DAL_PARTICIPATION###}',
                '- Required to earn DAL rewards: {###DAL_REQUIRED###}',
                '- DAL rewards at stake: {###DAL_REWARDS###}',
                '- Check the DAL node: the cycle can still be saved',
            ];
        }
        else if (self::IsDiscord())
        {
            $this->title = ['{###BAKER_NAME###} is below the DAL participation threshold'];
            $this->url   = '{###BAKER_URL###}';

            $this->description = [
                '- Cycle {###CYCLE###}: attested {###DAL_PARTICIPATION###}',
                '- Required to earn DAL rewards: {###DAL_REQUIRED###}',
                '- DAL rewards at stake: {###DAL_REWARDS###}',
                '- Check the DAL node: the cycle can still be saved',
            ];

            $this->color = self::COLOR_ORANGE;
        }

        $this->buttons[] = [
                NotificationButton::Link('Baker on TzKT', '{###BAKER_URL###}'),
        ];
    }
}
