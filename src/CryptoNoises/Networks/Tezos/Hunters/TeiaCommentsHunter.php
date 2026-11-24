<?php

namespace CryptoNoises\Networks\Tezos\Hunters;

use CryptoCore\Setapp;
use CryptoNoises\Core\Log;
use CryptoNoises\Entities\EventFlow;
use CryptoNoises\Enum;
use CryptoNoises\GatewayTezos;
use Exception;

/**
 * Comments posted on a token through Teia's comments contract (teia.art).
 *
 * Who hears about a comment, and why:
 *
 *   - the artist, because a comment on their work is addressed to them. The creator comes from this project's
 *     own token data, so a token it has never indexed produces no notification rather than a wrong one.
 *   - the author of the comment being answered, when the call carries a parent_id. That author is read from
 *     the contract's `comments` bigmap, which is indexed here like any other bigmap it does not recognise.
 *
 * Nobody is told about their own comment, and an artist who answers under their own work is not notified twice.
 *
 * The text is deliberately not part of the notification. It lives on IPFS - `content` is the bytes of an
 * ipfs:// URI - so quoting it would mean a gateway fetch on the notification path, which is the one place in
 * this project that cannot afford to wait on IPFS. The notification links to the token instead, where the
 * comment is shown.
 *
 * Only post_comment is a notification. edit_comment, set_own_comment_hidden and moderate_comment_hidden change
 * a comment that was already announced, and the admin entrypoints are not anybody's news.
 */
class TeiaCommentsHunter extends AbstractHunter
{
    public const kCommentsKTID                      = Setapp\TeiaComments::CommentsKTID;

    //------------------------------------------------------------------------------------------------------------------

    public static function GetAllMarketContracts(): array
    {
        return [self::kCommentsKTID];
    }

    public static function GetMarketplaceContractByTokenContract(string $tokensContract): ?string
    {
        return null;
    }

    //------------------------------------------------------------------------------------------------------------------

    public function parseTransaction(array $recInit, array $subRecords): bool
    {
        if ($recInit['target'] != self::kCommentsKTID)
            return false;

        if ($recInit['entrypoint'] == 'post_comment')
            return $this->analyzeCommentEvent($recInit, $subRecords);

        // A comment that is edited, hidden by its author or hidden by moderation was already announced when it
        // was posted. Marking those as handled keeps them out of the "unknown contract" reports.
        if (in_array($recInit['entrypoint'], ['edit_comment', 'set_own_comment_hidden', 'moderate_comment_hidden'], true))
            return $this->fixEventAsSkipped($recInit, $subRecords);

        return false;
    }

    //------------------------------------------------------------------------------------------------------------------

    private function analyzeCommentEvent(array $recInit, array $subRecords): bool
    {
        try
        {
            $recValue = $recInit['parameter']['value'] ?? null;

            if (!is_array($recValue))
                throw new Exception('post_comment without parameters');

            $tokenAddress   = $recValue['fa2_address'] ?? null;
            $tokenId        = $recValue['token_id'] ?? null;
            $parentId       = $recValue['parent_id'] ?? null;
            $commenter      = $recInit['sender'] ?? null;

            if ($tokenAddress === null || $tokenId === null || $commenter === null)
                throw new Exception('post_comment is missing the token or the sender');

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
            // The artist

            $tokenEntity = GatewayTezos\Tokens::GetTokenDetails($tokenAddress, (string) $tokenId);
            $creator     = $tokenEntity ? $tokenEntity->creatorAddress : null;

            $pushed = false;

            if ($creator !== null && $creator !== $commenter)
            {
                $this->pushCommentEvent($recInit, Enum\EventFlowType::TokenComment,
                    $commenter, $creator, $tokenAddress, (string) $tokenId);
                $pushed = true;
            }

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
            // The author of the comment being answered

            if ($parentId !== null)
            {
                $parentAuthor = $this->getCommentAuthor($parentId);

                if ($parentAuthor !== null && $parentAuthor !== $commenter && $parentAuthor !== $creator)
                {
                    $this->pushCommentEvent($recInit, Enum\EventFlowType::TokenCommentReply,
                        $commenter, $parentAuthor, $tokenAddress, (string) $tokenId);
                    $pushed = true;
                }
            }

            // A comment on a token this project has not indexed, or one an artist leaves under their own work,
            // is handled and simply has nobody to go to.
            if (!$pushed)
                return $this->fixEventAsSkipped($recInit, $subRecords);

            return true;
        }
        catch (Exception $e)
        {
            Log::Warning(get_class($this), __FUNCTION__, $e->getMessage()." #{$recInit['id']}", $recInit);
            return false;
        }
    }

    private function pushCommentEvent(array $recInit, string $eventType, string $commenter, string $recipient,
                                      string $tokenAddress, string $tokenId): void
    {
        $event = new EventFlow();
        $event->initInTezosNetworkByRecord($recInit);

        $event->event_type              = $eventType;

        $event->sender_address          = $commenter;
        $event->target_address          = $recipient;

        $event->price                   = null;
        $event->quantity                = 1;

        // The comments contract stands in as the "marketplace" so the token and profile links of a notification
        // resolve through the usual marketplaces / market_urls reference data, which points it at teia.art.
        $event->marketplace_address     = $recInit['target'];
        $event->token_address           = $tokenAddress;
        $event->token_id                = $tokenId;

        $this->parser->pushEvent($event);
    }

    /** Sender of a stored comment, from the contract's own bigmap (null when it is not indexed here). */
    private function getCommentAuthor($commentId): ?string
    {
        $value = GatewayTezos\BigmapGate::GetValueByBigmapAndKey(
            Setapp\TeiaComments::Bigmap_Comments, (string) $commentId);

        return is_array($value) ? ($value['sender'] ?? null) : null;
    }
}
