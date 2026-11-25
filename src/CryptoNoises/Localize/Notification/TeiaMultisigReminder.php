<?php

namespace CryptoNoises\Localize\Notification;

use CryptoNoises\Entities\User;
use CryptoNoises\Localize\Notification;

/** A multisig proposal expires within a day and this signer has not voted. Bound in AbstractBot_Notification_Events. */
class TeiaMultisigReminder extends Notification
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
                '⏰ Teia multisig proposal <b>#{###PROPOSAL_ID###}</b> expires soon and this wallet has not voted',
                '- {###PROPOSAL###}',
                '- Votes: {###VOTES###}',
                '- Expires: <b>{###ENDS###}</b>',
            ];
        }
        else if (self::IsDiscord())
        {
            $this->title = ['Teia multisig proposal #{###PROPOSAL_ID###}: not voted yet'];
            $this->description = [
                '- {###PROPOSAL###}',
                '- Votes: {###VOTES###}',
                '- Expires: **{###ENDS###}**',
            ];
            $this->color = self::COLOR_ORANGE;
        }
    }
}
