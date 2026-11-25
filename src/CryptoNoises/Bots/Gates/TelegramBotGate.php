<?php

namespace CryptoNoises\Bots\Gates;

use CryptoNoises\Config;
use CryptoNoises\Bots\AbstractBot;
use CryptoNoises\Bots\AbstractBotGate;
use CryptoNoises\Bots\Connections\TelegramBotConnection;
use CryptoNoises\Bots\Core\NotificationSendFailedException;
use CryptoNoises\Bots\Core\NotificationSendSkippedException;
use CryptoNoises\Bots\Core\SlashCommand;
use CryptoNoises\Core\Log;
use CryptoNoises\Entities\CallbackAction;
use CryptoNoises\Helpers\Helper;
use CryptoNoises\Localize\Notification;
use CryptoNoises\Localize\NotificationTelegram;
use Exception;

class TelegramBotGate extends AbstractBot implements AbstractBotGate
{
    private TelegramBotConnection $tgBotConnection;

    public function __construct(TelegramBotConnection $tgBotConnection)
    {
        $this->tgBotConnection = $tgBotConnection;
    }

    //------------------------------------------------------------------------------------------------------------------

    public function routeByUserMessage(?string $message): bool
    {
        $message = trim((string) $message);

        list ($commandId, $commandMessage) = Helper::SplitBySpaces($message, 2);

        $slashCommand = new SlashCommand();
        $slashCommand->setCommand($commandId);
        $slashCommand->setQuery($commandMessage);

        //--------------------------------------------------------------------------------------------------------------
        // Step 1: Command execute

        switch ($slashCommand->command)
        {
            case SlashCommand::START:               return $this->start();
            case SlashCommand::HELP:                return $this->help();

            case SlashCommand::SUMMARY:             return $this->walletsSummary();
            case SlashCommand::WALLETS_LIST:        return $this->walletsList();
            case SlashCommand::ADD_WALLET:          return $this->walletAdd($slashCommand->query);
            case SlashCommand::DELETE_WALLET:       return $this->walletDelete($slashCommand->query);

            case SlashCommand::FOLLOWING:           return $this->following();
            case SlashCommand::FOLLOW:              return $this->follow($slashCommand->query);
            case SlashCommand::UNFOLLOW:            return $this->unfollow($slashCommand->query);

            case SlashCommand::DOMAINS:             return $this->domains();
            case SlashCommand::OFFERS:              return $this->offers();
            case SlashCommand::COINS:               return $this->coinsRates();

            case SlashCommand::SETTINGS:            return $this->settings();

            case SlashCommand::ABOUT_TOKEN:         return $this->aboutToken($slashCommand->query);

            case SlashCommand::REDIRECT_DM:         return $this->redirectDM();
        }

        //--------------------------------------------------------------------------------------------------------------
        // Step 2: User Actions

        $userAction = $this->tgBotConnection->user->getNextAction();

        if (!empty($userAction))
        {
            $slashCommand = SlashCommand::NewByUserAction($userAction);

            switch ($slashCommand->command)
            {
                case SlashCommand::ADD_WALLET:          return $this->walletAdd($message);
                case SlashCommand::RENAME_WALLET:       return $this->walletRename((int)$slashCommand->vars['wallet_adid'], $message, $slashCommand->vars['wallet_message_id']);
                case SlashCommand::DELETE_WALLET:       return $this->walletDelete($message);

                case SlashCommand::FOLLOW:              return $this->follow($message);
                case SlashCommand::UNFOLLOW:            return $this->unfollow($message);
            }
        }

        //--------------------------------------------------------------------------------------------------------------

        if (trim($message, ". \t\n\r\0\x0B") == '')
            return false;

        $result = $this->doMessageIsUndefined($this->tgBotConnection->user, $message);
        $this->sendNotifications($result);
        return false;
    }

    public function routeByCallback(?string $callbackData): bool
    {
        try
        {
            $callbackAction = CallbackAction::Initialize($callbackData);

            if ($callbackAction === null)
                throw new Exception('Callback action does not exist');

            $callbackAction->parentMessageId = $this->tgBotConnection->webhook->getMessageId();

            switch ($callbackAction->scope)
            {
                case CallbackAction::Scope_Summary:             return $this->walletsSummary($callbackAction);
                case CallbackAction::Scope_Wallets_Details:     return $this->walletDetails($callbackAction);
                case CallbackAction::Scope_Wallets_Add:         return $this->walletAdd();
                case CallbackAction::Scope_Wallets_Delete:      return $this->walletDelete();
                case CallbackAction::Scope_Following:           return $this->following($callbackAction);
                case CallbackAction::Scope_Domains:             return $this->domains($callbackAction);
                case CallbackAction::Scope_Offers:              return $this->offers($callbackAction);
                case CallbackAction::Scope_CoinsRates:          return $this->coinsRates($callbackAction);
                case CallbackAction::Scope_Settings:            return $this->settings($callbackAction);
                case CallbackAction::Scope_Help:                return $this->help();

                case CallbackAction::Scope_System_CancelLastAction: return $this->cancelLastUserAction();
            }

            throw new Exception('Unknown scope');
        }
        catch (Exception $e)
        {
            Log::Warning(get_class($this), __FUNCTION__, $e->getMessage());

            $result = $this->doCallbackIsUndefined($this->tgBotConnection->user);
            $this->sendNotifications($result);
            return false;
        }
    }

    //------------------------------------------------------------------------------------------------------------------
    // Basic

    private function start(): bool
    {
        $result = $this->doStart($this->tgBotConnection->user);
        $this->sendNotifications($result);

        $this->help();
        return true;
    }

    private function help(): bool
    {
        $result = $this->doHelp($this->tgBotConnection->user);
        $this->sendNotifications($result);

        if (Config::cfg()->getLink('discord_invite') !== null)
            $this->sendNotifications(NotificationTelegram\Welcome_AlsoJoinDiscord::I($this->tgBotConnection->user));
        return true;
    }

    //------------------------------------------------------------------------------------------------------------------
    // Wallets

    private function walletsSummary(?CallbackAction $callbackAction=null): bool
    {
        $result = $this->doWalletsSummary($this->tgBotConnection->user, $callbackAction);
        $this->sendNotifications($result);
        return true;
    }

    private function walletsList(): bool
    {
        $result = $this->doWalletsList($this->tgBotConnection->user);
        $this->sendNotifications($result);
        return true;
    }

    private function walletDetails(CallbackAction $callbackAction): bool
    {
        $result = $this->doWalletDetails($this->tgBotConnection->user, null, $callbackAction);
        $this->sendNotifications($result);
        return true;
    }

    private function walletAdd(?string $message = null): bool
    {
        $result = $this->doWalletAdd($this, $this->tgBotConnection->user, $message);
        $this->sendNotifications($result);
        return true;
    }

    private function walletDelete(?string $message = null): bool
    {
        $result = $this->doWalletDelete($this->tgBotConnection->user, $message);
        $this->sendNotifications($result);
        return true;
    }

    private function walletRename(int $walletAdid, ?string $newName, ?int $walletMessageId = null): bool
    {
        $wallet = $this->tgBotConnection->user->getWalletByAdid($walletAdid);

        if ($walletMessageId)
            $this->tgBotConnection->deleteMessage($walletMessageId);

        $result = $this->doWalletRename($this->tgBotConnection->user, $wallet, $newName);
        $this->sendNotifications($result);
        return true;
    }

    //------------------------------------------------------------------------------------------------------------------
    // Notifications

    // A frozen user blocked the bot (or cannot be reached): notifications are not sent to him
    public function isRecipientActive(): bool
    {
        return $this->tgBotConnection->user->isActive();
    }

    /**
     * @param array $notificationItem
     * @param array $eventItem
     * @return void
     * @throws NotificationSendFailedException
     * @throws NotificationSendSkippedException
     */
    public function notifyEventFlow(array $notificationItem, array $eventItem): void
    {
        $result = $this->doNotifyEventFlow($this->tgBotConnection->user, $notificationItem, $eventItem);

        $this->tgBotConnection->deliverBotNotifications($result);
    }

    /**
     * @return array<int, array{string, string}> per row id: status, note
     * @throws NotificationSendFailedException
     */
    public function notifyEventDigest(array $rows): array
    {
        [$notification, $results] = $this->doNotifyEventDigest($this->tgBotConnection->user, $rows);

        if ($notification !== null)
            $this->tgBotConnection->deliverBotNotifications($notification);

        return $results;
    }

    /**
     * @throws NotificationSendFailedException
     */
    public function notifyInformFlow(array $notificationItem, array $informItem): bool
    {
        $result = $this->doNotifyInformFlow($this->tgBotConnection->user, $informItem);
        $this->tgBotConnection->deliverBotNotifications($result);

        return $result !== null;
    }

    //------------------------------------------------------------------------------------------------------------------
    // Following

    private function following(?CallbackAction $callbackAction=null): bool
    {
        $result = $this->doFollowing($this->tgBotConnection->user, $callbackAction);
        $this->sendNotifications($result);
        return true;
    }

    private function follow(string $message): bool
    {
        $result = $this->doFollow($this->tgBotConnection->user, $message);
        $this->sendNotifications($result);
        return true;
    }

    private function unfollow(string $message): bool
    {
        $result = $this->doUnfollow($this->tgBotConnection->user, $message);
        $this->sendNotifications($result);
        return true;
    }

    //------------------------------------------------------------------------------------------------------------------
    // Others

    private function domains(?CallbackAction $callbackAction=null): bool
    {
        $result = $this->doDomains($this->tgBotConnection->user, $callbackAction);
        $this->sendNotifications($result);
        return true;
    }

    private function offers(?CallbackAction $callbackAction=null): bool
    {
        $result = $this->doOffers($this->tgBotConnection->user, $callbackAction);
        $this->sendNotifications($result);
        return true;
    }

    private function coinsRates(?CallbackAction $callbackAction=null): bool
    {
        $result = $this->doCoinsRates($this->tgBotConnection->user, $callbackAction);
        $this->sendNotifications($result);
        return true;
    }

    private function settings(?CallbackAction $callbackAction=null): bool
    {
        $result = $this->doSettings($this->tgBotConnection->user, $callbackAction);
        $this->sendNotifications($result);
        return true;
    }

    //------------------------------------------------------------------------------------------------------------------
    // About

    private function aboutToken(?string $message): bool
    {
        $message = preg_replace('/ +/', ' ', (string) $message);
        $message = trim($message);

        [$tokenContract, $tokenId] = array_pad(explode(' ', $message, 2), 2, null);

        $result = $this->doAboutToken($this->tgBotConnection->user, $tokenContract, $tokenId);
        $this->sendNotifications($result);
        return true;
    }

    //------------------------------------------------------------------------------------------------------------------

    private function redirectDM(): bool
    {
        $result = NotificationTelegram\System_DiscordCommand::I();
        $this->sendNotifications($result);
        return true;
    }

    //------------------------------------------------------------------------------------------------------------------
    // System / Additional

    private function cancelLastUserAction(): bool
    {
        $result = $this->doCancelLastUserAction($this->tgBotConnection->user);
        $this->sendNotifications($result);
        return true;
    }

    public function onlyPrivateChatSupported(): bool
    {
        $result = NotificationTelegram\System_OnlySupportDM::I();
        $this->sendNotifications($result);
        return true;
    }


    //------------------------------------------------------------------------------------------------------------------

    public function sendNotifications(?Notification $notification): void
    {
        $this->tgBotConnection->sendBotNotifications($notification);
    }
}
