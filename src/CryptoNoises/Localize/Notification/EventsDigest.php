<?php

namespace CryptoNoises\Localize\Notification;

use CryptoNoises\Entities\User;
use CryptoNoises\Localize\Notification;

/** Several notifications in one message, one line each (digest setting) */
class EventsDigest extends Notification
{
    const kMAX_LINES = 20;

    public ?bool $discord_isAllowEphemeralResponse  = false;

    public static function I(?User $user, array $lines): self
    {
        return new self($user, $lines);
    }

    public function __construct(?User $user, array $lines)
    {
        parent::__construct($user);

        $count = count($lines);

        if (count($lines) > self::kMAX_LINES)
            $lines = array_merge(array_slice($lines, 0, self::kMAX_LINES), ['…and '.($count - self::kMAX_LINES).' more']);

        if (self::IsTelegram())
            $this->title = ["📬 <b>$count notifications</b>"];
        else if (self::IsDiscord())
            $this->title = ["📬 $count notifications"];

        $this->description = $lines;
    }
}
