<?php

namespace CryptoNoises\Bots\Gates;

use CryptoNoises\Bots\Autocompletes;
use CryptoNoises\Bots\Core\SlashCommand;
use CryptoNoises\Localize\Phrase;
use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\Application\Command\Choice;
use Discord\Parts\Interactions\Interaction;

trait DiscordBotGate_Autocompletes
{
    public static function OnAutocomplete(Discord $discord, Interaction $interaction, SlashCommand $slashCommand): bool
    {

        $botGate = DiscordBotGate::NewByInteraction($discord, $interaction);

        if ($botGate === null)
        {
            $interaction->respondWithMessage(MessageBuilder::new()->setContent(Phrase::I()->systemError), true);

            $discord->getLogger()->error("Cannot initialize bot-gate", [
                'location'      => 'DiscordBotGate::OnAutocomplete',
                'attributes'    => $interaction->data->getRawAttributes(),
            ]);
            return false;
        }

        return $botGate->routeByAutocomplete($discord, $interaction, $slashCommand);
    }

    public function routeByAutocomplete(Discord $discord, Interaction $interaction, SlashCommand $slashCommand): bool
    {
        $choices = $this->generateAutocompleteChoices($discord, $interaction, $slashCommand);

        $choicesPacked = [];

        foreach ($choices as $key => $value)
        {
            $choicesPacked[] = Choice::new($discord, $value, $key);
        }

        $interaction->autoCompleteResult($choicesPacked);
        return true;
    }

    private function generateAutocompleteChoices(Discord $discord, Interaction $interaction, SlashCommand $slashCommand): array
    {
        if ($slashCommand->focusedOption === null)
            return [];

        $searchQuery = (string) $slashCommand->focusedOption->value;

        switch ($slashCommand->command.'::'.$slashCommand->focusedOption->name)
        {
            case SlashCommand::ADD_WALLET       .'::address':
            case SlashCommand::FOLLOW           .'::address':
                return Autocompletes::FillAddress($this->dcBotConnection->user, $searchQuery);

            case SlashCommand::DELETE_WALLET    .'::address':
                return Autocompletes::FillAddress_onDeleteUserWallet($this->dcBotConnection->user, $searchQuery);

            case SlashCommand::UNFOLLOW         .'::address':
                return Autocompletes::FillAddress_onUnfollow($this->dcBotConnection->user, $searchQuery);
        }

        return [];
    }
}
