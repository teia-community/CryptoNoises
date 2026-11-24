<?php

namespace CryptoNoises\Bots\Threads;

use CryptoNoises\Bots\Core\SlashCommand;
use CryptoNoises\Bots\Threads\Helpers\CommandWrapper;
use CryptoNoises\Core\Log;
use Discord\Discord;
use Discord\Parts\Application\Command\Command;
use Exception;

class DiscordCommandsThread extends DiscordAbstractThread
{
    /** @var CommandWrapper[] */
    private array $createQueue = [];

    /** @var Command[] */
    private array $deleteQueue = [];

    /**
     * @return CommandWrapper[]
     */
    private function generateCommandsList(): array
    {
        $commands[] = CommandWrapper::New($this->discord,
            SlashCommand::START, 'Start here and the CryptoNoises bot will show you the magic.');

        $commands[] = CommandWrapper::New($this->discord,
            SlashCommand::HELP, 'Get the list of available commands.');

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $commands[] = CommandWrapper::New($this->discord,
            SlashCommand::SUMMARY, '📊 Show summary information about all your wallets.');

        $commands[] = CommandWrapper::New($this->discord,
            SlashCommand::WALLETS_LIST, 'ℹ️ Show your wallets and their settings.');

        $commands[] = CommandWrapper::New($this->discord,
            SlashCommand::ADD_WALLET, '➕ Add a new wallet to the bot.')
            ->appendAddressOption('Wallet address you want to add');

        $commands[] = CommandWrapper::New($this->discord,
            SlashCommand::DELETE_WALLET, '➖ Delete a wallet.')
            ->appendAddressOption('Wallet address you want to delete');

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $commands[] = CommandWrapper::New($this->discord,
            SlashCommand::FOLLOWING, '👥 Show users (wallets) you follow.');

        $commands[] = CommandWrapper::New($this->discord,
            SlashCommand::FOLLOW, '👥🔔 Follow users (by their wallets) to track Mint & List events.')
            ->appendAddressOption('Wallet address you want to follow');

        $commands[] = CommandWrapper::New($this->discord,
            SlashCommand::UNFOLLOW, '👥🔕 Unfollow users (by their wallets).')
            ->appendAddressOption('Wallet address you want to unfollow');

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $commands[] = CommandWrapper::New($this->discord,
            SlashCommand::DOMAINS, '🌐 Your domains');

        $commands[] = CommandWrapper::New($this->discord,
            SlashCommand::OFFERS, '🤝 Your offers on marketplaces');

        $commands[] = CommandWrapper::New($this->discord,
            SlashCommand::COINS, '💱 Show current coin rates.');

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $commands[] = CommandWrapper::New($this->discord,
            SlashCommand::SETTINGS, '⚙️ Settings.');

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $commands[] = CommandWrapper::New($this->discord,
            SlashCommand::REDIRECT_DM, '↩️ Redirect DM notifications to a Discord channel.');

        $commands[] = CommandWrapper::New($this->discord,
            SlashCommand::ABOUT_TOKEN, '🌅 View details about a token.')
            ->appendAddressOption('Token address', true)
            ->appendTokenIdOption('Token Id', true);

        return $commands;
    }

    public function show(): void
    {
        $this->logVerbose = true;

        if ($this->createDiscordInstance() == false)
            return;

        //--------------------------------------------------------------------------------------------------------------

        $this->discord->on('ready', function (Discord $discord)
        {
            $this->discord->getLogger()->notice("Ready");

            $this->discord->application->commands->freshen()->then(function ($commands)
            {
                $this->discord->getLogger()->notice("Received ".count($commands)." application commands");

                $wCommands = $this->generateCommandsList();

                $this->discord->getLogger()->notice("Commands list:");
                foreach ($commands as $command)
                {
                    $this->discord->getLogger()->notice("+ $command->name");

                    $wCommands = array_filter($wCommands, function ($wCmd) use ($command)
                    {
                        return $wCmd->name != $command->name;
                    });
                }
                $this->discord->getLogger()->notice(".");

                $this->discord->getLogger()->notice("Commands missed:");
                foreach ($wCommands as $wCmd)
                {
                    $this->discord->getLogger()->notice("- $wCmd->name");
                }
                $this->discord->getLogger()->notice(".");
            });
        });

        $this->discord->run();
    }

    //------------------------------------------------------------------------------------------------------------------
    // CREATE

    public function create(?string $filterCommands = null): void
    {
        $this->logVerbose = true;

        if ($this->createDiscordInstance() == false)
            return;

        //--------------------------------------------------------------------------------------------------------------

        $this->discord->on('ready', function (Discord $discord) use ($filterCommands)
        {
            $this->discord->getLogger()->notice("Ready");

            $wCommands = $this->generateCommandsList();

            $this->createQueue = array_filter($wCommands, function ($wCmd) use ($filterCommands)
            {
                return $this->isRequireProcessedCommand($wCmd->name, $filterCommands);
            });

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $this->createNextCommand();
        });

        $this->discord->run();
    }

    private function createNextCommand(): void
    {
        if (count($this->createQueue) == 0)
        {
            $this->discord->getLogger()->notice("DONE. All Created");
            return;
        }

        $this->sleep();

        $command = array_shift($this->createQueue);

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $this->discord->getLogger()->notice("Create command [{$command->name}]");

        try
        {
            $this->discord->application->commands->save($command->buildCommand())->then(
                function()
                {
                    $this->discord->getLogger()->notice(" > Done");
                    $this->createNextCommand();
                },
                function ($e)
                {
                    $this->discord->getLogger()->notice(" > FAILED");
                    $this->createNextCommand();
                });
        }
        catch (Exception $e)
        {
            $this->discord->getLogger()->error("ERROR: ".$e->getMessage());
        }
    }

    //------------------------------------------------------------------------------------------------------------------
    // DELETE

    public function delete(?string $filterCommands = null): void
    {
        $this->logVerbose = true;

        if ($this->createDiscordInstance() == false)
            return;

        //--------------------------------------------------------------------------------------------------------------

        $this->discord->on('ready', function (Discord $discord) use ($filterCommands)
        {
            $this->discord->getLogger()->notice("Ready");

            $this->discord->application->commands->freshen()->then(function ($commands) use ($filterCommands)
            {
                $this->discord->getLogger()->notice("Received ".count($commands)." application commands");

                foreach ($commands as $command)
                {
                    if ($this->isRequireProcessedCommand($command->name, $filterCommands))
                        $this->deleteQueue[] = $command;
                }

                $this->deleteNextCommand();
            });
        });

        $this->discord->run();
    }

    private function isRequireProcessedCommand(string $command, ?string $filterCommands): bool
    {
        $command = ltrim($command, '/');

        if ($filterCommands == null)
            $result = true;
        else
            $result = in_array($command, explode(',', $filterCommands));

        if ($result)
            $this->discord->getLogger()->notice("RequireProcessedCommand [$command] ? > YES");
        else
            $this->discord->getLogger()->notice("RequireProcessedCommand [$command] ? > NO");

        return $result;
    }

    private function deleteNextCommand(): void
    {
        if (count($this->deleteQueue) == 0)
        {
            $this->discord->getLogger()->notice("DONE. All Deleted");
            return;
        }

        $this->sleep();

        $command = array_shift($this->deleteQueue);

        $this->discord->getLogger()->notice("Delete command [$command->name]");

        try
        {
            $this->discord->application->commands->delete($command)->then(
                function()
                {
                    $this->discord->getLogger()->notice(" > Done");
                    $this->deleteNextCommand();
                },
                function ($e)
                {
                    $this->discord->getLogger()->notice(" > FAILED");
                    $this->deleteNextCommand();
                });
        }
        catch (Exception $e)
        {
            Log::Warning('DiscordCommandsThread', 'deleteNextCommand', $e->getMessage());
        }
    }

    //------------------------------------------------------------------------------------------------------------------

    private function sleep()
    {
        $this->discord->getLogger()->notice("Sleep 5 sec");
        sleep(5);
    }
}
