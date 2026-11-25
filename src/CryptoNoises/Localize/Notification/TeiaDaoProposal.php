<?php

namespace CryptoNoises\Localize\Notification;

use CryptoNoises\Entities\User;
use CryptoNoises\Localize\Notification;

/** A new Teia DAO proposal, for everyone. Bound in AbstractBot_Notification_Events. */
class TeiaDaoProposal extends Notification
{
    public ?bool $discord_isAllowEphemeralResponse  = false;

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
                '🗳 New Teia DAO proposal: <b>{###PROPOSAL_TITLE###}</b>',
                '- Proposed by: {###ISSUER###}',
                '- Voting ends: {###ENDS###}',
            ];
        }
        else if (self::IsDiscord())
        {
            $this->title = ['New Teia DAO proposal: {###PROPOSAL_TITLE###}'];
            $this->description = [
                '- Proposed by: {###ISSUER###}',
                '- Voting ends: {###ENDS###}',
            ];
            $this->color = self::COLOR_VIOLET;
        }
    }
}
