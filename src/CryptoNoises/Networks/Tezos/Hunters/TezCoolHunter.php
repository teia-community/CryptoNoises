<?php

namespace CryptoNoises\Networks\Tezos\Hunters;

use CryptoCore\Setapp;
use CryptoNoises\Core\Log;
use CryptoNoises\Entities\EventFlow;
use CryptoNoises\Enum;
use CryptoNoises\GatewayTezos;
use CryptoNoisesTezos\Vendors\Tzkt;
use Exception;

/**
 * Messages addressed to a wallet in tez.cool's chats (see Setapp\TezCool): a Chad Chat message, minted to the
 * recipient as an NFT, and a reply in the Trollbox to someone's message.
 *
 * The message text is deliberately not passed on. It is written by any sender, and putting it in a bot notification
 * would make the bot a channel for spam and phishing links; the notification links to the chat instead, where the
 * recipient reads it (and, for an encrypted message, decrypts it).
 */
class TezCoolHunter extends AbstractHunter
{
    public static function GetAllMarketContracts(): array
    {
        return [Setapp\TezCool::ChadChatKTID, Setapp\TezCool::TrollboxKTID];
    }

    public static function GetMarketplaceContractByTokenContract(string $tokensContract): ?string
    {
        return null;
    }

    //------------------------------------------------------------------------------------------------------------------

    public function parseTransaction(array $recInit, array $subRecords): bool
    {
        if ($recInit['target'] == Setapp\TezCool::ChadChatKTID && $recInit['entrypoint'] == 'mint')
            return $this->chadChatMessage($recInit, $subRecords);

        if ($recInit['target'] == Setapp\TezCool::TrollboxKTID && $recInit['entrypoint'] == 'post_message')
            return $this->trollboxPost($recInit, $subRecords);

        return false;
    }

    private function chadChatMessage(array $recInit, array $subRecords): bool
    {
        $value     = $recInit['parameter']['value'] ?? [];
        $recipient = $value['to_'] ?? null;

        // To oneself: publishing encryption keys (key_reg / pk), or a test - nobody to tell
        if ($recipient === null || $recipient == $recInit['sender'])
            return $this->fixEventAsSkipped($recInit, $subRecords);

        $this->pushMessageEvent($recInit, Enum\EventFlowType::ChatMessage, $recInit['sender'], $recipient, [
            'encrypted' => isset($value['metadata']['enc']),
        ]);

        return true;
    }

    private function trollboxPost(array $recInit, array $subRecords): bool
    {
        try
        {
            $replyTo = $recInit['parameter']['value']['reply_to'] ?? null;

            // A message to the room is addressed to nobody
            if ($replyTo === null)
                return $this->fixEventAsSkipped($recInit, $subRecords);

            // From this indexer's copy of the `messages` bigmap; from TzKT when that does not have it (an install that
            // started after the message was posted)
            $stored = GatewayTezos\BigmapGate::GetValueByBigmapAndKey(Setapp\TezCool::Bigmap_TrollboxMessages, (string) $replyTo);

            if (!is_array($stored) || empty($stored['author']))
                $stored = Tzkt::GetBigmapKeyValue(Setapp\TezCool::Bigmap_TrollboxMessages, (string) $replyTo);

            $author = is_array($stored) ? ($stored['author'] ?? null) : null;

            // An unknown message (not indexed here), or a reply to oneself
            if ($author === null || $author == $recInit['sender'])
                return $this->fixEventAsSkipped($recInit, $subRecords);

            $this->pushMessageEvent($recInit, Enum\EventFlowType::ChatReply, $recInit['sender'], $author, []);
            return true;
        }
        catch (Exception $e)
        {
            Log::Warning(get_class($this), __FUNCTION__, $e->getMessage()." #{$recInit['id']}");
            return false;
        }
    }

    private function pushMessageEvent(array $recInit, string $eventType, string $sender, string $recipient, array $custom): void
    {
        $event = new EventFlow();
        $event->initInTezosNetworkByRecord($recInit);

        $event->event_type      = $eventType;
        $event->sender_address  = $sender;
        $event->target_address  = $recipient;
        $event->custom_data     = $custom ?: null;

        $this->parser->pushEvent($event);
    }
}
