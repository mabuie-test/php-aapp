<?php
namespace App\Models;

use App\Config\Database;

class AffiliateCommission
{
    public static function create(array $data): int
    {
        $stmt = Database::pdo()->prepare('INSERT INTO affiliate_commissions (order_id, referrer_code, beneficiary_email, amount, status) VALUES (:order_id, :referrer_code, :beneficiary_email, :amount, :status)');
        $stmt->execute([
            ':order_id' => $data['order_id'],
            ':referrer_code' => $data['referrer_code'],
            ':beneficiary_email' => $data['beneficiary_email'],
            ':amount' => $data['amount'],
            ':status' => $data['status'] ?? 'PENDENTE',
        ]);
        return (int) Database::pdo()->lastInsertId();
    }

    public static function listForAdmin(): array
    {
        $stmt = Database::pdo()->query('SELECT * FROM affiliate_commissions ORDER BY id DESC');
        return $stmt->fetchAll();
    }

    public static function listForCode(string $code): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM affiliate_commissions WHERE referrer_code = :code ORDER BY id DESC');
        $stmt->execute([':code' => $code]);
        return $stmt->fetchAll();
    }

    public static function totalsForCode(string $code): array
    {
        $sql = "SELECT status, COALESCE(SUM(amount),0) AS total, COUNT(*) as qty FROM affiliate_commissions WHERE referrer_code = :code GROUP BY status";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':code' => $code]);
        $rows = $stmt->fetchAll();
        $totals = ['PENDENTE' => 0, 'APROVADA' => 0, 'PAGO' => 0];
        foreach ($rows as $row) {
            $totals[$row['status']] = (float) $row['total'];
        }
        return [
            'pending' => $totals['PENDENTE'] ?? 0,
            'approved' => $totals['APROVADA'] ?? 0,
            'paid' => $totals['PAGO'] ?? 0,
        ];
    }

    public static function markApprovedForOrder(int $orderId): void
    {
        $stmt = Database::pdo()->prepare("UPDATE affiliate_commissions SET status='APROVADA' WHERE order_id = :oid");
        $stmt->execute([':oid' => $orderId]);
    }

    public static function totalAvailableForCode(string $code): float
    {
        $stmt = Database::pdo()->prepare("SELECT COALESCE(SUM(amount),0) FROM affiliate_commissions WHERE referrer_code = :code AND status='APROVADA'");
        $stmt->execute([':code' => $code]);
        return (float) $stmt->fetchColumn();
    }

    public static function allocateToPayout(string $code, int $payoutId): void
    {
        $stmt = Database::pdo()->prepare("UPDATE affiliate_commissions SET status='PAGO', payout_id = :pid WHERE referrer_code = :code AND status='APROVADA'");
        $stmt->execute([':pid' => $payoutId, ':code' => $code]);
    }
}
