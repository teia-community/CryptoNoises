<?php

namespace CryptoNoises\Localize\Notification\Transaction;

use CryptoNoises\Entities\User;
use CryptoNoises\Localize\NotificationButton;

/**
 * A protocol proposal was submitted in the current proposal period (sent to bakers).
 *
 * Placeholders are bound in AbstractBot_Notification_Events::bindBakerEvent(); a line whose value is unknown there
 * is dropped.
 */
class GovernanceProposal extends AbstractTransaction
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
                '🗳 New protocol proposal: <b>{###PROPOSAL###}</b>',
                '- Proposed by: {###INITIATOR###}',
                '- Upvoting closes: {###PERIOD_END###}',
            ];
        }
        else if (self::IsDiscord())
        {
            $this->title = ['New protocol proposal: {###PROPOSAL###}'];
            $this->url   = '{###BAKER_URL###}';

            $this->description = [
                '- Proposed by: {###INITIATOR###}',
                '- Upvoting closes: {###PERIOD_END###}',
            ];

            $this->color = self::COLOR_BLUE;
        }

        $this->buttons[] = [
                NotificationButton::Link('Proposal', '{###GOVERNANCE_URL###}'),
        ];
    }
}
