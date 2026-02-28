<?php
namespace App\Models;

use App\Config\Database;
use PDO;

class User
{
    public static function findByReferralCode(string $code): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE referral_code = :code LIMIT 1');
        $stmt->execute([':code' => $code]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function generateReferralCode(): string
    {
        do {
            $candidate = strtoupper(bin2hex(random_bytes(4)));
        } while (self::findByReferralCode($candidate));
        return $candidate;
    }

    public static function create(array $data): int
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, role, active, referral_code, referred_by) VALUES (:name, :email, :password_hash, :role, 1, :referral_code, :referred_by)');
        $stmt->execute([
            ':name' => $data['name'],
            ':email' => $data['email'],
            ':password_hash' => password_hash($data['password'], PASSWORD_BCRYPT),
            ':role' => $data['role'] ?? 'cliente',
            ':referral_code' => $data['referral_code'] ?? self::generateReferralCode(),
            ':referred_by' => $data['referred_by'] ?? null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function findByEmail(string $email): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function listAll(): array
    {
        $stmt = Database::pdo()->query('SELECT id, name, email, role, active, referral_code, referred_by, created_at FROM users ORDER BY id DESC');
        return $stmt->fetchAll();
    }

    public static function adminEmails(): array
    {
        $stmt = Database::pdo()->query("SELECT email FROM users WHERE role='admin' AND active=1");
        return array_column($stmt->fetchAll(), 'email');
    }

    public static function updatePassword(int $id, string $password): void
    {
        $stmt = Database::pdo()->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $stmt->execute([':hash' => password_hash($password, PASSWORD_BCRYPT), ':id' => $id]);
    }


    public static function deleteNonAdmin(int $id): bool
    {
        $stmt = Database::pdo()->prepare("DELETE FROM users WHERE id = :id AND role != 'admin'");
        return $stmt->execute([':id' => $id]);
    }

    public static function firstAdminId(): ?int
    {
        $stmt = Database::pdo()->query("SELECT id FROM users WHERE role='admin' ORDER BY id ASC LIMIT 1");
        $id = $stmt->fetchColumn();
        return $id ? (int) $id : null;
    }

    public static function setActive(int $id, bool $active): void
    {
        $stmt = Database::pdo()->prepare('UPDATE users SET active = :active WHERE id = :id');
        $stmt->execute([':active' => $active ? 1 : 0, ':id' => $id]);
    }


    public static function dependencySummary(int $id): array
    {
        $pdo = Database::pdo();
        $tables = [
            'orders' => 'SELECT COUNT(*) FROM orders WHERE user_id = :id',
            'invoices' => 'SELECT COUNT(*) FROM invoices WHERE user_id = :id',
            'service_requests' => 'SELECT COUNT(*) FROM service_requests WHERE user_id = :id',
            'feedback' => 'SELECT COUNT(*) FROM feedback WHERE user_id = :id',
            'affiliate_payouts' => 'SELECT COUNT(*) FROM affiliate_payouts WHERE user_id = :id',
        ];
        $out = [];
        foreach ($tables as $name => $sql) {
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':id' => $id]);
                $out[$name] = (int) $stmt->fetchColumn();
            } catch (\Throwable $e) {
                $out[$name] = 0;
            }
        }
        return $out;
    }

    public static function anonymize(int $id): void
    {
        $anonEmail = sprintf('anon+%d@redacted.local', $id);
        $stmt = Database::pdo()->prepare('UPDATE users SET name = :name, email = :email, referred_by = NULL, referral_code = CONCAT("ANON", id), active = 0 WHERE id = :id');
        $stmt->execute([
            ':name' => 'Utilizador anonimizado #' . $id,
            ':email' => $anonEmail,
            ':id' => $id,
        ]);
    }
}
