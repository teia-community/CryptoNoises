<?php

namespace CryptoNoises\Localize;

use CryptoNoises\Entities\OptionsNotifier;

class Phrase
{
    public string $actionAdd                            = '➕ Add';
    public string $actionDelete                         = '🗑 Delete';

    public string $actionBack                           = '⬅️ Back';
    public string $actionCancel                         = '⬅️ Cancel';
    public string $actionRefresh                        = '🔄 Refresh';
    public string $actionSettings                       = '⚙️ Settings';
    public string $actionConfirm                        = '👍️ Confirm';
    public string $actionSetIcon                        = 'Set icon';
    public string $actionRename                         = '✏️ Rename';
    public string $actionFilters                        = '🔕 Filters';
    public string $actionFilterNotifications            = '🔕 Filter notifications';
    public string $actionCoinsConversion                = '💱 Coin conversion';

    public string $actionAddWallet                      = '➕ Add wallet';
    public string $actionDeleteWallet                   = '🗑 Delete wallet';

    public string $actionFollow                         = '➕ Follow';
    public string $actionFollowMore                     = '➕ Follow more';
    public string $actionUnfollow                       = '➖ Unfollow';
    public string $actionUnfollowMore                   = '➖ Unfollow more';
    public string $actionFollowing                      = '🗣 Following';

    public string $actionExpand                         = '↔️ Expand';
    public string $actionCollapse                       = '↩️ Collapse';

    public string $actionJoinTwitter                    = "👨‍💻 Follow on Twitter";
    public string $actionJoinDiscord                    = "🗣 Join Discord";
    public string $actionPrivacyPolicy                  = "🔒 Privacy policy";
    // TzKT's free tier requires one of its labels with a direct link to tzkt.io
    public string $dataSourcesAttribution               = "Data provided by TzKT API (https://tzkt.io) and CoinGecko (https://www.coingecko.com)";
    public string $poweredByCoinGecko                   = "Powered by CoinGecko";
    public string $actionRedirectToOpenTicket           = "🎟 Open Ticket";

    public string $walletRenameFailed                   = 'Cannot rename wallet';
    public string $walletRenameFailed_BadWalletName     = 'Bad wallet name';

    public string $walletWasDeleted                     = '❌ Wallet was successfully deleted';

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public string $filterTypeIncome                     = '🟢 Income';
    public string $filterTypeExpense                    = '🔴 Expense';

    public string $filterTypeTokenReceive               = '🟢 Token received (free)';
    public string $filterTypeTokenSend                  = '🔴 Token sent (free)';

    public string $filterTypeTokenSold                  = '🔵 Token sold';
    public string $filterTypeTokenPurchased             = '🟣 Token purchased';
    public string $filterTypeTokenRoyalty               = '🟡 Token royalty';
    public string $filterTypeTokenOffers                = '🟠 Token offer';

    public string $filterTypeAuctionEvents              = '🟤 Auction events';
    public string $filterTypeDomainsEvents              = '🟠 Domain events';
    public string $filterTypeTeiaEvents                 = '📣 Teia events';
    public string $filterTypeTeiaGovernance             = '🏛 Teia multisig, DAO & polls';

    public string $filterTypeTokenMint                  = '⚪️ Token mint';
    public string $filterTypeTokenList                  = '🟠 Token listing';
    public string $filterTypeTokenUpdateMetadata        = '🟡 Token metadata updates';
    public string $filterTypeTokenComment              = '💬 Comments on your tokens';
    public string $filterTypeStaking                   = '🥩 Staking & delegation';
    public string $filterTypeOrigination               = '📜 Contracts you originate';
    public string $filterTypeMessages                  = '💬 Messages to you';
    public string $filterTypeBakerDelegators           = '🧑‍🍳 Your delegators & stakers (bakers)';
    public string $filterTypeBakerAlerts               = '🚨 Missed rights, DAL & governance (bakers)';

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public string $filterThresholds                     = '💵 Thresholds';
    public string $filterThresholdTransactions          = '💵 Transactions threshold';
    public string $filterThresholdOffers                = '💵 Offers threshold';

    public string $thresholdOrMore                      = 'or more';
    public string $thresholdNotSet                      = 'Not set';

    public string $finalizeReminder                     = '⏰ Finalize reminder';
    public string $finalizeReminderInfo                 = 'When unstaked tez can be finalized but still are not, remind me:';

    public string $digest                               = '📬 Digest';
    public string $digestInfo                           = 'Bundle routine wallet activity (payments, NFT transfers, sales, offers…) into one message per period. Alerts, reminders and messages still come at once:';
    public string $missedAttestations                   = '🚨 Missed attestations';
    public string $missedAttestationsInfo               = 'For bakers: alert me when my baker misses at least this many attestations within an hour (missed blocks always alert):';

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public string $stateReceive                         = '🔔 On';
    public string $stateIgnore                          = '🔕 Off';

    public string $refresh                              = '🔄 Refresh';

    public string $marketplace                          = 'Marketplace';

    public string $syncing                              = 'Syncing...';
    public string $yes                                  = 'Yes';

    //------------------------------------------------------------------------------------------------------------------

    public array $userMissedMessage                    = [
        '<@{###USER_ID###}> you missed a message from the Bot.',
        '⚠️ Please check your privacy settings (allow direct messages from server members) to be able to receive notifications.{###PRIVACY_HELP_URL###}',
        '⚠️ Also, you can use `/redirect_dm` command to redirect all DM notifications to any channel on your Discord server.',
        ' ',
        '*This message will be automatically deleted in {###MINUTES_DELAY###} minutes*'
    ];

    //------------------------------------------------------------------------------------------------------------------

    public string $redirectInviteBot                    = 'Invite Bot';
    public string $redirectActivate                     = 'Activate';
    public string $redirectIsActive                     = '✅ Active';
    public string $redirectDeactivate                   = 'Deactivate';
    public string $redirectDeactivateSelf               = 'Deactivate Own Redirect';
    public string $redirectDeactivateOther              = 'Deactivate External Redirect';

    //------------------------------------------------------------------------------------------------------------------

    public string $demoWalletAddress                    = 'tz1burnburnburnburnburnburnburjAYjjX'; // Tezos burn address (nobody's wallet)
    public string $demoWalletName                       = 'Main Wallet Name';
    public string $demoDomainName                       = 'example.tez';

    //------------------------------------------------------------------------------------------------------------------

    public string $systemError                          = "🚨 Oops... Something went wrong. Please try again.";

    //------------------------------------------------------------------------------------------------------------------

    public string $discord_letsTalkInDM                 =
        "Let's talk privately... 🤫".PHP_EOL.
        "Check DM (Direct Message) from 🤖";

    public array $discord_replyOnAutoJoin_NewUser       = [
        "🤖 Hi",
        "🤖 I just got activated for you",
        "🤖 Check my instructions in your DMs",
        " ", // EMPTY_LINE
        "⚠️ If you didn't receive a DM, please check that direct messages from this server are allowed in your settings.",
        "⚙️ Right click on 'Server Logo' > Privacy Settings > Direct Messages > Turn ON",
    ];

    public array $discord_replyOnAutoJoin_ExistsUser    = [
        "🤖 I'm already activated, but I sent you the message again. Check your DMs.",
        " ", // EMPTY_LINE
        "⚠️ If you didn't receive a DM, please check that direct messages from this server are allowed in your settings.",
        "⚙️ Right click on 'Server Logo' > Privacy Settings > Direct Messages > Turn ON",
    ];

    //------------------------------------------------------------------------------------------------------------------

    public string $walletActionInfo_chooseSetting       = 'Choose what you want to configure';
    public string $walletActionInfo_chooseIcon          = 'Choose an icon for the wallet';
    public string $walletActionInfo_confirmDeletion     = 'Do you want to delete this wallet from the bot?';
    public string $walletActionInfo_chooseFilter        = 'Choose what kind of notifications you want to receive/ignore';
    public string $walletActionInfo_chooseThreshold     = 'Choose threshold type';
    public string $walletActionInfo_chooseThresholdVal  = 'Choose the minimum amount for notifications you want to receive';
    public string $walletActionInfo_sendNewName         = 'Send me a new name for this wallet ⬇️⬇️⬇️';

    //------------------------------------------------------------------------------------------------------------------

    private static ?Phrase $instance = null;

    public static function I(): Phrase
    {
        if (self::$instance === null)
            self::$instance = new Phrase();

        return self::$instance;
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public static function PageIndex(int $page, bool $isActive): string
    {
        return $isActive ? "✅ $page" : "📄 $page";
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    /** Label of a missed-attestations choice (OptionsNotifier::kMissedAttestationsOff = off) */
    public static function Digest(int $minutes): string
    {
        return match (true) {
            $minutes <= 0   => 'Off',
            $minutes < 60   => "Every $minutes min",
            $minutes == 60  => 'Every hour',
            default         => 'Every '.intdiv($minutes, 60).' hours',
        };
    }

    public static function MissedAttestations(int $count): string
    {
        if ($count <= 0)
            return 'Off';

        return $count == 1 ? 'Every miss' : "$count in an hour";
    }

    /** Label of a finalize-reminder choice (seconds after unlock; OptionsNotifier::kFinalizeReminderOff = off) */
    public static function FinalizeReminder(int $seconds): string
    {
        if ($seconds < 0)
            return 'Off';

        if ($seconds == 0)
            return 'As soon as they unlock';

        $days = intdiv($seconds, 86400);

        return $days == 1 ? '1 day after unlock' : "$days days after unlock";
    }

    public static function FriendlyDateTime(string $datetime): string
    {
        $timestamp = strtotime($datetime);
        return date('M d, Y, H:i \U\T\C', $timestamp);
    }

    public static function FriendlyDuration(?int $duration): string
    {
        if ($duration < 0)
            return '?';

        $SEC_YEAR = 365 * 86400;
        $SEC_DAY  = 86400;
        $SEC_HOUR = 3600;
        $SEC_MIN  = 60;

        $result = [];

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $value = floor($duration / $SEC_YEAR);
        $duration %= $SEC_YEAR;

        if ($value > 1)       $result[] = "$value years";
        else if($value == 1)  $result[] = "1 year";

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $value = floor($duration / $SEC_DAY);
        $duration %= $SEC_DAY;

        if ($value > 1)       $result[] = "$value days";
        else if($value == 1)  $result[] = "1 day";

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $value = floor($duration / $SEC_HOUR);
        $duration %= $SEC_HOUR;

        if ($value > 1)       $result[] = "$value hours";
        else if($value == 1)  $result[] = "1 hour";

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $value = floor($duration / $SEC_MIN);
        $duration %= $SEC_MIN;

        if ($value > 1)       $result[] = "$value minutes";
        else if($value == 1)  $result[] = "1 minute";

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        if ($duration > 1)       $result[] = "$duration seconds";
        else if($duration == 1)  $result[] = "1 second";

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        if (count($result) == 0)
            return 'now';

        return join(', ', array_slice($result, 0, 2));
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public static function FilterThreshold(string $scope): string
    {
        switch ($scope)
        {
            case OptionsNotifier::kThresholdTransaction:    return Phrase::I()->filterThresholdTransactions;
            case OptionsNotifier::kThresholdOffer:          return Phrase::I()->filterThresholdOffers;
        }
        return '';
    }

    public static function WithIcon(string $icon, string $message): string
    {
        return "$icon $message";
    }

    public static function PackAutocompleteAddressValue(string $address, ?string $domain = null): string
    {
        return $address . ($domain && mb_strlen($domain) <= 50 ? " 🌐 $domain" : '');
    }
}
