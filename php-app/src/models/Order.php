<?php
namespace App\Models;

use App\Config\Database;
use PDO;

class Order
{
    /**
     * Cria uma nova encomenda
     *
     * @param array $data
     *    - user_id, tipo, area, nivel, paginas, norma, complexidade, urgencia
     *    - descricao (string), estado, prazo_entrega, referred_by_code
     *    - materiais_info, materiais_percentual, materiais_uploads (string JSON)
     *    - invoice_id, final_file (opcional)
     * @return int ID da encomenda criada
     */
    public static function create(array $data): int
    {
        $pdo = Database::pdo();
        $sql = 'INSERT INTO orders (
            user_id, tipo, area, nivel, paginas, norma, complexidade, urgencia,
            descricao, estado, prazo_entrega, referred_by_code, materiais_info,
            materiais_percentual, materiais_uploads, invoice_id, final_file
        ) VALUES (
            :user_id, :tipo, :area, :nivel, :paginas, :norma, :complexidade, :urgencia,
            :descricao, :estado, :prazo_entrega, :referred_by_code, :materiais_info,
            :materiais_percentual, :materiais_uploads, :invoice_id, :final_file
        )';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $data['user_id'],
            ':tipo' => $data['tipo'] ?? '',
            ':area' => $data['area'] ?? '',
            ':nivel' => $data['nivel'] ?? '',
            ':paginas' => $data['paginas'] ?? 1,
            ':norma' => $data['norma'] ?? null,
            ':complexidade' => $data['complexidade'] ?? 'basica',
            ':urgencia' => $data['urgencia'] ?? 'normal',
            ':descricao' => $data['descricao'] ?? '',
            ':estado' => $data['estado'] ?? 'PENDENTE_PAGAMENTO',
            ':prazo_entrega' => $data['prazo_entrega'] ?? null,
            ':referred_by_code' => $data['referred_by_code'] ?? null,
            ':materiais_info' => $data['materiais_info'] ?? null,
            ':materiais_percentual' => $data['materiais_percentual'] ?? null,
            ':materiais_uploads' => $data['materiais_uploads'] ?? null,
            ':invoice_id' => $data['invoice_id'] ?? null,
            ':final_file' => $data['final_file'] ?? null
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Associa uma fatura a uma encomenda
     */
    public static function attachInvoice(int $orderId, int $invoiceId): void
    {
        $stmt = Database::pdo()->prepare('UPDATE orders SET invoice_id = :invoice_id WHERE id = :id');
        $stmt->execute([':invoice_id' => $invoiceId, ':id' => $orderId]);
    }

    /**
     * Atualiza o estado de uma encomenda
     */
    public static function updateEstado(int $orderId, string $estado): void
    {
        $stmt = Database::pdo()->prepare('UPDATE orders SET estado = :estado WHERE id = :id');
        $stmt->execute([':estado' => $estado, ':id' => $orderId]);
    }

    /**
     * Salva o ficheiro final e define estado como CONCLUIDA
     */
    public static function saveFinalFile(int $orderId, string $file): void
    {
        self::saveFinalFiles($orderId, [$file]);
    }

    public static function saveFinalFiles(int $orderId, array $files): void
    {
        $existing = self::findById($orderId);
        $current = [];
        if (!empty($existing['final_file']) && is_string($existing['final_file'])) {
            $decoded = json_decode($existing['final_file'], true);
            if (is_array($decoded)) {
                $current = $decoded;
            } elseif (trim($existing['final_file']) !== '') {
                $current = [$existing['final_file']];
            }
        }
        $merged = array_values(array_unique(array_filter(array_merge($current, $files))));
        $value = json_encode($merged, JSON_UNESCAPED_UNICODE);
        $stmt = Database::pdo()->prepare('UPDATE orders SET final_file = :file, estado = "CONCLUIDA" WHERE id = :id');
        $stmt->execute([':file' => $value, ':id' => $orderId]);
    }

    /**
     * Lista encomendas de um utilizador (com dados da fatura)
     */
    public static function listForUser(int $userId): array
    {
        $sql = 'SELECT o.*, i.numero as invoice_numero, i.estado as invoice_estado, i.valor_total, i.id as invoice_id 
                FROM orders o 
                LEFT JOIN invoices i ON i.id = o.invoice_id 
                WHERE o.user_id = :uid 
                ORDER BY o.id DESC';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Encontra uma encomenda com os dados da fatura associada
     */
    public static function findWithInvoice(int $orderId): ?array
    {
        $sql = 'SELECT o.*, i.numero as invoice_numero, i.estado as invoice_estado, i.valor_total, 
                       i.id as invoice_id, i.vencimento, i.comprovativo 
                FROM orders o 
                LEFT JOIN invoices i ON i.id = o.invoice_id 
                WHERE o.id = :id 
                LIMIT 1';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':id' => $orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Lista todas as encomendas com dados do utilizador e da fatura (para admin)
     */
    public static function listAllWithInvoices(): array
    {
        $sql = 'SELECT o.*, u.name as user_name, u.email as user_email, u.referral_code, u.referred_by, 
                       i.numero as invoice_numero, i.estado as invoice_estado, i.valor_total, 
                       i.id as invoice_id, i.comprovativo, i.vencimento 
                FROM orders o 
                LEFT JOIN invoices i ON i.id = o.invoice_id 
                LEFT JOIN users u ON u.id = o.user_id 
                ORDER BY o.id DESC';
        $stmt = Database::pdo()->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Encontra uma encomenda com dados do utilizador (e fatura, se houver)
     */
    public static function findWithUser(int $orderId): ?array
    {
        $sql = 'SELECT o.*, u.name as user_name, u.email as user_email, u.referred_by, u.referral_code, 
                       i.id as invoice_id, i.numero as invoice_numero, i.estado as invoice_estado, i.valor_total 
                FROM orders o 
                LEFT JOIN users u ON u.id = o.user_id 
                LEFT JOIN invoices i ON i.id = o.invoice_id 
                WHERE o.id = :id 
                LIMIT 1';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':id' => $orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Encontra uma encomenda apenas pelo ID
     */
    public static function findById(int $orderId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Lista encomendas concluídas que possuem ficheiro final (para um utilizador)
     */
    public static function listDeliveriesForUser(int $userId): array
    {
        $sql = 'SELECT o.*, i.numero as invoice_numero, i.estado as invoice_estado 
                FROM orders o 
                LEFT JOIN invoices i ON i.id = o.invoice_id 
                WHERE o.user_id = :uid AND o.final_file IS NOT NULL 
                ORDER BY o.id DESC';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Atualiza os materiais de uma encomenda (recebe array, codifica para JSON)
     */
    public static function updateMaterials(int $orderId, array $materials): void
    {
        $jsonMaterials = json_encode($materials);
        $stmt = Database::pdo()->prepare('UPDATE orders SET materiais_uploads = :materiais WHERE id = :id');
        $stmt->execute([':materiais' => $jsonMaterials, ':id' => $orderId]);
    }

    /**
     * Remove uma encomenda (cuidado: apaga em cascata? Apenas a ordem, faturas ficam órfãs)
     */
    public static function delete(int $orderId): bool
    {
        $stmt = Database::pdo()->prepare('DELETE FROM orders WHERE id = :id');
        return $stmt->execute([':id' => $orderId]);
    }

    /**
     * Conta encomendas por estado
     */
    public static function countByEstado(string $estado): int
    {
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM orders WHERE estado = :estado');
        $stmt->execute([':estado' => $estado]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Lista encomendas por estado (com limite)
     */
    public static function listByEstado(string $estado, int $limit = 100): array
    {
        $sql = 'SELECT o.*, u.name as user_name, u.email as user_email 
                FROM orders o 
                LEFT JOIN users u ON u.id = o.user_id 
                WHERE o.estado = :estado 
                ORDER BY o.created_at DESC 
                LIMIT :limit';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->bindValue(':estado', $estado, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Atualiza o prazo de entrega de uma encomenda
     */
    public static function updatePrazo(int $orderId, string $prazo): void
    {
        $stmt = Database::pdo()->prepare('UPDATE orders SET prazo_entrega = :prazo WHERE id = :id');
        $stmt->execute([':prazo' => $prazo, ':id' => $orderId]);
    }

    /**
     * Verifica se uma encomenda pertence a um utilizador
     */
    public static function belongsToUser(int $orderId, int $userId): bool
    {
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM orders WHERE id = :order_id AND user_id = :user_id');
        $stmt->execute([':order_id' => $orderId, ':user_id' => $userId]);
        return (int) $stmt->fetchColumn() > 0;
    }
}