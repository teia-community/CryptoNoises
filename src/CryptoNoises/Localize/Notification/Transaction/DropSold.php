<?php

namespace CryptoNoises\Localize\Notification\Transaction;

use CryptoNoises\Entities\User;
use CryptoNoises\Localize\NotificationButton;

/**
 * Somebody bought from your drop. See DropBuy for why no particular piece is named.
 */
class DropSold extends AbstractTransaction
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
                '🎲 Somebody bought from your drop',
                self::EMPTY_LINE,
                '- Collection: '.$this->presetCollectionInfo(),
                '- Buyer: '.$this->presetTargetProfile(),
                '- Price: <b>{###AMOUNT_TOTAL###}</b>',
                '{###AMOUNT_TOTAL_CONVERTED###}',
                '- You receive: <b>{###FULL_SELL_PRICE###}</b>',
                '- Marketplace: '.$this->presetMarketplace(),
            ];
        }
        else if (self::IsDiscord())
        {
            $this->title = ['Somebody bought from your drop'];

            $this->description = [
                '- Collection: '.$this->presetCollectionInfo(),
                '- Buyer: '.$this->presetTargetProfile(),
                '- Price: {###AMOUNT_TOTAL###} {###AMOUNT_TOTAL_CONVERTED###}',
                '- You receive: {###FULL_SELL_PRICE###}',
                '- Marketplace: '.$this->presetMarketplace(),
            ];

            $this->buttons[] = [
                NotificationButton::Link('Collection',          '{###TOKENS_COLLECTION_URL###}'),
                NotificationButton::Link('Buyer',               '{###TARGET_PROFILE_URL###}'),
            ];

            $this->color = self::COLOR_BLUE;
        }

        $this->footer = []; // reset!
    }
}
