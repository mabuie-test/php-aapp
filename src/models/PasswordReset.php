<?php
namespace App\Models;

use App\Config\Database;
use PDO;

class PasswordReset
{
    public static function create(string $email, ?string $token, string $code, string $expiresAt): void
    {
        $stmt = Database::pdo()->prepare('INSERT INTO password_resets (email, token, code, expires_at) VALUES (:email, :token, :code, :expires_at)');
        $stmt->execute([
            ':email' => $email,
            ':token' => $token,
            ':code' => $code,
            ':expires_at' => $expiresAt,
        ]);
    }

    public static function findValid(string $email, string $code): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM password_resets WHERE email = :email AND code = :code AND used = 0 AND expires_at > NOW() ORDER BY id DESC LIMIT 1');
        $stmt->execute([
            ':email' => $email,
            ':code' => $code,
        ]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function markUsed(int $id): void
    {
        $stmt = Database::pdo()->prepare('UPDATE password_resets SET used = 1 WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }
}
