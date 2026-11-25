<?php

namespace CryptoNoisesTezos;

use CryptoCore\Env\Environment;
use CryptoCore\Env\EnvironmentPredicates;
use CryptoNoisesTezos\Core\MediaSettings;

class Config
{
    use EnvironmentPredicates;

    // Levels the sync stays behind TzKT's head: a Tezos block is final once two more are on top of it, and TzKT
    // reorganises the levels above - data read from them may be replaced
    const kSYNC_BLOCK_BEHIND                            = 2;

    const kJOBS_TIMEOUT_DEFAULT                         = 55;

    /**
     * How long a looping command keeps working before it exits, in seconds.
     *
     * The per-command kJOBS_TIMEOUT_* constants are the PHP execution ceiling (set_time_limit) and must stay
     * ABOVE this: they are added to it, so each one is the slack one unit of work may take on top of the loop
     * budget. Setting a ceiling below the loop budget kills the process mid-task and leaves the block it was
     * working on in 'processing'.
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


    const kJOBS_TIMEOUT_TRANSACTION_SYNC                = 3*60;
    const kJOBS_TIMEOUT_TRANSACTION_ANALYZE             = 5*60;

    const kJOBS_TIMEOUT_BIGMAP_UPDATE_SYNC              = 3*60;
    const kJOBS_TIMEOUT_BIGMAP_UPDATE_ANALYZE           = 5*60;

    //------------------------------------------------------------------------------------------------------------------
    // Log tables cleanup (blockchain/*/cleanup): a table is dropped only when it is this many levels behind the slowest
    // reader. Recent log tables hold 10000 levels each (older ranges are bigger), so the minimum keeps at least the
    // current and the previous table.
    // Note: the CryptoNoises analyzer also reads transaction tables (via GatewayTezos) and must not lag more than this.

    const kLOG_CLEANUP_DEFAULT_OFFSET                   = 50000;
    const kLOG_CLEANUP_MIN_OFFSET                       = 20000;

    //------------------------------------------------------------------------------------------------------------------
    // Download limits for untrusted URLs (token metadata / media), see Core\Downloader

    const kDOWNLOAD_MAX_METADATA_BYTES                  = 5 * 1024 * 1024;
    const kDOWNLOAD_MAX_MEDIA_BYTES                     = 100 * 1024 * 1024;

    // ImageMagick / ffmpeg limits and preview settings: environment.php tezos.media (Core\MediaSettings)

    //------------------------------------------------------------------------------------------------------------------
    // Constants. Do not change these values.

    const kENVIRONMENT_Production                       = Environment::kPRODUCTION;
    const kENVIRONMENT_Development                      = Environment::kDEVELOPMENT;
    const kENVIRONMENT_Development_Local                = Environment::kDEVELOPMENT_LOCAL;

    //------------------------------------------------------------------------------------------------------------------

    const kTOKEN_EVENTS_FixMintAndUpdate_forProduction      = true;
    const kTOKEN_EVENTS_FixMintAndUpdate_forDevelopment    = false;

    //------------------------------------------------------------------------------------------------------------------

    private ?string $environment = null;                // see Environment::kALLOWED

    //------------------------------------------------------------------------------------------------------------------

    // Default IPFS gateway, used when tezos.ipfs_gateway_url is not set
    const kIPFS_GATEWAY_DEFAULT                 = 'https://ipfs.io/ipfs/';

    // Default TzKT API, used when tezos.tzkt_api_url is not set
    const kTZKT_API_DEFAULT                     = 'https://api.tzkt.io/v1';

    public string $tzktApiUrl;

    // Where account aliases ("Finn", a baker's name) and contract metadata (e.g. the ALEATORY-001 interface) are
    // looked up when the data from tzktApiUrl has none: both come from TzKT's metadata service, which a self-hosted
    // TzKT does not run. Empty = no lookups.
    public string $tzktAliasesUrl;

    // TzKT Pro key, sent as the `apikey` header to the TzKT URLs above; empty for the free tier
    public string $tzktApiKey;

    // Whether a caught-up sync waits for the next block on TzKT's WebSocket (Vendors/TzktHeadStream.php); it falls
    // back to polling /head on its own when the socket is not available
    public bool $tzktWebsocket;

    // Tezos node RPCs, for what only the protocol knows (a baker's DAL participation); see Vendors/TezosRpc.php.
    // Empty = not used.
    /** @var string[] */
    public array $tezosRpcUrls;

    public string $ipfsGatewayUrl;

    public string $ipfsLocalBaseUrl;
    public string $ipfsLocalBasePath;

    public string $privateStorageBasePath;

    public string $cmd_imageMagickConvert;
    public string $cmd_ffmpeg;
    public string $cmd_timeout;

    public MediaSettings $media;

    //------------------------------------------------------------------------------------------------------------------

    private static ?self $Instance = null;

    public static function cfg(): self
    {
        if (self::$Instance === null)
        {
            self::$Instance = new self();
            self::$Instance->initialize();   // initialized on first use (not as a side effect of loading the class)
        }

        return self::$Instance;
    }

    public function __construct()
    {
        $this->environment = Environment::name();
    }

    public function initialize(): void
    {
        // All chain data comes from here. The public api.tzkt.io rate-limits, and a scaled-up set of workers will
        // hit that limit, so point this at a TzKT you control (see Vendors/Tzkt.php).
        $this->tzktApiUrl                           = rtrim((string) Environment::get('tezos.tzkt_api_url', self::kTZKT_API_DEFAULT), '/');
        $this->tzktAliasesUrl                       = rtrim((string) Environment::get('tezos.tzkt_aliases_url', self::kTZKT_API_DEFAULT), '/');
        $this->tzktApiKey                           = trim((string) Environment::get('tezos.tzkt_api_key', ''));
        $this->tzktWebsocket                        = (bool) Environment::get('tezos.tzkt_websocket', true);
        $rpcUrls                                    = Environment::get('tezos.rpc_urls', 'https://rpc.tzkt.io/mainnet,https://rpc.tzbeta.net,https://mainnet.smartpy.io');
        $this->tezosRpcUrls                         = array_values(array_filter(array_map(fn ($url) => rtrim(trim((string) $url), '/'),
                                                          is_array($rpcUrls) ? $rpcUrls : explode(',', (string) $rpcUrls))));

        // Gateway that token metadata and media are read through. The public ipfs.io gateway rate-limits hard
        // these days, which shows up as "IPFS returned empty data", so point this at a gateway you control.
        $this->ipfsGatewayUrl                       = rtrim((string) Environment::get('tezos.ipfs_gateway_url', self::kIPFS_GATEWAY_DEFAULT), '/').'/';

        $this->ipfsLocalBaseUrl                     = (string) Environment::get('tezos.ipfs_local_base_url');
        $this->ipfsLocalBasePath                    = (string) Environment::get('tezos.ipfs_local_base_path');

        $this->privateStorageBasePath               = (string) Environment::get('tezos.private_storage_base_path');

        $this->cmd_imageMagickConvert               = (string) Environment::get('tezos.cmd_imagemagick_convert', 'convert');
        $this->cmd_ffmpeg                           = (string) Environment::get('tezos.cmd_ffmpeg', 'ffmpeg');
        $this->cmd_timeout                          = (string) Environment::get('tezos.cmd_timeout', '');

        $this->media                                = MediaSettings::FromEnvironment();
    }

    //------------------------------------------------------------------------------------------------------------------

    public function isTokenSupportsAnimatedPreview(): bool
    {
        return $this->media->animatedPreview;     // off by default
    }

    public function isTokenSupportsVideoPreview(): bool
    {
        return $this->media->videoPreview;        // off by default
    }
}
