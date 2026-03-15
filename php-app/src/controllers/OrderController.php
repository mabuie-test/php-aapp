<?php
namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Pricing;
use App\Helpers\Response;
use App\Helpers\AuditHelper;
use App\Helpers\Mailer;
use App\Helpers\DebitoGateway;
use App\Models\Order;
use App\Models\Invoice;
use App\Models\Feedback;
use App\Models\AffiliateCommission;
use App\Models\AffiliatePayout;
use App\Models\User;
use App\Models\Audit;
use App\Config\Config;

class OrderController
{
    public static function quote(): void
    {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        $isExercisePricing = (($body['tipo'] ?? '') === 'auxilio_secundario') || !empty($body['exercicios']);
        if ($isExercisePricing) {
            $exerciseCount = (int) ($body['exercicios'] ?? 0);
            if ($exerciseCount <= 0) {
                Response::json(['message' => 'Informe o número de alíneas/exercícios'], 400);
                return;
            }
            Response::json(Pricing::quoteExercises($exerciseCount));
            return;
        }

        if (!isset($body['paginas'], $body['nivel'], $body['complexidade'], $body['urgencia'])) {
            Response::json(['message' => 'Dados incompletos'], 400);
            return;
        }
        $quote = Pricing::quote((int) $body['paginas'], $body['nivel'], $body['complexidade'], $body['urgencia']);
        Response::json($quote);
    }

    public static function create(): void
    {
        $user = Auth::requireUser();
        $data = $_POST;
        $exerciseCount = (int) ($data['exercicios'] ?? 0);
        $isExercisePricing = (($data['tipo'] ?? '') === 'auxilio_secundario') || $exerciseCount > 0;

        if ($isExercisePricing && $exerciseCount <= 0) {
            Response::json(['message' => 'Informe o número de alíneas/exercícios'], 400);
            return;
        }
        if (!$isExercisePricing && (int) ($data['paginas'] ?? 0) <= 0) {
            Response::json(['message' => 'Informe o número de páginas'], 400);
            return;
        }

        $quote = $isExercisePricing
            ? Pricing::quoteExercises($exerciseCount)
            : Pricing::quote((int) ($data['paginas'] ?? 0), $data['nivel'] ?? '', $data['complexidade'] ?? '', $data['urgencia'] ?? '');

        $deadline = trim((string) ($data['prazo_entrega'] ?? ''));
        $deadline = $deadline !== '' ? $deadline : null;

        $materialsFiles = [];
        if (!empty($_FILES['materiais_uploads']['name'][0])) {
            $uploadDir = dirname(__DIR__, 2) . '/uploads/materiais';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0775, true);
            }
            foreach ($_FILES['materiais_uploads']['name'] as $index => $name) {
                $tmp = $_FILES['materiais_uploads']['tmp_name'][$index] ?? null;
                if (!$tmp) continue;
                $safeName = uniqid('mat_') . '-' . preg_replace('/[^a-zA-Z0-9\.\-_]/', '_', $name);
                $dest = $uploadDir . '/' . $safeName;
                if (move_uploaded_file($tmp, $dest)) {
                    $materialsFiles[] = '/uploads/materiais/' . $safeName;
                }
            }
        }

        if (!empty($_FILES['exercicios_uploads']['name'][0])) {
            $uploadDir = dirname(__DIR__, 2) . '/uploads/materiais';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0775, true);
            }
            foreach ($_FILES['exercicios_uploads']['name'] as $index => $name) {
                $tmp = $_FILES['exercicios_uploads']['tmp_name'][$index] ?? null;
                if (!$tmp) continue;
                $safeName = uniqid('ex_') . '-' . preg_replace('/[^a-zA-Z0-9\.\-_]/', '_', $name);
                $dest = $uploadDir . '/' . $safeName;
                if (move_uploaded_file($tmp, $dest)) {
                    $materialsFiles[] = '/uploads/materiais/' . $safeName;
                }
            }
        }

        // Validar código de indicação (não permite auto-indicação)
        $refCode = null;
        $inputRef = trim((string) ($data['referral_code'] ?? ''));
        if ($inputRef !== '') {
            $refUser = User::findByReferralCode($inputRef);
            if ($refUser && (int) $refUser['id'] !== (int) $user['id']) {
                $refCode = $refUser['referral_code'];
            } else {
                if ($refUser && (int) $refUser['id'] === (int) $user['id']) {
                    AuditHelper::log($user['id'], 'affiliate:fraud:self_referral', ['input_code' => $inputRef]);
                }
                // inválido ou tentativa de auto-indicação -> ignorar
                $refCode = null;
            }
        } elseif (!empty($user['referred_by'])) {
            // se o utilizador tem um referrer no perfil, validar também (evita uso indevido)
            $profileRef = trim((string) $user['referred_by']);
            if ($profileRef !== '') {
                $refUser = User::findByReferralCode($profileRef);
                if ($refUser && (int) $refUser['id'] !== (int) $user['id']) {
                    $refCode = $refUser['referral_code'];
                }
            }
        }

        try {
            $orderId = Order::create([
                'user_id' => $user['id'],
                'tipo' => $data['tipo'] ?? '',
                'area' => $data['area'] ?? '',
                'nivel' => $data['nivel'] ?? '',
                'paginas' => $isExercisePricing ? max(1, $exerciseCount) : (int) ($data['paginas'] ?? 0),
                'norma' => $data['norma'] ?? null,
                'complexidade' => $data['complexidade'] ?? null,
                'urgencia' => $data['urgencia'] ?? null,
                'descricao' => trim(($data['descricao'] ?? '') . ($isExercisePricing ? ("\n\n[AUXÍLIO SECUNDÁRIO] Alíneas/exercícios: " . max(1, $exerciseCount) . ' · Taxa unitária: ' . ($quote['unitPrice'] ?? 5) . ' MZN') : '')),
                'estado' => 'PENDENTE_PAGAMENTO',
                'prazo_entrega' => $deadline,
                'referred_by_code' => $refCode,
                'materiais_info' => $data['materiais_info'] ?? null,
                'materiais_percentual' => $data['materiais_percentual'] ?? null,
                'materiais_uploads' => $materialsFiles ? json_encode($materialsFiles) : null,
            ]);
        } catch (\Throwable $e) {
            error_log('Order create failed: ' . $e->getMessage());
            Response::json(['message' => 'Não foi possível criar a encomenda. Verifique os dados e tente novamente.'], 500);
            return;
        }

        $invoiceNumber = 'FAT-' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT);
        $invoiceId = Invoice::create([
            'order_id' => $orderId,
            'user_id' => $user['id'],
            'numero' => $invoiceNumber,
            'valor_total' => $quote['total'],
            'detalhes' => $quote,
            'estado' => 'EMITIDA',
            'vencimento' => date('Y-m-d H:i:s', strtotime('+24 hours')),
        ]);
        Order::attachInvoice($orderId, $invoiceId);

        AuditHelper::log($user['id'], 'order:create', ['order_id' => $orderId]);
        AuditHelper::log($user['id'], 'invoice:emitida', ['order_id' => $orderId, 'invoice_id' => $invoiceId, 'total' => $quote['total']]);

        Mailer::send($user['email'], 'Fatura emitida', 'A sua fatura ' . $invoiceNumber . ' foi emitida com valor ' . $quote['total']);

        $adminRecipients = User::adminEmails();
        $fallbackAdmin = Config::get('ADMIN_NOTIFY_EMAIL');
        if ($fallbackAdmin && !in_array($fallbackAdmin, $adminRecipients)) {
            $adminRecipients[] = $fallbackAdmin;
        }
        foreach ($adminRecipients as $adminEmail) {
            Mailer::send($adminEmail, 'Nova encomenda criada', 'Pedido #' . $orderId . ' criado para ' . $user['email']);
        }

        Response::json([
            'order_id' => $orderId,
            'invoice_id' => $invoiceId,
            'invoice_number' => $invoiceNumber,
            'valor_total' => $quote['total'],
        ], 201);
    }

    public static function uploadProof(): void
    {
        $user = Auth::requireUser();
        $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
        if (!$invoiceId || empty($_FILES['comprovativo']['tmp_name'])) {
            Response::json(['message' => 'Comprovativo em falta'], 400);
            return;
        }
        $dir = dirname(__DIR__, 2) . '/uploads/comprovativos';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $safeName = uniqid('comp_') . '-' . preg_replace('/[^a-zA-Z0-9\.\-_]/', '_', $_FILES['comprovativo']['name']);
        $dest = $dir . '/' . $safeName;
        if (!move_uploaded_file($_FILES['comprovativo']['tmp_name'], $dest)) {
            Response::json(['message' => 'Falha ao guardar comprovativo'], 500);
            return;
        }
        Invoice::saveComprovativo($invoiceId, '/uploads/comprovativos/' . $safeName);
        Order::updateEstado((int) ($_POST['order_id'] ?? 0), 'PAGAMENTO_EM_VALIDACAO');
        AuditHelper::log($user['id'], 'invoice:proof', ['invoice_id' => $invoiceId]);
        Mailer::send($user['email'], 'Comprovativo recebido', 'Recebemos o comprovativo da fatura #' . $invoiceId . '. Iremos validar em breve.');
        $adminRecipients = User::adminEmails();
        $fallbackAdmin = Config::get('ADMIN_NOTIFY_EMAIL');
        if ($fallbackAdmin && !in_array($fallbackAdmin, $adminRecipients)) {
            $adminRecipients[] = $fallbackAdmin;
        }
        foreach ($adminRecipients as $adminEmail) {
            Mailer::send($adminEmail, 'Comprovativo submetido', 'O cliente ' . $user['email'] . ' submeteu comprovativo da fatura #' . $invoiceId);
        }
        Response::json(['message' => 'Comprovativo enviado']);
    }


    private static function latestDebitoReferenceForOrder(int $orderId): ?string
    {
        try {
            $pdo = \App\Config\Database::pdo();
            $stmt = $pdo->prepare("SELECT meta FROM audits WHERE action='invoice:debit:start' AND JSON_EXTRACT(meta, '$.order_id') = :oid ORDER BY id DESC LIMIT 1");
            $stmt->execute([':oid' => $orderId]);
            $metaRaw = $stmt->fetchColumn();
            if (!is_string($metaRaw) || trim($metaRaw) === '') return null;
            $meta = json_decode($metaRaw, true);
            if (!is_array($meta)) return null;
            $provider = is_array($meta['provider_response'] ?? null) ? $meta['provider_response'] : [];
            $ref = (string) ($provider['debito_reference'] ?? $meta['debito_reference'] ?? '');
            return $ref !== '' ? $ref : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function finalizeDebitPayment(array $order, string $status, string $debitoReference, array $meta = []): bool
    {
        if (!self::statusIsPaid($status)) {
            return false;
        }

        $invoiceId = (int) ($order['invoice_id'] ?? 0);
        $orderId = (int) ($order['id'] ?? 0);
        if ($invoiceId <= 0 || $orderId <= 0) {
            return false;
        }

        if (($order['invoice_estado'] ?? '') !== 'PAGA') {
            Invoice::updateEstado($invoiceId, 'PAGA');
            Order::updateEstado($orderId, 'EM_EXECUCAO');
        }

        AuditHelper::log((int) ($order['user_id'] ?? 0), 'invoice:debit:paid', [
            'order_id' => $orderId,
            'invoice_id' => $invoiceId,
            'debito_reference' => $debitoReference,
            'status' => $status,
            'meta' => $meta,
        ]);
        return true;
    }

    public static function debitPay(int $orderId): void
    {
        $user = Auth::requireUser();
        $order = Order::findWithInvoice($orderId);
        if (!$order) {
            Response::json(['message' => 'Encomenda não encontrada'], 404);
            return;
        }
        if ((int) $order['user_id'] !== (int) $user['id']) {
            Response::json(['message' => 'Acesso negado'], 403);
            return;
        }
        if (empty($order['invoice_id'])) {
            Response::json(['message' => 'Fatura não encontrada para esta encomenda'], 400);
            return;
        }

        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $method = strtolower((string) ($body['method'] ?? ''));
        $msisdn = preg_replace('/\D+/', '', (string) ($body['msisdn'] ?? ''));
        $amount = (float) ($body['amount'] ?? 0);

        if (!in_array($method, ['mpesa', 'emola'], true)) {
            Response::json(['message' => 'Método de pagamento inválido'], 422);
            return;
        }
        if ($method === 'emola') {
            if (!preg_match('/^84\d{7}$/', $msisdn)) {
                Response::json(['message' => 'Número eMola inválido. Use formato 84xxxxxxx'], 422);
                return;
            }
        } elseif (!preg_match('/^8\d{8}$/', $msisdn)) {
            Response::json(['message' => 'Número M-Pesa inválido. Use formato 8xxxxxxxx'], 422);
            return;
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $scope = 'debitpay:' . $user['id'] . ':' . $orderId . ':' . $ip;
        if (self::rateLimitExceeded($scope, 6, 60)) {
            Response::json(['message' => 'Muitas tentativas de pagamento. Aguarde 1 minuto.'], 429);
            return;
        }

        $invoice = Invoice::findById((int) $order['invoice_id']);
        if (!$invoice) {
            Response::json(['message' => 'Fatura inválida para esta encomenda'], 404);
            return;
        }
        if (($invoice['estado'] ?? '') === 'PAGA') {
            Response::json(['message' => 'Esta fatura já está paga'], 409);
            return;
        }

        $invoiceTotal = (float) ($invoice['valor_total'] ?? 0);
        if ($amount < 1) {
            $amount = $invoiceTotal;
        }
        if ($amount < 1 || $amount > 50000) {
            Response::json(['message' => 'Valor de pagamento inválido (permitido: 1 a 50000)'], 422);
            return;
        }
        if ($invoiceTotal > 0) {
            if (abs($amount - $invoiceTotal) > 0.01) {
                Response::json(['message' => 'Valor deve ser exatamente o total da fatura'], 422);
                return;
            }
            $amount = $invoiceTotal;
        }

        $referenceDescription = (string) ($body['reference_description'] ?? ('Pagamento fatura ' . ($order['invoice_numero'] ?? ('FAT-' . $orderId))));
        $referenceDescription = trim($referenceDescription);
        if (mb_strlen($referenceDescription) < 3 || mb_strlen($referenceDescription) > 100) {
            Response::json(['message' => 'Descrição da referência inválida (3-100 chars)'], 422);
            return;
        }
        $internalNotes = (string) ($body['internal_notes'] ?? ('order_id=' . $orderId . ';invoice_id=' . ((int) $order['invoice_id']) . ';user_id=' . $user['id']));

        $appUrl = rtrim((string) Config::get('APP_URL', ''), '/');
        $callbackUrl = null;
        if ($appUrl !== '') {
            $callbackUrl = $appUrl . '/api/payments/debito/webhook-c2b';
            $cbSecret = trim((string) Config::get('DEBITO_CALLBACK_SECRET', ''));
            if ($cbSecret !== '') {
                $callbackUrl .= '?secret=' . rawurlencode($cbSecret);
            }
        }

        $gateway = DebitoGateway::createC2B($method, $msisdn, $amount, $referenceDescription, $internalNotes, $callbackUrl);
        if (!$gateway['ok']) {
            Response::json([
                'message' => $gateway['message'] ?? 'Falha ao iniciar pagamento automático',
                'provider_response' => $gateway['data'] ?? null,
            ], $gateway['status'] >= 400 ? (int) $gateway['status'] : 502);
            return;
        }

        Order::updateEstado($orderId, 'PAGAMENTO_EM_VALIDACAO');
        AuditHelper::log($user['id'], 'invoice:debit:start', [
            'order_id' => $orderId,
            'invoice_id' => (int) $order['invoice_id'],
            'provider' => $method,
            'msisdn' => self::maskMsisdn($msisdn),
            'amount' => $amount,
            'provider_response' => $gateway['data'] ?? null,
        ]);

        Response::json([
            'message' => 'Pedido de pagamento enviado com sucesso',
            'provider' => $method,
            'amount' => $amount,
            'data' => $gateway['data'] ?? [],
        ]);
    }



    public static function debitStatus(int $orderId): void
    {
        $user = Auth::requireUser();
        $order = Order::findWithInvoice($orderId);
        if (!$order) {
            Response::json(['message' => 'Encomenda não encontrada'], 404);
            return;
        }
        if ((int) $order['user_id'] !== (int) $user['id']) {
            Response::json(['message' => 'Acesso negado'], 403);
            return;
        }

        $debitoReference = trim((string) ($_GET['debito_reference'] ?? ''));
        if ($debitoReference === '') {
            $debitoReference = (string) (self::latestDebitoReferenceForOrder($orderId) ?? '');
        }
        if ($debitoReference === '') {
            Response::json(['message' => 'Referência de pagamento não encontrada'], 404);
            return;
        }

        $statusResponse = DebitoGateway::transactionStatus($debitoReference);
        if (!$statusResponse['ok']) {
            Response::json([
                'message' => $statusResponse['message'] ?? 'Não foi possível consultar estado da transação',
                'provider_response' => $statusResponse['data'] ?? null,
            ], $statusResponse['status'] >= 400 ? (int) $statusResponse['status'] : 502);
            return;
        }

        $provider = is_array($statusResponse['data'] ?? null) ? $statusResponse['data'] : [];
        $status = (string) ($provider['status'] ?? $provider['transaction_status'] ?? '');
        if ($status === '') {
            $msg = strtoupper((string) ($provider['message'] ?? ''));
            if (str_contains($msg, 'SUCESSO') || str_contains($msg, 'SUCCESS')) {
                $status = 'SUCCESS';
            }
        }
        $paid = self::finalizeDebitPayment($order, $status, $debitoReference, [
            'source' => 'status_poll',
            'provider_response' => $provider,
        ]);

        AuditHelper::log((int) $user['id'], 'invoice:debit:status', [
            'order_id' => $orderId,
            'invoice_id' => (int) ($order['invoice_id'] ?? 0),
            'debito_reference' => $debitoReference,
            'status' => $status,
            'paid' => $paid,
            'provider_response' => $provider,
        ]);

        Response::json([
            'message' => $paid ? 'Pagamento confirmado automaticamente' : 'Estado consultado',
            'debito_reference' => $debitoReference,
            'status' => $status,
            'paid' => $paid,
            'provider_response' => $provider,
        ]);
    }

    private static function rateLimitExceeded(string $scope, int $maxAttempts, int $windowSec): bool
    {
        $dir = sys_get_temp_dir() . '/flux_rate_limits';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $file = $dir . '/' . hash('sha256', $scope) . '.json';
        $now = time();
        $history = [];
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $history = array_values(array_filter($decoded, fn($t) => is_numeric($t) && ((int) $t) > ($now - $windowSec)));
            }
        }

        if (count($history) >= $maxAttempts) {
            return true;
        }

        $history[] = $now;
        @file_put_contents($file, json_encode($history), LOCK_EX);
        return false;
    }

    private static function maskMsisdn(string $msisdn): string
    {
        $clean = preg_replace('/\D+/', '', $msisdn);
        if ($clean === '') return '';
        if (strlen($clean) <= 4) return str_repeat('*', strlen($clean));
        return substr($clean, 0, 3) . str_repeat('*', max(0, strlen($clean) - 5)) . substr($clean, -2);
    }

    private static function callbackAuthenticated(string $rawBody): bool
    {
        $secret = trim((string) Config::get('DEBITO_CALLBACK_SECRET', ''));
        if ($secret === '') {
            return false;
        }

        $querySecret = (string) ($_GET['secret'] ?? '');
        if ($querySecret !== '' && hash_equals($secret, $querySecret)) {
            return true;
        }

        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $signature = (string) ($headers['X-Debito-Signature'] ?? $headers['x-debito-signature'] ?? '');
        $timestamp = (string) ($headers['X-Debito-Timestamp'] ?? $headers['x-debito-timestamp'] ?? '');
        if ($timestamp !== '' && ctype_digit($timestamp)) {
            $ts = (int) $timestamp;
            if (abs(time() - $ts) > 300) {
                return false;
            }
        }

        if ($signature !== '') {
            $expectedRaw = hash_hmac('sha256', $rawBody, $secret);
            $expectedTs = $timestamp !== '' ? hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret) : '';
            if (hash_equals($expectedRaw, $signature) || ($expectedTs !== '' && hash_equals($expectedTs, $signature))) {
                return true;
            }
        }

        $legacy = (string) ($headers['X-Debito-Callback-Secret'] ?? $headers['x-debito-callback-secret'] ?? $headers['X-Callback-Secret'] ?? $headers['x-callback-secret'] ?? '');
        return $legacy !== '' && hash_equals($secret, $legacy);
    }

    private static function findOrderIdByDebitoReference(string $debitoReference): int
    {
        $ref = trim($debitoReference);
        if ($ref === '') return 0;
        try {
            $pdo = \App\Config\Database::pdo();
            $stmt = $pdo->prepare("SELECT meta FROM audits WHERE action='invoice:debit:start' ORDER BY id DESC LIMIT 300");
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $meta = json_decode((string) ($row['meta'] ?? '{}'), true);
                if (!is_array($meta)) continue;
                $provider = is_array($meta['provider_response'] ?? null) ? $meta['provider_response'] : [];
                $candidate = (string) ($provider['debito_reference'] ?? $meta['debito_reference'] ?? '');
                if ($candidate !== '' && hash_equals($candidate, $ref)) {
                    return (int) ($meta['order_id'] ?? 0);
                }
            }
        } catch (\Throwable $e) {}
        return 0;
    }

    private static function parseOrderIdFromDebitoPayload(array $payload): int
    {
        $candidates = [
            $payload['order_id'] ?? null,
            $payload['orderId'] ?? null,
            $payload['metadata']['order_id'] ?? null,
            $payload['data']['order_id'] ?? null,
        ];

        foreach ($candidates as $c) {
            if (is_numeric($c) && (int) $c > 0) {
                return (int) $c;
            }
        }

        $notes = (string) ($payload['internal_notes'] ?? $payload['internalNotes'] ?? $payload['metadata']['internal_notes'] ?? '');
        if ($notes !== '' && preg_match('/order_id\s*=\s*(\d+)/i', $notes, $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    private static function statusIsPaid(string $status): bool
    {
        $normalized = strtoupper(trim($status));
        return in_array($normalized, ['PAID', 'SUCCESS', 'SUCCEEDED', 'COMPLETED', 'APPROVED', 'SUCCESSFUL', 'DONE', 'CONFIRMED'], true);
    }

    public static function debitCallback(): void
    {
        $raw = file_get_contents('php://input') ?: '';
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            Response::json(['message' => 'Payload inválido'], 400);
            return;
        }

        $cbIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (self::rateLimitExceeded('debitcb:' . $cbIp, 180, 60)) {
            Response::json(['message' => 'Demasiados callbacks em curto período'], 429);
            return;
        }

        if (!self::callbackAuthenticated($raw)) {
            Response::json(['message' => 'Assinatura do callback inválida'], 401);
            return;
        }

        $debitoReference = (string) ($payload['debito_reference'] ?? $payload['reference'] ?? $payload['transaction_reference'] ?? '');
        if (trim($debitoReference) === '') {
            Response::json(['message' => 'debito_reference em falta'], 422);
            return;
        }
        $status = (string) ($payload['status'] ?? $payload['transaction_status'] ?? '');
        if ($status === '') {
            $msg = strtoupper((string) ($payload['message'] ?? ''));
            if (str_contains($msg, 'SUCESSO') || str_contains($msg, 'SUCCESS')) {
                $status = 'SUCCESS';
            }
        }
        $orderId = self::parseOrderIdFromDebitoPayload($payload);
        if ($orderId <= 0) {
            $orderId = self::findOrderIdByDebitoReference($debitoReference);
        }

        if ($orderId <= 0) {
            AuditHelper::log(null, 'invoice:debit:callback:invalid', ['payload' => $payload]);
            Response::json(['message' => 'order_id não identificado no callback'], 422);
            return;
        }

        $order = Order::findWithInvoice($orderId);
        if (!$order || empty($order['invoice_id'])) {
            Response::json(['message' => 'Encomenda/fatura não encontrada'], 404);
            return;
        }

        if (self::finalizeDebitPayment($order, $status, $debitoReference, ['source' => 'webhook', 'payload' => $payload])) {
            Response::json(['message' => 'Pagamento confirmado e fatura atualizada']);
            return;
        }

        AuditHelper::log((int) ($order['user_id'] ?? 0), 'invoice:debit:callback', [
            'order_id' => $orderId,
            'invoice_id' => (int) $order['invoice_id'],
            'debito_reference' => $debitoReference,
            'status' => $status,
            'payload' => $payload,
        ]);

        Response::json(['message' => 'Callback recebido']);
    }

    public static function index(): void
    {
        $user = Auth::requireUser();
        $orders = Order::listForUser($user['id']);
        // decodifica materiais para facilidade no front
        $orders = array_map(function ($o) {
            $m = $o['materiais_uploads'] ?? null;
            $decoded = [];
            if ($m && is_string($m)) {
                $d = json_decode($m, true);
                if (is_array($d)) $decoded = $d;
            }
            $o['materiais_array'] = $decoded;
            return $o;
        }, $orders);
        Response::json(['orders' => $orders]);
    }

    public static function deliveries(): void
    {
        $user = Auth::requireUser();
        $orders = Order::listForUser($user['id']);

        $deliverables = array_map(function ($o) {
            $final = $o['final_file'] ?? null;
            $finalFiles = [];
            if (is_string($final) && $final !== '') {
                $decodedFinal = json_decode($final, true);
                if (is_array($decodedFinal)) {
                    $finalFiles = array_values(array_filter($decodedFinal, fn($f) => is_string($f) && $f !== ''));
                } else {
                    $finalFiles = [$final];
                }
            }
            $o['final_files'] = $finalFiles;

            $m = $o['materiais_uploads'] ?? null;
            $decoded = [];
            if ($m && is_string($m)) {
                $d = json_decode($m, true);
                if (is_array($d)) $decoded = $d;
            }
            $o['materiais_array'] = $decoded;
            return $o;
        }, $orders);

        $deliverables = array_values(array_filter($deliverables, fn($o) => !empty($o['final_files'])));
        Response::json(['documents' => $deliverables]);
    }

    public static function feedback(): void
    {
        $user = Auth::requireUser();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        if (empty($data['order_id']) || empty($data['rating'])) {
            Response::json(['message' => 'Feedback incompleto'], 400);
            return;
        }
        $order = Order::findWithInvoice((int) $data['order_id']);
        if (!$order || $order['user_id'] !== $user['id']) {
            Response::json(['message' => 'Encomenda inválida'], 404);
            return;
        }
        Feedback::create([
            'order_id' => (int) $data['order_id'],
            'user_id' => $user['id'],
            'rating' => (int) $data['rating'],
            'grade' => $data['grade'] ?? null,
            'comment' => $data['comment'] ?? null,
        ]);
        AuditHelper::log($user['id'], 'feedback:create', ['order_id' => $data['order_id']]);
        Response::json(['message' => 'Feedback registado']);
    }

    public static function affiliateSummary(): void
    {
        $user = Auth::requireUser();
        $code = $user['referral_code'] ?? null;
        if (!$code) {
            Response::json(['commissions' => [], 'totals' => ['pending' => 0, 'approved' => 0, 'paid' => 0], 'payouts' => []]);
            return;
        }
        $commissions = AffiliateCommission::listForCode($code);
        $totals = AffiliateCommission::totalsForCode($code);
        $payouts = AffiliatePayout::listForUser($user['id']);
        $outstanding = AffiliatePayout::outstandingForUser($user['id']);
        $available = max(0, AffiliateCommission::totalAvailableForCode($code) - $outstanding);
        $pdo = \App\Config\Database::pdo();
        $affStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE referred_by = :code');
        $affStmt->execute([':code' => $code]);
        $referredCount = (int) $affStmt->fetchColumn();

        $clicks = Audit::affiliateClickStats($code);

        Response::json([
            'commissions' => $commissions,
            'totals' => $totals,
            'payouts' => $payouts,
            'code' => $code,
            'available' => $available,
            'outstanding' => $outstanding,
            'stats' => [
                'referred_count' => $referredCount,
                'clicks_total' => $clicks['total'] ?? 0,
                'clicks_unique' => $clicks['unique'] ?? 0,
                'clicks_today' => $clicks['today'] ?? 0,
            ],
        ]);
    }

    public static function requestPayout(): void
    {
        $user = Auth::requireUser();
        $code = $user['referral_code'] ?? null;
        if (!$code) {
            Response::json(['message' => 'Não existe código de afiliado'], 400);
            return;
        }
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $metodo = $body['metodo'] ?? 'mpesa';
        $notes = $body['notes'] ?? null;
        $mpesa = $body['mpesa'] ?? null;
        $outstanding = AffiliatePayout::outstandingForUser($user['id']);
        $approved = AffiliateCommission::totalAvailableForCode($code);
        $available = max(0, $approved - $outstanding);
        if ($available <= 0) {
            Response::json(['message' => 'Sem saldo disponível para levantamento'], 400);
            return;
        }
        $payoutId = AffiliatePayout::create($user['id'], $available, 'SOLICITADO', $metodo, $notes, $mpesa);
        AuditHelper::log($user['id'], 'affiliate:payout', ['payout_id' => $payoutId, 'valor' => $available]);
        Mailer::send($user['email'], 'Pedido de levantamento recebido', 'Solicitação #' . $payoutId . ' no valor de ' . $available . ' MZN.');
        $adminEmail = Config::get('ADMIN_NOTIFY_EMAIL');
        if ($adminEmail) {
            Mailer::send($adminEmail, 'Novo levantamento de afiliado', 'O afiliado ' . $user['email'] . ' solicitou ' . $available . ' MZN para ' . ($mpesa ?: 'conta não informada'));
        }
        Response::json(['message' => 'Pedido registado', 'payout_id' => $payoutId]);
    }


    public static function trackAffiliateClick(): void
    {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $code = trim((string) ($body['code'] ?? ''));
        $visitor = trim((string) ($body['visitor'] ?? ''));

        if ($code === '') {
            Response::json(['message' => 'Código de afiliado obrigatório'], 400);
            return;
        }

        $refUser = User::findByReferralCode($code);
        if (!$refUser) {
            Response::json(['message' => 'Código inválido'], 404);
            return;
        }

        AuditHelper::log(null, 'affiliate:click', [
            'code' => $code,
            'visitor' => $visitor ?: null,
            'source' => $_SERVER['HTTP_REFERER'] ?? null,
            'ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);

        if ($visitor !== '') {
            try {
                $pdo = \App\Config\Database::pdo();
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM audits WHERE action='affiliate:click' AND JSON_UNQUOTE(JSON_EXTRACT(meta, '$.code')) = :code AND JSON_UNQUOTE(JSON_EXTRACT(meta, '$.visitor')) = :visitor AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
                $stmt->execute([':code' => $code, ':visitor' => $visitor]);
                $count = (int) $stmt->fetchColumn();
                if ($count >= 12) {
                    AuditHelper::log(null, 'affiliate:fraud:anomaly', [
                        'code' => $code,
                        'visitor' => $visitor,
                        'clicks_10m' => $count,
                    ]);
                }
            } catch (\Throwable $e) {
                error_log('Affiliate anomaly check error: ' . $e->getMessage());
            }
        }

        Response::json(['message' => 'click tracked']);
    }

    public static function notifications(): void
    {
        $user = Auth::requireUser();
        $records = \App\Models\Audit::listForUser($user['id']);
        Response::json(['notifications' => array_map(function ($row) {
            $meta = json_decode($row['meta'] ?? '[]', true);
            return [
                'action' => $row['action'],
                'meta' => $meta,
                'created_at' => $row['created_at'] ?? null,
            ];
        }, $records)]);
    }

    public static function show(int $orderId): void
    {
        $user = Auth::requireUser();
        $order = Order::findWithInvoice($orderId);
        if (!$order) {
            Response::json(['message' => 'Encomenda não encontrada'], 404);
            return;
        }
        if ($order['user_id'] !== $user['id'] && $user['role'] !== 'admin') {
            Response::json(['message' => 'Acesso negado'], 403);
            return;
        }

        $feedback = Feedback::listForOrder($orderId);

        // decodificar materiais e invoice detalhes
        $materials = [];
        if (!empty($order['materiais_uploads']) && is_string($order['materiais_uploads'])) {
            $m = json_decode($order['materiais_uploads'], true);
            if (is_array($m)) $materials = $m;
        }
        $invoiceDetails = null;
        if (!empty($order['invoice_id'])) {
            $inv = Invoice::findById((int) $order['invoice_id']);
            if ($inv) {
                $invoiceDetails = $inv;
                if (!empty($inv['detalhes']) && is_string($inv['detalhes'])) {
                    $decoded = json_decode($inv['detalhes'], true);
                    if (is_array($decoded)) $invoiceDetails['detalhes_decoded'] = $decoded;
                }
            }
        }

        $order['materiais_array'] = $materials;

        Response::json(['order' => $order, 'feedback' => $feedback, 'invoice_details' => $invoiceDetails]);
    }

    public static function invoicePdf(int $orderId): void
    {
        $user = Auth::requireUser();
        $order = Order::findWithInvoice($orderId);
        if (!$order) {
            http_response_code(404);
            echo 'Fatura não encontrada';
            return;
        }
        if ($order['user_id'] !== $user['id'] && $user['role'] !== 'admin') {
            http_response_code(403);
            echo 'Acesso negado';
            return;
        }

        $numero = $order['invoice_numero'] ?? ('FAT-' . $orderId);
        $nome = 'fatura-' . $numero . '.pdf';

        // tenta buscar a fatura completa
        $invoice = null;
        if (!empty($order['invoice_id'])) {
            $invoice = Invoice::findById((int) $order['invoice_id']);
        }

        $invoiceDetails = null;
        if ($invoice && !empty($invoice['detalhes'])) {
            $decoded = is_string($invoice['detalhes']) ? json_decode($invoice['detalhes'], true) : $invoice['detalhes'];
            if (is_array($decoded)) $invoiceDetails = $decoded;
        }

        $materials = [];
        if (!empty($order['materiais_uploads']) && is_string($order['materiais_uploads'])) {
            $m = json_decode($order['materiais_uploads'], true);
            if (is_array($m)) $materials = $m;
        }

        // logo SVG inline
        $logoSvg = '<svg width="160" height="48" viewBox="0 0 320 96" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Logo">
      <rect width="320" height="96" rx="12" fill="#0b63e6"/>
      <text x="36" y="64" font-family="Arial, Helvetica, sans-serif" font-size="44" fill="#fff" font-weight="700">Livre-se</text>
    </svg>';

        // valor a exibir
        $valor_total = $invoice['valor_total'] ?? $order['valor_total'] ?? $order['total'] ?? null;
        $valor_display = $valor_total !== null ? number_format((float)$valor_total, 2, '.', ',') : '—';

        // montar HTML mais profissional
        $html = '<!doctype html>
<html lang="pt">
<head>
  <meta charset="utf-8">
  <title>Fatura ' . htmlspecialchars($numero) . '</title>
  <style>
    body { font-family: Arial, Helvetica, sans-serif; color: #111; padding: 28px; }
    .header { display:flex; align-items:center; justify-content:space-between; gap:12px; }
    .company { text-align: right; font-size: 13px; color: #333; }
    h1 { color: #0b63e6; margin: 8px 0 2px; }
    .muted { color: #666; font-size: 12px; }
    .info { margin-top: 12px; display:flex; gap:20px; align-items:flex-start; }
    .box { padding: 10px; border-radius: 8px; border: 1px solid #e6eefc; background: #fbfdff; }
    table { width: 100%; border-collapse: collapse; margin-top: 14px; }
    th, td { padding: 10px 8px; border-bottom: 1px solid #e8eef6; text-align: left; }
    .right { text-align: right; }
    .total-row { font-weight: 700; background: #f5f9ff; }
    ul { margin: 8px 0 0 18px; }
    .small { font-size: 12px; color: #444; }
  </style>
</head>
<body>
  <div class="header">
    <div class="logo">' . $logoSvg . '</div>
    <div class="company">
      <div><strong>Livre-se das Tarefas</strong></div>
      <div class="muted">M-Pesa: 851619970 · Titular: Maria António Chicavele</div>
      <div class="muted">Email: suporte@fluxosoftwares.com</div>
    </div>
  </div>

  <h1>Fatura ' . htmlspecialchars($numero) . '</h1>
  <div class="muted">Gerada em ' . date('Y-m-d H:i') . '</div>

  <div class="info">
    <div class="box" style="flex:1">
      <strong>Dados do cliente</strong>
      <div>' . htmlspecialchars($user['name'] ?? $user['email']) . '</div>
      <div class="muted">' . htmlspecialchars($user['email'] ?? '') . '</div>
    </div>

    <div class="box" style="width:320px">
      <strong>Resumo da fatura</strong>
      <div class="muted">Número:</div>
      <div>' . htmlspecialchars($numero) . '</div>
      <div class="muted" style="margin-top:6px">Vencimento:</div>
      <div>' . htmlspecialchars($invoice['vencimento'] ?? '—') . '</div>
    </div>
  </div>

  <h3>Detalhes da encomenda</h3>
  <table>
    <thead>
      <tr><th>Descrição</th><th class="right">Qtd</th><th class="right">Preço unit. (MZN)</th><th class="right">Subtotal (MZN)</th></tr>
    </thead>
    <tbody>';

        // se houver detalhes do invoice com base e páginas, mostrar linha detalhada
        if (is_array($invoiceDetails) && isset($invoiceDetails['base']) && isset($order['paginas'])) {
            $base = (float) ($invoiceDetails['base'] ?? 0);
            $pages = (int) ($order['paginas'] ?? 1);
            $subtotal = round($base * $pages, 2);
            $html .= '<tr>
                <td>' . htmlspecialchars($order['tipo'] ?? 'Serviço académico') . ' · ' . htmlspecialchars($order['area'] ?? '') . '</td>
                <td class="right">' . $pages . '</td>
                <td class="right">' . number_format($base, 2, '.', ',') . '</td>
                <td class="right">' . number_format($subtotal, 2, '.', ',') . '</td>
            </tr>';
        } else {
            $html .= '<tr>
                <td>' . htmlspecialchars($order['tipo'] ?? 'Serviço') . '</td>
                <td class="right">—</td>
                <td class="right">—</td>
                <td class="right">' . $valor_display . '</td>
            </tr>';
        }

        if (count($materials) > 0) {
            $html .= '<tr><td colspan="4"><strong>Materiais anexados:</strong><ul>';
            foreach ($materials as $m) {
                $html .= '<li>' . htmlspecialchars($m) . '</li>';
            }
            $html .= '</ul></td></tr>';
        }

        $html .= '
    </tbody>
    <tfoot>
      <tr class="total-row"><td colspan="3" class="right">Total</td><td class="right">' . $valor_display . ' MZN</td></tr>
    </tfoot>
  </table>

  <h3>Campos preenchidos no pedido</h3>
  <table>
    <tbody>
      <tr><td><strong>Tipo</strong></td><td>' . htmlspecialchars($order['tipo'] ?? '—') . '</td></tr>
      <tr><td><strong>Área</strong></td><td>' . htmlspecialchars($order['area'] ?? '—') . '</td></tr>
      <tr><td><strong>Nível</strong></td><td>' . htmlspecialchars($order['nivel'] ?? '—') . '</td></tr>
      <tr><td><strong>Páginas</strong></td><td>' . htmlspecialchars((string)($order['paginas'] ?? '—')) . '</td></tr>
      <tr><td><strong>Norma</strong></td><td>' . htmlspecialchars($order['norma'] ?? '—') . '</td></tr>
      <tr><td><strong>Complexidade</strong></td><td>' . htmlspecialchars($order['complexidade'] ?? '—') . '</td></tr>
      <tr><td><strong>Urgência</strong></td><td>' . htmlspecialchars($order['urgencia'] ?? '—') . '</td></tr>
      <tr><td><strong>Prazo desejado</strong></td><td>' . htmlspecialchars($order['prazo_entrega'] ?? '—') . '</td></tr>
      <tr><td><strong>Descrição</strong></td><td>' . nl2br(htmlspecialchars($order['descricao'] ?? '—')) . '</td></tr>
      <tr><td><strong>Materiais informados</strong></td><td>' . htmlspecialchars($order['materiais_info'] ?? 'Não') . '</td></tr>
      <tr><td><strong>% uso materiais</strong></td><td>' . htmlspecialchars((string)($order['materiais_percentual'] ?? '—')) . '</td></tr>
    </tbody>
  </table>

  <h3>Instruções de pagamento</h3>
  <p class="small">Pagar via M-Pesa nº <strong>851619970</strong> · Titular: <strong>Maria António Chicavele</strong></p>
  <p class="muted small">Se já pagou, envie o comprovativo na página da fatura para acelerar a validação.</p>
</body>
</html>';

        // tentar gerar PDF com Dompdf se disponível
        if (class_exists('\Dompdf\Dompdf')) {
            try {
                $dompdf = new \Dompdf\Dompdf();
                $dompdf->set_option('isRemoteEnabled', true);
                $dompdf->loadHtml($html);
                $dompdf->setPaper('A4', 'portrait');
                $dompdf->render();
                // força download do PDF
                $dompdf->stream($nome, ['Attachment' => true]);
                return;
            } catch (\Throwable $e) {
                error_log('Dompdf error: ' . $e->getMessage());
                // fallback para HTML legível abaixo
            }
        }

        // fallback: devolver HTML legível (se não houver Dompdf)
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
    }
}
