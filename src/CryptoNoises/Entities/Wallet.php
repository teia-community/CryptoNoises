<?php

namespace CryptoNoises\Entities;

use CryptoNoises\Config;
use CryptoNoises\Core\Log;
use CryptoNoises\Models;
use CryptoNoises\Networks\AbstractNetwork;
use CryptoCore\Time;

class Wallet
{
    protected int $walletAdid;
    protected string $walletAddress;

    protected ?int $balance;
    protected string $currency;

    protected ?string $tsLastUpdate;

    //------------------------------------------------------------------------------------------------------------------
    // Cache values

    private ?string $walletName = null;

    private ?AbstractNetwork $network = null;

    //------------------------------------------------------------------------------------------------------------------

    public static function WalletByAdid(int $walletAdid): ?Wallet
    {
        $wallet = new self();

        return $wallet->initializeWallet($walletAdid) ? $wallet : null;
    }

    public static function WalletByAddress(string $address): ?Wallet
    {
        $walletAdid = Address::GetAdid($address);

        if ($walletAdid == null)
            return null;

        return self::WalletByAdid($walletAdid);
    }

    //------------------------------------------------------------------------------------------------------------------

    protected function initializeWallet($walletAdid): bool
    {
        $dbWallet = Models\Wallets::model()->getWalletItem($walletAdid);

        if ($dbWallet == null)
        {
            Log::Error(get_class($this), __FUNCTION__, 'WalletItem not found', [$walletAdid]);
            return false;
        }

        $this->walletAdid           = $dbWallet['wallet_adid'];
        $this->walletAddress        = $dbWallet['wallet_address'];

        $this->balance              = $dbWallet['balance'];
        $this->currency             = $dbWallet['currency'];

        $this->tsLastUpdate         = $dbWallet['ts_updated'];

        return true;
    }

    //------------------------------------------------------------------------------------------------------------------
    // Getter

    public function getWalletAdid(): int
    {
        return $this->walletAdid;
    }

    public function getWalletAddress(): string
    {
        return $this->walletAddress;
    }

    public function getWalletName(): string
    {
        if ($this->walletName === null)
        {
            $this->walletName = \CryptoNoises\GatewayTezos\Wallets::GetWalletPublicName($this->walletAddress);

            if ($this->walletName === null)
                $this->walletName = $this->getWalletShortAddress();
        }

        return $this->walletName;
    }

    //------------------------------------------------------------------------------------------------------------------

    public function network(): AbstractNetwork
    {
        if ($this->network === null)
            $this->network = AbstractNetwork::GetNetworkById(Config::kNETWORK_Tezos);

        return $this->network;
    }

    //------------------------------------------------------------------------------------------------------------------

    public function getWalletShortAddress(): string
    {
        return $this->network()->getWalletShortName($this->walletAddress);
    }

    public function getProfileUrl(): string
    {
        return $this->network()->getAddressPublicUrl($this->walletAddress);
    }

    //------------------------------------------------------------------------------------------------------------------

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getBalance(): ?int
    {
        return $this->balance;
    }

    //------------------------------------------------------------------------------------------------------------------

    public function syncBalanceIfOutOfDate(): bool
    {
        if (Models\QueueWalletsSync::model()->isExists($this->walletAddress, Models\QueueWalletsSync::kPRIORITY_Normal) ||
            Time::Time()-strtotime($this->tsLastUpdate) >= kSEC_IN_HOUR)
        {
            return $this->syncBalance();
        }

        return false;
    }

    public function syncBalance(): bool
    {
        // Should be 1st
        Models\QueueWalletsSync::model()->pop($this->walletAddress);

        $balance = $this->network()->getBalanceForAddress($this->walletAddress);

        if ($balance === null)
            return false;

        $this->balance = $balance;

        Models\Wallets::model()->update($this->walletAdid, ['balance' => $balance]);
        return true;
    }

    public function postponeSyncBalanceIfRequired(): bool
    {
        if (Models\QueueWalletsSync::model()->isExists($this->walletAddress, Models\QueueWalletsSync::kPRIORITY_ANY))
            return false;

        if (Time::Time()-strtotime($this->tsLastUpdate) < 3*kSEC_IN_MINUTE)
            return false;

        Models\QueueWalletsSync::model()->push($this->walletAdid, $this->walletAddress, Models\QueueWalletsSync::kPRIORITY_Normal);
        return true;
    }

    //------------------------------------------------------------------------------------------------------------------

    public static function IsActiveWalletByAdid(?int $adid): bool
    {
        if ($adid === null)
            return false;

        return Models\Wallets::model()->getWalletStatus($adid) == Models\Wallets::kSTATUS_Active;
    }
}
