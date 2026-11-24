<?php

namespace CryptoNoises\Bots\Threads;

use CryptoNoises\Models;
use CryptoCore\Time;
use Discord\Builders\MessageBuilder;
use Discord\Http\Exceptions\NoPermissionsException;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Channel\Message\Message;
use Discord\Parts\Channel\Message\Embed\Embed;
use Exception;
use Throwable;

trait DiscordAssistantThread_Stats
{
    private function stats_sync(): void
    {
        $workerId  = self::kWORKER_ID_Stats.":main";

        $channelId = Models\Config::model()->getValue('discord.stats.main.channel-id');
        $messageId = Models\Config::model()->getValue('discord.stats.main.message-id');

        if ($channelId == null)
            return;

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $tzktLastBlock              = \CryptoNoisesTezos\Vendors\Tzkt::GetBlockLastIndex();

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $tezLastBlockFullySynced    = \CryptoNoises\GatewayTezos\Gateway::GetLastFullySyncedBlock();

        $tezLastBlockTxSynced       = \CryptoNoises\GatewayTezos\Gateway::GetLastTransactionsSyncedBlock();
        $tezLastBlockTxAnalyzed     = \CryptoNoises\GatewayTezos\Gateway::GetLastTransactionsAnalyzedBlock();
        $tezLastBlockBmSynced       = \CryptoNoises\GatewayTezos\Gateway::GetLastBigmapsSyncedBlock();
        $tezLastBlockBmAnalyzed     = \CryptoNoises\GatewayTezos\Gateway::GetLastBigmapsAnalyzedBlock();

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $apiLastBlockAnalyzed       = Models\FixerTezosTransactionsAnalyze::model()->getLastSuccessJobItem();
        $apiLastBlockAnalyzed       = $apiLastBlockAnalyzed ? (int) $apiLastBlockAnalyzed['level'] : null;

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $messageBuilder = MessageBuilder::new();
        $embed = new Embed($this->discord);

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $description = [];
        $description[] = "**Blockchain**";
        $description[] = "```";
        $description[] = $this->getStatsRecord('Last Block',             $tzktLastBlock,             $tzktLastBlock);
        $description[] = "```";
        $description[] = "**Tezos.Syncer**";
        $description[] = "```";
        $description[] = $this->getStatsRecord('Transaction',         $tezLastBlockTxSynced,      $tzktLastBlock);
        $description[] = $this->getStatsRecord('Bigmaps',             $tezLastBlockBmSynced,      $tzktLastBlock);
        $description[] = $this->getStatsRecord('Fully-Synced',        $tezLastBlockFullySynced,   $tzktLastBlock);
        $description[] = "```";
        $description[] = "**Tezos.Analyzer**";
        $description[] = "```";
        $description[] = $this->getStatsRecord('Transaction',       $tezLastBlockTxAnalyzed,    $tzktLastBlock);
        $description[] = $this->getStatsRecord('Bigmaps',           $tezLastBlockBmAnalyzed,    $tzktLastBlock);
        $description[] = "```";
        $description[] = "**CryptoNoises.Analyzer**";
        $description[] = "```";
        $description[] = $this->getStatsRecord('Transactions',        $apiLastBlockAnalyzed,      $tzktLastBlock);
        $description[] = "```";

        $embed->setDescription(join("\n", $description));

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $embed->setFooter(Time::Now());
        $messageBuilder->addEmbed($embed);

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $this->stats_syncMessage($workerId, $channelId, $messageId, $messageBuilder);
    }

    private function getStatsRecord(string $title, ?int $lastBlockInStat, ?int $lastBlockGlobal): string
    {
        $value = $lastBlockInStat ?? '?';

        if ($lastBlockInStat !== null && $lastBlockGlobal !== null)
        {
            $diff = $lastBlockInStat - $lastBlockGlobal;

            if ($diff != 0)
            {
                if ($diff < 0)
                    $value .= " (".abs($diff)." behind)";
                else
                    $value .= " ($diff ahead)";
            }
        }

        return "$title: ".str_repeat(' ', 16-strlen($title)).$value;
    }

    //------------------------------------------------------------------------------------------------------------------

    private function stats_syncMessage(string $workerId, string $channelId, ?string $messageId, MessageBuilder $messageBuilder): void
    {
        if ($this->isWorkerActive($workerId))
            return;

        try
        {
            $this->activateWorker($workerId);

            $channel = $this->discord->getChannel($channelId);

            if ($channel === null)
                throw new Exception("Cannot find channel #$channelId");

            if ($messageId == null)
            {
                $this->stats_createMessage($workerId, $channel, $messageBuilder);
                return;
            }

            $this->stats_updateMessageById($workerId, $channel, $messageId, $messageBuilder);
        }
        catch (Exception $e)
        {
            $this->stats_closeError($workerId, $e->getMessage());
        }
    }

    //------------------------------------------------------------------------------------------------------------------

    private function stats_createMessage(string $workerId, Channel $channel, MessageBuilder $messageBuilder): void
    {
        try
        {
            $channel->sendMessage($messageBuilder)->then(
                function (Message $message) use ($workerId)
                {
                    Models\Config::model()->setValue('discord.stats.main.message-id', $message->id);

                    $this->stats_closeSuccess($workerId);
                },
                function ($error) use ($workerId)
                {
                    $this->stats_closeError($workerId, $error instanceof Throwable ? $error->getMessage() : 'sendMessage failed');
                });
        }
        catch (NoPermissionsException $e)
        {
            $this->stats_closeError($workerId, $e->getMessage());
        }
    }

    private function stats_updateMessageById(string $workerId, Channel $channel, string $messageId, MessageBuilder $messageBuilder): void
    {
        try
        {
            $channel->messages->fetch($messageId)->then(
                function (Message $message) use ($workerId, $messageBuilder)
                {
                    $message->edit($messageBuilder);
                    $this->stats_closeSuccess($workerId);
                },
                function ($error) use ($workerId, $channel, $messageBuilder)
                {
                    $this->stats_createMessage($workerId, $channel, $messageBuilder);
                });
        }
        catch (Exception $e)
        {
            $this->stats_closeError($workerId, $e->getMessage());
        }
    }

    //------------------------------------------------------------------------------------------------------------------

    private function stats_closeSuccess(string $workerId): void
    {
        $this->closeWorker($workerId);
    }

    private function stats_closeError(string $workerId, string $errorMessage): void
    {
        $this->discord->getLogger()->error("ERR: ".$errorMessage, [
            'location'      => get_class($this).'->'.__FUNCTION__,
            'workerId'      => $workerId,
        ]);

        $this->closeWorker($workerId);
    }
}
