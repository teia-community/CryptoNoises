<?php

namespace CryptoNoises\Bots\Threads;

use Discord\Discord;
use Discord\WebSockets\Intents;
use Exception;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use VssCfg;

abstract class DiscordAbstractThread
{
    protected string $discordToken;

    protected Discord $discord;

    public bool $logVerbose = false;

    public function __construct(string $discordToken)
    {
        $this->discordToken   = $discordToken;
    }

    protected function createDiscordInstance(): bool
    {
        try
        {
            $logger = new Logger('Logger');

            if ($this->logVerbose)
            {
                $logger->pushHandler(new StreamHandler('php://stdout', Level::Info));
            }
            else
            {
                $scope = explode('\\', get_class($this));
                $scope = array_pop($scope);

                $logger->pushHandler(new StreamHandler(VssCfg::cfg()->pathLog."/$scope-".date('Ymd').".log", Level::Warning));
            }

            $this->discord = new Discord([
                'token'   => $this->discordToken,
                'logger'  => $logger,
                'intents' => $this->intents(),

                // None of these threads ever joins a voice channel. Without this, DiscordPHP sets up its voice
                // client on every start and logs an ERROR, because Discord has required its DAVE end-to-end
                // encryption for voice since March 2026 and that needs libdave through ext-ffi. An ERROR on every
                // boot for a feature the bot does not have is noise that hides the ones that matter.
                'disableVoiceClient' => true,
            ]);
            return true;
        }
        catch (Exception $e)
        {
            // $this->discord is not initialized here (its constructor is what failed)
            error_log(get_class($this).'->'.__FUNCTION__.': '.$e->getMessage());
            return false;
        }
    }

    // Gateway intents of this thread; privileged intents must also be enabled in the Discord developer portal
    protected function intents(): int
    {
        return Intents::getDefaultIntents();
    }
}
