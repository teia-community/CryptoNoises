<?php

namespace CryptoNoises\Localize\Notification;

use CryptoNoises\Entities\CallbackAction;
use CryptoNoises\Entities\User;
use CryptoNoises\Entities\UserDomain;
use CryptoNoises\Entities\UserDomains;
use CryptoNoises\Localize\Notification;
use CryptoNoises\Localize\Phrase;

class Domains extends Notification
{
    public ?bool $discord_isAllowEphemeralResponse  = true;

    //------------------------------------------------------------------------------------------------------------------

    public static function I(?User $user): self
    {
        return new self($user);
    }

    public function __construct(?User $user)
    {
        parent::__construct($user);

        $this->title = ['🌐 Domains'];

        $this->appendDescription('Add wallets with linked domains to track their expiration dates automatically.');
    }

    public function initialize(UserDomains $userDomains, CallbackAction $caBuilder, int $totalOffersCount, int $itemsOffset, int $itemsPerPage): self
    {
        if ($userDomains->count() == 0)
        {
            $this->setWarning("No domains linked to your wallets were found.");
            return $this;
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $this->appendDescription(self::EMPTY_LINE);

        $index = $itemsOffset + 1;
        foreach ($userDomains->getItems($itemsOffset, $itemsPerPage) as $userDomain)
        {
            switch ($userDomain->state)
            {
                case UserDomain::kACTIVE:       $badge = '✅'; break;
                case UserDomain::kEXPIRE_SOON:  $badge = '⚠️'; break;
                case UserDomain::kEXPIRED:      $badge = '❌'; break;
                default:                        $badge = ''; break;
            }

            $expireDateTime = Phrase::FriendlyDateTime($userDomain->expireDateTime);
            $expireDuration = Phrase::FriendlyDuration(abs($userDomain->expireDuration));

            if ($userDomain->expireDuration <= 0)
            {
                $expireIn = "Expired $expireDuration ago";
                $expireAt = "Expired at $expireDateTime";
            }
            else if ($userDomain->expireDuration < 30*86400)
            {
                $expireIn = "Expires soon: in $expireDuration";
                $expireAt = "Expires at $expireDateTime";
            }
            else
            {
                $expireIn = "Expires in $expireDuration";
                $expireAt = "Expires at $expireDateTime";
            }

            if (self::IsTelegram())
            {
                $this->description[] =  "$badge <code>$userDomain->domain</code>";
                $this->description[] =  "- $expireIn";
                $this->description[] =  "- $expireAt";
                $this->description[] =  "- Owner: $userDomain->address";
            }
            else if (self::IsDiscord())
            {
                $this->description[] =  "$badge `$userDomain->domain`";
                $this->description[] =  "- $expireIn";
                $this->description[] =  "- $expireAt";
                $this->description[] =  "- Owner: `$userDomain->address`";
            }

            $this->description[] = self::EMPTY_LINE;

            $index++;
        }

        return $this;
    }
}
