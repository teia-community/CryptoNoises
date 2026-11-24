<?php

namespace CryptoNoises\Models;

use CryptoNoises\Core\Log;
use CryptoCore\Vss\Vss;
use CryptoCore\Vss\VssModel;

class Users extends VssModel
{
    const kTYPE_User                = 'user';

    const kSTATUS_Active            = 'active';
    const kSTATUS_Frozen            = 'frozen';

    private static array $FieldLengths = [
        'username'              => 255,
        'first_name'            => 255,
        'last_name'             => 255,
        'language_code'         => 32,
    ];

    public static function model($className=__CLASS__): self
    {
        return parent::model($className);
    }

    public function tableName(): string
    {
        return 'users';
    }

    public function getUserItemById(int $userId): ?array
    {
        $sql = "
            SELECT *
            FROM {$this->tableName()}
            WHERE user_id = :user_id
        ";
        return Vss::db()->queryRow($sql, ['user_id'=>$userId]);
    }

    public function getUserIdByIds($platform, $platformUserId, $platformChannelId)
    {
        $sql = "
            SELECT user_id
            FROM {$this->tableName()}
            WHERE platform            = :platform
              AND platform_user_id    = :platform_user_id
              AND platform_channel_id = :platform_channel_id
        ";
        return Vss::db()->queryScalar($sql, [
            'platform'              => $platform,
            'platform_user_id'      => $platformUserId,
            'platform_channel_id'   => $platformChannelId,
        ]);
    }

    public function createByIds(string $type, string $platform, int $platformUserId, int $platformChannelId, ?int $platformGuildId = null)
    {
        Vss::db()->insert($this->tableName(), [
            'type'                  => $type,
            'platform'              => $platform,
            'platform_user_id'      => $platformUserId,
            'platform_channel_id'   => $platformChannelId,
            'platform_guild_id'     => $platformGuildId,
        ], 'IGNORE');

        return $this->getUserIdByIds($platform, $platformUserId, $platformChannelId);
    }

    public function update($userId, $data)
    {
        foreach ($data as $key => $value)
        {
            if (!isset(self::$FieldLengths[$key]))
                continue;

            $length = self::$FieldLengths[$key];

            // A null is a legitimate value here - Telegram leaves out username and last_name for users who
            // have not set them - and on PHP 8.1 passing it to mb_strlen() is a deprecation, which Fat-Free
            // turns into a fatal. A null is never too long, so cast and let it through to be stored as NULL.
            if (mb_strlen((string) $value) > $length)
            {
                Log::Warning(get_class($this), __FUNCTION__, "Value for `$key` is too long (more than $length). Skipped", $value);
                unset($data[$key]);
            }
        }

        if (empty($data))
            return false;

        return Vss::db()->update($this->tableName(), $data, ['user_id'=>$userId]);
    }

    //------------------------------------------------------------------------------------------------------------------

    public function getActiveUsersByWalletAdid($walletAdid): array
    {
        $sql = "
            SELECT DISTINCT u.platform, u.user_id, u.platform_user_id
            FROM {$this->tableName()} AS u
            JOIN ".UsersRefWallets::model()->tableName()." AS ref
                ON u.user_id = ref.user_id
                AND ref.wallet_adid = :wallet_adid
            WHERE u.status = :status_active
        ";

        return Vss::db()->query($sql, [
            'wallet_adid'   => $walletAdid,
            'status_active' => self::kSTATUS_Active,
        ]);
    }

    //------------------------------------------------------------------------------------------------------------------
}
