<?php

namespace CryptoNoises\Localize\Notification;

use CryptoNoises\Entities\User;
use CryptoNoises\Localize\Notification;

/** A new event on teia.art/events (TeiaEvents command). Bound in AbstractBot_Notification_Events. */
class TeiaEvent extends Notification
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
                '📣 New Teia event: <b>{###EVENT_TITLE###}</b>',
                '<i>{###EVENT_SUBTITLE###}</i>',
                '{###EVENT_DESCRIPTION###}',
            ];
        }
        else if (self::IsDiscord())
        {
            $this->title = ['📣 New Teia event: {###EVENT_TITLE###}'];

            $this->description = [
                '*{###EVENT_SUBTITLE###}*',
                '{###EVENT_DESCRIPTION###}',
            ];

            $this->color = self::COLOR_VIOLET;
        }
    }
}
