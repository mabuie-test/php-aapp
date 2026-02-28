<?php
namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Response;
use App\Helpers\AuditHelper;
use App\Helpers\Mailer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Models\Feedback;
use App\Models\AffiliateCommission;
use App\Models\AffiliatePayout;
use App\Models\Audit;
use App\Models\AdminMessage;
use App\Config\Config;
use App\Config\Database;

class AdminController
{
    private static function requireAdmin(): array
    {
        $user = Auth::requireUser();
        if (!isset($user['role']) || $user['role'] !== 'admin') {
            Response::json(['message' => 'Acesso negado'], 403);
            exit;
        }
        return $user;
    }

    /**
     * Approve payment:
     *  - mark invoice as PAGA
     *  - put order into execution
     *  - create affiliate commission only if:
     *      * referred_by_code exists and maps to a real user
     *      * referrer is not the beneficiary (prevent self-referral)
     *      * there is no existing commission for this order
     */
    public static function approvePayment(): void
    {
        $admin = self::requireAdmin();
        $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
        $invoice = Invoice::findById($invoiceId);
        if (!$invoice) {
            Response::json(['message' => 'Fatura não encontrada'], 404);
            return;
        }

        // mark invoice paid
        Invoice::updateEstado($invoiceId, 'PAGA');

        $order = Order::findWithUser((int) $invoice['order_id']);
        if ($order) {
            Order::updateEstado((int) $order['id'], 'EM_EXECUCAO');

            // Determine referrer code robustly (prefer the explicit referred_by_code saved on the order)
            $refCode = $order['referred_by_code'] ?? $order['referred_by'] ?? $order['referral_code'] ?? null;

            // validate referrer and prevent self-referral / duplicate commission
            if (!empty($refCode)) {
                try {
                    $pdo = Database::pdo();

                    // find referrer user by referral_code
                    $stmt = $pdo->prepare('SELECT id, email, name FROM users WHERE referral_code = :code LIMIT 1');
                    $stmt->execute([':code' => $refCode]);
                    $refUser = $stmt->fetch();

                    // ensure referrer exists and is not the order owner
                    $orderUserId = (int) ($order['user_id'] ?? 0);
                    $refExists = $refUser && isset($refUser['id']) && (int)$refUser['id'] !== $orderUserId;

                    // ensure no existing commission already recorded for this order
                    $stmt2 = $pdo->prepare('SELECT id FROM affiliate_commissions WHERE order_id = :oid LIMIT 1');
                    $stmt2->execute([':oid' => (int)$order['id']]);
                    $existing = $stmt2->fetch();

                    if ($refExists && !$existing) {
                        $commission = round((float) $invoice['valor_total'] * 0.18, 2);

                        // create commission with status APROVADA (admin-approved on payment)
                        AffiliateCommission::create([
                            'order_id' => (int) $order['id'],
                            'referrer_code' => $refCode,
                            'beneficiary_email' => $order['user_email'],
                            'amount' => $commission,
                            'status' => 'APROVADA',
                        ]);

                        AuditHelper::log((int)$refUser['id'], 'affiliate:allocated', [
                            'order_id' => (int)$order['id'],
                            'referrer' => $refUser['email'] ?? $refUser['id'],
                            'amount' => $commission
                        ]);
                    }
                } catch (\Throwable $e) {
                    // non-fatal: log and continue
                    error_log('Affiliate allocation error: ' . $e->getMessage());
                }
            }

            Mailer::send($order['user_email'], 'Pagamento aprovado', 'Pagamento confirmado para a fatura ' . $invoice['numero'] . '. O seu trabalho segue para execução.');
            AuditHelper::log((int) $order['user_id'], 'invoice:aprovada', ['invoice_id' => $invoiceId, 'order_id' => $order['id']]);
        }

        AuditHelper::log($admin['id'], 'invoice:approve', ['invoice_id' => $invoiceId]);
        Response::json(['message' => 'Pagamento validado']);
    }

    /**
     * Reject payment: set invoice to PENDENTE and order to PENDENTE_PAGAMENTO when applicable.
     */
    public static function rejectPayment(): void
    {
        $admin = self::requireAdmin();
        $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($reason === '') {
            Response::json(['message' => 'Motivo da rejeição é obrigatório'], 422);
            return;
        }

        Invoice::updateEstado($invoiceId, 'REJEITADA');
        if (!empty($_POST['order_id'])) {
            Order::updateEstado((int) $_POST['order_id'], 'PENDENTE_PAGAMENTO');
        }
        $order = !empty($_POST['order_id']) ? Order::findWithUser((int) $_POST['order_id']) : null;
        if ($order) {
            Mailer::send($order['user_email'], 'Pagamento rejeitado', 'O comprovativo da fatura #' . $invoiceId . ' foi rejeitado. Motivo: ' . $reason . '. Envie um novo ficheiro ou contacte o suporte.');
            AuditHelper::log((int) $order['user_id'], 'invoice:rejeitada', ['invoice_id' => $invoiceId, 'order_id' => $order['id'], 'reason' => $reason, 'ts' => date('c')]);
        }
        AuditHelper::log($admin['id'], 'invoice:reject', ['invoice_id' => $invoiceId, 'reason' => $reason, 'ts' => date('c')]);
        Response::json(['message' => 'Pagamento rejeitado']);
    }

/**
 * Listar comissões de afiliados (para admin-affiliates.html)
 */
public static function commissions(): void
{
    self::requireAdmin();
    try {
        $pdo = Database::pdo();
        
        // Comissões com detalhes
        $sql = "SELECT 
                    ac.*,
                    u.name as referrer_name,
                    u.email as referrer_email,
                    o.id as order_id,
                    o.tipo as order_type,
                    ap.status as payout_status
                FROM affiliate_commissions ac
                LEFT JOIN users u ON u.referral_code = ac.referrer_code
                LEFT JOIN orders o ON o.id = ac.order_id
                LEFT JOIN affiliate_payouts ap ON ap.id = ac.payout_id
                ORDER BY ac.id DESC";
        
        $stmt = $pdo->query($sql);
        $commissions = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        // Totais por referrer
        $totalsSql = "SELECT 
                        referrer_code,
                        status,
                        COUNT(*) as count,
                        SUM(amount) as total
                      FROM affiliate_commissions
                      GROUP BY referrer_code, status";
        
        $totalsStmt = $pdo->query($totalsSql);
        $totals = $totalsStmt->fetchAll(\PDO::FETCH_ASSOC);
        
        // Top referrers (aprovados)
        $leadersSql = "SELECT 
                          referrer_code,
                          COUNT(*) as qty_approved,
                          SUM(amount) as total_approved
                       FROM affiliate_commissions
                       WHERE status = 'APROVADA'
                       GROUP BY referrer_code
                       ORDER BY total_approved DESC
                       LIMIT 10";
        
        $leadersStmt = $pdo->query($leadersSql);
        $leaders = $leadersStmt->fetchAll(\PDO::FETCH_ASSOC);
        
        // Adicionar nomes aos líderes
        foreach ($leaders as &$leader) {
            $userStmt = $pdo->prepare("SELECT name FROM users WHERE referral_code = ? LIMIT 1");
            $userStmt->execute([$leader['referrer_code']]);
            $user = $userStmt->fetch();
            $leader['referrer_name'] = $user['name'] ?? $leader['referrer_code'];
        }
        
        Response::json([
            'commissions' => $commissions,
            'totals_by_referrer_status' => $totals,
            'leaders' => $leaders
        ]);
        
    } catch (\Throwable $e) {
        error_log('Commissions error: ' . $e->getMessage());
        Response::json(['message' => 'Erro ao carregar comissões', 'error' => $e->getMessage()], 500);
    }
}

/**
 * Listar pedidos de levantamento (para admin-affiliates.html)
 */
public static function payouts(): void
{
    self::requireAdmin();
    try {
        $pdo = Database::pdo();
        
        $sql = "SELECT 
                    ap.*,
                    u.name,
                    u.email,
                    u.referral_code
                FROM affiliate_payouts ap
                LEFT JOIN users u ON u.id = ap.user_id
                ORDER BY ap.id DESC";
        
        $stmt = $pdo->query($sql);
        $payouts = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        Response::json(['payouts' => $payouts]);
        
    } catch (\Throwable $e) {
        error_log('Payouts error: ' . $e->getMessage());
        Response::json(['message' => 'Erro ao carregar levantamentos'], 500);
    }
}


    /**
     * Upload final document:
     * - saves final file
     * - sets order state to DOCUMENTO_FINAL_SUBMETIDO (so admin listing can show "documento final submetido")
     * - keeps an audit trail
     */
    public static function uploadFinal(): void
    {
        $admin = self::requireAdmin();
        $orderId = (int) ($_POST['order_id'] ?? 0);
        if (!$orderId || empty($_FILES['final']['tmp_name'])) {
            Response::json(['message' => 'Ficheiro final em falta'], 400);
            return;
        }
        $dir = dirname(__DIR__, 2) . '/uploads/finais';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $safeName = uniqid('final_') . '-' . preg_replace('/[^a-zA-Z0-9\.\-_]/', '_', $_FILES['final']['name']);
        $dest = $dir . '/' . $safeName;
        if (!move_uploaded_file($_FILES['final']['tmp_name'], $dest)) {
            Response::json(['message' => 'Não foi possível guardar o ficheiro'], 500);
            return;
        }

        // Attach final file (this method currently sets estado = CONCLUIDA in the model).
        // We still persist the file, then explicitly set status to DOCUMENTO_FINAL_SUBMETIDO
        // so admin UI can show the intermediate state. If you prefer automatic conclusion,
        // modify to 'CONCLUIDA' later when marking as delivered.
        Order::saveFinalFile($orderId, '/uploads/finais/' . $safeName);
        Order::updateEstado($orderId, 'DOCUMENTO_FINAL_SUBMETIDO');

        $order = Order::findWithUser($orderId);
        if ($order) {
            Mailer::send($order['user_email'], 'Trabalho entregue', 'O documento final para a encomenda #' . $orderId . ' está disponível para download.');
            AuditHelper::log((int) $order['user_id'], 'order:entregue', ['order_id' => $orderId]);
        }
        AuditHelper::log($admin['id'], 'order:deliver', ['order_id' => $orderId]);
        Response::json(['message' => 'Documento final enviado']);
    }

    /**
     * Admin orders listing: enhanced with:
     * - materiais_array decoded
     * - invoice_details decoded
     * - invoice_pdf_url
     * - feedback
     * - final_submitted flag (true when final_file present)
     */
    public static function listOrders(): void
    {
        self::requireAdmin();
        $orders = Order::listAllWithInvoices();
        $enhanced = [];

        foreach ($orders as $o) {
            // garantir campos básicos
            $o = is_array($o) ? $o : (array) $o;

            // decodificar materiais
            $materials = [];
            if (!empty($o['materiais_uploads']) && is_string($o['materiais_uploads'])) {
                $m = json_decode($o['materiais_uploads'], true);
                if (is_array($m)) $materials = $m;
            }
            $o['materiais_array'] = $materials;

            // anexar invoice details quando houver invoice_id
            $o['invoice_details'] = null;
            if (!empty($o['invoice_id'])) {
                $inv = Invoice::findById((int) $o['invoice_id']);
                if ($inv) {
                    // decodificar detalhes se necessário
                    if (!empty($inv['detalhes']) && is_string($inv['detalhes'])) {
                        $decoded = json_decode($inv['detalhes'], true);
                        if (is_array($decoded)) {
                            $inv['detalhes_decoded'] = $decoded;
                        }
                    }
                    $o['invoice_details'] = $inv;
                }
            }

            // construir URL para o PDF (o OrderController::invoicePdf usa orderId)
            $orderId = (int) ($o['id'] ?? 0);
            $o['invoice_pdf_url'] = '/api/orders/' . $orderId . '/pdf';

            // feedbacks referentes a essa encomenda (aparecerá directamente na listagem do admin)
            $o['feedback'] = Feedback::listForOrder($orderId);

            // final_submitted flag para UI
            $o['final_submitted'] = !empty($o['final_file']) ? true : false;

            $enhanced[] = $o;
        }

        Response::json(['orders' => $enhanced]);
    }

    public static function listUsers(): void
    {
        $admin = self::requireAdmin();
        $firstAdminId = User::firstAdminId();
        Response::json([
            'users' => User::listAll(),
            'can_delete_users' => $firstAdminId !== null && (int) $admin['id'] === $firstAdminId,
            'first_admin_id' => $firstAdminId,
        ]);
    }

    /**
     * Toggle user active/inactive.
     * Note: front-end should remove approve/reject buttons where appropriate; server side we log and change active flag.
     */
    public static function toggleUser(): void
    {
        $admin = self::requireAdmin();
        $userId = (int) ($_POST['user_id'] ?? 0);
        $active = ($_POST['active'] ?? '1') === '1';
        User::setActive($userId, $active);
        AuditHelper::log($admin['id'], 'user:toggle', ['user_id' => $userId, 'active' => $active]);

        // Optionally: when deactivating, invalidate tokens / sessions (requires session store).
        // For now, ensure login checks active status (AuthController should be adjusted accordingly).
        Response::json(['message' => 'Estado atualizado']);
    }

    public static function deleteUser(): void
    {
        $admin = self::requireAdmin();
        $targetId = (int) ($_POST['user_id'] ?? 0);
        if ($targetId <= 0) {
            Response::json(['message' => 'user_id obrigatório'], 400);
            return;
        }

        $firstAdminId = User::firstAdminId();
        if ($firstAdminId === null || (int) $admin['id'] !== $firstAdminId) {
            Response::json(['message' => 'Apenas o primeiro admin pode eliminar utilizadores'], 403);
            return;
        }

        $target = User::findById($targetId);
        if (!$target) {
            Response::json(['message' => 'Utilizador não encontrado'], 404);
            return;
        }
        if (($target['role'] ?? '') === 'admin') {
            Response::json(['message' => 'Não é permitido eliminar administradores'], 400);
            return;
        }

        $deps = User::dependencySummary($targetId);
        $hasDependencies = array_sum($deps) > 0;
        if ($hasDependencies) {
            Response::json(['message' => 'Utilizador com dependências. Use anonimização.', 'dependencies' => $deps], 409);
            return;
        }

        User::deleteNonAdmin($targetId);
        AuditHelper::log($admin['id'], 'user:delete', ['user_id' => $targetId, 'email' => $target['email'] ?? null]);
        Response::json(['message' => 'Utilizador eliminado']);
    }

    /**
     * Metrics (unchanged) - summary for admin dashboard
     */
    public static function metrics(): void
    {
        self::requireAdmin();
        $pdo = Database::pdo();
        $totals = [
            'orders' => (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
            'invoices' => (int) $pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn(),
            'paid' => (float) $pdo->query("SELECT COALESCE(SUM(valor_total),0) FROM invoices WHERE estado='PAGA'")->fetchColumn(),
            'pending' => (float) $pdo->query("SELECT COALESCE(SUM(valor_total),0) FROM invoices WHERE estado!='PAGA'")->fetchColumn(),
            'payouts_pending' => (float) $pdo->query("SELECT COALESCE(SUM(valor),0) FROM affiliate_payouts WHERE status IN ('SOLICITADO','APROVADO')")->fetchColumn(),
        ];
        $statusBreakdown = $pdo->query("SELECT estado, COUNT(*) as total FROM orders GROUP BY estado")->fetchAll();
        $trend = $pdo->query("SELECT DATE_FORMAT(created_at, '%Y-%m') as mes, COALESCE(SUM(valor_total),0) as total FROM invoices WHERE estado='PAGA' GROUP BY mes ORDER BY mes DESC LIMIT 6")->fetchAll();
        $services = $pdo->query("SELECT categoria, COUNT(*) as total FROM service_requests GROUP BY categoria ORDER BY total DESC LIMIT 6")->fetchAll();
        $affiliates = $pdo->query("SELECT referrer_code, COUNT(*) as total, COALESCE(SUM(amount),0) as valor FROM affiliate_commissions GROUP BY referrer_code ORDER BY valor DESC LIMIT 5")->fetchAll();
        Response::json(['metrics' => $totals, 'status' => $statusBreakdown, 'trend' => $trend, 'services' => $services, 'affiliates' => $affiliates]);
    }



    public static function growthDashboard(): void
    {
        self::requireAdmin();
        $pdo = Database::pdo();

        $leads = 0;
        $paidOrders = 0;
        $revenue = 0.0;
        $channel = [];

        try {
            $leads = (int) $pdo->query("SELECT COUNT(*) FROM audits WHERE action='marketing:lead'")->fetchColumn();
        } catch (\Throwable $e) {}

        try {
            $paidOrders = (int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE estado='PAGA'")->fetchColumn();
            $revenue = (float) $pdo->query("SELECT COALESCE(SUM(valor_total),0) FROM invoices WHERE estado='PAGA'")->fetchColumn();
        } catch (\Throwable $e) {}

        try {
            $stmt = $pdo->query("SELECT COALESCE(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.origin.utm_source')), 'direct') as channel, COUNT(*) as total FROM audits WHERE action='marketing:attribution' GROUP BY channel ORDER BY total DESC");
            $channel = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $channel = [];
        }

        $estimatedAdSpend = $leads * 12.0;
        $cac = $paidOrders > 0 ? round($estimatedAdSpend / $paidOrders, 2) : 0;
        $roas = $estimatedAdSpend > 0 ? round($revenue / $estimatedAdSpend, 2) : 0;
        $ltv = $paidOrders > 0 ? round(($revenue / $paidOrders) * 1.8, 2) : 0;
        $leadToPaid = $leads > 0 ? round(($paidOrders / $leads) * 100, 2) : 0;

        Response::json([
            'kpis' => [
                'estimated_cac' => $cac,
                'estimated_roas' => $roas,
                'approx_ltv' => $ltv,
                'lead_to_paid_conversion' => $leadToPaid,
            ],
            'totals' => [
                'leads' => $leads,
                'paid_orders' => $paidOrders,
                'revenue' => $revenue,
                'estimated_ad_spend' => $estimatedAdSpend,
            ],
            'channel_conversion' => $channel,
        ]);
    }

    public static function feedback(): void
    {
        self::requireAdmin();
        Response::json(['feedback' => Feedback::listAll()]);
    }

    /**
     * Return commissions with richer mapping for fraud detection / admin reconciliation.
     * Joins to:
     *  - referrer user (by referral_code)
     *  - beneficiary user (by email)
     *  - payout (if any)
     */
    public static function affiliateTransactions(): void
    {
        self::requireAdmin();
        try {
            $pdo = Database::pdo();
            $sql = "SELECT ac.*,
                           p.id AS payout_id, p.status AS payout_status, p.created_at AS payout_created_at,
                           ur.id AS referrer_id, ur.name AS referrer_name, ur.email AS referrer_email,
                           ub.id AS ben_id, ub.name AS beneficiary_name, ub.email AS beneficiary_email
                    FROM affiliate_commissions ac
                    LEFT JOIN affiliate_payouts p ON p.id = ac.payout_id
                    LEFT JOIN users ur ON ur.referral_code = ac.referrer_code
                    LEFT JOIN users ub ON ub.email = ac.beneficiary_email
                    ORDER BY ac.id DESC";
            $stmt = $pdo->query($sql);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            Response::json(['transactions' => $rows]);
        } catch (\Throwable $e) {
            error_log('affiliateTransactions error: ' . $e->getMessage());
            Response::json(['message' => 'Erro ao obter transações de afiliados'], 500);
        }
    }

    /**
     * Payout update:
     * - update payout status (APROVADO -> PAGO for immediate UX simplification)
     * - allocate commissions to payout
     * - send emails and audit log
     */
    public static function updatePayout(): void
    {
        $admin = self::requireAdmin();
        $payoutId = (int) ($_POST['payout_id'] ?? 0);
        $statusInput = strtoupper(trim($_POST['status'] ?? 'PENDENTE'));
        $notes = $_POST['notes'] ?? null;

        $payout = AffiliatePayout::find($payoutId);
        if (!$payout) {
            Response::json(['message' => 'Solicitação não encontrada'], 404);
            return;
        }

        // Normalize the flow: when admin chooses APROVAR, we mark as PAGO (so UI shows no action buttons).
        $finalStatus = $statusInput === 'APROVADO' ? 'PAGO' : $statusInput;

        AffiliatePayout::updateStatus($payoutId, $finalStatus, $admin['id'], $notes);

        // if payout moved to PAGO, allocate approved commissions to this payout_id
        if ($finalStatus === 'PAGO') {
            try {
                $refCode = $payout['referral_code'] ?? null;
                if ($refCode) {
                    AffiliateCommission::allocateToPayout($refCode, $payoutId);
                }
            } catch (\Throwable $e) {
                error_log('allocateToPayout error: ' . $e->getMessage());
            }
        }

        // notify user
        $recipientEmail = $payout['email'] ?? null;
        if ($recipientEmail) {
            Mailer::send($recipientEmail, 'Atualização do pagamento de afiliado', 'Estado da sua solicitação #' . $payoutId . ': ' . $finalStatus);
        }

        AuditHelper::log($admin['id'], 'affiliate:payout:update', ['payout_id' => $payoutId, 'status' => $finalStatus]);
        Response::json(['message' => 'Pagamento de afiliado atualizado', 'status' => $finalStatus]);
    }


    public static function marketingRecipients(): void
    {
        self::requireAdmin();
        $pdo = Database::pdo();
        $includeAdmins = (($_GET['include_admins'] ?? '0') === '1');

        $sql = "SELECT id, name, email, role, active, created_at FROM users WHERE active = 1";
        if (!$includeAdmins) {
            $sql .= " AND role != 'admin'";
        }
        $sql .= " ORDER BY id DESC";

        $rows = $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
        $valid = [];
        foreach ($rows as $r) {
            $email = trim((string)($r['email'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if (str_ends_with(strtolower($email), '@redacted.local')) {
                continue;
            }
            $valid[] = $r;
        }

        Response::json([
            'count' => count($valid),
            'recipients' => $valid,
        ]);
    }

    public static function sendMarketingCampaign(): void
    {
        $admin = self::requireAdmin();

        $raw = file_get_contents('php://input') ?: '';
        $json = json_decode($raw, true);
        $data = is_array($json) ? $json : $_POST;

        $subject = trim((string)($data['subject'] ?? ''));
        $html = trim((string)($data['html'] ?? ''));
        $includeAdmins = (($data['include_admins'] ?? '0') === '1');
        $maxRecipients = max(1, min(1000, (int)($data['max_recipients'] ?? 500)));
        $sendTestTo = trim((string)($data['send_test_to'] ?? ''));

        if ($subject === '' || $html === '') {
            Response::json(['message' => 'subject e html são obrigatórios'], 422);
            return;
        }
        if (mb_strlen($subject) > 200) {
            Response::json(['message' => 'subject excede 200 caracteres'], 422);
            return;
        }

        $campaignId = 'camp_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $startedAt = microtime(true);

        if ($sendTestTo !== '') {
            if (!filter_var($sendTestTo, FILTER_VALIDATE_EMAIL)) {
                Response::json(['message' => 'Email de teste inválido'], 422);
                return;
            }
            $ok = Mailer::send($sendTestTo, '[TESTE] ' . $subject, $html);
            AuditHelper::log((int)$admin['id'], 'marketing:campaign:test', [
                'campaign_id' => $campaignId,
                'subject' => $subject,
                'test_email' => $sendTestTo,
                'ok' => $ok,
                'timestamp' => date('c'),
            ]);
            Response::json([
                'message' => $ok ? 'Email de teste enviado' : 'Falha no envio de teste',
                'campaign_id' => $campaignId,
                'sent' => $ok ? 1 : 0,
                'failed' => $ok ? 0 : 1,
                'total_targets' => 1,
            ], $ok ? 200 : 500);
            return;
        }

        $pdo = Database::pdo();
        $sql = "SELECT id, email, role FROM users WHERE active = 1";
        if (!$includeAdmins) {
            $sql .= " AND role != 'admin'";
        }
        $sql .= " ORDER BY id DESC";
        $rows = $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);

        $targets = [];
        foreach ($rows as $r) {
            $email = strtolower(trim((string)($r['email'] ?? '')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            if (str_ends_with($email, '@redacted.local')) continue;
            $targets[$email] = true;
            if (count($targets) >= $maxRecipients) break;
        }
        $targets = array_keys($targets);

        if (!$targets) {
            Response::json(['message' => 'Nenhum destinatário válido encontrado'], 404);
            return;
        }

        $sent = 0;
        $failed = [];
        foreach ($targets as $i => $email) {
            $ok = Mailer::send($email, $subject, $html);
            if ($ok) $sent++;
            else $failed[] = $email;

            if ((($i + 1) % 50) === 0) {
                usleep(120000);
            }
        }

        $durationMs = (int)round((microtime(true) - $startedAt) * 1000);
        AuditHelper::log((int)$admin['id'], 'marketing:campaign:send', [
            'campaign_id' => $campaignId,
            'subject' => $subject,
            'include_admins' => $includeAdmins,
            'requested_max' => $maxRecipients,
            'sent' => $sent,
            'failed' => count($failed),
            'failed_emails' => $failed,
            'duration_ms' => $durationMs,
            'timestamp' => date('c'),
        ]);

        Response::json([
            'message' => 'Campanha processada',
            'campaign_id' => $campaignId,
            'sent' => $sent,
            'failed' => count($failed),
            'failed_emails' => $failed,
            'total_targets' => count($targets),
            'duration_ms' => $durationMs,
        ]);
    }

    public static function marketingCampaignHistory(): void
    {
        self::requireAdmin();
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = max(1, min(50, (int)($_GET['per_page'] ?? 10)));
        $offset = ($page - 1) * $perPage;

        $pdo = Database::pdo();
        $countStmt = $pdo->query("SELECT COUNT(*) FROM audits WHERE action IN ('marketing:campaign:send','marketing:campaign:test')");
        $total = (int)$countStmt->fetchColumn();

        $sql = "SELECT id, created_at, action, user_id, meta
                FROM audits
                WHERE action IN ('marketing:campaign:send','marketing:campaign:test')
                ORDER BY id DESC
                LIMIT :lim OFFSET :off";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':lim', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $history = array_map(function ($r) {
            $meta = [];
            if (!empty($r['meta']) && is_string($r['meta'])) {
                $d = json_decode($r['meta'], true);
                if (is_array($d)) $meta = $d;
            }
            return [
                'id' => (int)$r['id'],
                'created_at' => $r['created_at'] ?? null,
                'action' => $r['action'] ?? null,
                'campaign_id' => $meta['campaign_id'] ?? null,
                'subject' => $meta['subject'] ?? null,
                'sent' => (int)($meta['sent'] ?? 0),
                'failed' => (int)($meta['failed'] ?? 0),
                'duration_ms' => (int)($meta['duration_ms'] ?? 0),
                'test_email' => $meta['test_email'] ?? null,
            ];
        }, $rows);

        Response::json([
            'history' => $history,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int)ceil($total / $perPage),
            ],
        ]);
    }

    public static function marketingLeads(): void
    {
        self::requireAdmin();

        $q = trim((string)($_GET['q'] ?? ''));
        $source = trim((string)($_GET['source'] ?? ''));
        $interest = trim((string)($_GET['interest'] ?? ''));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = max(1, min(100, (int)($_GET['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

        $pdo = Database::pdo();
        $where = ["action = 'marketing:lead'"];
        $params = [];

        if ($q !== '') {
            $where[] = "(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.name')) LIKE :q OR JSON_UNQUOTE(JSON_EXTRACT(meta, '$.email')) LIKE :q OR JSON_UNQUOTE(JSON_EXTRACT(meta, '$.phone')) LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }
        if ($source !== '') {
            $where[] = "JSON_UNQUOTE(JSON_EXTRACT(meta, '$.source')) = :source";
            $params[':source'] = $source;
        }
        if ($interest !== '') {
            $where[] = "JSON_UNQUOTE(JSON_EXTRACT(meta, '$.interest')) = :interest";
            $params[':interest'] = $interest;
        }

        $whereSql = implode(' AND ', $where);

        $countSql = "SELECT COUNT(*) FROM audits WHERE {$whereSql}";
        $countStmt = $pdo->prepare($countSql);
        foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
        $countStmt->execute();
        $total = (int)$countStmt->fetchColumn();

        $sql = "SELECT id, created_at,
                       JSON_UNQUOTE(JSON_EXTRACT(meta, '$.name')) as name,
                       JSON_UNQUOTE(JSON_EXTRACT(meta, '$.email')) as email,
                       JSON_UNQUOTE(JSON_EXTRACT(meta, '$.phone')) as phone,
                       JSON_UNQUOTE(JSON_EXTRACT(meta, '$.interest')) as interest,
                       JSON_UNQUOTE(JSON_EXTRACT(meta, '$.source')) as source
                FROM audits
                WHERE {$whereSql}
                ORDER BY id DESC
                LIMIT :lim OFFSET :off";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':lim', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $leads = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $sourceSql = "SELECT JSON_UNQUOTE(JSON_EXTRACT(meta, '$.source')) as source, COUNT(*) as total
                      FROM audits
                      WHERE action = 'marketing:lead'
                      GROUP BY JSON_UNQUOTE(JSON_EXTRACT(meta, '$.source'))
                      ORDER BY total DESC";
        $sources = $pdo->query($sourceSql)->fetchAll(\PDO::FETCH_ASSOC);

        $interestSql = "SELECT JSON_UNQUOTE(JSON_EXTRACT(meta, '$.interest')) as interest, COUNT(*) as total
                        FROM audits
                        WHERE action = 'marketing:lead'
                        GROUP BY JSON_UNQUOTE(JSON_EXTRACT(meta, '$.interest'))
                        ORDER BY total DESC";
        $interests = $pdo->query($interestSql)->fetchAll(\PDO::FETCH_ASSOC);

        Response::json([
            'leads' => $leads,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int)ceil($total / $perPage),
            ],
            'sources' => $sources,
            'interests' => $interests,
        ]);
    }

    public static function anonymizeUser(): void
    {
        $admin = self::requireAdmin();
        $targetId = (int) ($_POST['user_id'] ?? 0);
        if ($targetId <= 0) {
            Response::json(['message' => 'user_id obrigatório'], 400);
            return;
        }
        User::anonymize($targetId);
        AuditHelper::log($admin['id'], 'user:anonymize', ['user_id' => $targetId]);
        Response::json(['message' => 'Utilizador anonimizado']);
    }

    public static function notificationsCenter(): void
    {
        self::requireAdmin();
        $pdo = Database::pdo();
        $rows = $pdo->query("SELECT id, action, created_at, meta FROM audits ORDER BY id DESC LIMIT 100")->fetchAll(\PDO::FETCH_ASSOC);
        Response::json(['notifications' => $rows]);
    }

    public static function affiliateConversionCsv(): void
    {
        self::requireAdmin();
        $pdo = Database::pdo();
        $sql = "SELECT referrer_code, COUNT(*) AS total_orders, SUM(CASE WHEN status='APROVADA' THEN 1 ELSE 0 END) as approved_orders, COALESCE(SUM(amount),0) as commission_total FROM affiliate_commissions GROUP BY referrer_code ORDER BY commission_total DESC";
        $rows = $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=affiliate-conversion.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['referrer_code', 'total_orders', 'approved_orders', 'commission_total']);
        foreach ($rows as $r) fputcsv($out, [$r['referrer_code'], $r['total_orders'], $r['approved_orders'], $r['commission_total']]);
        fclose($out);
    }

    public static function slaPanel(): void
    {
        self::requireAdmin();
        $pdo = Database::pdo();
        $metrics = [
            'orders_pending_payment' => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE estado='PENDENTE_PAGAMENTO'")->fetchColumn(),
            'orders_in_execution' => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE estado='EM_EXECUCAO'")->fetchColumn(),
            'services_open' => (int) $pdo->query("SELECT COUNT(*) FROM service_requests WHERE status IN ('NOVO','EM_ANALISE')")->fetchColumn(),
            'chat_open_sessions' => (int) $pdo->query("SELECT COUNT(*) FROM support_chat_sessions WHERE status IN ('open','assigned','waiting_client')")->fetchColumn(),
        ];
        Response::json(['sla' => $metrics]);
    }

    public static function affiliateFraudPanel(): void
    {
        self::requireAdmin();
        $pdo = Database::pdo();

        $signals = [
            'self_referral_attempts' => 0,
            'suspicious_click_bursts' => [],
            'high_conversion_codes' => [],
            'auto_block_recommendations' => [],
        ];

        try {
            $signals['self_referral_attempts'] = (int) $pdo->query("SELECT COUNT(*) FROM audits WHERE action='affiliate:fraud:self_referral'")->fetchColumn();
        } catch (\Throwable $e) {}

        try {
            $sql = "SELECT JSON_UNQUOTE(JSON_EXTRACT(meta, '$.code')) AS code, COUNT(*) AS clicks
                    FROM audits
                    WHERE action='affiliate:click' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                    GROUP BY code
                    HAVING clicks >= 25
                    ORDER BY clicks DESC
                    LIMIT 20";
            $signals['suspicious_click_bursts'] = $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {}

        try {
            $sql = "SELECT referrer_code, COUNT(*) AS approved_orders, COALESCE(SUM(amount),0) AS commission
                    FROM affiliate_commissions
                    WHERE status='APROVADA'
                    GROUP BY referrer_code
                    HAVING approved_orders >= 5
                    ORDER BY approved_orders DESC
                    LIMIT 20";
            $signals['high_conversion_codes'] = $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {}

        foreach (($signals['suspicious_click_bursts'] ?? []) as $row) {
            if ((int)($row['clicks'] ?? 0) >= 80) {
                $signals['auto_block_recommendations'][] = [
                    'code' => $row['code'] ?? null,
                    'reason' => 'Clique anómalo em 24h',
                    'severity' => 'high',
                ];
            }
        }

        Response::json(['fraud' => $signals]);
    }

    public static function audits(): void
    {
        self::requireAdmin();
        Response::json(['audits' => Audit::listRecent()]);
    }

    public static function chatMessages(): void
    {
        self::requireAdmin();
        $orderId = isset($_GET['order_id']) ? (int) $_GET['order_id'] : null;
        Response::json(['messages' => AdminMessage::listRecent($orderId)]);
    }

    public static function postChatMessage(): void
    {
        $admin = self::requireAdmin();
        $orderId = isset($_POST['order_id']) && $_POST['order_id'] !== '' ? (int) $_POST['order_id'] : null;
        $message = trim($_POST['message'] ?? '');
        if (!$message && empty($_FILES['attachment']['tmp_name'])) {
            Response::json(['message' => 'Mensagem ou anexo obrigatório'], 400);
            return;
        }
        $attachment = null;
        if (!empty($_FILES['attachment']['tmp_name'])) {
            $dir = dirname(__DIR__, 2) . '/uploads/admin-chat';
            if (!is_dir($dir)) mkdir($dir, 0775, true);
            $safeName = uniqid('adm_') . '-' . preg_replace('/[^a-zA-Z0-9\.\-_]/', '_', $_FILES['attachment']['name']);
            $dest = $dir . '/' . $safeName;
            if (move_uploaded_file($_FILES['attachment']['tmp_name'], $dest)) {
                $attachment = '/uploads/admin-chat/' . $safeName;
            }
        }
        $msgId = AdminMessage::create($admin['id'], $orderId, $message, $attachment);
        AuditHelper::log($admin['id'], 'admin:chat', ['message_id' => $msgId, 'order_id' => $orderId]);
        Response::json(['message' => 'Nota registada', 'id' => $msgId]);
    }
}