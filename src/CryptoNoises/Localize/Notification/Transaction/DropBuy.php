<?php

namespace CryptoNoises\Localize\Notification\Transaction;

use CryptoNoises\Entities\User;
use CryptoNoises\Localize\NotificationButton;

/**
 * You bought from a drop. Which piece you got is not decided yet - that arrives as an ordinary mint
 * notification a few minutes later - so this says so rather than showing an empty artwork.
 */
class DropBuy extends AbstractTransaction
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
                '🎲 You bought from a drop',
                self::EMPTY_LINE,
                '- Collection: '.$this->presetCollectionInfo(),
                '- Creator: '.$this->presetSenderProfile(),
                '- Paid: <b>{###AMOUNT_TOTAL###}</b>',
                '{###AMOUNT_TOTAL_CONVERTED###}',
                '- Marketplace: '.$this->presetMarketplace(),
                self::EMPTY_LINE,
                '<i>Your piece is picked when it is minted, in a few minutes. You will get a separate '
                    .'notification once it arrives.</i>',
            ];
        }
        else if (self::IsDiscord())
        {
            $this->title = ['You bought from a drop'];

            $this->description = [
                '- Collection: '.$this->presetCollectionInfo(),
                '- Creator: '.$this->presetSenderProfile(),
                '- Paid: {###AMOUNT_TOTAL###} {###AMOUNT_TOTAL_CONVERTED###}',
                '- Marketplace: '.$this->presetMarketplace(),
                self::EMPTY_LINE,
                'Your piece is picked when it is minted, in a few minutes.',
            ];

            $this->buttons[] = [
                NotificationButton::Link('Collection',          '{###TOKENS_COLLECTION_URL###}'),
                NotificationButton::Link('Creator',             '{###SENDER_PROFILE_URL###}'),
            ];

            $this->color = self::COLOR_VIOLET;
        }

        $this->footer = []; // reset!
    }
}
