<?php

namespace CryptoNoises\Bots;

use CryptoNoises\Bots\Results\AddWalletsResult;
use CryptoNoises\Config;
use CryptoNoises\Entities\Address;
use CryptoNoises\Entities\User;
use CryptoNoises\Entities\UserWallet;
use CryptoNoises\Helpers\Helper;
use CryptoNoises\Networks\Tezos\Services\TezosDomains;
use CryptoNoises\Networks\Tezos\TezosNetwork;
use Exception;

class BotAction_Wallets
{
    public static function AddWallets(User $user, string $message): AddWalletsResult
    {
        $result = new AddWalletsResult();

        // Limit the number of lines per request (spam protection)
        $limitPerRequest = Config::cfg()->limitWallets;

        $lines = explode("\n", $message);

        while ($lines)
        {
            $line = array_shift($lines);
            $line = trim($line);

            if ($line == '')
                continue;

            list ($address, $walletName) = Helper::SplitBySpaces($line, 2);

            $address    = Address::Normalize($address);
            $walletName = trim((string) $walletName); // SplitBySpaces() pads the missing name with null

            try
            {
                if (TezosNetwork::IsDomainAddressValid($address))
                {
                    $domain = $address;
                    $address = TezosDomains::GetAddressByDomain($domain);

                    if ($walletName == '')
                        $walletName = $domain;
                }

                if (!TezosNetwork::IsWalletAddressValid($address))
                    throw new Exception();

                if (in_array($address, $result->addressSuccessList))
                    continue; // Just added before in this loop

                if ($user->isWalletExistsByAddress($address))
                {
                    $wallet = $user->getWalletByAddress($address);

                    if (!$wallet instanceof UserWallet)
                        throw new Exception();

                    $result->walletsList[] = $wallet;
                    continue;
                }

                if ($user->limits && $user->getWalletsCount() >= $user->limits->walletsCount)
                {
                    $result->didReachLimit = true;

                    $address = null; // do not add this address to the failed list
                    $lines = [];     // ... and others too >> reset!
                    throw new Exception();
                }

                if ($walletName == '')
                    $walletName = (string) TezosDomains::GetDomainByAddress($address);

                $wallet = $user->createWallet($address, $walletName);

                if (!$wallet instanceof UserWallet)
                    throw new Exception();

                $result->walletsList[] = $wallet;
                $result->addressSuccessList[] = $address;
            }
            catch (Exception $e)
            {
                if ($address && !in_array($address, $result->addressSuccessList))
                    $result->addressFailedList[] = $address;
            }

            if (--$limitPerRequest == 0)
                break;
        }

        return $result;
    }
}