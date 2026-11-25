<?php

namespace CryptoNoises\Bots\Gates;

use CryptoNoises\Bots\AbstractBot;
use CryptoNoises\Bots\AbstractBotGate;
use CryptoNoises\Bots\Connections\MatrixBotConnection;
use CryptoNoises\Bots\Core\NotificationSendFailedException;
use CryptoNoises\Bots\Core\NotificationSendSkippedException;
use CryptoNoises\Bots\Core\SlashCommand;
use CryptoNoises\Helpers\Helper;
use CryptoNoises\Localize\Notification;
use CryptoNoises\Localize\NotificationMatrix;

/**
 * The Matrix bot: delivers notifications, and answers a few text commands in a direct message ("!add tz1…",
 * "!wallets", ...). The wallet commands are the shared ones the Telegram bot uses, in its conversational form: "!add"
 * alone asks for the address, and the next message is taken as the answer.
 */
class MatrixBotGate extends AbstractBot implements AbstractBotGate
{
    private MatrixBotConnection $connection;

    public function __construct(MatrixBotConnection $connection)
    {
        $this->connection = $connection;
    }

    //------------------------------------------------------------------------------------------------------------------
    // Notifier

    public function isRecipientActive(): bool
    {
        return $this->connection->user->isActive();
    }

    /**
     * @throws NotificationSendFailedException
     * @throws NotificationSendSkippedException
     */
    public function notifyEventFlow(array $notificationItem, array $eventItem): void
    {
        $result = $this->doNotifyEventFlow($this->connection->user, $notificationItem, $eventItem);
        $this->connection->deliverBotNotifications($result);
    }

    /**
     * @throws NotificationSendFailedException
     */
    public function notifyInformFlow(array $notificationItem, array $informItem): bool
    {
        $result = $this->doNotifyInformFlow($this->connection->user, $informItem);
        $this->connection->deliverBotNotifications($result);

        return $result !== null;
    }

    public function sendNotifications(?Notification $notification): void
    {
        $this->connection->sendBotNotifications($notification);
    }

    //------------------------------------------------------------------------------------------------------------------
    // Commands

    public function welcome(): void
    {
        $this->sendNotifications(NotificationMatrix\Help::I($this->connection->user, true));
    }

    /** @return bool whether the message was understood */
    public function routeByUserMessage(string $message): bool
    {
        $user    = $this->connection->user;
        $message = trim($message);

        // "!add tz1…", or the same without the "!"; "/" too, for clients that let it through
        [$word, $query] = Helper::SplitBySpaces(ltrim($message, '!/'), 2);
        $isCommand = $message !== '' && ($message[0] === '!' || $message[0] === '/');

        switch ($isCommand || $user->getNextAction() === null ? strtolower((string) $word) : '')
        {
            case 'start':
            case 'help':
                $user->cancelNextAction();
                $this->sendNotifications(NotificationMatrix\Help::I($user));
                return true;

            case 'wallets':
            case 'list':
                $this->sendNotifications($this->doWalletsList($user));
                return true;

            case 'summary':
            case 'balances':
                $this->sendNotifications($this->doWalletsSummary($user));
                return true;

            case 'add':
            case 'add_wallet':
                $user->cancelNextAction();
                $this->sendNotifications($this->doWalletAdd($this, $user, $query));
                return true;

            case 'remove':
            case 'delete':
            case 'remove_wallet':
            case 'delete_wallet':
                $user->cancelNextAction();
                $this->sendNotifications($this->doWalletDelete($user, $query));
                return true;

            case 'stop':
            case 'pause':
                $user->deactivate('Paused on Matrix');
                $this->sendNotifications(Notification\Custom::I('⏸ Notifications paused. Send me anything to resume them.'));
                return true;

            case 'cancel':
                $user->cancelNextAction();
                $this->sendNotifications(Notification\Custom::I('OK, cancelled.'));
                return true;
        }

        // The answer to "!add" / "!remove" asked without an address
        $userAction = $user->getNextAction();

        if (!empty($userAction))
        {
            $action = SlashCommand::NewByUserAction($userAction);

            switch ($action->command)
            {
                case SlashCommand::ADD_WALLET:      $this->sendNotifications($this->doWalletAdd($this, $user, $message)); return true;
                case SlashCommand::DELETE_WALLET:   $this->sendNotifications($this->doWalletDelete($user, $message)); return true;
            }
        }

        // A bare Tezos address: watch it
        if (preg_match('/^(tz[1-4]|KT1)[1-9A-HJ-NP-Za-km-z]{33}\b/', $message))
        {
            $this->sendNotifications($this->doWalletAdd($this, $user, $message));
            return true;
        }

        $this->sendNotifications(Notification\Custom::I("Sorry, I don't understand this. Send <code>!help</code> for what I can do."));
        return false;
    }
}
