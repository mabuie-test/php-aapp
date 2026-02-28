<?php
namespace App\Models;

use App\Config\Database;
use PDOException;

class AffiliatePayout
{
    private static bool $schemaChecked = false;

    private static function ensureSchema(): void
    {
        if (self::$schemaChecked) {
            return;
        }
        try {
            $stmt = Database::pdo()->query("SHOW COLUMNS FROM affiliate_payouts LIKE 'mpesa_destino'");
            $exists = $stmt->fetch();
            if (!$exists) {
                Database::pdo()->exec("ALTER TABLE affiliate_payouts ADD COLUMN mpesa_destino VARCHAR(50) NULL AFTER metodo");
            }
        } catch (PDOException $e) {
            // Falha silenciosa para ambientes com permissões limitadas
        }
        self::$schemaChecked = true;
    }

    public static function create(int $userId, float $valor, string $status = 'PENDENTE', string $metodo = 'mpesa', ?string $notes = null, ?string $mpesa = null): int
    {
        self::ensureSchema();
        $stmt = Database::pdo()->prepare(
            'INSERT INTO affiliate_payouts (user_id, valor, metodo, status, notes, mpesa_destino) VALUES (:user_id, :valor, :metodo, :status, :notes, :mpesa)'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':valor' => $valor,
            ':metodo' => $metodo,
            ':status' => $status,
            ':notes' => $notes,
            ':mpesa' => $mpesa,
        ]);
        return (int) Database::pdo()->lastInsertId();
    }

    public static function listForUser(int $userId): array
    {
        self::ensureSchema();
        $stmt = Database::pdo()->prepare('SELECT * FROM affiliate_payouts WHERE user_id = :uid ORDER BY id DESC');
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll();
    }

    public static function listAll(): array
    {
        self::ensureSchema();
        $sql = 'SELECT p.*, u.email, u.name, u.referral_code FROM affiliate_payouts p LEFT JOIN users u ON u.id = p.user_id ORDER BY p.id DESC';
        return Database::pdo()->query($sql)->fetchAll();
    }

    public static function outstandingForUser(int $userId): float
    {
        self::ensureSchema();
        $stmt = Database::pdo()->prepare("SELECT COALESCE(SUM(valor),0) FROM affiliate_payouts WHERE user_id = :uid AND status IN ('SOLICITADO','APROVADO')");
        $stmt->execute([':uid' => $userId]);
        return (float) $stmt->fetchColumn();
    }

    public static function updateStatus(int $payoutId, string $status, int $adminId, ?string $notes = null): void
    {
        self::ensureSchema();
        $stmt = Database::pdo()->prepare('UPDATE affiliate_payouts SET status = :status, notes = :notes, processed_by = :admin,processed_at = NOW() WHERE id = :id');
        $stmt->execute([':status' => $status, ':notes' => $notes, ':admin' => $adminId, ':id' => $payoutId]);
    }

    public static function find(int $payoutId): ?array
    {
        self::ensureSchema();
        $stmt = Database::pdo()->prepare('SELECT p.*, u.email, u.name, u.referral_code FROM affiliate_payouts p LEFT JOIN users u ON u.id = p.user_id WHERE p.id = :id LIMIT 1');
        $stmt->execute([':id' => $payoutId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
