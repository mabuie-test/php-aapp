<?php
namespace App\Models;

use App\Config\Database;

class Audit
{
    public static function record(?int $userId, string $action, array $meta): void
    {
        $stmt = Database::pdo()->prepare('INSERT INTO audits (user_id, action, meta) VALUES (:user_id, :action, :meta)');
        $stmt->execute([
            ':user_id' => $userId,
            ':action' => $action,
            ':meta' => json_encode($meta)
        ]);
    }

    public static function listRecent(int $limit = 25): array
    {
        $stmt = Database::pdo()->prepare('SELECT a.*, u.email FROM audits a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT :lim');
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }


    public static function affiliateClickStats(string $code): array
    {
        $pdo = Database::pdo();

        $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM audits WHERE action = 'affiliate:click' AND JSON_UNQUOTE(JSON_EXTRACT(meta, '$.code')) = :code");
        $totalStmt->execute([':code' => $code]);
        $total = (int) $totalStmt->fetchColumn();

        $uniqStmt = $pdo->prepare("SELECT COUNT(DISTINCT JSON_UNQUOTE(JSON_EXTRACT(meta, '$.visitor'))) FROM audits WHERE action = 'affiliate:click' AND JSON_UNQUOTE(JSON_EXTRACT(meta, '$.code')) = :code");
        $uniqStmt->execute([':code' => $code]);
        $unique = (int) $uniqStmt->fetchColumn();

        $todayStmt = $pdo->prepare("SELECT COUNT(*) FROM audits WHERE action = 'affiliate:click' AND JSON_UNQUOTE(JSON_EXTRACT(meta, '$.code')) = :code AND DATE(created_at) = CURDATE()");
        $todayStmt->execute([':code' => $code]);
        $today = (int) $todayStmt->fetchColumn();

        return [
            'total' => $total,
            'unique' => $unique,
            'today' => $today,
        ];
    }

    public static function listForUser(int $userId, int $limit = 20): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM audits WHERE user_id = :user_id ORDER BY id DESC LIMIT :lim');
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
