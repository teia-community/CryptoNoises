<?php

namespace CryptoNoises\Bots;

use CryptoNoises\Config;
use CryptoNoises\Entities\CallbackAction;
use CryptoNoises\Entities\OptionsNotifier;
use CryptoNoises\Localize\Notification;
use CryptoNoises\Localize\NotificationButton;
use CryptoNoises\Localize\Phrase;
use CryptoNoises\Networks\AbstractNetwork;
trait AbstractBot_OptionsNotifier
{
    protected function doOptionsNotifier_FilterByTypes(Notification $notification,
                                                       OptionsNotifier $options,
                                                       CallbackAction $callbackAction, CallbackAction $caBuilder,
                                                       string $backEventId = 'settings'): void
    {
        $filterTypes = $options->getNotificationTypes();

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
        // Update state if required

        if ($callbackAction->event == 'filter-by-type.set')
        {
            $filterKey = $callbackAction->data['filter_key'] ?? null;

            if (isset($filterTypes[$filterKey]))
                $options->toggleNotify($filterTypes[$filterKey]['cfg_key']);
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $buttons = [];
        foreach ($filterTypes as $filterKey => $filterParams)
        {
            $thisCallbackAuid = $caBuilder->newEventAuid('filter-by-type.set', ['filter_key'=>$filterKey]);

            $isFilterActive = $options->isNotify($filterParams['cfg_key']);

            if (Config::cfg()->usesTelegramLayout())
            {
                $stateTitle = $isFilterActive ? Phrase::I()->stateReceive : Phrase::I()->stateIgnore;

                $buttons[] = NotificationButton::CallbackAction($filterParams['title'], $thisCallbackAuid);
                $buttons[] = NotificationButton::CallbackAction($stateTitle, $thisCallbackAuid);
            }
            else if (Config::cfg()->isDiscordPlatform())
            {
                $button = NotificationButton::CallbackAction($filterParams['title'], $thisCallbackAuid);

                if ($isFilterActive)
                    $button->setStyleSuccess();
                else
                    $button->setStyleDanger();

                $buttons[] = $button;
            }
        }

        $notification->appendButtonsAsRows($buttons, Config::cfg()->uiFilterNotificationTypesPerRow);

        $notification->appendButtonsRow([
            NotificationButton::CallbackAction(Phrase::I()->actionBack, $caBuilder->newEventAuid($backEventId))
        ]);

        $notification->setWarning(Phrase::I()->walletActionInfo_chooseFilter);
    }

    protected function doOptionsNotifier_FilterThresholds(Notification $notification,
                                                          OptionsNotifier $options,
                                                          CallbackAction $callbackAction, CallbackAction $caBuilder,
                                                          string $backEventId = 'settings'): void
    {
        $filterThresholdScopes = OptionsNotifier::GetThresholdScopes();
        $filterThresholdValues = OptionsNotifier::GetThresholdValues();

        $network = AbstractNetwork::GetNetworkById(Config::kNETWORK_Tezos);

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
        // Update state if required

        if ($callbackAction->event == 'thresholds.set')
        {
            $thresholdScope = $callbackAction->data['threshold_scope'] ?? null;
            $thresholdKey   = $callbackAction->data['threshold_key'] ?? null;
            $thresholdValue = $filterThresholdValues[$thresholdKey] ?? null;

            if ($thresholdValue !== null && in_array($thresholdScope, $filterThresholdScopes))
                $options->setThreshold($thresholdScope, $thresholdValue);
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        if ($callbackAction->event == 'thresholds.list')
        {
            $thresholdScope = $callbackAction->data['threshold_scope'] ?? null;

            if (!in_array($thresholdScope, $filterThresholdScopes))
                return;

            $valueThreshold = $options->getThreshold($thresholdScope);

            $buttons = [];

            foreach ($filterThresholdValues as $thresholdKey => $thresholdValue)
            {
                $stateTitle = $thresholdValue > 0
                    ? $network->getCoinsFriendlyFullValue($thresholdValue).' '.Phrase::I()->thresholdOrMore
                    : Phrase::I()->thresholdNotSet;

                if ($thresholdValue == $valueThreshold)
                    $stateTitle = Phrase::WithIcon('✅', $stateTitle);

                $buttons[] = NotificationButton::CallbackAction($stateTitle,
                    $caBuilder->newEventAuid('thresholds.set', ['threshold_scope'=>$thresholdScope, 'threshold_key'=>$thresholdKey])
                );
            }

            $notification->appendButtonsAsRows($buttons, Config::cfg()->uiThresholdValuesPerRow);

            $notification->appendButtonsRow([
                NotificationButton::CallbackAction(Phrase::I()->actionBack, $caBuilder->newEventAuid('thresholds'))
            ]);

            $notification->appendDescription(Phrase::I()->walletActionInfo_chooseThresholdVal);
        }
        else // 'thresholds' || 'thresholds.set'
        {
            $buttons = [];

            foreach ($filterThresholdScopes as $thresholdScope)
            {
                $valueThreshold = $options->getThreshold($thresholdScope);
                $valueThreshold = $valueThreshold > 0
                    ? $network->getCoinsFriendlyFullValue($valueThreshold)
                    : Phrase::I()->thresholdNotSet;

                $buttons[] = NotificationButton::CallbackAction(Phrase::FilterThreshold($thresholdScope).': '.$valueThreshold,
                    $caBuilder->newEventAuid('thresholds.list', ['threshold_scope'=>$thresholdScope]));
            }

            $notification->appendButtonsAsRows($buttons, Config::cfg()->uiThresholdsPerRow);

            $notification->appendButtonsRow([
                NotificationButton::CallbackAction(Phrase::I()->actionBack, $caBuilder->newEventAuid($backEventId))
            ]);

            $notification->appendDescription(Phrase::I()->walletActionInfo_chooseThreshold);
        }
    }
}
