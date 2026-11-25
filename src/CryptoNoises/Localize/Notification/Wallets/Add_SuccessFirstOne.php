<?php

namespace CryptoNoises\Localize\Notification\Wallets;

use CryptoNoises\Config;
use CryptoNoises\Entities\User;
use CryptoNoises\Localize\Notification;

class Add_SuccessFirstOne extends Notification
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

        $this->title = [
            "✅ Wallet was successfully added",
        ];

        $this->description = [
            "Now you'll receive notifications about:",
            "🟢 Income / 🔴 Expenses",
            "🔵 NFT sales / 🟣 Purchases / 🟡 Royalty",
            "🟠 NFT offers",
            "🟤 Auction events",
            "and many other events...",
        ];

        // Matrix has no Settings menu: its commands are all there is
        $this->footer = Config::cfg()->isMatrixPlatform()
            ? ["Send <code>!help</code> for what else I can do."]
            : ["Click on ⚙️ Settings to customize your wallet, set up notification filters, etc."];
    }
}
