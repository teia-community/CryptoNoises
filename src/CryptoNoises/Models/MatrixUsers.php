<?php

namespace CryptoNoises\Models;

use CryptoCore\Vss\Vss;
use CryptoCore\Vss\VssModel;

/**
 * Matrix users and the room the bot talks to each of them in. Their ids are strings (@user:server, !room:server),
 * while users.platform_user_id / platform_channel_id are numbers, so a Matrix user's `users` row holds matrix_ref,
 * the id of its row here, in both.
 */
class MatrixUsers extends VssModel
{
    public static function model($className = __CLASS__): self
    {
        return parent::model($className);
    }

    public function tableName(): string
    {
        return 'matrix_users';
    }

    /** The ref of a Matrix user, created on first contact; the room is updated when given */
    public function getOrCreateRef(string $matrixUserId, ?string $roomId = null): int
    {
        Vss::db()->query("INSERT INTO {$this->tableName()} (matrix_user_id, room_id) VALUES (:u, :r)
            ON DUPLICATE KEY UPDATE room_id = COALESCE(VALUES(room_id), room_id)", ['u' => $matrixUserId, 'r' => $roomId]);

        return (int) Vss::db()->queryScalar("SELECT matrix_ref FROM {$this->tableName()} WHERE matrix_user_id = :u", ['u' => $matrixUserId]);
    }

    public function getByRef(int $matrixRef): ?array
    {
        $row = Vss::db()->queryRow("SELECT * FROM {$this->tableName()} WHERE matrix_ref = :r", ['r' => $matrixRef]);

        return $row ?: null;
    }

    public function getRefByRoom(string $roomId): ?int
    {
        $ref = Vss::db()->queryScalar("SELECT matrix_ref FROM {$this->tableName()} WHERE room_id = :r", ['r' => $roomId]);

        return $ref ? (int) $ref : null;
    }
}
