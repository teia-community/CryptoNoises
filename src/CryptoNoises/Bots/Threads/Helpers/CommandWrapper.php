<?php

namespace CryptoNoises\Bots\Threads\Helpers;

use Discord\Discord;
use Discord\Parts\Application\Command\Command;
use Discord\Parts\Application\Command\Option;

class CommandWrapper
{
    private Discord $discord;
    private array $attributes;

    public /*readonly*/ string $name;

    public static function New(Discord $discord, string $name, string $description): self
    {
        $instance = new self();
        $instance->discord = $discord;
        $instance->name = $name;
        $instance->attributes = [
            'name'        => $name,
            'description' => $description,
            'options'     => [],
        ];
        return $instance;
    }

    public function appendAddressOption(string $description, bool $isRequired = false): self
    {
        try
        {
            $option = new Option($this->discord);
            $option->setType(Option::STRING);
            $option->setName("address");
            $option->setDescription($description);
            $option->setRequired($isRequired);
            $option->setAutoComplete(true);

            $this->attributes['options'][] = $option;
        }
        catch (\Exception $e)
        {
            $this->discord->getLogger()->notice("Error@appendAddressOption: {$e->getMessage()}");
        }

        return $this;
    }

    public function appendTokenIdOption(string $description, bool $isRequired = false): self
    {
        try
        {
            $option = new Option($this->discord);
            $option->setType(Option::STRING);
            $option->setName("token_id");
            $option->setDescription($description);
            $option->setRequired($isRequired);

            $this->attributes['options'][] = $option;
        }
        catch (\Exception $e)
        {
            $this->discord->getLogger()->notice("Error@appendTokenIdOption: {$e->getMessage()}");
        }

        return $this;
    }

    public function buildCommand(): Command
    {
        return new Command($this->discord, $this->attributes);
    }
}
