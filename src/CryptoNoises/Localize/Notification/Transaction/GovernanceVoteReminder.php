<?php

namespace CryptoNoises\Localize\Notification\Transaction;

use CryptoNoises\Entities\User;
use CryptoNoises\Localize\NotificationButton;

/**
 * The current vote closes within a day and the user's baker has not voted (sent once per period).
 *
 * Placeholders are bound in AbstractBot_Notification_Events::bindBakerEvent(); a line whose value is unknown there
 * is dropped.
 */
class GovernanceVoteReminder extends AbstractTransaction
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
                '⏰ <a href="{###BAKER_URL###}">{###BAKER_NAME###}</a> has not voted yet',
                '- {###PERIOD_KIND###}: {###PROPOSAL###}',
                '- {###PROPOSALS_COUNT###}',
                '- Closes: <b>{###PERIOD_END###}</b>',
            ];
        }
        else if (self::IsDiscord())
        {
            $this->title = ['{###BAKER_NAME###} has not voted yet'];
            $this->url   = '{###BAKER_URL###}';

            $this->description = [
                '- {###PERIOD_KIND###}: {###PROPOSAL###}',
                '- {###PROPOSALS_COUNT###}',
                '- Closes: {###PERIOD_END###}',
            ];

            $this->color = self::COLOR_ORANGE;
        }

        $this->buttons[] = [
                NotificationButton::Link('Governance', '{###GOVERNANCE_URL###}'),
        ];
    }
}
