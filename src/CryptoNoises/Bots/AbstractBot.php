<?php

namespace CryptoNoises\Bots;

use CryptoNoises\Config;
use CryptoNoises\Entities\User;
use CryptoNoises\Localize\Notification;

abstract class AbstractBot
{
    use AbstractBot_About;
    use AbstractBot_CoinsRates;
    use AbstractBot_Domains;
    use AbstractBot_Following;
    use AbstractBot_Notification_Events;
    use AbstractBot_Notification_Informs;
    use AbstractBot_Offers;
    use AbstractBot_OptionsNotifier;
    use AbstractBot_Redirect;
    use AbstractBot_Settings;
    use AbstractBot_Wallets;

    //------------------------------------------------------------------------------------------------------------------

    protected function doStart(User $user): ?Notification
    {
        return Notification\Welcome::I($user);
    }

    protected function doHelp(User $user): ?Notification
    {
        return Notification\Help::I($user);
    }

    //------------------------------------------------------------------------------------------------------------------

    protected function doMessageIsUndefined(User $user, string $command): ?Notification
    {
        return Notification\System_UndefinedMessage::I($user);
    }

    protected function doCallbackIsUndefined(User $user): ?Notification
    {
        return Notification\System_UndefinedCallback::I($user);
    }

    protected function doCancelLastUserAction(User $user): ?Notification
    {
        $user->cancelNextAction();
        return Notification\System_Delete::I();
    }

    //------------------------------------------------------------------------------------------------------------------
    // Helpers

    protected static function IsDiscord(): bool
    {
        return Config::cfg()->isDiscordPlatform();
    }

    /** Telegram, and Matrix, which renders the Telegram variant (Config::usesTelegramLayout()) */
    protected static function IsTelegram(): bool
    {
        return Config::cfg()->usesTelegramLayout();
    }
}
