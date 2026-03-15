<?php
namespace App\Models;

use App\Config\Database;
use PDO;

class ServiceRequest
{
    public static function create(array $data): int
    {
        $db = Database::pdo();
        $stmt = $db->prepare('INSERT INTO service_requests (user_id, categoria, contact_name, contact_email, contact_phone, norma_preferida, software_preferido, detalhes, attachment) VALUES (:user_id, :categoria, :contact_name, :contact_email, :contact_phone, :norma, :software, :detalhes, :attachment)');
        $stmt->execute([
            ':user_id' => $data['user_id'] ?? null,
            ':categoria' => $data['categoria'],
            ':contact_name' => $data['contact_name'],
            ':contact_email' => $data['contact_email'],
            ':contact_phone' => $data['contact_phone'] ?? null,
            ':norma' => $data['norma_preferida'] ?? null,
            ':software' => $data['software_preferido'] ?? null,
            ':detalhes' => $data['detalhes'] ?? null,
            ':attachment' => $data['attachment'] ?? null,
        ]);
        return (int) $db->lastInsertId();
    }

    public static function listAll(): array
    {
        $db = Database::pdo();
        $stmt = $db->query('SELECT sr.*, u.email AS user_email FROM service_requests sr LEFT JOIN users u ON sr.user_id = u.id ORDER BY sr.created_at DESC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function listForUser(int $userId): array
    {
        $db = Database::pdo();
        $stmt = $db->prepare('SELECT * FROM service_requests WHERE user_id = :user ORDER BY created_at DESC');
        $stmt->execute([':user' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function find(int $id): ?array
    {
        $db = Database::pdo();
        $stmt = $db->prepare('SELECT sr.*, u.email AS user_email FROM service_requests sr LEFT JOIN users u ON sr.user_id = u.id WHERE sr.id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function updateStatus(int $id, string $status): void
    {
        $db = Database::pdo();
        $stmt = $db->prepare('UPDATE service_requests SET status = :status WHERE id = :id');
        $stmt->execute([':status' => $status, ':id' => $id]);
    }
}
