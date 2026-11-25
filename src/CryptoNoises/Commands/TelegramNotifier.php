<?php

namespace CryptoNoises\Commands;

use CryptoNoises\Bots\Connections\TelegramBotConnection;
use CryptoNoises\Bots\Gates\TelegramBotGate;
use CryptoNoises\Config;
use Exception;

class TelegramNotifier extends AbstractNotifier
{
    /** @var TelegramBotGate[] */
    protected array $botsGates = [];

    public function __construct()
    {
        parent::__construct();

        Config::cfg()->initializeByPlatform(Config::kPLATFORM_Telegram);
    }

    protected function platform(): string
    {
        return Config::kPLATFORM_Telegram;
    }

    /**
     * @throws Exception
     */
    protected function getBotGate(int $userId): TelegramBotGate
    {
        if (!isset($this->botsGates[$userId]))
        {
            $tgBotConnection = new TelegramBotConnection();

            if (!$tgBotConnection->initUserById($userId))
                throw new Exception('Cannot initialize Telegram user by userId#'.$userId);

            $this->botsGates[$userId] = new TelegramBotGate($tgBotConnection);
        }

        return $this->botsGates[$userId];
    }
}
