<?php

namespace CryptoNoises\Bots\Connections;

use CryptoNoises\Models;
use Exception;

/**
 * Cross-signing for the bot's account, so that clients show its messages as coming from a device its owner verified.
 *
 * Pantalaimon holds the device keys but does not do cross-signing, so without this Element marks every message from
 * the bot as "encrypted by a device not verified by its owner". The bot gets a cross-signing identity - master,
 * self-signing and user-signing keys (spec: client-server API, "Cross-signing") - and signs, with the self-signing
 * key, each device of its account that has keys: only pantalaimon creates such devices on the bot's account, so a
 * new pantalaimon device is signed the next time this runs.
 *
 * The private keys are kept in `config` (Matrix.CrossSigning), so the identity stays the same across runs; uploading
 * them needs the bot's password. If the server's master key is not ours (the identity was reset elsewhere), a new
 * identity replaces it.
 */
final class MatrixCrossSigning
{
    const kCFG_KEYS = 'Matrix.CrossSigning';

    private MatrixClient $client;
    private string $user;

    public function __construct(MatrixClient $client)
    {
        $this->client = $client;
        $this->user   = $client->userId();
    }

    /** @return string[] what was done, for the log */
    public function ensure(): array
    {
        $done   = [];
        $query  = $this->client->request('POST', '/_matrix/client/v3/keys/query', ['device_keys' => [$this->user => []]]);
        $seeds  = json_decode((string) Models\Config::model()->getValue(self::kCFG_KEYS), true) ?: null;

        $serverMaster = array_values($query['master_keys'][$this->user]['keys'] ?? [])[0] ?? null;

        if ($seeds === null || $serverMaster === null || self::PublicKey($seeds['master']) !== $serverMaster)
        {
            $seeds = [
                'master'        => base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES)),
                'self_signing'  => base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES)),
                'user_signing'  => base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES)),
            ];

            $this->uploadIdentity($seeds);
            Models\Config::model()->setValue(self::kCFG_KEYS, json_encode($seeds));

            $done[] = 'created the cross-signing identity '.self::PublicKey($seeds['master']);

            $query = $this->client->request('POST', '/_matrix/client/v3/keys/query', ['device_keys' => [$this->user => []]]);
        }

        // Every device with keys, not yet signed by our self-signing key
        $sskId      = 'ed25519:'.self::PublicKey($seeds['self_signing']);
        $signatures = [];

        foreach ($query['device_keys'][$this->user] ?? [] as $deviceId => $device)
        {
            if (empty($device['keys']) || isset($device['signatures'][$this->user][$sskId]))
                continue;

            $signed = array_diff_key($device, ['signatures' => 1, 'unsigned' => 1]);
            $signed['signatures'] = [$this->user => [$sskId => self::Sign($seeds['self_signing'], $signed)]];

            $signatures[$deviceId] = $signed;
        }

        if ($signatures)
        {
            $result = $this->client->request('POST', '/_matrix/client/v3/keys/signatures/upload', [$this->user => $signatures]);

            if (!empty($result['failures']))
                throw new Exception('Matrix refused device signatures: '.json_encode($result['failures']));

            $done[] = 'signed device(s) '.join(', ', array_keys($signatures));
        }

        return $done;
    }

    private function uploadIdentity(array $seeds): void
    {
        $key = fn (string $usage, string $seed) => [
            'user_id'   => $this->user,
            'usage'     => [$usage],
            'keys'      => ['ed25519:'.self::PublicKey($seed) => self::PublicKey($seed)],
        ];

        $masterId = 'ed25519:'.self::PublicKey($seeds['master']);
        $master   = $key('master', $seeds['master']);
        $ssk      = $key('self_signing', $seeds['self_signing']);
        $usk      = $key('user_signing', $seeds['user_signing']);

        $ssk['signatures'] = [$this->user => [$masterId => self::Sign($seeds['master'], $ssk)]];
        $usk['signatures'] = [$this->user => [$masterId => self::Sign($seeds['master'], $usk)]];

        $this->client->requestWithPassword('POST', '/_matrix/client/v3/keys/device_signing/upload', [
            'master_key'        => $master,
            'self_signing_key'  => $ssk,
            'user_signing_key'  => $usk,
        ]);
    }

    //------------------------------------------------------------------------------------------------------------------

    /** Unpadded base64 Ed25519 public key of a base64 seed */
    private static function PublicKey(string $seed): string
    {
        return rtrim(base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair(base64_decode($seed)))), '=');
    }

    /** Unpadded base64 signature of an object's canonical JSON (without its signatures and unsigned) */
    private static function Sign(string $seed, array $object): string
    {
        unset($object['signatures'], $object['unsigned']);

        $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(base64_decode($seed)));

        return rtrim(base64_encode(sodium_crypto_sign_detached(self::CanonicalJson($object), $secret)), '=');
    }

    /** Matrix canonical JSON: keys sorted, no whitespace, UTF-8 unescaped */
    public static function CanonicalJson($value): string
    {
        $sort = function ($v) use (&$sort) {
            if (!is_array($v))
                return $v;

            if (array_is_list($v))
                return array_map($sort, $v);

            ksort($v, SORT_STRING);
            return array_map($sort, $v);
        };

        $value = $sort($value);

        // An empty object must stay {}, not []
        $json = json_encode($value === [] ? new \stdClass() : $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json;
    }
}
