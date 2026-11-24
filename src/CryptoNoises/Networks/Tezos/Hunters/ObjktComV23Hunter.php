<?php

namespace CryptoNoises\Networks\Tezos\Hunters;

use CryptoCore\Setapp;
use CryptoNoises\Core\Log;
use CryptoNoises\Entities\EventFlow;
use CryptoNoises\Entities\TokenActions;
use CryptoNoises\Enum;
use CryptoNoises\Networks\Tezos\TezosNetwork;
use Exception;

abstract class ObjktComV23Hunter extends AbstractHunter
{
    protected const kHunterVersion4                 = '4.0';
    protected const kHunterVersion3                 = '3.0';
    protected const kHunterVersion3a                = '3.alpha';
    protected const kHunterVersion2                 = '2.0';

    // objkt.com's "marketplace v6.2" and "english auction v6.1": same parameters, bigmap values and fee wallet
    // as v3, so they run through the same handlers. See Setapp\ObjktCom_v4 for the version-name mapping.
    protected const kMarketplaceKTID_v4             = Setapp\ObjktCom_v4::MarketplaceKTID;
    protected const kEnglishAuctionsKTID_v4         = Setapp\ObjktCom_v4::EnglishAuctionsKTID;
    protected const kMarketplaceFeeWallet_v4        = Setapp\ObjktCom_v4::MarketplaceFeeWallet;

    protected const kMarketplaceKTID_v3             = Setapp\ObjktCom_v3::MarketplaceKTID;
    protected const kEnglishAuctionsKTID_v3         = Setapp\ObjktCom_v3::EnglishAuctionsKTID;
    protected const kDutchAuctionsKTID_v3           = Setapp\ObjktCom_v3::DutchAuctionsKTID;
    protected const kMarketplaceFeeWallet_v3        = Setapp\ObjktCom_v3::MarketplaceFeeWallet;

    protected const kMarketplaceKTID_v3a            = Setapp\ObjktCom_v3a::MarketplaceKTID;
    protected const kMarketplaceFeeWallet_v3a       = Setapp\ObjktCom_v3a::MarketplaceFeeWallet;

    protected const kMarketplaceKTID_v2             = Setapp\ObjktCom_v2::MarketplaceKTID;
    protected const kEnglishAuctionsKTID_v2         = Setapp\ObjktCom_v2::EnglishAuctionsKTID;
    protected const kDutchAuctionsKTID_v2           = Setapp\ObjktCom_v2::DutchAuctionsKTID;
    protected const kMarketplaceFeeWallet_v2        = Setapp\ObjktCom_v2::MarketplaceFeeWallet;

    protected const kWrapperKTID                    = Setapp\ObjktCom::TokenKTID_oXTZ;

    //------------------------------------------------------------------------------------------------------------------
    // The following fields are set per call, depending on the target contract

    protected ?string $kHunterVersion               = null; // should be self::kHunterVersion...

    protected ?string $kMarketplaceKTID             = null;
    protected ?string $kEnglishAuctionsKTID         = null;
    protected ?string $kDutchAuctionsKTID           = null;
    protected ?string $kMarketplaceFeeWallet        = null;

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    protected function setupHunterAttrsByContract($contract): bool
    {
        switch ($contract)
        {
            case self::kMarketplaceKTID_v4:
            case self::kEnglishAuctionsKTID_v4:
                $this->kHunterVersion = self::kHunterVersion4;
                break;

            case self::kMarketplaceKTID_v3:
            case self::kEnglishAuctionsKTID_v3:
            case self::kDutchAuctionsKTID_v3:
            case self::kWrapperKTID:
                $this->kHunterVersion = self::kHunterVersion3;
                break;

            case self::kMarketplaceKTID_v3a:
                $this->kHunterVersion = self::kHunterVersion3a;
                break;

            case self::kMarketplaceKTID_v2:
            case self::kEnglishAuctionsKTID_v2:
            case self::kDutchAuctionsKTID_v2:
                $this->kHunterVersion = self::kHunterVersion2;
                break;

            default:
                return false;
        }

        switch ($this->kHunterVersion)
        {
            case self::kHunterVersion4:
                $this->kMarketplaceKTID             = self::kMarketplaceKTID_v4;
                $this->kEnglishAuctionsKTID         = self::kEnglishAuctionsKTID_v4;
                $this->kDutchAuctionsKTID           = null;             // objkt.com's current dutch is v3's
                $this->kMarketplaceFeeWallet        = self::kMarketplaceFeeWallet_v4;
                break;

            case self::kHunterVersion3:
                $this->kMarketplaceKTID             = self::kMarketplaceKTID_v3;
                $this->kEnglishAuctionsKTID         = self::kEnglishAuctionsKTID_v3;
                $this->kDutchAuctionsKTID           = self::kDutchAuctionsKTID_v3;
                $this->kMarketplaceFeeWallet        = self::kMarketplaceFeeWallet_v3;
                break;

            case self::kHunterVersion3a:
                $this->kMarketplaceKTID             = self::kMarketplaceKTID_v3a;
                $this->kMarketplaceFeeWallet        = self::kMarketplaceFeeWallet_v3a;
                break;

            case self::kHunterVersion2:
                $this->kMarketplaceKTID             = self::kMarketplaceKTID_v2;
                $this->kEnglishAuctionsKTID         = self::kEnglishAuctionsKTID_v2;
                $this->kDutchAuctionsKTID           = self::kDutchAuctionsKTID_v2;
                $this->kMarketplaceFeeWallet        = self::kMarketplaceFeeWallet_v2;
                break;

            default:
                return false;
        }
        return true;
    }

    //------------------------------------------------------------------------------------------------------------------

    public static function GetAllMarketContracts(): array
    {
        return [
            self::kMarketplaceKTID_v4, self::kEnglishAuctionsKTID_v4,
            self::kMarketplaceKTID_v3, self::kEnglishAuctionsKTID_v3, self::kDutchAuctionsKTID_v3,
            self::kMarketplaceKTID_v3a,
            self::kMarketplaceKTID_v2, self::kEnglishAuctionsKTID_v2, self::kDutchAuctionsKTID_v2,
        ];
    }

    public static function GetMarketplaceContractByTokenContract(string $tokensContract): ?string
    {
        return null;
    }

    //------------------------------------------------------------------------------------------------------------------

    protected function analyzeTokenPurchaseEvent_withTokens(array $recInit, array $subRecords, ?string $storeRoyaltyWallet, ?string $eventDetails=null): bool
    {
        try
        {
            if (count($subRecords) < 2)
                return false;

            //----------------------------------------------------------------------------------------------------------
            // All records should have 'entrypoint'='transfer'

            $transfersCount = array_reduce($subRecords, function($carry, $record)
            {
                $carry += ($record['entrypoint'] == 'transfer');
                return $carry;
            }, 0);

            if (count($subRecords) != $transfersCount)
                return false; // return silently!

            //----------------------------------------------------------------------------------------------------------
            // All records will have entrypoint = 'transfer'.
            // - If record have 'value' key inside -> this is transfer tokens (payments)
            // - Other -> transfer token transaction

            $recNftTransfer = null;

            /** @var TokenActions\Transfer[] $transferPayments; key=user-address */
            $transferPayments = [];

            foreach ($subRecords as $key => $record)
            {
                if ($this->isSupportedCurrencyToken($record['target']))
                {
                    $transfers = $this->unpackTransfersEvents($record);

                    if (empty($transfers))
                        throw new Exception('Bad transfer records count');

                    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

                    foreach ($transfers as $transfer)
                    {
                        if (isset($transferPayments[$transfer->target]))
                            $transferPayments[$transfer->target]->quantity += $transfer->quantity;
                        else
                            $transferPayments[$transfer->target] = $transfer;
                    }
                }
                else
                {
                    if ($recNftTransfer !== null)
                        throw new Exception('Found 2nd NFT-transfer record');

                    $recNftTransfer = $record;
                }
            }

            if ($recNftTransfer === null)
                throw new Exception('Cannot find NFT-transfer record');

            //----------------------------------------------------------------------------------------------------------

            /** @var TokenActions\Transfer|null $transferStoreFee */
            $transferStoreFee = null;

            if ($storeRoyaltyWallet && isset($transferPayments[$storeRoyaltyWallet]))
            {
                $transferStoreFee = $transferPayments[$storeRoyaltyWallet];
                unset($transferPayments[$storeRoyaltyWallet]);
            }

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            if (count($transferPayments) == 0)
                throw new Exception('$transferPayments is empty');

            usort($transferPayments, function (TokenActions\Transfer $i1, TokenActions\Transfer $i2)
            {
                return $i2->quantity <=> $i1->quantity;
            });

            /** @var TokenActions\Transfer $transferRevenue */
            $transferRevenue = array_shift($transferPayments);

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
            // There may be more than one royalty record

            $royaltyAddressList = [];
            $royaltyTotalAmount = 0;

            foreach ($transferPayments as $transfer)
            {
                $royaltyAddressList[$transfer->target] = ($royaltyAddressList[$transfer->target] ?? 0) + $transfer->quantity;
                $royaltyTotalAmount += $transfer->quantity;
            }

            unset($transferPayments);

            //----------------------------------------------------------------------------------------------------------
            // 2: Transfer Data

            $transfers = $this->unpackTransfersEvents($recNftTransfer);

            if (empty($transfers))
                throw new Exception('Cannot detect transfers records');

            if (count($transfers) > 1)
                throw new Exception('More than one transfer item');

            foreach ($transfers as $transfer)
            {
                $event = new EventFlow();
                $event->initInTezosNetworkByRecord($recInit);

                $event->event_type              = Enum\EventFlowType::TokenPurchase;
                $event->event_details           = $eventDetails;

                $event->sender_address          = $transferRevenue->target; // #sender_address is seller of token >> $transferRevenue->target >> who receive money
                $event->target_address          = $transferRevenue->sender; // #target_address is buyer  of token >> $transferRevenue->sender >> who send money
                $event->royalty_address         = null;
                $event->royalty_address_list    = $royaltyAddressList;
                $event->fee_address             = $transferStoreFee ? $transferStoreFee->target : null;

                $event->revenue                 = $transferRevenue->quantity;
                $event->royalty                 = $royaltyTotalAmount ?: null;
                $event->fee                     = $transferStoreFee ? $transferStoreFee->quantity : null;
                $event->price                   = $event->revenue + $event->royalty + $event->fee;

                $event->currency_address        = $transferRevenue->tokenAddress.':'.$transferRevenue->tokenId;
                $event->quantity                = $transfer->quantity;

                $event->marketplace_address     = $recInit['target'];
                $event->token_address           = $transfer->tokenAddress;
                $event->token_id                = $transfer->tokenId;

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

    //------------------------------------------------------------------------------------------------------------------

    /*
        XTZ     "currency":{"tez":[]}
        USDtz   "currency":{"fa12":"KT1LN4LPSqTMS7Sd2CJw4bbDGRkMv2t68Fy9"}
        uUSD    "currency":{"fa2":{"address":"KT1XRPEPXbZK25r3Htzp2o1x7xdMMmfocKNW","token_id":"0"}}
        oXTZ    "currency":{"fa12":"KT1TjnZYs5CGLbmV6yuW169P8Pnr9BiVwwjz"}
        $Pole   "currency":{"fa2":{"address":"KT1UQVEDf4twF2eMbrCKQAxN7YYunTAiCTTm","token_id":"0"}}
     */
    protected function detectCurrencyIdOrToken($currencyRecord): string
    {
        if (isset($currencyRecord['tez']))
            return TezosNetwork::kCURRENCY_ID;

        if (isset($currencyRecord['fa12']) && $this->isSupportedCurrencyToken($currencyRecord['fa12']))
            return $currencyRecord['fa12'];

        if (isset($currencyRecord['fa2']) && $this->isSupportedCurrencyToken($currencyRecord['fa2']['address']))
            return $currencyRecord['fa2']['address'].':'.$currencyRecord['fa2']['token_id'];

        throw new \Exception('Unknown currency type');
    }

    protected function isSupportedCurrencyToken($tokenKTID): bool
    {
        return in_array($tokenKTID, [
            Setapp\ObjktCom::TokenKTID_USDtz,
            Setapp\ObjktCom::TokenKTID_uUSD,
            Setapp\ObjktCom::TokenKTID_oXTZ,
            Setapp\ObjktCom::TokenKTID_Pole,

            'KT1ShjB24tTXX8m5oDCrBHXqynCpmNJDxpD5',         // TRASH token
        ]);
    }

    //------------------------------------------------------------------------------------------------------------------
    // Helpers: Shares

    /*
     * [v3]
        "shares":{
            "<address>":"750",
            ...
        }

     * [v2]
        "shares":[
            {"amount":"350","recipient":"<address>"},
            {"amount":"250","recipient":"<address>"},
            ...
        ]
     */

    protected function getTotalShareAmount(array $shares): int
    {
        if (empty($shares))
            return 0;

        if ($this->kHunterVersion == self::kHunterVersion4 || $this->kHunterVersion == self::kHunterVersion3
            || $this->kHunterVersion == self::kHunterVersion3a)
            return array_sum(array_values($shares));

        if ($this->kHunterVersion == self::kHunterVersion2)
            return array_sum(array_column($shares, 'amount'));

        throw new \Exception("Unknown kHunterVersion={$this->kHunterVersion} on getTotalShareAmount()");
    }

    protected function getAddressWithHighestShareAmount(array $shares): ?string
    {
        if (empty($shares))
            return null;

        if ($this->kHunterVersion == self::kHunterVersion4 || $this->kHunterVersion == self::kHunterVersion3
            || $this->kHunterVersion == self::kHunterVersion3a)
        {
            $topAddr  = null;
            $topShare = null;

            foreach ($shares as $addr => $share)
            {
                if ($topShare === null || $topShare < $share)
                {
                    $topAddr  = $addr;
                    $topShare = $share;
                }
            }

            return $topAddr;
        }

        if ($this->kHunterVersion == self::kHunterVersion2)
        {
            $bestItem = array_shift($shares);

            while ($shareItem = array_shift($shares))
            {
                if ($shareItem['amount'] > $bestItem['amount'])
                    $bestItem = $shareItem;
            }

            return $bestItem['recipient'];
        }

        throw new \Exception("Unknown kHunterVersion={$this->kHunterVersion} on getAddressWithHighestShareAmount()");
    }
}
