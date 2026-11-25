<?php

// Copy this file to config/environment.php and adjust it for your deployment.
// config/environment.php is gitignored.

return [
    // production | development | development-local
    'environment'       => 'development-local',

    //------------------------------------------------------------------------------------------------------------------
    // How long a looping command (sync, analyze, /jobs/exec, the notifiers) works before it exits.
    //
    // 55 is right when cron starts them every minute. If you run them as long-lived services instead and let a
    // supervisor restart them, raise this: it is the process lifetime, not a limit for one task.

    'worker' => [
        'time_limit_sec'    => 55,
    ],

    // Appends the notification class and the event / notification ids to every message sent. For debugging only:
    // on a development environment they are written to the log either way.
    'notification_debug_footer' => false,

    //------------------------------------------------------------------------------------------------------------------
    // CryptoNoises (bots + API)

    'api' => [
        // Public domain of public-app/ (redirects to marketplaces)
        'public_domain'                 => 'https://app.example.com',

        // Public base URL of public-api/ (Telegram webhooks are registered here by /telegram/webhook/set)
        'public_api_url'                => 'https://api.example.com',

        // Public links shown in bot messages / used by public-app redirects.
        // Empty string or null hides the related button / text line.
        'links' => [
            'homepage'                      => 'https://example.com',             // public-app/ and public-api/ "/" redirect here
            'twitter'                       => 'https://twitter.com/your_account',
            'discord_invite'                => 'https://discord.gg/your_invite_code',
            'discord_open_ticket_url'       => 'https://discord.com/channels/your_server_id/your_channel_id',
            'discord_privacy_help_url'      => null,                              // how to allow DMs from server members
            'privacy_policy'                => null,                              // your privacy policy (button in Welcome / Help)
        ],

        // Limits, the same for every user
        'limits' => [
            'wallets'                       => 50,
            'following'                     => 1000,                              // users (wallets) to follow
            'offers_max_holders'            => 250,                               // offers on tokens / collections with more holders are not sent
        ],

        'discord' => [
            // Onboarding channels on your server (null = disabled). The bot ignores normal messages in servers
            // (users talk to it via slash commands and DMs), except in these channels. On such a message it:
            //   1. activates the author (creates the user on the first message);
            //   2. replies in the channel "check my instructions in DM" + how to allow DMs from the server;
            //   3. sends /start (new user) or /help (existing user) to the author's DMs;
            //   4. deletes both the author's message and its reply after 60 seconds, so the channel stays clean.
            //
            // channel_auto_join_any: reacts to ANY message in the channel (a dedicated "start here" channel);
            // channel_auto_join_hi:  reacts only to the message "hi" (a channel where people also chat).
            'channel_auto_join_any'         => null,
            'channel_auto_join_hi'          => null,

            // Channel on your server (kDISCORD_GUILD_ID) for "the bot cannot DM you, open your DMs" notices (null = disabled).
            // Used when a DM fails: a member of your server (see the assistant thread) is mentioned in this channel,
            // a user who is not on your server is deactivated.
            'channel_missed_notifications'  => null,
        ],

        // Internal user ids (users table) of broadcast "channel" users (null = disabled)
        //
        // These are not real people: each one is a row in `users` whose platform_channel_id points to a public
        // Telegram / Discord channel. The bot posts feeds there through the regular notification flow:
        //   - tezos_global      : every new Tezos mint (EventsTokensMint)
        //   - teia ... rarible  : mints from that marketplace only (in addition to the global feed)
        //   - metadata_updates  : token metadata updates (EventsTokensUpdates)
        // To enable a feed: create such a user for your channel and put its id here.
        'channel_user_ids' => [
            'tezos_global'      => null,
            'teia'              => null,
            'objkt_com'         => null,
            'fxhash'            => null,
            'versum'            => null,
            'kalamint'          => null,
            'rarible_tez'       => null,
            'metadata_updates'  => null,
        ],
    ],

    //------------------------------------------------------------------------------------------------------------------
    // CryptoNoisesTezos (indexer)

    'tezos' => [
        // TzKT API base URL, without a trailing slash. All chain data comes from here.
        // The public api.tzkt.io rate-limits: the indexer asks it for every block, every unseen bigmap and every
        // contract, so running more than a few workers earns HTTP 429 ("Cannot get bigmap data from tzkt").
        // Point this at a TzKT you run if you index at any scale.
        'tzkt_api_url'                          => 'https://api.tzkt.io/v1',

        // Account aliases ("Finn", a baker's name) and contract metadata (aleatory.art's ALEATORY-001), when
        // tzkt_api_url has none: both come from TzKT's metadata service, which a self-hosted TzKT does not run.
        // A few requests per day. '' = no lookups.
        'tzkt_aliases_url'                      => 'https://api.tzkt.io/v1',

        // TzKT Pro API key, sent as the `apikey` header to the TzKT URLs above. '' = the free tier.
        'tzkt_api_key'                          => '',

        // Tezos node RPCs, comma-separated or a list, for bakers' DAL participation and protocol constants. The
        // freshest are used, earlier ones first; one behind the chain or failing is skipped. '' = no DAL alerts.
        'rpc_urls'                              => 'https://rpc.tzkt.io/mainnet,https://rpc.tzbeta.net,https://mainnet.smartpy.io',

        // IPFS gateway that token metadata and media are read through.
        // The public ipfs.io gateway rate-limits heavily, which surfaces as "IPFS returned empty data" in the
        // sync-tokens-metadata / sync-ipfs job notices, so prefer a gateway you run or pay for.
        'ipfs_gateway_url'                      => 'https://ipfs.io/ipfs/',

        // Public URL + local path where media previews are stored and served from
        'ipfs_local_base_url'                   => 'https://ipfs.example.com',
        'ipfs_local_base_path'                  => __DIR__.'/../external-ipfs',

        // Private storage for downloaded originals
        'private_storage_base_path'             => __DIR__.'/../external-storage',

        // External tools
        'cmd_imagemagick_convert'               => '/usr/bin/convert',
        'cmd_ffmpeg'                            => '/usr/bin/ffmpeg',
        // coreutils "timeout" to kill hung conversions ('' = disabled; on macOS: brew install coreutils -> gtimeout)
        'cmd_timeout'                           => '/usr/bin/timeout',

        // Media previews (the values below are the defaults)
        'media' => [
            'thumbnail_width'                   => 256,     // JPEG preview of images and of the first video frame, px
            'thumbnail_quality'                 => 60,      // JPEG quality, %
            'video_preview'                     => false,   // also make an mp4 preview of videos
            'video_width'                       => 512,     // px
            'video_bitrate'                     => '1M',    // ffmpeg -b:v
            'animated_preview'                  => false,   // also make a GIF preview of animations
            'animated_width'                    => 240,     // px
            'animated_fps'                      => 30,
            'max_duration_sec'                  => 30,      // input video / animation is cut to this length
            'tool_timeout_sec'                  => 60,      // ImageMagick / ffmpeg run time limit (ffmpeg needs cmd_timeout)

            // Days a generated preview is kept; 0 keeps everything. A preview exists so a notification can
            // carry an image, not as an archive: Telegram fetches it once while sending and serves its own
            // copy afterwards. /jobs/clean/old-previews does the pruning.
            'preview_retention_days'            => 0,
        ],
    ],
];
