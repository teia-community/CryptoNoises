<?php

namespace CryptoNoises\Commands;

use CryptoNoises\Bots\Connections\MatrixBotConnection;
use CryptoNoises\Bots\Gates\MatrixBotGate;
use CryptoNoises\Config;
use Exception;

/** The Matrix notification queues; see AbstractNotifier */
class MatrixNotifier extends AbstractNotifier
{
    /** @var MatrixBotGate[] */
    protected array $botsGates = [];

    public function __construct()
    {
        parent::__construct();

        Config::cfg()->initializeByPlatform(Config::kPLATFORM_Matrix);
    }

    protected function platform(): string
    {
        return Config::kPLATFORM_Matrix;
    }

    protected function lockId(string $queue): string
    {
        return 'command-lock.notifier.matrix.'.$queue;
    }

    /**
     * @throws Exception
     */
    protected function getBotGate(int $userId): MatrixBotGate
    {
        if (!isset($this->botsGates[$userId]))
        {
            $connection = new MatrixBotConnection();

            if (!$connection->initUserById($userId))
                throw new Exception('Cannot initialize Matrix user by userId#'.$userId);

            $this->botsGates[$userId] = new MatrixBotGate($connection);
        }

        return $this->botsGates[$userId];
    }
}
