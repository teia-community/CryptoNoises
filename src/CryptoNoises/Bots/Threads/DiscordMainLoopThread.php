<?php

namespace CryptoNoises\Bots\Threads;

use CryptoNoises\Bots\Core\SlashCommand;
use CryptoNoises\Bots\Gates\DiscordBotGate;
use CryptoNoises\Entities\CallbackAction;
use CryptoCore\Vss\Vss;
use Discord\Discord;
use Discord\InteractionType;
use Discord\Parts\Channel\Message\Message;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\Interactions\Request\Component;
use Discord\Parts\Interactions\Request\Option;
use Discord\Helpers\ExCollectionInterface;
use Discord\WebSockets\Event;
use Exception;

class DiscordMainLoopThread extends DiscordAbstractThread
{
    use DiscordMainLoopThread_GuildEvents;

    public function run(): bool
    {
        if ($this->createDiscordInstance() == false)
            return false;

        //--------------------------------------------------------------------------------------------------------------

        $this->discord->on('ready', function (Discord $discord)
        {
            $this->discord->getLogger()->notice("READY");

            $discord->on('message', function (Message $message, Discord $discord)
            {
                $this->onMessage($message);
            });

            $discord->on(Event::INTERACTION_CREATE, function (Interaction $interaction)
            {
                try
                {
                    if ($interaction->type == InteractionType::APPLICATION_COMMAND)
                    {
                        $this->onSlashCommand($interaction);
                    }
                    else if ($interaction->type == InteractionType::MESSAGE_COMPONENT)
                    {
                        if ($interaction->data->component_type == \Discord\Builders\Components\Component::TYPE_BUTTON)
                        {
                            $this->onButtonClicked($interaction);
                        }
                        else
                        {
                            throw new Exception('Unknown component_type ['.$interaction->data->component_type.']');
                        }
                    }
                    else if ($interaction->type == InteractionType::APPLICATION_COMMAND_AUTOCOMPLETE)
                    {
                        $this->onAutocomplete($interaction);
                    }
                    else if ($interaction->type == InteractionType::MODAL_SUBMIT)
                    {
                        $this->onModalSubmit($interaction);
                    }
                    else
                    {
                        throw new Exception('Unknown InteractionType ['.$interaction->type.']');
                    }
                }
                catch (Exception $e)
                {
                    $this->discord->getLogger()->error($e->getMessage(), [
                        'location'      => get_class($this).'->'.__FUNCTION__,
                        'attributes'    => $interaction->getRawAttributes(),
                    ]);
                }
            });

            //----------------------------------------------------------------------------------------------------------

            $this->attachOnGuildEvents();

            //----------------------------------------------------------------------------------------------------------

            // Periodically close DB connections so they do not go stale; a query in flight may be interrupted
            $this->discord->getLoop()->addPeriodicTimer(3*3600, function ($timer)
            {
                Vss::dbCloseAll();
            });
        });

        $this->discord->run();
        return true;
    }

    //------------------------------------------------------------------------------------------------------------------

    private function onMessage(Message $message)
    {
        DiscordBotGate::OnMessage($this->discord, $message);
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    private function onSlashCommand(Interaction $interaction): void
    {
        $this->discord->getLogger()->debug("Exec: onSlashCommand");

        $slashCommand = new SlashCommand();
        $slashCommand->setCommand($interaction->data->name);

        /** @var Option $option */
        foreach ($interaction->data->options as $option)
        {
            $slashCommand->setVar($option->name, $option->value);
        }

        DiscordBotGate::OnSlashCommand($this->discord, $interaction, $slashCommand);
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    private function onButtonClicked(Interaction $interaction): void
    {
        $this->discord->getLogger()->debug("Exec: onButtonClicked");

        $callbackAction = CallbackAction::Initialize($interaction->data->custom_id);

        if ($callbackAction === null)
            return;

        DiscordBotGate::OnCallback($this->discord, $interaction, $callbackAction);
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    private function onModalSubmit(Interaction $interaction): void
    {
        /* Modal submit: the original message cannot be updated from here (neither updateMessage() nor
         * updateOriginalResponse() works), only from the modal submit callback. So the callback always updates the
         * original message to "[v] Done", and the notifications built here start with a System_Skip message that
         * only acknowledges the interaction (see DiscordBotConnection::sendBotNotifications()).
         */

        $this->discord->getLogger()->debug("Exec: onModalSubmit");

        $callbackAction = CallbackAction::Initialize($interaction->data->custom_id);

        if ($callbackAction === null)
            return;

        $callbackAction->data['modal'] = [];
        $this->unpackModalIncomingVars($callbackAction->data['modal'], $interaction->data->components);

        DiscordBotGate::OnCallback($this->discord, $interaction, $callbackAction);
    }

    private function unpackModalIncomingVars(array &$output, ?ExCollectionInterface $components): void
    {
        if ($components == null)
            return;

        /** @var Component $component */
        foreach ($components as $component)
        {
            if ($component->type == \Discord\Builders\Components\Component::TYPE_ACTION_ROW)
                $this->unpackModalIncomingVars($output, $component->components);

            if ($component->type == \Discord\Builders\Components\Component::TYPE_TEXT_INPUT)
                $output[$component->custom_id] = $component->value;
        }
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    private function onAutocomplete(Interaction $interaction): void
    {
        $this->discord->getLogger()->debug("Exec: onAutocomplete");

        $slashCommand = new SlashCommand();
        $slashCommand->setCommand($interaction->data->name);

        foreach ($interaction->data->options as $option)
        {
            if ($option->focused)
            {
                $slashCommand->focusedOption = $option;
                break;
            }
        }

        DiscordBotGate::OnAutocomplete($this->discord, $interaction, $slashCommand);
    }

    // The 'hi' auto-join reads the text of guild messages: that needs the privileged MESSAGE_CONTENT intent
    protected function intents(): int
    {
        $intents = parent::intents();

        if (\CryptoNoises\Config::cfg()->discordChannelAutoJoin_hi)
            $intents |= \Discord\WebSockets\Intents::MESSAGE_CONTENT;

        return $intents;
    }
}
