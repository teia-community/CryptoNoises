<?php

namespace CryptoNoises\Localize\Notification\Settings;

use CryptoNoises\Entities\User;
use CryptoNoises\Localize\Notification;
use CryptoNoises\Localize\Phrase;

/**
 * Settings screen: how often routine notifications are bundled into a digest.
 */
class Digest extends Notification
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
            "Digest",
        ];

        $this->appendDescription(Phrase::I()->digestInfo);
    }
}
