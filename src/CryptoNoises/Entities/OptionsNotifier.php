<?php

namespace CryptoNoises\Entities;

use CryptoNoises\Localize\Phrase;
use CryptoNoises\Models;

class OptionsNotifier
{
    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    const kNotify_Income                        = 'notify_income';
    const kNotify_Expense                       = 'notify_expense';

    const kNotify_TokenReceive                  = 'notify_token_receive';
    const kNotify_TokenSend                     = 'notify_token_send';

    const kNotify_TokenSold                     = 'notify_token_sold';
    const kNotify_TokenPurchased                = 'notify_token_purchased';
    const kNotify_TokenRoyalty                  = 'notify_token_royalty';
    const kNotify_TokenOffer                    = 'notify_token_offer';
    const kNotify_Auction                       = 'notify_auction';

    const kNotify_TokenMint                     = 'notify_token_mint';
    const kNotify_TokenList                     = 'notify_token_list';
    const kNotify_TokenUpdateMetadata           = 'notify_token_update_metadata';

    // Covers both a comment on your token and a reply to your comment: one switch, because somebody who does
    // not want to hear about comments does not want half of them either.
    const kNotify_TokenComment                  = 'notify_token_comment';

    // Delegation, staking, and your baker changing its terms: one switch, it is all about where your tez are staked
    const kNotify_Staking                       = 'notify_staking';
    const kNotify_Origination                   = 'notify_origination';

    // Messages addressed to the wallet in on-chain chats (Chad Chat, Trollbox replies)
    const kNotify_Messages                      = 'notify_messages';

    const kNotify_Domains                       = 'notify_domains';

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
    // These values match the column suffixes (threshold_<value>)

    const kThresholdTransaction                 = 'transaction';
    const kThresholdOffer                       = 'offer';

    //------------------------------------------------------------------------------------------------------------------

    private int $userId;
    private int $walletAdid;

    private array $types = [];

    private array $data = [];

    //------------------------------------------------------------------------------------------------------------------

    private function __construct(int $userId, int $walletAdid)
    {
        $this->userId           = $userId;
        $this->walletAdid       = $walletAdid;

        $data = Models\OptionsNotifier::model()->getItem($userId, $walletAdid);

        if ($data != null)
            $this->data = $data;
    }

    public static function NewForUser(int $userId): self
    {
        $instance = new self($userId, 0);
        $instance->activatePresetForUser();
        return $instance;
    }

    public static function NewForUserWallet(int $userId, int $walletAdid): self
    {
        $instance = new self($userId, $walletAdid);
        $instance->activatePresetForUserWallet();
        return $instance;
    }

    //------------------------------------------------------------------------------------------------------------------
    // Filter by Notification-Types

    public function isNotify(string $notifyId): bool
    {
        if (!isset($this->data[$notifyId]) || $this->data[$notifyId] === null)
            return $this->types[$notifyId]['default'] ?? true; // as a default value

        return $this->data[$notifyId] === 'yes';
    }

    public function setNotify(string $notifyId, bool $state)
    {
        $this->data[$notifyId] = $state ? 'yes' : 'no';

        Models\OptionsNotifier::model()->save($this->userId, $this->walletAdid, [
            $notifyId => $this->data[$notifyId],
        ]);
    }

    public function toggleNotify(string $notifyId)
    {
        $this->setNotify($notifyId, !$this->isNotify($notifyId));
    }

    //------------------------------------------------------------------------------------------------------------------

    private function activatePresetForUser(): void
    {
        $this->addType(OptionsNotifier::kNotify_Income,                 true);
        $this->addType(OptionsNotifier::kNotify_Expense,                true);

        $this->addType(OptionsNotifier::kNotify_TokenReceive,           true);
        $this->addType(OptionsNotifier::kNotify_TokenSend,              true);

        $this->addType(OptionsNotifier::kNotify_TokenSold,              true);
        $this->addType(OptionsNotifier::kNotify_TokenPurchased,         true);
        $this->addType(OptionsNotifier::kNotify_TokenRoyalty,           true);
        $this->addType(OptionsNotifier::kNotify_TokenOffer,             true);
        $this->addType(OptionsNotifier::kNotify_Auction,                true);

        $this->addType(OptionsNotifier::kNotify_TokenUpdateMetadata,    true);
        $this->addType(OptionsNotifier::kNotify_TokenComment,           true);
        $this->addType(OptionsNotifier::kNotify_Staking,                true);
        $this->addType(OptionsNotifier::kNotify_Origination,            true);
        $this->addType(OptionsNotifier::kNotify_Messages,               true);
        $this->addType(OptionsNotifier::kNotify_Domains,                true);
    }

    private function activatePresetForUserWallet(): void
    {
        $this->addType(OptionsNotifier::kNotify_Income,                 true);
        $this->addType(OptionsNotifier::kNotify_Expense,                true);

        $this->addType(OptionsNotifier::kNotify_TokenReceive,           true);
        $this->addType(OptionsNotifier::kNotify_TokenSend,              true);

        $this->addType(OptionsNotifier::kNotify_TokenSold,              true);
        $this->addType(OptionsNotifier::kNotify_TokenPurchased,         true);
        $this->addType(OptionsNotifier::kNotify_TokenRoyalty,           true);
        $this->addType(OptionsNotifier::kNotify_TokenOffer,             true);
        $this->addType(OptionsNotifier::kNotify_Auction,                true);

        $this->addType(OptionsNotifier::kNotify_TokenUpdateMetadata,    true);
        $this->addType(OptionsNotifier::kNotify_TokenComment,           true);
        $this->addType(OptionsNotifier::kNotify_Staking,                true);
        $this->addType(OptionsNotifier::kNotify_Origination,            true);
        $this->addType(OptionsNotifier::kNotify_Messages,               true);
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    private function addType(string $cfgKey, bool $defaultState): void
    {
        switch ($cfgKey)
        {
            case OptionsNotifier::kNotify_Income:               $title = Phrase::I()->filterTypeIncome; break;
            case OptionsNotifier::kNotify_Expense:              $title = Phrase::I()->filterTypeExpense; break;

            case OptionsNotifier::kNotify_TokenReceive:         $title = Phrase::I()->filterTypeTokenReceive; break;
            case OptionsNotifier::kNotify_TokenSend:            $title = Phrase::I()->filterTypeTokenSend; break;

            case OptionsNotifier::kNotify_TokenSold:            $title = Phrase::I()->filterTypeTokenSold; break;
            case OptionsNotifier::kNotify_TokenPurchased:       $title = Phrase::I()->filterTypeTokenPurchased; break;
            case OptionsNotifier::kNotify_TokenRoyalty:         $title = Phrase::I()->filterTypeTokenRoyalty; break;
            case OptionsNotifier::kNotify_TokenOffer:           $title = Phrase::I()->filterTypeTokenOffers; break;
            case OptionsNotifier::kNotify_Auction:              $title = Phrase::I()->filterTypeAuctionEvents; break;

            case OptionsNotifier::kNotify_TokenMint:            $title = Phrase::I()->filterTypeTokenMint; break;
            case OptionsNotifier::kNotify_TokenList:            $title = Phrase::I()->filterTypeTokenList; break;
            case OptionsNotifier::kNotify_TokenUpdateMetadata:  $title = Phrase::I()->filterTypeTokenUpdateMetadata; break;
            case OptionsNotifier::kNotify_TokenComment:         $title = Phrase::I()->filterTypeTokenComment; break;
            case OptionsNotifier::kNotify_Staking:              $title = Phrase::I()->filterTypeStaking; break;
            case OptionsNotifier::kNotify_Origination:          $title = Phrase::I()->filterTypeOrigination; break;
            case OptionsNotifier::kNotify_Messages:             $title = Phrase::I()->filterTypeMessages; break;

            case OptionsNotifier::kNotify_Domains:              $title = Phrase::I()->filterTypeDomainsEvents; break;

            default:                                            $title = "[$cfgKey]"; break;
        }

        $this->types[$cfgKey] = [
            'title'     => $title,
            'cfg_key'   => $cfgKey,
            'default'   => $defaultState,
        ];
    }

    //------------------------------------------------------------------------------------------------------------------

    public function activeNotifiers(): int
    {
        $count = 0;
        foreach ($this->types as $filterParams)
        {
            if ($this->isNotify($filterParams['cfg_key']))
                $count++;
        }
        return $count;
    }

    public function totalNotifiers(): int
    {
        return count($this->types);
    }

    public function getNotificationTypes(): array
    {
        return $this->types;
    }

    //------------------------------------------------------------------------------------------------------------------
    // Filter by thresholds

    public function getThreshold(string $key): int
    {
        if (!in_array($key, self::GetThresholdScopes()))
            return 0;

        $key = 'threshold_'.$key;

        if (!isset($this->data[$key]) || $this->data[$key] === null)
            return 0; // as a default value

        return $this->data[$key];
    }

    public function setThreshold(string $key, int $value): void
    {
        if (!in_array($key, self::GetThresholdScopes()))
            return;

        $key = 'threshold_'.$key;

        $this->data[$key] = $value;

        Models\OptionsNotifier::model()->save($this->userId, $this->walletAdid, [
            $key => $value,
        ]);
    }

    //------------------------------------------------------------------------------------------------------------------
    // Reminder to finalize unstaked tez: how long after they unlock, if they are still not finalized

    const kFinalizeReminderColumn               = 'finalize_reminder_sec';
    const kFinalizeReminderOff                  = -1;
    const kFinalizeReminderDefault              = 86400;    // one day: time for the staker, or a service, to do it

    /** key => seconds after unlock; kFinalizeReminderOff = no reminder. Keys travel in callback data. */
    public static function GetFinalizeReminderValues(): array
    {
        return [
            'off' => self::kFinalizeReminderOff,
            'd0'  => 0,
            'd1'  => 86400,
            'd3'  => 3 * 86400,
            'd7'  => 7 * 86400,
        ];
    }

    public function getFinalizeReminderSec(): int
    {
        $value = $this->data[self::kFinalizeReminderColumn] ?? null;

        if ($value === null || !in_array((int) $value, self::GetFinalizeReminderValues(), true))
            return self::kFinalizeReminderDefault;

        return (int) $value;
    }

    public function setFinalizeReminderSec(int $seconds): void
    {
        if (!in_array($seconds, self::GetFinalizeReminderValues(), true))
            return;

        $this->data[self::kFinalizeReminderColumn] = $seconds;

        Models\OptionsNotifier::model()->save($this->userId, $this->walletAdid, [
            self::kFinalizeReminderColumn => $seconds,
        ]);
    }

    //------------------------------------------------------------------------------------------------------------------

    public static function GetThresholdScopes(): array
    {
        return [
            OptionsNotifier::kThresholdTransaction,
            OptionsNotifier::kThresholdOffer,
        ];
    }

    public static function GetThresholdValues(): array
    {
        return [
            'v0'    => 0,              #    not set
            'v1'    => 1000,           #  0.001 XTZ

            'v2'    => 10000,          #  0.010 XTZ
            'v3'    => 50000,          #  0.050 XTZ

            'v4'    => 100000,         #  0.100 XTZ
            // no 'v5': the keys are column suffixes (threshold_<key>), so they are kept as they are
            'v6'    => 500000,         #  0.500 XTZ

            'v7'    => 1000000,        #  1.000 XTZ
            'v8'    => 5000000,        #  5.000 XTZ

            'v9'    => 10000000,       # 10.000 XTZ
            'v10'   => 50000000,       # 50.000 XTZ
        ];
    }
}
