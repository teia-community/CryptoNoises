<?php

namespace CryptoNoises\Localize\Notification\Transaction;

use CryptoNoises\Entities\User;
use CryptoNoises\Localize\NotificationButton;

/**
 * A new governance voting period started (sent to bakers).
 *
 * Placeholders are bound in AbstractBot_Notification_Events::bindBakerEvent(); a line whose value is unknown there
 * is dropped.
 */
class GovernancePeriod extends AbstractTransaction
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
                '🗳 New voting period: <b>{###PERIOD_KIND###}</b>',
                '- Proposal: {###PROPOSAL###}',
                '- Ends: {###PERIOD_END###}',
                '- Your baker: <a href="{###BAKER_URL###}">{###BAKER_NAME###}</a>',
            ];
        }
        else if (self::IsDiscord())
        {
            $this->title = ['New voting period: {###PERIOD_KIND###}'];
            $this->url   = '{###BAKER_URL###}';

            $this->description = [
                '- Proposal: {###PROPOSAL###}',
                '- Ends: {###PERIOD_END###}',
                '- Your baker: {###BAKER_NAME###}',
            ];

            $this->color = self::COLOR_BLUE;
        }

        $this->buttons[] = [
                NotificationButton::Link('Governance', '{###GOVERNANCE_URL###}'),
        ];
    }
}
