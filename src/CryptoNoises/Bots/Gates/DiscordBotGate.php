<?php

namespace CryptoNoises\Bots\Gates;

use CryptoNoises\Bots\AbstractBot;
use CryptoNoises\Bots\AbstractBotGate;
use CryptoNoises\Bots\Connections\DiscordBotConnection;
use CryptoNoises\Bots\Core\NotificationSendFailedException;
use CryptoNoises\Bots\Core\NotificationSendSkippedException;
use CryptoNoises\Entities\CallbackAction;
use CryptoNoises\Localize\Notification;
use Discord\Discord;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\User\User;

class DiscordBotGate extends AbstractBot implements AbstractBotGate
{
    use DiscordBotGate_Autocompletes;
    use DiscordBotGate_Callbacks;
    use DiscordBotGate_Message;
    use DiscordBotGate_SlashCommand;

    private DiscordBotConnection $dcBotConnection;

    public function __construct(DiscordBotConnection $dcBotConnection)
    {
        $this->dcBotConnection = $dcBotConnection;
    }

    //------------------------------------------------------------------------------------------------------------------

    public static function NewByUserId(Discord $discord, string $userId): ?DiscordBotGate
    {
        $dcBotConnection = new DiscordBotConnection($discord);

        if ($dcBotConnection->initUserByUserId($userId) == false)
            return null;

        return new self($dcBotConnection);
    }

    public static function NewByDiscordUser(Discord $discord, User $user): ?DiscordBotGate
    {
        $dcBotConnection = new DiscordBotConnection($discord);

        if ($dcBotConnection->initUserByDiscordUser($user) == false)
            return null;

        return new self($dcBotConnection);
    }

    /**
     * @notice No need to store/cache DiscordBotGate objects, because every interaction is a new instance
     */
    public static function NewByInteraction(Discord $discord, Interaction $interaction): ?DiscordBotGate
    {
        $dcBotConnection = new DiscordBotConnection($discord);

        if ($dcBotConnection->initUserByInteraction($interaction) == false)
            return null;

        return new self($dcBotConnection);
    }

    //------------------------------------------------------------------------------------------------------------------
    // Basic

    public function start(): bool
    {
        $result = $this->doStart($this->dcBotConnection->user);
        $this->sendNotifications($result);

        $this->help();
        return true;
    }

    public function help(): bool
    {
        $result = $this->doHelp($this->dcBotConnection->user);
        $this->sendNotifications($result);
        return true;
    }

    //------------------------------------------------------------------------------------------------------------------
    // Wallets

    private function walletsSummary(?CallbackAction $callbackAction=null): bool
    {
        $result = $this->doWalletsSummary($this->dcBotConnection->user, $callbackAction);
        $this->sendNotifications($result);
        return true;
    }

    private function walletsList(): bool
    {
        $result = $this->doWalletsList($this->dcBotConnection->user);
        $this->sendNotifications($result);
        return true;
    }

    private function walletDetails(CallbackAction $callbackAction): bool
    {
        $result = $this->doWalletDetails($this->dcBotConnection->user, null, $callbackAction);
        $this->sendNotifications($result);
        return true;
    }

    private function walletAdd(?string $message, ?CallbackAction $callbackAction): bool
    {
        $result = $this->doWalletAdd($this, $this->dcBotConnection->user, $message, $callbackAction);
        $this->sendNotifications($result);
        return true;
    }

    private function walletDelete(?string $message, ?CallbackAction $callbackAction): bool
    {
        $result = $this->doWalletDelete($this->dcBotConnection->user, $message, $callbackAction);
        $this->sendNotifications($result);
        return true;
    }

    private function walletRename(int $walletAdid, ?string $newName): bool
    {
        $wallet = $this->dcBotConnection->user->getWalletByAdid($walletAdid);

        $result = $this->doWalletRename($this->dcBotConnection->user, $wallet, $newName);
        $this->sendNotifications($result);
        return true;
    }

    //------------------------------------------------------------------------------------------------------------------
    // Notifications

    /**
     * @param array $notificationItem
     * @param array $eventItem
     * @return void
     * @throws NotificationSendFailedException
     * @throws NotificationSendSkippedException
     */
    // A frozen user blocked the bot (or cannot be reached): notifications are not sent to him
    public function isRecipientActive(): bool
    {
        return $this->dcBotConnection->user->isActive();
    }

    /**
     * @param callable $onDeliveryFailed function (string $reason): void - called when Discord rejects the send later
     * @throws NotificationSendFailedException
     * @throws NotificationSendSkippedException
     */
    public function notifyEventFlow(array $notificationItem, array $eventItem, callable $onDeliveryFailed): void
    {
        $result = $this->doNotifyEventFlow($this->dcBotConnection->user, $notificationItem, $eventItem);

        $this->dcBotConnection->deliverBotNotifications($result, $onDeliveryFailed);
    }

    /**
     * @return array<int, array{string, string}> per row id: status, note
     * @throws NotificationSendFailedException
     */
    public function notifyEventDigest(array $rows, callable $onDeliveryFailed): array
    {
        [$notification, $results] = $this->doNotifyEventDigest($this->dcBotConnection->user, $rows);

        if ($notification !== null)
            $this->dcBotConnection->deliverBotNotifications($notification, $onDeliveryFailed);

        return $results;
    }

    /**
     * @param callable $onDeliveryFailed function (string $reason): void - called when Discord rejects the send later
     * @throws NotificationSendFailedException
     */
    public function notifyInformFlow(array $notificationItem, array $informItem, callable $onDeliveryFailed): bool
    {
        $result = $this->doNotifyInformFlow($this->dcBotConnection->user, $informItem);
        $this->dcBotConnection->deliverBotNotifications($result, $onDeliveryFailed);

        return $result !== null;
    }

    //------------------------------------------------------------------------------------------------------------------
    // Following

    private function following(?CallbackAction $callbackAction=null): bool
    {
        $result = $this->doFollowing($this->dcBotConnection->user, $callbackAction);
        $this->sendNotifications($result);
        return true;
    }

    private function follow(?string $message, ?CallbackAction $callbackAction): bool
    {
        $result = $this->doFollow($this->dcBotConnection->user, $message, $callbackAction);
        $this->sendNotifications($result);
        return true;
    }

    private function unfollow(?string $message, ?CallbackAction $callbackAction): bool
    {
        $result = $this->doUnfollow($this->dcBotConnection->user, $message, $callbackAction);
        $this->sendNotifications($result);
        return true;
    }

    //------------------------------------------------------------------------------------------------------------------
    // Others

    private function domains(?CallbackAction $callbackAction=null): bool
    {
        $result = $this->doDomains($this->dcBotConnection->user, $callbackAction);
        $this->sendNotifications($result);
        return true;
    }

    private function offers(?CallbackAction $callbackAction=null): bool
    {
        $result = $this->doOffers($this->dcBotConnection->user, $callbackAction);
        $this->sendNotifications($result);
        return true;
    }

    private function coinsRates(?CallbackAction $callbackAction=null): bool
    {
        $result = $this->doCoinsRates($this->dcBotConnection->user, $callbackAction);
        $this->sendNotifications($result);
        return true;
    }

    private function settings(?CallbackAction $callbackAction=null): bool
    {
        $result = $this->doSettings($this->dcBotConnection->user, $callbackAction);
        $this->sendNotifications($result);
        return true;
    }

    //------------------------------------------------------------------------------------------------------------------
    // About

    private function aboutToken(?string $tokenContract, ?string $tokenId): bool
    {
        $result = $this->doAboutToken($this->dcBotConnection->user, $tokenContract, $tokenId);
        $this->sendNotifications($result);
        return true;
    }

    //------------------------------------------------------------------------------------------------------------------

    private function redirectDM(Interaction $interaction, ?CallbackAction $callbackAction=null): bool
    {
        if ($interaction->channel->type === Channel::TYPE_TEXT)
        {
            $redirectToChannelId = $interaction->channel_id;
            $redirectInGuildId   = $interaction->guild_id;
            $isModerator         = $interaction->member->permissions->administrator || $interaction->member->permissions->manage_guild;
        }
        else
        {
            $redirectToChannelId = null;
            $redirectInGuildId   = null;
            $isModerator         = null;
        }

        $result = $this->doRedirectDM($this->dcBotConnection->user, $callbackAction, $isModerator, $redirectToChannelId, $redirectInGuildId);
        $this->sendNotifications($result);
        return true;
    }

    //------------------------------------------------------------------------------------------------------------------
    // System / Additional

    private function cancelLastUserAction(): bool
    {
        $result = $this->doCancelLastUserAction($this->dcBotConnection->user);
        $this->sendNotifications($result);
        return true;
    }


    //------------------------------------------------------------------------------------------------------------------

    public function sendNotifications(?Notification $notification): void
    {
        $this->dcBotConnection->sendBotNotifications($notification);
    }
}
