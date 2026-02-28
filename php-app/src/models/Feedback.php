<?php
namespace App\Models;

use App\Config\Database;

class Feedback
{
    public static function create(array $data): int
    {
        $stmt = Database::pdo()->prepare('INSERT INTO feedbacks (order_id, user_id, rating, grade, comment) VALUES (:order_id, :user_id, :rating, :grade, :comment)');
        $stmt->execute([
            ':order_id' => $data['order_id'],
            ':user_id' => $data['user_id'],
            ':rating' => $data['rating'],
            ':grade' => $data['grade'],
            ':comment' => $data['comment'],
        ]);
        return (int) Database::pdo()->lastInsertId();
    }

    public static function reply(int $id, string $reply): void
    {
        $stmt = Database::pdo()->prepare('UPDATE feedbacks SET admin_reply = :reply WHERE id = :id');
        $stmt->execute([':reply' => $reply, ':id' => $id]);
    }

    public static function listForOrder(int $orderId): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM feedbacks WHERE order_id = :order ORDER BY id DESC');
        $stmt->execute([':order' => $orderId]);
        return $stmt->fetchAll();
    }

    public static function listAll(): array
    {
        $stmt = Database::pdo()->query('SELECT * FROM feedbacks ORDER BY id DESC');
        return $stmt->fetchAll();
    }
}
