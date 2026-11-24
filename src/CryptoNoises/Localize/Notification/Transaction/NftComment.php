<?php

namespace CryptoNoises\Localize\Notification\Transaction;

use CryptoNoises\Entities\User;
use CryptoNoises\Localize\NotificationButton;

/**
 * Somebody commented on a token of yours.
 *
 * The comment text is not here on purpose: it lives on IPFS, and the notification path does not wait on a
 * gateway. The link goes to the token, where the comment is shown.
 */
class NftComment extends AbstractTransaction
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
                '💬 New comment on your NFT "'.$this->presetNftName().'"',
                self::EMPTY_LINE,
                '- From: '.$this->presetSenderProfile(),
                '- Collection: '.$this->presetCollectionInfo(),
                self::EMPTY_LINE,
                '<a href="{###TOKEN_STORE_URL###}">Read the comment</a>',
            ];
        }
        else if (self::IsDiscord())
        {
            $this->title = ['New comment on your NFT "'.$this->presetNftName().'"'];
            $this->url   = '{###TOKEN_STORE_URL###}';

            $this->description = [
                '- From: '.$this->presetSenderProfile(),
                '- Collection: '.$this->presetCollectionInfo(),
            ];

            $this->buttons[] = [
                NotificationButton::Link('Read the comment',    '{###TOKEN_STORE_URL###}'),
                NotificationButton::Link('From',                '{###SENDER_PROFILE_URL###}'),
            ];

            $this->color = self::COLOR_BLUE;
        }

        $this->footer = []; // reset!
    }
}
