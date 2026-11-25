<?php

namespace CryptoNoises\Localize\Notification\Settings;

use CryptoNoises\Entities\User;
use CryptoNoises\Localize\Notification;
use CryptoNoises\Localize\Phrase;

/**
 * Settings screen: when to be reminded about unstaked tez that can be finalized but are not yet.
 */
class FinalizeReminder extends Notification
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
            "Finalize reminder",
        ];

        $this->appendDescription(Phrase::I()->finalizeReminderInfo);
    }
}
