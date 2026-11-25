<?php

namespace CryptoNoises\Localize\Notification\Transaction;

use CryptoNoises\Entities\User;
use CryptoNoises\Localize\NotificationButton;

/**
 * The user's baker was denounced for a DAL attestation this cycle: it loses the cycle's DAL rewards (BakerAlerts).
 *
 * Placeholders are bound in AbstractBot_Notification_Events::bindBakerEvent(); a line whose value is unknown there
 * is dropped.
 */
class BakerDalDenounced extends AbstractTransaction
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
                '🚨 <a href="{###BAKER_URL###}">{###BAKER_NAME###}</a> was denounced for a DAL attestation',
                '- Cycle {###CYCLE###}: its DAL rewards for the cycle are lost',
                '- Attested so far: {###DAL_PARTICIPATION###}',
            ];
        }
        else if (self::IsDiscord())
        {
            $this->title = ['{###BAKER_NAME###} was denounced for a DAL attestation'];
            $this->url   = '{###BAKER_URL###}';

            $this->description = [
                '- Cycle {###CYCLE###}: its DAL rewards for the cycle are lost',
                '- Attested so far: {###DAL_PARTICIPATION###}',
            ];

            $this->color = self::COLOR_RED;
        }

        $this->buttons[] = [
                NotificationButton::Link('Baker on TzKT', '{###BAKER_URL###}'),
        ];
    }
}
