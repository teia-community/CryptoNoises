<?php

namespace CryptoNoises\Localize\Notification\Transaction;

use CryptoNoises\Entities\User;
use CryptoNoises\Localize\NotificationButton;

/**
 * Your wallet moved its delegation from one baker to another.
 *
 * Placeholders are bound in AbstractBot_Notification_Events::bindAccountEvent(); a line whose value is unknown
 * there is dropped.
 */
class DelegationChange extends AbstractTransaction
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
                '🥩 Your wallet changed baker',
                '- From: <a href="{###PREV_BAKER_URL###}">{###PREV_BAKER_NAME###}</a>',
                '- To: <b><a href="{###BAKER_URL###}">{###BAKER_NAME###}</a></b>',
                '- Delegated balance: <b>{###AMOUNT_TOTAL###}</b>',
            ];
        }
        else if (self::IsDiscord())
        {
            $this->title = ['Your wallet changed baker to {###BAKER_NAME###}'];
            $this->url   = '{###BAKER_URL###}';

            $this->description = [
                '- From: {###PREV_BAKER_NAME###}',
                '- To: {###BAKER_NAME###}',
                '- Delegated balance: {###AMOUNT_TOTAL###}',
            ];

            $this->buttons[] = [
                NotificationButton::Link('New baker', '{###BAKER_URL###}'),
                NotificationButton::Link('Previous baker', '{###PREV_BAKER_URL###}'),
            ];

            $this->color = self::COLOR_BLUE;
        }
    }
}
