<?php

namespace CryptoNoises\Bots;

use CryptoNoises\Bots\Helpers\CurrencyButtonsGrid;
use CryptoNoises\Config;
use CryptoNoises\Entities\CallbackAction;
use CryptoNoises\Entities\User;
use CryptoNoises\Localize\Notification;
use CryptoNoises\Localize\NotificationButton;
use CryptoNoises\Localize\Phrase;
use CryptoNoises\Models;

trait AbstractBot_Settings
{
    protected function doSettings(User $user, ?CallbackAction $callbackAction=null): ?Notification
    {
        $caBuilder = CallbackAction::Builder(CallbackAction::Scope_Settings);

        switch ($callbackAction ? $callbackAction->event : null)
        {
            case 'coins-conversion':
                $notification = $this->doSettings_CoinsConversion($user, $callbackAction, $caBuilder);
                break;

            case 'filter-by-type':
            case 'filter-by-type.set':
                $notification = Notification\Settings\FilterByTypes::I($user);
                $this->doOptionsNotifier_FilterByTypes($notification, $user->options(), $callbackAction, $caBuilder);
                break;

            case 'thresholds':
            case 'thresholds.set':
            case 'thresholds.list':
                $notification = Notification\Settings\FilterThresholds::I($user);
                $this->doOptionsNotifier_FilterThresholds($notification, $user->options(), $callbackAction, $caBuilder);
                break;

            case 'finalize-reminder':
            case 'finalize-reminder.set':
                $notification = $this->doSettings_FinalizeReminder($user, $callbackAction, $caBuilder);
                break;

            case 'missed-attestations':
            case 'missed-attestations.set':
                $notification = $this->doSettings_MissedAttestations($user, $callbackAction, $caBuilder);
                break;

            default:
                $notification = null;
                break;
        }

        //--------------------------------------------------------------------------------------------------------------

        if ($notification === null)
        {
            $notification = Notification\Settings\Main::I($user);

            $buttons = [];

            $buttons[] = NotificationButton::CallbackAction(Phrase::I()->actionCoinsConversion,
                $caBuilder->newEventAuid('coins-conversion'));

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $options = $user->options();
            $buttons[] = NotificationButton::CallbackAction(Phrase::I()->actionFilterNotifications.' ('.$options->activeNotifiers().'/'.$options->totalNotifiers().')',
                $caBuilder->newEventAuid('filter-by-type'));

            $buttons[] = NotificationButton::CallbackAction(Phrase::I()->filterThresholds,
                $caBuilder->newEventAuid('thresholds'));

            $buttons[] = NotificationButton::CallbackAction(Phrase::I()->finalizeReminder.': '
                .Phrase::FinalizeReminder($options->getFinalizeReminderSec()),
                $caBuilder->newEventAuid('finalize-reminder'));

            $buttons[] = NotificationButton::CallbackAction(Phrase::I()->missedAttestations.': '
                .Phrase::MissedAttestations($options->getMissedAttestationsAlert()),
                $caBuilder->newEventAuid('missed-attestations'));

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            if (Config::cfg()->isTelegramPlatform())
            {
                $notification->appendButtonsAsRows($buttons, 1);
            }
            else if(Config::cfg()->isDiscordPlatform())
            {
                $notification->appendButtonsAsRows($buttons, 5);
            }
        }

        return $notification;
    }

    /**
     * The choices for the finalize reminder, the current one marked; a click saves it. A user-level setting: the
     * reminder is about the user's own patience, not about one wallet.
     */
    private function doSettings_FinalizeReminder(User $user, CallbackAction $callbackAction, CallbackAction $caBuilder): Notification
    {
        $notification = Notification\Settings\FinalizeReminder::I($user);
        $options      = $user->options();
        $values       = \CryptoNoises\Entities\OptionsNotifier::GetFinalizeReminderValues();

        if ($callbackAction->event == 'finalize-reminder.set')
        {
            $key = $callbackAction->data['value'] ?? null;

            if (isset($values[$key]))
                $options->setFinalizeReminderSec($values[$key]);
        }

        $current = $options->getFinalizeReminderSec();
        $buttons = [];

        foreach ($values as $key => $seconds)
        {
            $title = Phrase::FinalizeReminder($seconds);

            if ($seconds === $current)
                $title = Phrase::WithIcon('✅', $title);

            $buttons[] = NotificationButton::CallbackAction($title, $caBuilder->newEventAuid('finalize-reminder.set', ['value' => $key]));
        }

        $notification->appendButtonsAsRows($buttons, 1);

        $notification->appendButtonsRow([
            NotificationButton::CallbackAction(Phrase::I()->actionBack, $caBuilder->newEventAuid()),
        ]);

        return $notification;
    }

    /**
     * For bakers: how many missed attestations within an hour make an alert. User-level, like the finalize reminder.
     */
    private function doSettings_MissedAttestations(User $user, CallbackAction $callbackAction, CallbackAction $caBuilder): Notification
    {
        $notification = Notification\Settings\MissedAttestations::I($user);
        $options      = $user->options();
        $values       = \CryptoNoises\Entities\OptionsNotifier::GetMissedAttestationsValues();

        if ($callbackAction->event == 'missed-attestations.set')
        {
            $key = $callbackAction->data['value'] ?? null;

            if (isset($values[$key]))
                $options->setMissedAttestationsAlert($values[$key]);
        }

        $current = $options->getMissedAttestationsAlert();
        $buttons = [];

        foreach ($values as $key => $count)
        {
            $title = Phrase::MissedAttestations($count);

            if ($count === $current)
                $title = Phrase::WithIcon('✅', $title);

            $buttons[] = NotificationButton::CallbackAction($title, $caBuilder->newEventAuid('missed-attestations.set', ['value' => $key]));
        }

        $notification->appendButtonsAsRows($buttons, 1);

        $notification->appendButtonsRow([
            NotificationButton::CallbackAction(Phrase::I()->actionBack, $caBuilder->newEventAuid()),
        ]);

        return $notification;
    }

    private function doSettings_CoinsConversion(User $user, CallbackAction $callbackAction, CallbackAction $caBuilder): ?Notification
    {
        $notification = Notification\Settings\CoinsConversion::I($user);

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
        // Update state if required

        $toggleCurrency = $callbackAction->data['toggle_currency'] ?? null;

        if ($toggleCurrency)
        {
            Models\UsersCurrencyConvert::model()->toggleCurrency($user->userId, $toggleCurrency);
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
        // Generate control-buttons

        $currencyOutIds = Models\UsersCurrencyConvert::model()->selectIds($user->userId);

        $page = (int) ($callbackAction->data['page'] ?? null);

        $grid = new CurrencyButtonsGrid(Models\CurrencyOut::model()->selectPublicFull(), $page,
            fn (array $item): bool => in_array($item['currency_out_id'], $currencyOutIds),
            fn (array $item): string => $caBuilder->newEventAuid('coins-conversion', [
                'toggle_currency' => $item['currency_out_id'],
                'page' => $page,
            ]),
            fn (int $i): string => $caBuilder->newEventAuid('coins-conversion', ['page'=>$i]),
            $caBuilder->newEventAuid());

        $buttonsNavigation = $grid->navigation;
        $buttonsCurrency   = $grid->currencies;
        $buttonsPerRow     = $grid->buttonsPerRow;

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
        // Generate message

        if ($currencyOutIds)
        {
            foreach ($currencyOutIds as $currencyOutId)
            {
                $currencyItem = Models\CurrencyOut::model()->getItemById($currencyOutId);
                $notification->appendCurrencyName($currencyItem['name']);
            }
        }
        else
        {
            $notification->nothingSelected();
        }

        $notification->appendButtonsAsRows($buttonsNavigation, 100);
        $notification->appendButtonsAsRows($buttonsCurrency, $buttonsPerRow);

        return $notification;
    }
}
