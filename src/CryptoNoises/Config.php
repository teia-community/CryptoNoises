<?php

namespace CryptoNoises;

use CryptoCore\Env\Environment;
use CryptoCore\Env\EnvironmentPredicates;

class Config
{
    use EnvironmentPredicates;

    //------------------------------------------------------------------------------------------------------------------
    // Constants. Do not change these values.

    const kENVIRONMENT_Development_Local                = Environment::kDEVELOPMENT_LOCAL;
    const kENVIRONMENT_Development                      = Environment::kDEVELOPMENT;
    const kENVIRONMENT_Production                       = Environment::kPRODUCTION;

    const kPLATFORM_Discord                             = 'discord';
    const kPLATFORM_Telegram                            = 'telegram';
    const kPLATFORM_Matrix                              = 'matrix';

    const kNETWORK_Tezos                                = 'tezos';

    //------------------------------------------------------------------------------------------------------------------

    const kJOBS_TIMEOUT_DEFAULT                         =    55;

    /**
     * How long a looping command keeps working before it exits, in seconds.
     *
     * Under cron this has to stay below the one-minute tick, which is what kJOBS_TIMEOUT_DEFAULT is for. Running
     * the same commands as long-lived worker services (see compose.yaml) means they should not exit every minute:
     * set worker.time_limit_sec (WORKER_TIME_LIMIT_SEC) to the lifetime you want, and let the restart policy
     * bring the process back. It is a lifetime, not a deadline for one task.
     */
    public static function runTimeLimit(): int
    {
        $value = (int) Environment::get('worker.time_limit_sec', self::kJOBS_TIMEOUT_DEFAULT);

        return $value > 0 ? $value : self::kJOBS_TIMEOUT_DEFAULT;
    }


    const kJOBS_TIMEOUT_TELEGRAM_NOTIFIER               =  3*60;
    const kJOBS_TIMEOUT_TEZOS_TRANSACTION_ANALYZE       =  8*60;
    const kJOBS_TIMEOUT_EVENTS_TOKENS                   =  5*60;

    //------------------------------------------------------------------------------------------------------------------

    const kMaxActiveHoldersForOffer          = 10001;    // tokens with this many active holders are permanently excluded from offer notifications
    const kMaxActiveHoldersForCollection     = 15001;    // collections with this many active holders are permanently excluded from offer notifications

    //------------------------------------------------------------------------------------------------------------------
    // Discord

    const kDiscord_Assistant_ResyncStats                = 10;
    const kDiscord_Assistant_ResyncTimeout              = 60;

    const kDiscord_AutoDisconnectFromServerTimeout      = 3*60;

    const kDiscord_Notifier_IdleTime                    = 2.5;

    const kDiscord_AutoDeleteMissedMessageInfo_Minutes  = 5;

    //------------------------------------------------------------------------------------------------------------------

    const kUI_SummaryWalletsPerPage                     = 5;
    const kUI_DomainsPerPage                            = 5;
    const kUI_OffersPerPage                             = 8;
    const kUI_FollowingPerPage                          = 25;

    //------------------------------------------------------------------------------------------------------------------

    public string $dbMain;                                                  // main DB name (from DSN), for cross-DB queries

    public string $publicDomain;                                            // public-app/ base URL
    public bool $notificationDebugFooter;                                   // debug ids appended to sent messages
    public string $publicApiUrl;                                            // public-api/ base URL (Telegram webhooks)

    // Matrix bot (environment.php: api.matrix): the client-server API it talks to - pantalaimon, which encrypts and
    // decrypts for it - and its user id. The password is kMATRIX_PASSWORD (consts-secrets.php). Empty = no Matrix bot.
    public string $matrixProxyUrl = '';
    public string $matrixUser = '';
    public string $matrixAlertsRoom = '';      // room id for operator alerts (OpsAlerts); '' = none

    /** @var array<string, mixed> Public project links (environment.php: api.links) */
    private array $links = [];

    // Per-user limits, the same for every user (environment.php: api.limits)
    public int $limitWallets;
    public int $limitFollowing;
    public int $limitOffersMaxHolders;                                      // offers on tokens / collections with more holders are not sent

    private ?string $platform = null;                                       // 'discord'     | 'telegram'
    private ?string $environment = null;                                    // see Environment::kALLOWED

    public ?string $discordChannelAutoJoin_any = null;                      // api.discord.* in environment.php: joined when the user writes ANY message
    public ?string $discordChannelAutoJoin_hi  = null;                      // api.discord.* in environment.php: joined when the user writes 'hi' only
    public ?string $discordChannelMissedNotifications = null;               // channel that receives notices about undeliverable DMs

    public int $uiNftDetailsButtonsPerRow;                                  // depends on Platform

    public int $uiWalletIconsPerRow;                                        // depends on Platform
    public int $uiFilterNotificationTypesPerRow;                            // depends on Platform
    public int $uiThresholdsPerRow;                                         // depends on Platform
    public int $uiThresholdValuesPerRow;                                    // depends on Platform

    public int $uiCoinsCurrencyPerRow;                                      // depends on Platform
    public int $uiCoinsCurrencyPerPage;                                     // depends on Platform

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
    // These values are initialized in the constructor

    public ?int $userId_ChannelTezosGlobal              = null;
    public ?int $userId_ChannelTeia                     = null;
    public ?int $userId_ChannelObjktCom                 = null;
    public ?int $userId_ChannelFxHash                   = null;
    public ?int $userId_ChannelVersum                   = null;
    public ?int $userId_ChannelKalamint                 = null;
    public ?int $userId_ChannelRaribleTez               = null;

    public ?int $userId_ChannelMetadataUpdates          = null;

    //------------------------------------------------------------------------------------------------------------------

    private static ?self $Instance = null;

    public static function cfg(): self
    {
        if (self::$Instance === null)
            self::$Instance = new self();

        return self::$Instance;
    }

    public function __construct()
    {
        $this->environment                          = Environment::name();

        $this->dbMain                               = \VssCfg::cfg()->getDbName('dbMain');
        $this->publicDomain                         = (string) Environment::get('api.public_domain');
        $this->notificationDebugFooter              = (bool) Environment::get('notification_debug_footer', false);
        $this->publicApiUrl                         = (string) Environment::get('api.public_api_url');
        $this->links                                = (array) Environment::get('api.links', []);

        $this->matrixProxyUrl                       = rtrim((string) Environment::get('api.matrix.proxy_url', ''), '/');
        $this->matrixUser                           = (string) Environment::get('api.matrix.user', '');
        $this->matrixAlertsRoom                     = (string) Environment::get('api.matrix.alerts_room', '');

        $this->limitWallets                         = (int) Environment::get('api.limits.wallets', 50);
        $this->limitFollowing                       = (int) Environment::get('api.limits.following', 1000);
        $this->limitOffersMaxHolders                = (int) Environment::get('api.limits.offers_max_holders', 250);

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $this->discordChannelAutoJoin_any           = self::NullableString(Environment::get('api.discord.channel_auto_join_any'));
        $this->discordChannelAutoJoin_hi            = self::NullableString(Environment::get('api.discord.channel_auto_join_hi'));
        $this->discordChannelMissedNotifications    = self::NullableString(Environment::get('api.discord.channel_missed_notifications'));

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $this->userId_ChannelTezosGlobal            = Environment::get('api.channel_user_ids.tezos_global');
        $this->userId_ChannelTeia                   = Environment::get('api.channel_user_ids.teia');
        $this->userId_ChannelObjktCom               = Environment::get('api.channel_user_ids.objkt_com');
        $this->userId_ChannelFxHash                 = Environment::get('api.channel_user_ids.fxhash');
        $this->userId_ChannelVersum                 = Environment::get('api.channel_user_ids.versum');
        $this->userId_ChannelKalamint               = Environment::get('api.channel_user_ids.kalamint');
        $this->userId_ChannelRaribleTez             = Environment::get('api.channel_user_ids.rarible_tez');

        $this->userId_ChannelMetadataUpdates        = Environment::get('api.channel_user_ids.metadata_updates');
    }

    private static function NullableString($value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /**
     * Not called in some workflows (for example CLI commands)
     *
     * @param string $platform
     * @return void
     */
    public function initializeByPlatform(string $platform): void
    {
        $this->platform = $platform;

        switch ($this->platform)
        {
            case self::kPLATFORM_Discord:
                $this->uiNftDetailsButtonsPerRow            = 5;

                $this->uiWalletIconsPerRow                  = 5;
                $this->uiThresholdsPerRow                   = 1;
                $this->uiThresholdValuesPerRow              = 4;
                $this->uiFilterNotificationTypesPerRow      = 4;

                $this->uiCoinsCurrencyPerRow                = 5;
                $this->uiCoinsCurrencyPerPage               = 20;
                break;

            case self::kPLATFORM_Telegram:
            case self::kPLATFORM_Matrix:
                $this->uiNftDetailsButtonsPerRow            = 2;

                $this->uiWalletIconsPerRow                  = 8;
                $this->uiThresholdsPerRow                   = 1;
                $this->uiThresholdValuesPerRow              = 2;
                $this->uiFilterNotificationTypesPerRow      = 2;

                $this->uiCoinsCurrencyPerRow                = 4;
                $this->uiCoinsCurrencyPerPage               = 20;
                break;
        }
    }

    //------------------------------------------------------------------------------------------------------------------

    /**
     * Public project link from environment.php (api.links.<key>). Empty value -> null (caller hides the button/line).
     */
    public function getLink(string $key): ?string
    {
        $value = $this->links[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    //------------------------------------------------------------------------------------------------------------------

    public function isDiscordPlatform(): bool
    {
        return $this->platform == self::kPLATFORM_Discord;
    }

    public function isTelegramPlatform(): bool
    {
        return $this->platform == self::kPLATFORM_Telegram;
    }

    public function isMatrixPlatform(): bool
    {
        return $this->platform == self::kPLATFORM_Matrix;
    }

    /**
     * Messages laid out as for Telegram: HTML text, buttons in rows. Matrix takes the same HTML subset, so its
     * notifications are rendered from the Telegram variant of every template (MatrixBotConnection then turns the
     * link buttons into links).
     */
    public function usesTelegramLayout(): bool
    {
        return $this->isTelegramPlatform() || $this->isMatrixPlatform();
    }
}
