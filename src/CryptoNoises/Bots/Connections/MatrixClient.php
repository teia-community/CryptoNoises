<?php

namespace CryptoNoises\Bots\Connections;

use CryptoCore\Process\Shutdown;
use CryptoNoises\Config;
use CryptoNoises\Core\Log;
use CryptoNoises\Models;
use Exception;

/**
 * The Matrix client-server API, as the bot needs it: log in, sync, join and leave rooms, send messages, upload
 * images. Plain HTTP and JSON (spec.matrix.org/latest/client-server-api), sent to pantalaimon rather than to the
 * homeserver: pantalaimon encrypts what the bot sends to an end-to-end encrypted room and decrypts what it reads,
 * and passes everything else through. Pantalaimon keeps its own device and keys, which is why the bot logs in
 * through it with a password - a token from a login elsewhere belongs to another device and cannot decrypt.
 *
 * The access token and the sync position are kept in `config`, so restarts reuse the same device.
 */
final class MatrixClient
{
    const kCFG_ACCESS_TOKEN         = 'Matrix.AccessToken';
    const kCFG_DEVICE_ID            = 'Matrix.DeviceId';

    // Synapse limits a normal account to a message every 5 s after a burst of 10 (rc_message), and answers past
    // that with 429 M_LIMIT_EXCEEDED and retry_after_ms. Each wait is honoured up to kMAX_RETRY_AFTER_SEC.
    const kMAX_429_RETRIES          = 5;
    const kMAX_RETRY_AFTER_SEC      = 60;

    private static ?self $instance = null;

    private ?string $accessToken = null;
    private string $baseUrl;

    public static function I(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->baseUrl = Config::cfg()->matrixProxyUrl;
    }

    public static function IsConfigured(): bool
    {
        return Config::cfg()->matrixUser !== '' && Config::cfg()->matrixProxyUrl !== '' && defined('kMATRIX_PASSWORD') && kMATRIX_PASSWORD !== '';
    }

    public function userId(): string
    {
        return Config::cfg()->matrixUser;
    }

    //------------------------------------------------------------------------------------------------------------------
    // API

    /** @return array the sync response; long-polls up to $timeoutMs when nothing is new */
    public function sync(?string $since, int $timeoutMs, ?string $filter = null): array
    {
        $query = ['timeout' => $timeoutMs];

        if ($since !== null)
            $query['since'] = $since;

        if ($filter !== null)
            $query['filter'] = $filter;

        return $this->request('GET', '/_matrix/client/v3/sync', null, $query, ($timeoutMs / 1000) + 30);
    }

    public function joinRoom(string $roomId): void
    {
        $this->request('POST', '/_matrix/client/v3/join/'.rawurlencode($roomId), []);
    }

    public function leaveRoom(string $roomId): void
    {
        $this->request('POST', '/_matrix/client/v3/rooms/'.rawurlencode($roomId).'/leave', []);
    }

    /** @return string[] user ids of the room's joined members */
    public function joinedMembers(string $roomId): array
    {
        $data = $this->request('GET', '/_matrix/client/v3/rooms/'.rawurlencode($roomId).'/joined_members');

        return array_keys($data['joined'] ?? []);
    }

    /** Sends an m.room.message; @return string the event id */
    public function sendMessage(string $roomId, array $content): string
    {
        $txnId = 'cn'.bin2hex(random_bytes(8));
        $data  = $this->request('PUT', '/_matrix/client/v3/rooms/'.rawurlencode($roomId).'/send/m.room.message/'.$txnId, $content);

        return (string) ($data['event_id'] ?? '');
    }

    /** Uploads a file to the media repository; @return string its mxc:// URI */
    public function upload(string $bytes, string $contentType, string $filename): string
    {
        $data = $this->request('POST', '/_matrix/media/v3/upload', $bytes, ['filename' => $filename], 60, $contentType);

        if (empty($data['content_uri']))
            throw new Exception('Matrix upload returned no content_uri');

        return (string) $data['content_uri'];
    }

    /**
     * A call that needs user-interactive auth (uploading cross-signing keys, deleting a device): the homeserver first
     * answers 401 with a session, and the call is repeated with the bot's password.
     */
    public function requestWithPassword(string $method, string $path, array $body): array
    {
        [$code, $data, $raw] = $this->call($method, $path, $body, [], 30, 'application/json', $this->token());

        if ($code == 401 && isset($data['flows']))
        {
            $body['auth'] = [
                'type'          => 'm.login.password',
                'identifier'    => ['type' => 'm.id.user', 'user' => preg_replace('/^@([^:]+):.*$/', '$1', $this->userId())],
                'password'      => kMATRIX_PASSWORD,
                'session'       => $data['session'] ?? null,
            ];

            [$code, $data, $raw] = $this->call($method, $path, $body, [], 30, 'application/json', $this->token());
        }

        if ($code < 200 || $code >= 300)
            throw new Exception("Matrix $method $path: HTTP $code ".($data['errcode'] ?? substr((string) $raw, 0, 200))
                .(isset($data['error']) ? " ({$data['error']})" : ''));

        return is_array($data) ? $data : [];
    }

    //------------------------------------------------------------------------------------------------------------------
    // Transport

    /**
     * One API call, as the bot. Logs in when there is no token, and once more when the token is refused
     * (M_UNKNOWN_TOKEN); waits out rate limits.
     *
     * @param array|string|null $body JSON body (array), raw bytes (string, with $contentType) or none
     * @throws Exception on any other error, with Matrix's errcode
     */
    public function request(string $method, string $path, $body = null, array $query = [], int $timeoutSec = 30,
                            string $contentType = 'application/json'): array
    {
        $relogged = false;

        for ($attempt = 0; ; $attempt++)
        {
            [$code, $data, $raw] = $this->call($method, $path, $body, $query, $timeoutSec, $contentType, $this->token());

            if ($code >= 200 && $code < 300)
                return is_array($data) ? $data : [];

            $errcode = $data['errcode'] ?? '';

            if ($code == 401 && $errcode == 'M_UNKNOWN_TOKEN' && !$relogged)
            {
                $this->forgetToken();
                $relogged = true;
                continue;
            }

            if ($code == 429 && $attempt < self::kMAX_429_RETRIES && !Shutdown::Requested())
            {
                $wait = (int) ceil(((int) ($data['retry_after_ms'] ?? 1000)) / 1000);

                if ($wait <= self::kMAX_RETRY_AFTER_SEC)
                {
                    Log::Notice(self::class, __FUNCTION__, "Matrix rate limit on $method $path: retrying in $wait s");
                    sleep($wait + 1);
                    continue;
                }
            }

            throw new Exception("Matrix $method $path: HTTP $code ".($errcode ?: substr((string) $raw, 0, 200))
                .(isset($data['error']) ? " ({$data['error']})" : ''));
        }
    }

    /** @return array{int, ?array, string|false} HTTP code, decoded JSON, raw body */
    private function call(string $method, string $path, $body, array $query, int $timeoutSec, string $contentType, ?string $token): array
    {
        $url = $this->baseUrl.$path.($query ? '?'.http_build_query($query) : '');

        $headers = [];

        if ($token !== null)
            $headers[] = 'Authorization: Bearer '.$token;

        $ch = curl_init($url);
        $options = [
            CURLOPT_CUSTOMREQUEST   => $method,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_CONNECTTIMEOUT  => 10,
            CURLOPT_TIMEOUT         => $timeoutSec,
            CURLOPT_USERAGENT       => \CryptoCore\Http\AbstractVendor::kUserAgent,
        ];

        if ($body !== null)
        {
            $headers[] = 'Content-Type: '.$contentType;
            $options[CURLOPT_POSTFIELDS] = is_array($body) ? json_encode($body ?: new \stdClass()) : $body;
        }

        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = is_string($raw) ? json_decode($raw, true) : null;

        return [$code, is_array($data) ? $data : null, $raw];
    }

    //------------------------------------------------------------------------------------------------------------------
    // Session

    private function token(): string
    {
        if ($this->accessToken !== null)
            return $this->accessToken;

        $stored = Models\Config::model()->getValue(self::kCFG_ACCESS_TOKEN);

        if (is_string($stored) && $stored !== '')
            return $this->accessToken = $stored;

        return $this->accessToken = $this->login();
    }

    private function forgetToken(): void
    {
        $this->accessToken = null;
        Models\Config::model()->setValue(self::kCFG_ACCESS_TOKEN, '');
    }

    /** Password login through pantalaimon, reusing the device pantalaimon already holds keys for */
    private function login(): string
    {
        if (!self::IsConfigured())
            throw new Exception('Matrix bot is not configured (api.matrix.user, api.matrix.proxy_url, kMATRIX_PASSWORD)');

        $localpart = preg_replace('/^@([^:]+):.*$/', '$1', $this->userId());
        $deviceId  = Models\Config::model()->getValue(self::kCFG_DEVICE_ID);

        $body = [
            'type'                          => 'm.login.password',
            'identifier'                    => ['type' => 'm.id.user', 'user' => $localpart],
            'password'                      => kMATRIX_PASSWORD,
            'initial_device_display_name'   => 'CryptoNoises bot',
        ];

        if (is_string($deviceId) && $deviceId !== '')
            $body['device_id'] = $deviceId;

        [$code, $data] = $this->call('POST', '/_matrix/client/v3/login', $body, [], 30, 'application/json', null);

        if ($code != 200 || empty($data['access_token']))
            throw new Exception("Matrix login failed: HTTP $code ".($data['errcode'] ?? '').(isset($data['error']) ? " ({$data['error']})" : ''));

        Models\Config::model()->setValue(self::kCFG_ACCESS_TOKEN, $data['access_token']);
        Models\Config::model()->setValue(self::kCFG_DEVICE_ID, (string) ($data['device_id'] ?? ''));

        Log::Notice(self::class, __FUNCTION__, 'Logged in to Matrix as '.$this->userId().', device '.($data['device_id'] ?? '?'));

        return $data['access_token'];
    }
}
