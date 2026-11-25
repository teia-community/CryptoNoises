<?php

namespace CryptoNoises\Localize\Notification\Transaction;

use CryptoNoises\Entities\User;
use CryptoNoises\Localize\NotificationButton;

/**
 * The user's baker missed at least their threshold of attestations within an hour, or a block (BakerAlerts).
 *
 * Placeholders are bound in AbstractBot_Notification_Events::bindBakerEvent(); a line whose value is unknown there
 * is dropped.
 */
class BakerMissedRights extends AbstractTransaction
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
                '🚨 <a href="{###BAKER_URL###}">{###BAKER_NAME###}</a> is missing rights',
                '- Attestations missed within the last hour: <b>{###MISSED_ATTESTATIONS###}</b>',
                '- Blocks missed: <b>{###MISSED_BLOCKS###}</b>',
                '- Levels: {###LEVELS###}',
            ];
        }
        else if (self::IsDiscord())
        {
            $this->title = ['{###BAKER_NAME###} is missing rights'];
            $this->url   = '{###BAKER_URL###}';

            $this->description = [
                '- Attestations missed within the last hour: {###MISSED_ATTESTATIONS###}',
                '- Blocks missed: {###MISSED_BLOCKS###}',
                '- Levels: {###LEVELS###}',
            ];

            $this->color = self::COLOR_RED;
        }

        $this->buttons[] = [
                NotificationButton::Link('Baker on TzKT', '{###BAKER_URL###}'),
        ];
    }
}
