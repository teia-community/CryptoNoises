<?php

// Docker: deployment settings come from the environment (see .env / compose.yaml).
// The entrypoint installs this file as config/environment.php unless you mount your own.

$env = static function (string $name, $default = null) {
    $value = getenv($name);

    return ($value === false || $value === '') ? $default : $value;
};

$envInt = static function (string $name, ?int $default) use ($env): ?int {
    $value = $env($name);

    return ($value === null) ? $default : (int) $value;
};

$envBool = static function (string $name, bool $default) use ($env): bool {
    $value = $env($name);

    if ($value === null)
        return $default;

    return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
};

return [
    'environment' => $env('APP_ENVIRONMENT', 'development-local'),

    //------------------------------------------------------------------------------------------------------------------
    // Worker lifetime
    //
    // How long a looping command works before it exits and the restart policy brings it back. 55 suits a
    // once-a-minute cron tick; the worker services in compose.yaml set a much larger value.

    'worker' => [
        'time_limit_sec' => $envInt('WORKER_TIME_LIMIT_SEC', 55),
    ],

    // Appends the notification class and the event / notification ids to every message sent. For debugging only:
    // on a development instance they are written to the log either way.
    'notification_debug_footer' => (bool) $envInt('NOTIFICATION_DEBUG_FOOTER', 0),

    //------------------------------------------------------------------------------------------------------------------
    // CryptoNoises (bots + API)

    'api' => [
        'public_domain'   => $env('APP_PUBLIC_DOMAIN',  'http://localhost:8081'),
        'public_api_url'  => $env('APP_PUBLIC_API_URL', 'http://localhost:8080'),

        'links' => [
            'homepage'                      => $env('LINK_HOMEPAGE'),
            'twitter'                       => $env('LINK_TWITTER'),
            'discord_invite'                => $env('LINK_DISCORD_INVITE'),
            'discord_open_ticket_url'       => $env('LINK_DISCORD_TICKET_URL'),
            'discord_privacy_help_url'      => $env('LINK_DISCORD_PRIVACY_HELP_URL'),
            'privacy_policy'                => $env('LINK_PRIVACY_POLICY'),
        ],

        'limits' => [
            'wallets'                       => $envInt('LIMIT_WALLETS',            50),
            'following'                     => $envInt('LIMIT_FOLLOWING',          1000),
            'offers_max_holders'            => $envInt('LIMIT_OFFERS_MAX_HOLDERS', 250),
        ],

        'discord' => [
            'channel_auto_join_any'         => $env('DISCORD_CHANNEL_AUTO_JOIN_ANY'),
            'channel_auto_join_hi'          => $env('DISCORD_CHANNEL_AUTO_JOIN_HI'),
            'channel_missed_notifications'  => $env('DISCORD_CHANNEL_MISSED_NOTIFICATIONS'),
        ],

        // Matrix bot: its user id, and the client-server API it uses - pantalaimon, which encrypts and decrypts for
        // it (compose profile "matrix"). The password is MATRIX_PASSWORD. Empty user = no Matrix bot.
        'matrix' => [
            'user'                          => $env('MATRIX_USER', ''),
            'proxy_url'                     => $env('MATRIX_PROXY_URL', 'http://pantalaimon:8009'),
            // Room id (!…:server) that errors are sent to; invite the bot. Empty = none
            'alerts_room'                   => $env('MATRIX_ALERTS_ROOM', ''),
        ],

        'channel_user_ids' => [
            'tezos_global'      => $envInt('CHANNEL_USER_TEZOS_GLOBAL',     null),
            'teia'              => $envInt('CHANNEL_USER_TEIA',             null),
            'objkt_com'         => $envInt('CHANNEL_USER_OBJKT_COM',        null),
            'fxhash'            => $envInt('CHANNEL_USER_FXHASH',           null),
            'versum'            => $envInt('CHANNEL_USER_VERSUM',           null),
            'kalamint'          => $envInt('CHANNEL_USER_KALAMINT',         null),
            'rarible_tez'       => $envInt('CHANNEL_USER_RARIBLE_TEZ',      null),
            'metadata_updates'  => $envInt('CHANNEL_USER_METADATA_UPDATES', null),
        ],
    ],

    //------------------------------------------------------------------------------------------------------------------
    // CryptoNoisesTezos (indexer)

    'tezos' => [
        // TzKT API base URL, no trailing slash. The public one rate-limits; see the README.
        'tzkt_api_url'              => $env('TEZOS_TZKT_API_URL', 'https://api.tzkt.io/v1'),

        // TzKT that account aliases are read from when tzkt_api_url has none (a self-hosted TzKT has none)
        'tzkt_aliases_url'          => $env('TEZOS_TZKT_ALIASES_URL', 'https://api.tzkt.io/v1'),

        // TzKT Pro API key (sent as the `apikey` header); empty for the free tier
        'tzkt_api_key'              => $env('TEZOS_TZKT_API_KEY', ''),

        // A caught-up sync waits for the next block on TzKT's WebSocket instead of polling /head
        'tzkt_websocket'            => $envBool('TEZOS_TZKT_WEBSOCKET', true),

        // Tezos node RPCs, comma-separated, for bakers' DAL participation and protocol constants. Several, so one
        // falling behind or failing is skipped (Vendors/TezosRpc.php); earlier ones are preferred.
        'rpc_urls'                  => $env('TEZOS_RPC_URLS', 'https://rpc.tzkt.io/mainnet,https://rpc.tzbeta.net,https://mainnet.smartpy.io'),

        // Gateway used to read token metadata and media
        'ipfs_gateway_url'          => $env('TEZOS_IPFS_GATEWAY_URL', 'https://ipfs.io/ipfs/'),

        // Gateway that metadata and media are fetched through first (the "ipfs" profile's node: http://ipfs:8080/ipfs/),
        // ipfs_gateway_url being the fallback. Never stored or sent, so it may be internal. '' = none.
        'ipfs_fetch_gateway_url'    => $env('TEZOS_IPFS_FETCH_GATEWAY_URL', ''),

        'ipfs_local_base_url'       => $env('TEZOS_IPFS_LOCAL_BASE_URL', 'http://localhost:8082'),
        'ipfs_local_base_path'      => $env('TEZOS_IPFS_LOCAL_BASE_PATH', __DIR__.'/../external-ipfs'),
        'private_storage_base_path' => $env('TEZOS_PRIVATE_STORAGE_BASE_PATH', __DIR__.'/../external-storage'),

        // ImageMagick 7 (Debian trixie) ships `magick`; `convert` is the deprecated IM6 entry point
        'cmd_imagemagick_convert'   => $env('TEZOS_CMD_IMAGEMAGICK_CONVERT', '/usr/bin/magick'),
        'cmd_ffmpeg'                => $env('TEZOS_CMD_FFMPEG',  '/usr/bin/ffmpeg'),
        'cmd_timeout'               => $env('TEZOS_CMD_TIMEOUT', '/usr/bin/timeout'),

        'media' => [
            'thumbnail_width'       => $envInt('MEDIA_THUMBNAIL_WIDTH',   256),
            'thumbnail_quality'     => $envInt('MEDIA_THUMBNAIL_QUALITY', 60),
            'video_preview'         => $envBool('MEDIA_VIDEO_PREVIEW',    false),
            'video_width'           => $envInt('MEDIA_VIDEO_WIDTH',       512),
            'video_bitrate'         => $env('MEDIA_VIDEO_BITRATE',        '1M'),
            'animated_preview'      => $envBool('MEDIA_ANIMATED_PREVIEW', false),
            'animated_width'        => $envInt('MEDIA_ANIMATED_WIDTH',    240),
            'animated_fps'          => $envInt('MEDIA_ANIMATED_FPS',      30),
            'max_duration_sec'      => $envInt('MEDIA_MAX_DURATION_SEC',  30),
            'tool_timeout_sec'      => $envInt('MEDIA_TOOL_TIMEOUT_SEC',  60),
            'preview_retention_days'=> $envInt('MEDIA_PREVIEW_RETENTION_DAYS', 0),
        ],
    ],
];
