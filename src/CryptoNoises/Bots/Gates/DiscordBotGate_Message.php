<?php

namespace CryptoNoises\Bots\Gates;

use CryptoNoises\Bots\Connections\DiscordBotConnection;
use CryptoNoises\Bots\Core\SlashCommand;
use CryptoNoises\Localize\Notification;
use CryptoNoises\Localize\Phrase;
use CryptoNoises\Models;
use Discord\Discord;
use Discord\Parts\Channel\Message\Message;
use Discord\Parts\User\User;

trait DiscordBotGate_Message
{
    public static function OnMessage(Discord $discord, Message $message): bool
    {
        if ($message->author->bot)
            return false;

        if ($message->guild_id !== null)
        {
            if (DiscordBotConnection::IsRequireAutoJoinChannelByMessage($message) === false)
                return false;
        }

        //--------------------------------------------------------------------------------------------------------------

        $logRecord = Models\Log\BotMessagesDiscord::model()->saveMessage($message);

        $discord->getLogger()->debug("on.message #{$message->author->id}");

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        if (!$message->author instanceof User)
        {
            // no reply to non-user authors

            $discord->getLogger()->error("Cannot detect User instance", [
                'location'      => 'DiscordBotGate::OnMessage::'.__LINE__,
            ]);
            return false;
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $botGate = DiscordBotGate::NewByDiscordUser($discord, $message->author);

        if ($botGate === null)
        {
            $message->author->sendMessage(Phrase::I()->systemError);

            $discord->getLogger()->error("Cannot initialize bot-gate", [
                'location'      => 'DiscordBotGate::OnMessage::'.__LINE__,
                'author-id'     => $message->author->id,
            ]);
            return false;
        }

        $result = $botGate->routeByMessage($message);
        return $logRecord->done($result);
    }

    public function routeByMessage(Message $messageObject): bool
    {
        if (DiscordBotConnection::IsRequireAutoJoinChannelByMessage($messageObject))
        {
            if ($this->dcBotConnection->user->isJustCreatedUser)
            {
                $promise = $this->dcBotConnection->replyNotification($messageObject,
                    Notification\Custom::I()->setDescription(Phrase::I()->discord_replyOnAutoJoin_NewUser));

                $this->start();
            }
            else
            {
                $promise = $this->dcBotConnection->replyNotification($messageObject,
                    Notification\Custom::I()->setDescription(Phrase::I()->discord_replyOnAutoJoin_ExistsUser));

                $this->help();
            }

            if ($promise !== null)
            {
                $promise->then(
                    function (Message $replyMessage) use ($messageObject)
                    {
                        $sleepTime = 60; // seconds

                        $messageObject->delayedDelete($sleepTime * 1000);
                        $replyMessage->delayedDelete($sleepTime * 1000);
                    },
                    function ($error)
                    {
                        // Reply failed (e.g. no permissions in the channel): nothing to clean up
                    });
            }

            return true;
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $message = trim($messageObject->content);

        // Read (and clear) the pending user action here:
        // if any other command is applied, the pending action is dropped for the next messages
        $this->dcBotConnection->user->readNextActionAndCleanUp();

        $userAction = $this->dcBotConnection->user->getNextAction();

        if ($userAction == null)
        {
            if (strtolower($message) === 'hi')
                return $this->start();
        }
        else
        {
            $slashCommand = SlashCommand::NewByUserAction($userAction);

            switch ($slashCommand->command)
            {
                case SlashCommand::ADD_WALLET:          return $this->walletAdd($message, null);
                case SlashCommand::RENAME_WALLET:       return $this->walletRename((int)$slashCommand->vars['wallet_adid'], $message);
                case SlashCommand::DELETE_WALLET:       return $this->walletDelete($message, null);

                case SlashCommand::FOLLOW:              return $this->follow($message, null);
                case SlashCommand::UNFOLLOW:            return $this->unfollow($message, null);
            }
        }

        if (trim($message, ". \t\n\r\0\x0B") == '')
            return false;

        $result = $this->doMessageIsUndefined($this->dcBotConnection->user, $message);
        $this->sendNotifications($result);
        return false;
    }
}
