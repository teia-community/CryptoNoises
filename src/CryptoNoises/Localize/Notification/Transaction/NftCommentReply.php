<?php

namespace CryptoNoises\Localize\Notification\Transaction;

use CryptoNoises\Entities\User;
use CryptoNoises\Localize\NotificationButton;

/**
 * Somebody answered a comment of yours. See NftComment for why the text is not included.
 */
class NftCommentReply extends AbstractTransaction
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
                '💬 Reply to your comment on "'.$this->presetNftName().'"',
                self::EMPTY_LINE,
                '- From: '.$this->presetSenderProfile(),
                '- Creator: '.$this->presetTokenCreatorProfile(),
                self::EMPTY_LINE,
                '<a href="{###TOKEN_STORE_URL###}">Read the reply</a>',
            ];
        }
        else if (self::IsDiscord())
        {
            $this->title = ['Reply to your comment on "'.$this->presetNftName().'"'];
            $this->url   = '{###TOKEN_STORE_URL###}';

            $this->description = [
                '- From: '.$this->presetSenderProfile(),
                '- Creator: '.$this->presetTokenCreatorProfile(),
            ];

            $this->buttons[] = [
                NotificationButton::Link('Read the reply',      '{###TOKEN_STORE_URL###}'),
                NotificationButton::Link('From',                '{###SENDER_PROFILE_URL###}'),
            ];

            $this->color = self::COLOR_BLUE;
        }

        $this->footer = []; // reset!
    }
}
