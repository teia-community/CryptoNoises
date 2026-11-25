<?php

namespace CryptoNoises\Bots;

use CryptoNoises\Config;
use CryptoNoises\Core\CryException;
use CryptoNoises\Entities\Address;
use CryptoNoises\Entities\CallbackAction;
use CryptoNoises\Entities\CoinExchange;
use CryptoNoises\Entities\User;
use CryptoNoises\Entities\UserWallet;
use CryptoNoises\Helpers\Helper;
use CryptoNoises\Helpers\Icons;
use CryptoNoises\Localize\Notification;
use CryptoNoises\Localize\NotificationButton;
use CryptoNoises\Localize\NotificationDiscord;
use CryptoNoises\Localize\Phrase;
use CryptoNoises\Networks\AbstractNetwork;
use CryptoNoises\Networks\Tezos\Services\TezosDomains;
use CryptoNoises\Networks\Tezos\TezosNetwork;

trait AbstractBot_Wallets
{
    protected function doWalletsSummary(User $user, ?CallbackAction $callbackAction=null): ?Notification
    {
        $wallets = $user->getWalletsList();

        if (empty($wallets))
            return Notification\Wallets\ListIsEmpty::I($user);

        $totalAmounts = [];
        $walletsData = [];

        foreach ($wallets as $wallet)
        {
            $message = Notification\Wallets\SummaryInfo_Item::I($user)->bindWallet($wallet)->getDescription();

            $walletsData[] = trim($message);

            $totalAmounts[Config::kNETWORK_Tezos][$wallet->getCurrency()] = ($totalAmounts[Config::kNETWORK_Tezos][$wallet->getCurrency()] ?? 0) + $wallet->getBalance();

            $wallet->postponeSyncBalanceIfRequired();
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $balancesData = [];

        foreach ($totalAmounts as $networkId => $byNetworkAmounts)
        {
            $network = AbstractNetwork::GetNetworkById($networkId);

            foreach ($byNetworkAmounts as $currencyId => $amount)
            {
                $phrases = [];
                $phrases['{###TOTAL_BALANCE###}']           = $network->getCoinsFriendlyShortValue($amount);
                $phrases['{###TOTAL_BALANCE_CONVERTED###}'] = CoinExchange::Create($user->userId, $currencyId)->convertToString($amount);

                $message = Notification\Wallets\SummaryInfo_Item::I($user)->binds($phrases)->getFooter();

                $balancesData[] = trim($message);
            }
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $itemsPerPage   = Config::kUI_SummaryWalletsPerPage;
        $itemsCount     = count($walletsData);

        $pagesCount     = ceil($itemsCount/$itemsPerPage);
        $pageIndex      = (int)($callbackAction->data['page'] ?? null);
        $pageIndex      = max($pageIndex, 0);
        $pageIndex      = max(0, min($pageIndex, $pagesCount-1));

        $firstItemIndex = $pageIndex*$itemsPerPage+1;

        $walletsData    = array_slice($walletsData, $pageIndex*$itemsPerPage, $itemsPerPage);
        $caBuilder      = CallbackAction::Builder(CallbackAction::Scope_Summary);

        return Notification\Wallets\SummaryInfo::I($user)
            ->initialize($walletsData, $balancesData, $itemsCount, $firstItemIndex)
            ->appendPagerControl($caBuilder, $pageIndex, $itemsCount, $itemsPerPage);
    }

    //------------------------------------------------------------------------------------------------------------------

    protected function doWalletsList(User $user): ?Notification
    {
        $wallets = $user->getWalletsList();

        if (empty($wallets))
            return Notification\Wallets\ListIsEmpty::I($user);

        $notifications = [];

        foreach ($wallets as $wallet)
        {
            // Balances are synced in the background, not here: syncing would delay the bot response by seconds.

            $notifications[] = $this->doWalletDetails($user, $wallet);
        }

        return Notification::ConvertListToQueue($notifications);
    }

    protected function doWalletDetails(User $user, ?UserWallet $wallet, ?CallbackAction $callbackAction=null): ?Notification
    {
        if ($wallet === null)
        {
            if ($callbackAction === null)
                return null;

            $wallet = $user->getWalletByAdid($callbackAction->data['wallet_adid'] ?? null);

            // The wallet no longer exists for this user
            if ($wallet === null)
                return Notification\Wallets\WalletNotFound::I($user);
        }

        $wallet->postponeSyncBalanceIfRequired();

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
        // Execute Action

        $callbackActionEvent = $callbackAction ? $callbackAction->event : null;
        $errorMessage = null;

        switch ($callbackActionEvent)
        {
            case 'icon.set':
                $iconId = $callbackAction->data['icon_id'] ?? null;

                if (Icons::GetWalletIcon($iconId) !== null)
                    $wallet->setIconId($iconId);

                $callbackActionEvent = 'settings'; // reset!
                break;

            case 'rename.set':
                $newName = $callbackAction->data['modal']['ds.wallet.rename.new_name'] ?? null;

                if ($wallet->setWalletName($newName) == false)
                    $errorMessage = Phrase::I()->walletRenameFailed_BadWalletName;

                $callbackActionEvent = 'settings'; // reset
                break;
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
        // Get wallet details

        $notification = Notification\Wallets\Details::I($user);
        $notification->bindWallet($wallet);

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
        // Generate next action

        $caBuilder = CallbackAction::Builder(CallbackAction::Scope_Wallets_Details, [
            'wallet_adid' => $wallet->getWalletAdid(),
        ]);

        switch ($callbackActionEvent)
        {
            default:
                $notification->appendButtonsRow([
                    NotificationButton::CallbackAction(Phrase::I()->actionRefresh,
                        $caBuilder->newEventAuid()),

                    NotificationButton::CallbackAction(Phrase::I()->actionSettings,
                        $caBuilder->newEventAuid('settings')),
                ]);
                break;

            //----------------------------------------------------------------------------------------------------------

            case 'settings':
            {
                $iconTitle = trim($wallet->getIconName().' '.Phrase::I()->actionSetIcon);
                $btnIcon = NotificationButton::CallbackAction($iconTitle,
                    $caBuilder->newEventAuid('icon'));

                $btnRename = NotificationButton::CallbackAction(Phrase::I()->actionRename,
                    $caBuilder->newEventAuid('rename'));

                $btnDelete = NotificationButton::CallbackAction(Phrase::I()->actionDeleteWallet,
                    $caBuilder->newEventAuid('delete'))->setStyleDanger();

                $options = $wallet->options();
                $btnFilterByTypes = NotificationButton::CallbackAction(Phrase::I()->actionFilters.' ('.$options->activeNotifiers().'/'.$options->totalNotifiers().')',
                    $caBuilder->newEventAuid('filter-by-type'));

                $btnThresholds = NotificationButton::CallbackAction(Phrase::I()->filterThresholds,
                    $caBuilder->newEventAuid('thresholds'));

                $btnBack = NotificationButton::CallbackAction(Phrase::I()->actionBack,
                    $caBuilder->newEventAuid());

                if (Config::cfg()->usesTelegramLayout())
                {
                    $notification->appendButtonsRow([$btnIcon, $btnRename]);
                    $notification->appendButtonsRow([$btnDelete, $btnFilterByTypes]);
                    $notification->appendButtonsRow([$btnThresholds]);
                    $notification->appendButtonsRow([$btnBack]);
                }
                else if(Config::cfg()->isDiscordPlatform())
                {
                    $notification->appendButtonsRow([$btnIcon, $btnRename, $btnDelete]);
                    $notification->appendButtonsRow([$btnFilterByTypes, $btnThresholds]);
                    $notification->appendButtonsRow([$btnBack]);
                }

                $notification->setWarning(Phrase::I()->walletActionInfo_chooseSetting);
                break;
            }

            //----------------------------------------------------------------------------------------------------------

            case 'icon':
                $buttons = [];

                foreach (Icons::GetWalletIcons() as $iconId => $iconName)
                {
                    $buttons[] = NotificationButton::CallbackAction($iconName, $caBuilder->newEventAuid('icon.set', ['icon_id'=>$iconId]));
                }

                $buttons[] = NotificationButton::CallbackAction(Phrase::I()->actionBack, $caBuilder->newEventAuid('settings'));

                $notification->appendButtonsAsRows($buttons, Config::cfg()->uiWalletIconsPerRow);

                $notification->setWarning(Phrase::I()->walletActionInfo_chooseIcon);
                break;

            //----------------------------------------------------------------------------------------------------------

            case 'rename':
                if (self::IsTelegram())
                {
                    $user->setNextAction_Wallet_Rename($wallet->getWalletAdid(), $callbackAction->parentMessageId);

                    $notification->appendButtonsRow([
                        NotificationButton::CallbackAction(Phrase::I()->actionCancel, $caBuilder->newEventAuid('settings')),
                    ]);

                    $notification->setWarning(Phrase::I()->walletActionInfo_sendNewName);
                }
                else if(self::IsDiscord())
                {
                    $notification = NotificationDiscord\Wallets\RenameModal::I($user);
                    $notification->setCallbackAuid($caBuilder->newEventAuid('rename.set'));
                }
                break;

            case 'delete':
                $notification->appendButtonsRow([
                    NotificationButton::CallbackAction(Phrase::I()->actionBack, $caBuilder->newEventAuid('settings')),
                    NotificationButton::CallbackAction(Phrase::I()->actionConfirm, $caBuilder->newEventAuid('delete-confirm'))->setStyleDanger(),
                ]);

                $notification->setWarning(Phrase::I()->walletActionInfo_confirmDeletion);
                break;

            case 'delete-confirm':
                $isSuccess = $wallet->deleteUserWallet();

                if ($isSuccess)
                    $notification->setFooter([Phrase::I()->walletWasDeleted]);
                break;

            //----------------------------------------------------------------------------------------------------------
            // Filter by notification-types

            case 'filter-by-type':
            case 'filter-by-type.set':
                $this->doOptionsNotifier_FilterByTypes($notification, $wallet->options(), $callbackAction, $caBuilder);
                break;

            case 'thresholds':
            case 'thresholds.set':
            case 'thresholds.list':
                $this->doOptionsNotifier_FilterThresholds($notification, $wallet->options(), $callbackAction, $caBuilder);
                break;
        }

        if ($errorMessage)
            $notification->setError($errorMessage);

        return $notification;
    }

    //------------------------------------------------------------------------------------------------------------------
    // Add / Delete Wallets

    protected function doWalletAdd(AbstractBotGate $botGate, User $user, ?string $message, ?CallbackAction $callbackAction = null): ?Notification
    {
        if ($user->getWalletsCount() >= $user->limits->walletsCount)
            return Notification\Wallets\ReachLimit::I($user);

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $message = trim((string) $message);

        switch ($callbackAction ? $callbackAction->event : null)
        {
            case 'wallet-add.modal.show':
                $caBuilder = CallbackAction::Builder(CallbackAction::Scope_Wallets_Add);

                $notification = NotificationDiscord\Wallets\AddModal::I($user);
                $notification->setCallbackAuid($caBuilder->newEventAuid('wallet-add.modal.submit'));
                return $notification;

            case 'wallet-add.modal.submit':
                $message = trim($callbackAction->data['modal']['ds.wallet.add.address'] ?? '');
                break;
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        if ($message == '')
        {
            $notification = Notification\Wallets\HowToAdd::I($user);

            if (self::IsTelegram())
            {
                $user->setNextAction_Wallet_Add();
                $notification->appendButton_CancelLastAction();
            }
            else if(self::IsDiscord())
            {
                $caBuilder = CallbackAction::Builder(CallbackAction::Scope_Wallets_Add);

                $notification->appendButtonsRow([
                    NotificationButton::CallbackAction(Phrase::I()->actionAddWallet,
                        $caBuilder->newEventAuid('wallet-add.modal.show')),
                ]);
            }

            return $notification;
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $walletsCountBefore = $user->getWalletsCount();

        $result = BotAction_Wallets::AddWallets($user, $message);

        $notifications = [];

        if ($result->walletsList)
        {
            if ($walletsCountBefore == 0)
                $botGate->sendNotifications(Notification\Wallets\Add_SuccessFirstOne::I($user));

            foreach ($result->walletsList as $wallet)
            {
                $notifications[] = $this->doWalletDetails($user, $wallet);
            }
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        if ($result->addressFailedList)
        {
            $notification = Notification\ProcessingResult::Failed($user, 'wallet-add');
            $notification->addWalletNames($result->addressFailedList);
            $notifications[] = $notification;
        }

        if ($result->didReachLimit)
            $notifications[] = Notification\Wallets\ReachLimit::I($user);

        return Notification::ConvertListToQueue($notifications);
    }

    protected function doWalletDelete(User $user, ?string $message, ?CallbackAction $callbackAction = null): ?Notification
    {
        $message = trim((string) $message);

        switch ($callbackAction ? $callbackAction->event : null)
        {
            case 'wallet-delete.modal.show':
                $caBuilder = CallbackAction::Builder(CallbackAction::Scope_Wallets_Delete);

                $notification = NotificationDiscord\Wallets\DeleteModal::I($user);
                $notification->setCallbackAuid($caBuilder->newEventAuid('wallet-delete.modal.submit'));
                return $notification;

            case 'wallet-delete.modal.submit':
                $message = trim($callbackAction->data['modal']['ds.wallet.delete.address'] ?? '');
                break;
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        if ($message == '')
        {
            $wallets = $user->getWalletsList();

            if (count($wallets) == 0)
                return Notification\Wallets\ListIsEmpty::I($user);

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $notification = Notification\Wallets\HowToDelete::I($user);

            $notification->appendWalletsOpen();
            foreach ($wallets as $idx => $wallet)
            {
                $notification->appendWalletAddress($idx+1, $wallet);
            }
            $notification->appendWalletsClose();

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            if (self::IsTelegram())
            {
                $user->setNextAction_Wallet_Delete();
                $notification->appendButton_CancelLastAction();
            }
            else if(self::IsDiscord())
            {
                $caBuilder = CallbackAction::Builder(CallbackAction::Scope_Wallets_Delete);

                $notification->appendButtonsRow([
                    NotificationButton::CallbackAction(Phrase::I()->actionDeleteWallet,
                        $caBuilder->newEventAuid('wallet-delete.modal.show')),
                ]);
            }

            return $notification;
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $lines = explode("\n", $message);

        if (count($lines) == 1)
        {
            $dropIndexes = Helper::UnpackIndexesList($lines[0], Config::cfg()->limitWallets);

            if (!empty($dropIndexes))
            {
                $addressList = [];

                $wallets = $user->getWalletsList();

                // IF user<$dropIndex>=1 THEN list<dropIndex> = 0
                foreach ($dropIndexes as $dropIndex)
                {
                    $idxNormalized = $dropIndex-1;

                    if (isset($wallets[$idxNormalized]))
                        $addressList[] = $wallets[$idxNormalized]->getWalletAddress();
                }

                if (!empty($addressList))
                    $lines = $addressList;
            }
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $resultSuccess = [];
        $resultFailed = [];

        while ($lines)
        {
            $address = array_shift($lines);
            $address = Address::Normalize($address);

            if ($address == '')
                continue;

            if (TezosNetwork::IsDomainAddressValid($address))
            {
                $domain  = $address;
                $address = TezosDomains::GetAddressByDomain($domain);

                // Unknown / expired domain: report it as failed instead of passing null further
                if ($address === null)
                {
                    $resultFailed[] = $domain;
                    continue;
                }
            }

            $wallet = $user->getWalletByAddress($address);

            if ($wallet)
            {
                $wallet->deleteUserWallet();
                $resultSuccess[] = $address;
            }
            else
            {
                if (!in_array($address, $resultSuccess))
                    $resultFailed[] = $address;
            }
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $notifications = [];

        if (count($resultSuccess) > 0)
        {
            $notification = Notification\ProcessingResult::Success($user, 'wallet-delete');
            $notification->addWalletNames($resultSuccess);
            $notifications[] = $notification;
        }

        if (count($resultFailed) > 0)
        {
            $notification = Notification\ProcessingResult::Failed($user, 'wallet-delete');
            $notification->addWalletNames($resultFailed);
            $notifications[] = $notification;
        }

        return Notification::ConvertListToQueue($notifications);
    }

    //------------------------------------------------------------------------------------------------------------------
    // Also used by the Discord rename modal

    protected function doWalletRename(User $user, ?UserWallet $wallet, ?string $newName): ?Notification
    {
        $newName = trim((string) $newName);

        try
        {
            if ($wallet === null)
                throw new CryException(Phrase::I()->walletRenameFailed);

            if ($wallet->setWalletName($newName) == false)
                throw new CryException(Phrase::I()->walletRenameFailed_BadWalletName);

            return $this->doWalletDetails($user, $wallet);
        }
        catch (CryException $e)
        {
            $notification = Notification\Wallets\Details::I($user);
            $notification->bindWallet($wallet);
            $notification->setError($e->getMessage());
            return $notification;
        }
    }
}
