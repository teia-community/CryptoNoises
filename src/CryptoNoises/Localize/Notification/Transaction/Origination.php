<?php

namespace CryptoNoises\Localize\Notification\Transaction;

use CryptoNoises\Entities\User;
use CryptoNoises\Localize\NotificationButton;

/**
 * Your wallet originated (deployed) a contract, directly or through a factory contract.
 *
 * Placeholders are bound in AbstractBot_Notification_Events::bindAccountEvent(); a line whose value is unknown
 * there is dropped.
 */
class Origination extends AbstractTransaction
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
                '📜 Your wallet originated a contract: <a href="{###CONTRACT_URL###}">{###CONTRACT###}</a>',
                '- Through: {###OPERATED_BY###}',
            ];
        }
        else if (self::IsDiscord())
        {
            $this->title = ['Your wallet originated a contract: {###CONTRACT###}'];
            $this->url   = '{###CONTRACT_URL###}';

            $this->description = [
                '- Through: {###OPERATED_BY###}',
            ];

            $this->buttons[] = [
                NotificationButton::Link('Contract', '{###CONTRACT_URL###}'),
            ];

            $this->color = self::COLOR_VIOLET;
        }
    }
}
