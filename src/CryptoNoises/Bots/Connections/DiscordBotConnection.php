<?php

namespace CryptoNoises\Bots\Connections;

/*
 *  Required permissions for bot @ https://discord.com/developers/applications/<application_id>/oauth2/url-generator
 *  - applications.commands
 *  - bot
 *      Send Messages
 *      Manage Messages
 *      Embed Links
 *      Attach Files
 *      Use Slash Commands
 */

use CryptoNoises\Bots\Core\DiscordEmbedWrapper;
use CryptoNoises\Bots\Core\NotificationSendFailedException;
use CryptoNoises\Config;
use CryptoNoises\Core\Log;
use CryptoNoises\Entities\CallbackAction;
use CryptoNoises\Entities\User as CrynzUser;
use CryptoNoises\Localize\Notification;
use CryptoNoises\Localize\NotificationButton;
use CryptoNoises\Localize\NotificationDiscord;
use CryptoNoises\Localize\Phrase;
use CryptoNoises\Models;
use Discord\Builders\Components\ActionRow;
use Discord\Builders\Components\Button;
use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Http\Exceptions\NoPermissionsException;
use Discord\InteractionType;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Channel\Message\Message;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\User\User as DiscordUser;
use Exception;
use Throwable;
use React\Promise\PromiseInterface;

class DiscordBotConnection
{
    public CrynzUser $user;

    private Discord $discord;

    private ?DiscordUser $discordUser = null;

    private ?Interaction $interaction = null;

    public function __construct(Discord $discord)
    {
        $this->discord = $discord;
    }

    //------------------------------------------------------------------------------------------------------------------

    public function initUserByUserId(string $userId): bool
    {
        $this->discordUser = null;

        $crynzUser = CrynzUser::GetByUserId($userId);

        if ($crynzUser === null)
        {
            $this->discord->getLogger()->error("Cannot find user item #".$userId, [
                'location'      => get_class($this).'->'.__FUNCTION__,
                'userId'        => $userId,
            ]);
            return false;
        }

        $this->user = $crynzUser;
        return true;
    }

    public function initUserByDiscordUser(DiscordUser $discordUser): bool
    {
        $this->discordUser = $discordUser;

        $crynzUser = CrynzUser::GetOrCreatePlatformUser(Config::kPLATFORM_Discord, $discordUser->id, 0);

        if ($crynzUser === null)
        {
            $this->discord->getLogger()->error("Cannot find user item", [
                'location'          => get_class($this).'->'.__FUNCTION__,
                'discordUserId'    => $discordUser->id,
                'discordChannelId' => 0,
            ]);
            return false;
        }

        $crynzUser->setUserAttributes([
            'username'      => $discordUser->username,
            'discriminator' => $discordUser->discriminator,
            'first_name'    => null,
            'last_name'     => null,
            'avatar'        => $discordUser->avatar,
            'language_code' => $discordUser->locale,
            'verified'      => $discordUser->verified !== null ? ( $discordUser->verified ? 1 : 0) : null,
        ]);

        $this->user = $crynzUser;
        return true;
    }

    public function initUserByInteraction(Interaction $interaction): bool
    {
        $this->interaction = $interaction;

        return $this->initUserByDiscordUser($interaction->user);
    }

    //------------------------------------------------------------------------------------------------------------------

    public function getDiscord(): Discord
    {
        return $this->discord;
    }

    //------------------------------------------------------------------------------------------------------------------
    // Send notifications queue

    private bool $didReplyOnInteraction = false;
    private bool $didUpdateInteractionMessage = false;

    private bool $isForcedResponseToDM = false;

    /** @var callable|null function (string $reason): void - set while the notifier delivers a queue row */
    private $onDeliveryFailed = null;

    /**
     * Notifier path: a failure while sending throws NotificationSendFailedException; a failure reported later by
     * Discord (the send is asynchronous) calls $onDeliveryFailed, so the caller can mark its queue row as failed.
     *
     * @throws NotificationSendFailedException
     */
    public function deliverBotNotifications(?Notification $notification, callable $onDeliveryFailed): void
    {
        $this->onDeliveryFailed = $onDeliveryFailed;

        try
        {
            $this->sendBotNotifications($notification);
        }
        finally
        {
            $this->onDeliveryFailed = null;
        }
    }

    public function sendBotNotifications(?Notification $notification): void
    {
        if ($this->interaction && $this->interaction->type == InteractionType::MODAL_SUBMIT)
        {
            // Modal submit: the first message only acknowledges the interaction (see DiscordMainLoopThread::onModalSubmit())

            $notificationEmpty = Notification\System_Skip::I();
            $notificationEmpty->next = $notification;

            $notification = $notificationEmpty;
        }

        while ($notification !== null)
        {
            $this->sendNotification($notification);
            $notification = $notification->next;
        }

        if ($this->interaction !== null && !$this->didReplyOnInteraction)
        {
            $this->interaction->acknowledge();

            $this->didReplyOnInteraction = true;
        }
    }

    private function sendNotification(Notification $notification): void
    {
        $discordUserId    = $this->user->platformUserId;
        $discordChannelId = $this->user->platformChannelId;

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $isRedirectChannel = false;

        if ($discordUserId && $discordChannelId == 0)
        {
            $redirect = $this->user->redirect();

            if ($redirect->isEnabled)
            {
                $discordChannelId  = $redirect->redirectChannelId;
                $isRedirectChannel = true;
            }
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        if ($notification->discord_isAllowEphemeralResponse === null)
        {
            Log::Error("DiscordBotConnection", "sendNotification", "discord_isAllowEphemeralResponse is not set for this notification class", [
                'class' => get_class($notification),
                'notification' => $notification,
            ]);
            $notification->discord_isAllowEphemeralResponse = false;
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        try
        {
            $logRecord = Models\Log\BotMessagesDiscord::model()->saveResponse($discordUserId, $discordChannelId, $notification);

            $message = $this->buildMessage($notification);

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
            // Interaction response

            $didSendResponse = $this->sendNotification_onInteraction($notification, $message);

            if ($didSendResponse)
                return;

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
            // Normal responses (to the channel or direct message)

            if ($message === null)
                return;

            $channel = null;

            if ($discordChannelId && !$this->isForcedResponseToDM)
            {
                $channel = $this->discord->getChannel($discordChannelId);

                if ($channel === null && !$isRedirectChannel)
                    throw new Exception("Cannot fetch channel by #$discordChannelId");

                // The redirect channel is gone (deleted, or the bot left the server): deliver to DM instead
                if ($channel === null)
                    $this->discord->getLogger()->warning("Redirect channel #$discordChannelId not found, sending to DM", [
                        'userId' => $this->user->userId,
                    ]);
            }

            if ($channel !== null)
            {
                $this->sendMessageToChannel($channel, $message, $notification, $logRecord, $discordUserId);
            }
            else if ($discordUserId)
            {
                if ($this->discordUser !== null)
                {
                    $this->sendMessageToUser($this->discordUser, $message, $notification, $logRecord);
                    return;
                }

                // Find user in discord cache
                $this->discordUser = $this->discord->users->find(function (DiscordUser $userDiscord) use ($discordUserId)
                {
                    return (string) $userDiscord->id === (string) $discordUserId;
                });

                if ($this->discordUser !== null)
                {
                    $this->sendMessageToUser($this->discordUser, $message, $notification, $logRecord);
                    return;
                }

                // Fetch user data and then send message
                $this->discord->users->fetch($discordUserId)->then(
                    function (DiscordUser $userDiscord) use ($message, $notification, $logRecord)
                    {
                        $this->sendMessageToUser($userDiscord, $message, $notification, $logRecord);
                    },
                    $this->asyncFailureHandler($logRecord, $this->onDeliveryFailed));
            }
            else
            {
                throw new Exception('Unknown state: discordChannelId and discordUserId are empty');
            }
        }
        catch (Throwable $e)
        {
            $this->discord->getLogger()->error('Failed to send notification', [
                'location'      => get_class($this).'->'.__FUNCTION__,
                'error'         => $e->getMessage(),
                'userId'        => $this->user->userId,
            ]);

            if ($this->onDeliveryFailed !== null)
                throw new NotificationSendFailedException('Discord: '.$e->getMessage());
        }
    }

    /**
     * Rejection handler of an asynchronous send: logs the error and reports it to the notifier (if it is waiting).
     */
    private function asyncFailureHandler(Models\Log\BotMessagesDiscordRecord $logRecord, ?callable $onFailed): callable
    {
        return function ($error) use ($logRecord, $onFailed)
        {
            $logRecord->doneWithError($error instanceof Throwable ? $error : null);

            if ($onFailed !== null)
                $onFailed('Discord: '.($error instanceof Throwable ? $error->getMessage() : 'send failed'));
        };
    }

    //------------------------------------------------------------------------------------------------------------------

    /**
     * @throws NoPermissionsException
     */
    private function sendMessageToChannel(Channel $channel, MessageBuilder $message, Notification $notification,
                                          Models\Log\BotMessagesDiscordRecord $logRecord, int $discordUserId): void
    {
        $onFailed = $this->asyncFailureHandler($logRecord, $this->onDeliveryFailed);

        $channel->sendMessage($message)->then(
            function (Message $message) use ($logRecord)
            {
                $logRecord->doneSuccess();
            },
            function ($error) use ($channel, $notification, $onFailed, $discordUserId)
            {
                $onFailed($error);

                if ($error instanceof NoPermissionsException)
                    $this->fixMissedMessage($discordUserId, $channel->id, $channel->guild_id, $notification);
            });
    }

    private function sendMessageToUser(DiscordUser $user, MessageBuilder $message, Notification $notification,
                                       Models\Log\BotMessagesDiscordRecord $logRecord): void
    {
        $onFailed = $this->asyncFailureHandler($logRecord, $this->onDeliveryFailed);

        $user->sendMessage($message)->then(
            function (Message $message) use ($logRecord)
            {
                $logRecord->doneSuccess();
            },
            function ($error) use ($user, $notification, $onFailed)
            {
                $onFailed($error);

                if ($error instanceof NoPermissionsException)
                    $this->fixMissedMessage($user->id, null, null, $notification);
            });
    }

    private function fixMissedMessage(?int $discordUserId, ?int $channelId, ?int $guildId,
                                      Notification $notification): void
    {
        try
        {
            $autoReportChannelId = Config::cfg()->discordChannelMissedNotifications;

            if ($autoReportChannelId == null)
                throw new Exception('Reporting is disabled');

            if (!Models\DiscordUsers::model()->isUserConnectedToServer($discordUserId))
            {
                $crynzUser = CrynzUser::GetByPlatformUserId(Config::kPLATFORM_Discord, $discordUserId, 0);

                if ($crynzUser !== null)
                    $crynzUser->deactivate('User is not in the CryptoNoises Discord server and has DMs blocked');

                throw new Exception('User is not in the CryptoNoises Discord server');
            }

            $autoReportChannel = $this->discord->getChannel($autoReportChannelId);

            if ($autoReportChannel === null)
            {
                Log::Error('DiscordBotConnection', 'fixMissedMessage', "Cannot find AutoReportChannel #$autoReportChannelId");
                throw new Exception('Cannot get channel instance');
            }

            $delayInMinutes = Config::kDiscord_AutoDeleteMissedMessageInfo_Minutes;

            $infoMessage = Phrase::I()->userMissedMessage;
            $infoMessage = join(PHP_EOL, $infoMessage);
            $infoMessage = str_replace('{###USER_ID###}', $discordUserId, $infoMessage);
            $infoMessage = str_replace('{###MINUTES_DELAY###}', $delayInMinutes, $infoMessage);

            $privacyHelpUrl = Config::cfg()->getLink('discord_privacy_help_url');
            $infoMessage = str_replace('{###PRIVACY_HELP_URL###}', $privacyHelpUrl !== null ? ' '.$privacyHelpUrl : '', $infoMessage);

            // A fresh message with the notice only: the embed of the private notification must not reach the channel
            $infoBuilder = MessageBuilder::new()
                ->setContent($infoMessage)
                ->setAllowedMentions(['parse' => [], 'users' => [(string) $discordUserId]]);

            $autoReportChannel->sendMessage($infoBuilder)->then(
                function (Message $message) use ($delayInMinutes)
                {
                    $message->delayedDelete($delayInMinutes * 60 * 1000);
                },
                function ($error)
                {
                    // Best effort notice: ignore failures
                });

            $notifyCompleted = true;
            $notifyNotice = null;
        }
        catch (Throwable $e)
        {
            $notifyCompleted = false;
            $notifyNotice = $e->getMessage();
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $rec = new Models\DiscordMissedMessages();
        $rec->type = $channelId ? Models\DiscordMissedMessages::kTYPE_Channel : Models\DiscordMissedMessages::kTYPE_User;
        $rec->state = $notifyCompleted ? Models\DiscordMissedMessages::kSTATE_Notified : Models\DiscordMissedMessages::kSTATE_Ignored;
        $rec->userId = $discordUserId;
        $rec->channelId = $channelId;
        $rec->guildId = $guildId;
        $rec->message = get_class($notification);
        $rec->notice = $notifyNotice;
        $rec->insert();
    }

    //------------------------------------------------------------------------------------------------------------------

    private function sendNotification_onInteraction(Notification $notification, ?MessageBuilder $message): bool
    {
        if ($this->interaction === null)
            return false;

        if ($this->isForcedResponseToDM)
            return false;

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
        // Delete current message

        if ($notification instanceof Notification\System_Skip)
        {
            $this->interaction->acknowledge();

            $this->didReplyOnInteraction = true;
            $this->didUpdateInteractionMessage = true;
            return true;
        }

        if ($this->interaction->message && $notification instanceof Notification\System_Delete)
        {
            $this->interaction->message->delete();
            $this->interaction->acknowledge();

            $this->didReplyOnInteraction = true;
            $this->didUpdateInteractionMessage = true;
            return true;
        }

        if ($this->interaction->message && $notification instanceof NotificationDiscord\Modal)
        {
            $notification->showModal($this->interaction);
            return true;
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        if ($message === null)
            return false;

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
        // Update interaction message (after button clicked) - for 1st notification only!

        if ($this->interaction->message && !$this->didUpdateInteractionMessage)
        {
            if ($this->sendNotification_onInteraction_updateCurrent($message, $notification->discord_isAllowEphemeralResponse))
                return true;

            // At this point => $interaction->message IS "ephemeral", but $message(new) IS "NOT ephemeral"

            if ($this->isUserInDMChannel())
            {
                // If user already in DM -> remove current "ephemeral" message
                $this->interaction->acknowledge();

                // an ephemeral message cannot be deleted: keep it
                if (!$this->interaction->message->ephemeral)
                    $this->interaction->deleteOriginalResponse();

                $this->didUpdateInteractionMessage = true;
            }
            else
            {
                // If user in channel -> show notification to switch to DM
                $this->sendNotification_onInteraction_switchToDM();
            }

            return false; // ... the caller then sends $message as a DM
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
        // Direct response to slash-command [or] 2nd+ notification from queue

        if ($this->isUserInGuildChannel() && $notification->discord_isAllowEphemeralResponse == false)
        {
            return $this->sendNotification_onInteraction_switchToDM();
        }

        return $this->sendNotification_onInteraction_respond($message, $notification->discord_isAllowEphemeralResponse);
    }

    private function sendNotification_onInteraction_updateCurrent(MessageBuilder $message, bool $messageIsEphemeral): bool
    {
        if ($this->didUpdateInteractionMessage)
            return false; // No need to update it 2nd time...

        if ($this->interaction->message->ephemeral && $messageIsEphemeral)
        {
            $this->interaction->updateMessage($message);
            $this->interaction->acknowledge();

            $this->didReplyOnInteraction = true;
            $this->didUpdateInteractionMessage = true;
            return true; // didUpdate
        }

        if ($this->interaction->message->ephemeral && !$messageIsEphemeral)
        {
            return false; // Cannot update!
        }

        // At this point => current message is NON-ephemeral -> just update it!

        $this->interaction->message->edit($message);
        $this->interaction->acknowledge();

        $this->didReplyOnInteraction = true;
        $this->didUpdateInteractionMessage = true;
        return true; // didUpdate
    }

    private function sendNotification_onInteraction_respond(MessageBuilder $message, bool $ephemeral): bool
    {
        if ($this->didReplyOnInteraction)
        {
            $this->interaction->sendFollowUpMessage($message, $ephemeral);
        }
        else
        {
            $this->interaction->respondWithMessage($message, $ephemeral);
            $this->interaction->acknowledge();
        }

        $this->didReplyOnInteraction = true;
        return true;
    }

    /*
     * Call only when the user is not in a DM channel
     */
    private function sendNotification_onInteraction_switchToDM(): bool
    {
        $this->isForcedResponseToDM = true;

        // User in Channel -> send info about "switch to DM"

        $messageToDM = MessageBuilder::new()
            ->setContent(Phrase::I()->discord_letsTalkInDM)
            ->setEmbeds([])         // Remove current "Embeds"
            ->setComponents([]);    // Remove current "Buttons"

        if ($this->interaction->message)
        {
            // edit (non-ephemeral) or updateMessage (ephemeral) + acknowledge, exactly once
            $this->sendNotification_onInteraction_updateCurrent($messageToDM, true);
        }
        else
        {
            $this->sendNotification_onInteraction_respond($messageToDM, true);
        }

        return false; // ... the caller then sends $message as a DM
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    private function isUserInDMChannel(): bool
    {
        return $this->interaction->guild_id === null;
    }

    private function isUserInGuildChannel(): bool
    {
        return !$this->isUserInDMChannel();
    }

    //------------------------------------------------------------------------------------------------------------------

    public function replyNotification(Message $replyToMessage, Notification $notification): ?PromiseInterface
    {
        $discordUserId    = $this->user->platformUserId;
        $discordChannelId = $this->user->platformChannelId;

        try
        {
            Models\Log\BotMessagesDiscord::model()->saveResponse($discordUserId, $discordChannelId, $notification);

            $message = $this->buildMessage($notification);

            if ($message === null)
                return null;

            return $replyToMessage->reply($notification->getDescription());
        }
        catch (Throwable $e)
        {
            $this->discord->getLogger()->error("Failed to reply notification", [
                'location'      => get_class($this).'->'.__FUNCTION__,
                'error'         => $e->getMessage(),
                'userId'        => $this->user->userId,
            ]);
            return null;
        }
    }

    protected function buildMessage(Notification $notification): ?MessageBuilder
    {
        if ($notification instanceof Notification\System_Skip ||
            $notification instanceof Notification\System_Delete)
            return null;

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $messageBuilder = MessageBuilder::new();

        if ($plainText = $notification->getPlainText())
            $messageBuilder->setContent($plainText);

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $wEmbed = new DiscordEmbedWrapper($this->discord);

        $wEmbed->setTitleAndDescription($notification->getTitle(), $notification->getDescription());
        $wEmbed->setFooter($notification->getFooterWithAttentions());

        $wEmbed->setUrl($notification->getUrl());

        if ($notification->hasPreviewAnimationUrl())
            $wEmbed->setImage($notification->getPreviewAnimationUrl());
        else
            $wEmbed->setImage($notification->getPreviewThumbnailUrl());

        $wEmbed->setThumbnail($notification->getThumbnailUrl());
        $wEmbed->setColor($notification->getColor());

        // video previews are not sent to Discord

        foreach ($notification->getFields() as $label => $value)
        {
            $wEmbed->addField($label, $value, $notification->isFieldsInline());
        }

        if ($embed = $wEmbed->getEmbed())
            $messageBuilder->addEmbed($embed);

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $buttons = $notification->getButtons();

        if (!empty($buttons))
        {
            foreach ($buttons as $buttonsInRow)
            {
                $actionRow = ActionRow::new();

                foreach ($buttonsInRow as $buttonAttrs)
                {
                    switch ($buttonAttrs->type)
                    {
                        case NotificationButton::kTYPE_CallbackAction:
                            $button = Button::new(Button::STYLE_PRIMARY)
                                ->setLabel($buttonAttrs->caption)
                                ->setCustomId(CallbackAction::PackFullCustomId($buttonAttrs->callbackAuid));

                            switch ($buttonAttrs->style)
                            {
                                case NotificationButton::kSTYLE_Secondary:
                                    $button->setStyle(Button::STYLE_SECONDARY);
                                    break;

                                case NotificationButton::kSTYLE_Success:
                                    $button->setStyle(Button::STYLE_SUCCESS);
                                    break;

                                case NotificationButton::kSTYLE_Danger:
                                    $button->setStyle(Button::STYLE_DANGER);
                                    break;

                                case NotificationButton::kSTYLE_Disabled:
                                    $button->setDisabled(true);
                                    break;
                            }

                            $actionRow->addComponent($button);
                            break;

                        case NotificationButton::kTYPE_Link:
                            $button = Button::new(Button::STYLE_LINK)
                                ->setLabel($buttonAttrs->caption)
                                ->setUrl($buttonAttrs->url);

                            $actionRow->addComponent($button);
                            break;

                        default:
                            $this->discord->getLogger()->error("Failed to create button", [
                                'location'      => get_class($this).'->'.__FUNCTION__,
                                'attributes'    => $buttonAttrs,
                            ]);
                            break;
                    }
                }

                if (count($actionRow->getComponents()) > 0)
                    $messageBuilder->addComponent($actionRow);
            }
        }
        else
        {
            // An updated ephemeral message keeps the buttons of the previous message unless components are reset
            $messageBuilder->setComponents([]);
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        return $messageBuilder;
    }

    //------------------------------------------------------------------------------------------------------------------
    // Helpers

    public static function IsRequireAutoJoinChannelByMessage(?Message $message): bool
    {
        if ($message === null || $message->channel_id === null)
            return false;

        if ($message->channel_id === Config::cfg()->discordChannelAutoJoin_any)
            return true;

        if ($message->channel_id === Config::cfg()->discordChannelAutoJoin_hi)
            return strtolower(trim($message->content)) == 'hi';

        return false;
    }

    public static function GetInviteBotToServerUrl(string $clientId): string
    {
        // Send Messages (1<<11) + Manage Messages (1<<13) + Embed Links (1<<14) + Use Application Commands (1<<31)
        $permissions = '2147510272';

        return 'https://discord.com/api/oauth2/authorize'
            . '?client_id='.$clientId
            . '&permissions='.$permissions
            . '&scope=bot%20applications.commands';
    }
}
