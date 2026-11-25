<?php

namespace CryptoNoises\Localize\Notification;

use CryptoNoises\Entities\User;
use CryptoNoises\Localize\Notification;

/** A new Teia community poll, for everyone. Bound in AbstractBot_Notification_Events. */
class TeiaPoll extends Notification
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
                '📊 New Teia poll: <b>{###PROPOSAL_TITLE###}</b>',
                '{###PROPOSAL_TEXT###}',
                '- Options: {###OPTIONS###}',
                '- Asked by: {###ISSUER###}',
                '- Voting ends: {###ENDS###}',
            ];
        }
        else if (self::IsDiscord())
        {
            $this->title = ['New Teia poll: {###PROPOSAL_TITLE###}'];
            $this->description = [
                '{###PROPOSAL_TEXT###}',
                '- Options: {###OPTIONS###}',
                '- Asked by: {###ISSUER###}',
                '- Voting ends: {###ENDS###}',
            ];
            $this->color = self::COLOR_VIOLET;
        }
    }
}
