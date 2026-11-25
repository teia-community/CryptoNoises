<?php

namespace CryptoNoises\Entities;

use CryptoNoises\Helpers\Helper;
use CryptoNoises\Networks\Tezos\TezosHelper;

class Token
{
    const kTOKEN_TYPE_Nft                       = 'nft';
    const kTOKEN_TYPE_Coin                      = 'coin';
    const kTOKEN_TYPE_Domain                    = 'domain';

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public string $networkId;
    public string $tokenAddress;
    public string $tokenId;

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
    // Main data

    public ?string  $minterAddress = null;
    public ?string  $creatorAddress = null;
    public ?int     $creatorRoyalty_d4 = null;

    public ?string  $tokenType = null;

    public ?string  $name = null;
    public ?string  $description = null;

    public ?string  $symbol = null;
    public ?int     $decimals = null;

    public ?int     $editionIndex = null;
    public ?int     $editions = null;

    public ?string  $rights = null;

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public ?array   $tags = null;
    public ?array   $attributes = null;

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
    // Media data

    public ?string  $thumbnailUrl = null;
    public ?string  $animationUrl = null;
    public ?string  $movieClipUrl = null;

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
    // Additional data

    public ?string  $tokenMarketUrl = null;

    public ?string  $collectionName = null;
    public ?string  $collectionGroup = null;
    public ?int     $collectionId = null;

    //------------------------------------------------------------------------------------------------------------------

    public ?float   $amount = null;

    //------------------------------------------------------------------------------------------------------------------

    public function __construct(string $networkId, string $tokenAddress, string $tokenId)
    {
        $this->networkId    = $networkId;
        $this->tokenAddress = $tokenAddress;
        $this->tokenId      = $tokenId;
    }

    public function isNftToken(): bool
    {
        switch ($this->tokenType)
        {
            case self::kTOKEN_TYPE_Nft:     return true;
            case self::kTOKEN_TYPE_Coin:    return false;
            case self::kTOKEN_TYPE_Domain:  return false;
            default:                        return $this->decimals == 0;
        }
    }

    public function isCoinToken(): bool
    {
        switch ($this->tokenType)
        {
            case self::kTOKEN_TYPE_Nft:     return false;
            case self::kTOKEN_TYPE_Coin:    return true;
            case self::kTOKEN_TYPE_Domain:  return false;
            default:                        return $this->decimals > 0;
        }
    }

    public function isDomainToken(): bool
    {
        return $this->tokenType == self::kTOKEN_TYPE_Domain;
    }

    public function getFriendlyCoinsAmount(?float $amount = null): string
    {
        if ($amount === null)
            $amount = $this->amount;

        $decimals       = (int) $this->decimals;
        $decimalsToShow = min($decimals, 8);

        $amount = number_format($amount / 10**$decimals, $decimalsToShow);

        return Helper::TrimRightZerosInNumber($amount).' '.$this->symbol;
    }

    public function getCurrentEditions(): ?int
    {
        return TezosHelper::GetTokenCurrentEditions($this->tokenAddress, $this->tokenId);
    }

    //------------------------------------------------------------------------------------------------------------------

    // A wrapped token such as oXTZ is treated as 1:1 with XTZ, so the raw amount is returned and the thresholds
    // are applied to it. This is not correct for other tokens, so they are not handled (null is returned).
    public function convertTokenPriceToXtz(): ?float
    {
        if ($this->decimals != 6)
            return null; // TODO: convert amounts with other decimals to 6

        return $this->amount;
    }
}
