<?php

namespace CryptoNoises\Networks\Tezos\Hunters;

use CryptoCore\Setapp;
use CryptoNoises\Core\CustomData;
use CryptoNoises\Core\Log;
use CryptoNoises\Entities\EventFlow;
use CryptoNoises\Entities\TokenActions;
use CryptoNoises\Enum;
use CryptoNoises\Networks\Tezos\TezosNetwork;
use Exception;

/**
 * https://api.akaswap.com/v2/doc/index.html#section/Introduction
 */
abstract class AkaSwap extends AbstractHunter
{
    public const kTokensKTID                        = 'KT1AFq5XorPduoYyWxs5gEyrFK6fVjJVbtCj';

    public const kAkaDaoKTID                        = 'KT1AM3PV1cwmGRw28DVTgsjjsjHvmL6z4rGh';

    public const kMarketplaceKTID                   = 'KT1Dn3sambs7KZGW88hH2obZeSzfmCmGvpFo'; # akaMetaverse V2.1

    // The two swap contracts before V2.1. V2 stores a swap exactly as V2.1 does; V1 predates multi-FA2 support,
    // so it names its fields after akaOBJ and only ever traded kTokensKTID.
    public const kMarketplaceKTID_v2                = 'KT1Qieo8hJWj2rHFfe8BRqnMHXYga9av89GJ'; # akaSwap V2
    public const kMarketplaceKTID_v1                = 'KT1HGL8vx7DP4xETVikL4LUYvFxSV19DxdFN'; # akaSwap V1
    public const kAuctionKTID                       = 'KT1B2BN4qgmtsaoLRhhnFVDNoTyybwTfdrE4'; # akaAuction V2
    public const kOfferKTID                         = 'KT1J2C7BsYNnSjQsGoyrSXShhYGkrDDLVGDd'; # akaOffer

    public const kMarketplaceFeeWallet              = 'tz1WCYsbPyHTBcnj4saWG6SRFHECCj2TTzC6';

    //------------------------------------------------------------------------------------------------------------------

    public static function GetAllMarketContracts(): array
    {
        return [self::kMarketplaceKTID, self::kMarketplaceKTID_v2, self::kMarketplaceKTID_v1,
                self::kAuctionKTID, self::kOfferKTID];
    }

    public static function GetMarketplaceContractByTokenContract(string $tokensContract): ?string
    {
        if ($tokensContract == self::kTokensKTID)
            return self::kMarketplaceKTID;

        return null;
    }

    protected static function IsSupportedTokensContract(?string $tokensContract): bool
    {
        if ($tokensContract == 'KT1VTBuWpY5f4sEdCHVWRSn99yUS5HqVWVk2' ||    # token/akaswap-asmeir
            $tokensContract == Setapp\FxHash_v1::TokensKTID             ||  # token/akaswap-fxhash
            $tokensContract == Setapp\FxHash_v0::TokensKTID             ||  # token/akaswap-fxhash-genesis
            $tokensContract == 'KT1RJ6PbjHpwc3M5rw5s2Nbmefwbuwbdxton' ||    # token/akaswap-hen
            $tokensContract == 'KT1Xphnv7A1sUgRwZsecmAGFWm7WNxJz76ax' ||    # token/akaswap-tezdozen
            $tokensContract == 'KT1ShjqosdcqJBhaabPvkCwoXtS1R2dEbx4W' ||    # token/akaswap-tgguardian
            $tokensContract == 'KT1DEwdmXvjbdCz3HcehrYGiV46rVAwDiYVk' ||    # token/akaswap-taga
            $tokensContract == 'KT1MYSapB87YGSm1zxN3pbBGWDxea9YCkPH8' ||    # token/akaswap-showroom
            $tokensContract == 'KT1NS9Z59NvQkFvxNYiXkdbTDEzWWdJNbzoJ' ||    # token/akaswap-nuitblanche
            $tokensContract == self::kTokensKTID)                           # token/akaswap
            return true;

        return false;
    }

    //------------------------------------------------------------------------------------------------------------------

    /**
     * @throws Exception
     */
    protected function unpackTransfersEvent(array $subRecords): TokenActions\Transfer
    {
        if (($recTransfer = self::ExtractRecordByEntrypoint($subRecords, 'transfer')) == null)
            throw new Exception('Cannot find transfer transaction-record');

        $transfers = $this->unpackTransfersEvents($recTransfer);

        if (empty($transfers))
            throw new Exception('Cannot detect transfer records');

        if (count($transfers) > 1)
            throw new Exception('Too many transfer records');

        return $transfers[0];
    }

    protected function analyzePurchaseEvent(array $recInit, array $subRecords, ?string $eventDetails=null): bool
    {
        try
        {
            $recAuctionBidReturn = null;

            if ($eventDetails == Enum\EventFlowDetails::AuctionConclude)
            {
                // The bid refund may be the first element of the list
                $recAuctionBidReturn = array_shift($subRecords);

                if ($recAuctionBidReturn['amount'] == 0)
                {
                    array_unshift($subRecords, $recAuctionBidReturn); // move it back!
                    $recAuctionBidReturn = null;
                }
            }

            //----------------------------------------------------------------------------------------------------------
            // Remove records that are not needed

            self::ExtractRecordByEntrypoint($subRecords, 'get_royalty');
            self::ExtractRecordByEntrypoint($subRecords, 'handle_fee_callback');
            self::ExtractRecordByEntrypoint($subRecords, 'assign_akaDAO');

            //----------------------------------------------------------------------------------------------------------
            // Extract akaDAO transfers

            $recTransferDAOs = [];

            while ($recTransferDAO = self::ExtractRecordByTargetAddress($subRecords, self::kAkaDaoKTID))
            {
                $recTransferDAOs[] = $recTransferDAO;
            }

            //----------------------------------------------------------------------------------------------------------
            // Purchase

            if ($this->analyzeStdTokenPurchaseEvent($recInit, $subRecords, self::kMarketplaceFeeWallet, $eventDetails) == false)
                return false;

            //----------------------------------------------------------------------------------------------------------
            // Transfer akaDAO tokens

            foreach ($recTransferDAOs as $recTransferDAO)
            {
                $transfers = $this->unpackTransfersEvents($recTransferDAO);

                if (empty($transfers))
                    throw new Exception('Cannot detect transfer records (DAO)');

                if (count($transfers) > 1)
                    throw new Exception('Too many transfer records (DAO)');

                $transfer = $transfers[0];

                $event = new EventFlow();
                $event->initInTezosNetworkByRecord($recTransferDAO);

                $event->event_type              = Enum\EventFlowType::TokenTransfer;
                $event->sender_address          = $transfer->sender;
                $event->target_address          = $transfer->target;

                $event->quantity                = $transfer->quantity;

                $event->token_address           = $transfer->tokenAddress;
                $event->token_id                = $transfer->tokenId;

                $this->parser->pushEvent($event);
            }

            //----------------------------------------------------------------------------------------------------------

            if ($recAuctionBidReturn != null)
            {
                $transfer = $this->unpackTransfersEvent($subRecords);

                $event = new EventFlow();
                $event->initInTezosNetworkByRecord($recInit, 1);

                $event->event_type              = Enum\EventFlowType::AuctionOutBid;
                $event->sender_address          = $recAuctionBidReturn['sender'];
                $event->target_address          = $recAuctionBidReturn['target'];
                $event->royalty_address         = null;

                $event->price                   = $recAuctionBidReturn['amount'];
                $event->currency                = TezosNetwork::kCURRENCY_ID;

                $event->marketplace_address     = $recInit['target'];
                $event->token_address           = $transfer->tokenAddress;
                $event->token_id                = $transfer->tokenId;

                $event->custom_data             = [
                    CustomData::kAUCTION_ID         => $recInit['parameter']['value'],
                    CustomData::kAUCTION_TOP_BID    => $recInit['amount'],
                    CustomData::kAUCTION_TOP_OWNER  => $recInit['sender'],
                ];

                $this->parser->pushEvent($event);
            }

            return true;
        }
        catch (Exception $e)
        {
            Log::Warning(get_class($this), __FUNCTION__, $e->getMessage()." #{$recInit['id']}", $recInit);
            return false;
        }
    }
}
