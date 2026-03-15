<?php
namespace App\Models;

use App\Config\Database;
use PDO;
use PDOException;

class Invoice
{
    /**
     * Cria uma nova fatura
     *
     * @param array $data
     *    - order_id, user_id, numero, valor_total
     *    - detalhes: array (será codificado como JSON)
     *    - estado, vencimento, comprovativo (opcionais)
     * @return int ID da fatura criada
     * @throws PDOException
     */
    public static function create(array $data): int
    {
        $pdo = Database::pdo();
        $sql = 'INSERT INTO invoices (
            order_id, user_id, numero, valor_total, detalhes, estado, vencimento, comprovativo
        ) VALUES (
            :order_id, :user_id, :numero, :valor_total, :detalhes, :estado, :vencimento, :comprovativo
        )';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':order_id' => $data['order_id'],
            ':user_id' => $data['user_id'],
            ':numero' => $data['numero'],
            ':valor_total' => $data['valor_total'],
            ':detalhes' => json_encode($data['detalhes'] ?? []),   // array → JSON
            ':estado' => $data['estado'] ?? 'EMITIDA',
            ':vencimento' => $data['vencimento'] ?? date('Y-m-d H:i:s', strtotime('+24 hours')),
            ':comprovativo' => $data['comprovativo'] ?? null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Atualiza o estado de uma fatura
     */
    public static function updateEstado(int $id, string $estado): void
    {
        $stmt = Database::pdo()->prepare('UPDATE invoices SET estado = :estado WHERE id = :id');
        $stmt->execute([':estado' => $estado, ':id' => $id]);
    }

    /**
     * Salva o comprovativo de pagamento e altera estado para PENDENTE_VALIDACAO
     */
    public static function saveComprovativo(int $id, string $path): void
    {
        $stmt = Database::pdo()->prepare('UPDATE invoices SET comprovativo = :path, estado = "PENDENTE_VALIDACAO" WHERE id = :id');
        $stmt->execute([':path' => $path, ':id' => $id]);
    }

    /**
     * Encontra uma fatura por ID
     * @return array|null Array associativo ou null
     */
    public static function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM invoices WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Encontra uma fatura pelo ID da encomenda
     */
    public static function findByOrderId(int $orderId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM invoices WHERE order_id = :order_id LIMIT 1');
        $stmt->execute([':order_id' => $orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Lista faturas de um utilizador
     */
    public static function listForUser(int $userId): array
    {
        $sql = 'SELECT i.*, o.tipo, o.area, o.estado as order_estado 
                FROM invoices i 
                LEFT JOIN orders o ON o.id = i.order_id 
                WHERE i.user_id = :user_id 
                ORDER BY i.id DESC';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lista todas as faturas (para admin)
     */
    public static function listAll(): array
    {
        $sql = 'SELECT i.*, u.name as user_name, u.email as user_email, o.tipo, o.area 
                FROM invoices i 
                LEFT JOIN users u ON i.user_id = u.id 
                LEFT JOIN orders o ON i.order_id = o.id 
                ORDER BY i.id DESC';
        $stmt = Database::pdo()->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Atualiza campos permitidos de uma fatura
     */
    public static function update(int $id, array $data): void
    {
        $allowedFields = ['estado', 'comprovativo', 'vencimento', 'valor_total', 'numero'];
        $updates = [];
        $params = [':id' => $id];
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }
        if (!empty($updates)) {
            $sql = 'UPDATE invoices SET ' . implode(', ', $updates) . ' WHERE id = :id';
            $stmt = Database::pdo()->prepare($sql);
            $stmt->execute($params);
        }
    }

    /**
     * Atualiza apenas o campo 'detalhes' (recebe array, codifica para JSON)
     */
    public static function updateDetalhes(int $id, array $detalhes): void
    {
        $stmt = Database::pdo()->prepare('UPDATE invoices SET detalhes = :detalhes WHERE id = :id');
        $stmt->execute([
            ':detalhes' => json_encode($detalhes),
            ':id' => $id
        ]);
    }

    /**
     * Conta faturas por estado
     */
    public static function countByEstado(string $estado): int
    {
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM invoices WHERE estado = :estado');
        $stmt->execute([':estado' => $estado]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Lista faturas por estado (com limite)
     */
    public static function listByEstado(string $estado, int $limit = 100): array
    {
        $sql = 'SELECT i.*, u.name as user_name, u.email as user_email, o.tipo 
                FROM invoices i 
                LEFT JOIN users u ON u.id = i.user_id 
                LEFT JOIN orders o ON o.id = i.order_id 
                WHERE i.estado = :estado 
                ORDER BY i.created_at DESC 
                LIMIT :limit';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->bindValue(':estado', $estado, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtém o total de receitas por período (mensal ou diário)
     */
    public static function getRevenueByPeriod(string $period = 'month'): array
    {
        $format = $period === 'month' ? '%Y-%m' : '%Y-%m-%d';
        $sql = "SELECT DATE_FORMAT(created_at, '$format') as periodo, 
                       SUM(valor_total) as total, 
                       COUNT(*) as quantidade 
                FROM invoices 
                WHERE estado = 'PAGA' 
                GROUP BY periodo 
                ORDER BY periodo DESC";
        $stmt = Database::pdo()->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Remove uma fatura e desassocia da encomenda
     */
    public static function delete(int $id): bool
    {
        $pdo = Database::pdo();
        $pdo->prepare('UPDATE orders SET invoice_id = NULL WHERE invoice_id = :id')
            ->execute([':id' => $id]);
        $stmt = $pdo->prepare('DELETE FROM invoices WHERE id = :id');
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Obtém o total de valor faturado (estado PAGA)
     */
    public static function getTotalRevenue(): float
    {
        $stmt = Database::pdo()->query("SELECT COALESCE(SUM(valor_total), 0) FROM invoices WHERE estado = 'PAGA'");
        return (float) $stmt->fetchColumn();
    }

    /**
     * Obtém o total pendente (não pago)
     */
    public static function getTotalPending(): float
    {
        $stmt = Database::pdo()->query("SELECT COALESCE(SUM(valor_total), 0) FROM invoices WHERE estado != 'PAGA'");
        return (float) $stmt->fetchColumn();
    }

    /**
     * Verifica se uma fatura pertence a um utilizador
     */
    public static function belongsToUser(int $invoiceId, int $userId): bool
    {
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM invoices WHERE id = :invoice_id AND user_id = :user_id');
        $stmt->execute([':invoice_id' => $invoiceId, ':user_id' => $userId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Marca uma fatura como vencida (se vencimento já passou e não está paga)
     */
    public static function markAsVencida(int $id): void
    {
        $stmt = Database::pdo()->prepare('UPDATE invoices SET estado = "VENCIDA" WHERE id = :id AND estado NOT IN ("PAGA", "VENCIDA") AND vencimento < NOW()');
        $stmt->execute([':id' => $id]);
    }

    /**
     * Marca todas as faturas vencidas
     * @return int Número de faturas atualizadas
     */
    public static function markAllVencidas(): int
    {
        $stmt = Database::pdo()->prepare('UPDATE invoices SET estado = "VENCIDA" WHERE estado NOT IN ("PAGA", "VENCIDA") AND vencimento < NOW()');
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Lista faturas próximas do vencimento (dentro de X dias)
     */
    public static function getProximoVencimento(int $days = 3): array
    {
        $sql = 'SELECT i.*, u.name as user_name, u.email as user_email, o.tipo 
                FROM invoices i 
                LEFT JOIN users u ON u.id = i.user_id 
                LEFT JOIN orders o ON o.id = i.order_id 
                WHERE i.estado NOT IN ("PAGA", "VENCIDA") 
                AND i.vencimento BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL :days DAY) 
                ORDER BY i.vencimento ASC';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}