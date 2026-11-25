<?php

namespace CryptoNoises\Localize\Notification;

use CryptoNoises\Entities\User;
use CryptoNoises\Localize\Notification;

/** A new proposal on the Teia core team multisig, for a signer's watchers. Bound in AbstractBot_Notification_Events. */
class TeiaMultisigProposal extends Notification
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
                '🏛 New Teia multisig proposal <b>#{###PROPOSAL_ID###}</b>: {###PROPOSAL###}',
                '{###PROPOSAL_TEXT###}',
                '- Proposed by: {###ISSUER###}',
                '- Votes: {###VOTES###}',
                '- Expires: {###ENDS###}',
            ];
        }
        else if (self::IsDiscord())
        {
            $this->title = ['New Teia multisig proposal #{###PROPOSAL_ID###}'];
            $this->description = [
                '{###PROPOSAL_TEXT###}',
                '- Proposed by: {###ISSUER###}',
                '- Votes: {###VOTES###}',
                '- Expires: {###ENDS###}',
            ];
            $this->color = self::COLOR_VIOLET;
        }
    }
}
