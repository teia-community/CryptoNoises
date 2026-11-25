<?php

namespace CryptoNoises\Localize\Notification\Settings;

use CryptoNoises\Entities\User;
use CryptoNoises\Localize\Notification;
use CryptoNoises\Localize\Phrase;

/**
 * Settings screen for bakers: how many missed attestations within an hour make an alert.
 */
class MissedAttestations extends Notification
{
    public ?bool $discord_isAllowEphemeralResponse  = true;

    //------------------------------------------------------------------------------------------------------------------

    public static function I(?User $user): self
    {
        return new self($user);
    }

    public function __construct(?User $user)
    {
        parent::__construct($user);

        $this->title = [
            "Missed attestations",
        ];

        $this->appendDescription(Phrase::I()->missedAttestationsInfo);
    }
}
