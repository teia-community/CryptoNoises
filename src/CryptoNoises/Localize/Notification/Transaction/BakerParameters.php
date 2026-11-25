<?php

namespace CryptoNoises\Localize\Notification\Transaction;

use CryptoNoises\Entities\User;
use CryptoNoises\Localize\NotificationButton;

/**
 * The baker your wallet delegates to (and maybe stakes with) changed what it offers stakers.
 *
 * Edge is the share of its stakers' rewards the baker keeps; the limit is how much external stake it accepts,
 * as a multiple of its own. Each line shows old -> new when the previous terms are known.
 *
 * Placeholders are bound in AbstractBot_Notification_Events::bindAccountEvent(); a line whose value is unknown
 * there is dropped.
 */
class BakerParameters extends AbstractTransaction
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
                '📣 Your baker <a href="{###BAKER_URL###}">{###BAKER_NAME###}</a> changed its staking terms',
                self::EMPTY_LINE,
                '- Share of stakers\' rewards it keeps (edge): <b>{###EDGE_CHANGE###}</b>',
                '- Staking capacity (limit): <b>{###LIMIT_CHANGE###}</b>',
                '- Takes effect: {###ACTIVATION###}',
            ];
        }
        else if (self::IsDiscord())
        {
            $this->title = ['Your baker {###BAKER_NAME###} changed its staking terms'];
            $this->url   = '{###BAKER_URL###}';

            $this->description = [
                '- Share of stakers\' rewards it keeps (edge): {###EDGE_CHANGE###}',
                '- Staking capacity (limit): {###LIMIT_CHANGE###}',
                '- Takes effect: {###ACTIVATION###}',
            ];

            $this->buttons[] = [
                NotificationButton::Link('Baker', '{###BAKER_URL###}'),
            ];

            $this->color = self::COLOR_YELLOW;
        }
    }
}
