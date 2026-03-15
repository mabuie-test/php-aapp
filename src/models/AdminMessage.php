<?php
namespace App\Models;

use App\Config\Database;
use PDO;

class AdminMessage
{
    public static function create(int $userId, ?int $orderId, string $message, ?string $attachment = null): int
    {
        $stmt = Database::pdo()->prepare('INSERT INTO admin_messages (user_id, order_id, message, attachment) VALUES (:user_id, :order_id, :message, :attachment)');
        $stmt->execute([
            ':user_id' => $userId,
            ':order_id' => $orderId,
            ':message' => $message,
            ':attachment' => $attachment,
        ]);
        return (int) Database::pdo()->lastInsertId();
    }

    public static function listRecent(?int $orderId = null, int $limit = 50): array
    {
        $pdo = Database::pdo();
        if ($orderId) {
            $stmt = $pdo->prepare('SELECT am.*, u.name AS author FROM admin_messages am JOIN users u ON u.id = am.user_id WHERE am.order_id = :order_id OR am.order_id IS NULL ORDER BY am.id DESC LIMIT :limit');
            $stmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
        } else {
            $stmt = $pdo->prepare('SELECT am.*, u.name AS author FROM admin_messages am JOIN users u ON u.id = am.user_id ORDER BY am.id DESC LIMIT :limit');
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
