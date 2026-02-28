<?php
namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Response;
use App\Helpers\AuditHelper;
use App\Helpers\Mailer;
use App\Models\Order;
use App\Models\Invoice;
use App\Models\User;
use App\Config\Config;
use App\Config\Database;

class CareerController
{
    /**
     * Cria um pedido de serviço de carreira (CV, CoverLetter, InternshipReport)
     */
    public static function createOrder(): void
    {
        // --- Garante resposta JSON mesmo em caso de erro fatal ---
        if (!headers_sent()) {
            header('Content-Type: application/json');
            ob_clean(); // descarta qualquer saída anterior
        }

        try {
            // 1. Autenticação obrigatória
            $user = Auth::requireUser();
            error_log('[Career] Iniciado para usuário: ' . $user['email'] . ' (ID: ' . $user['id'] . ')');

            // 2. Método deve ser POST
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                Response::json(['message' => 'Método não permitido'], 405);
                return;
            }

            // 3. Validar tipo de serviço
            if (empty($_POST['service_type'])) {
                Response::json(['message' => 'Tipo de serviço não especificado'], 400);
                return;
            }

            $type = $_POST['service_type'];
            $prices = [
                'CV' => 250.00,
                'CoverLetter' => 200.00,
                'InternshipReport' => 950.00,
            ];

            if (!isset($prices[$type])) {
                Response::json([
                    'message' => 'Tipo de serviço inválido. Escolha entre: CV, CoverLetter ou InternshipReport'
                ], 400);
                return;
            }

            $price = $prices[$type];

            // 4. Campos obrigatórios comuns
            $requiredFields = ['name', 'email'];
            foreach ($requiredFields as $field) {
                if (empty($_POST[$field])) {
                    Response::json(['message' => "Campo obrigatório faltando: {$field}"], 400);
                    return;
                }
            }

            // 5. Processar uploads (apenas para InternshipReport)
            $uploads = [];
            if ($type === 'InternshipReport' && isset($_FILES['evidence_files'])) {
                $uploads = self::handleUploads($_FILES['evidence_files']);
                // Para relatório de estágio, exigir pelo menos um arquivo
                if (empty($uploads)) {
                    Response::json([
                        'message' => 'Para relatório de estágio, é necessário enviar pelo menos um arquivo de evidência'
                    ], 400);
                    return;
                }
            }

            // 6. Montar descrição como array (será guardado como JSON na ordem)
            $descricao = [
                'nome'          => $_POST['name'] ?? '',
                'whatsapp'      => $_POST['whatsapp'] ?? '',
                'email'         => $_POST['email'] ?? '',
                'instituicao'   => $_POST['institution'] ?? '',
                'tutor'         => $_POST['tutor'] ?? '',
                'duracao'       => $_POST['duration'] ?? '',
                'notas'         => $_POST['notes'] ?? '',
                'uploads'       => $uploads,
                'tipo_servico'  => $type
            ];

            // 7. Criar a ordem
            error_log('[Career] Criando ordem...');
            $orderId = Order::create([
                'user_id'               => $user['id'],
                'tipo'                  => $type,
                'area'                  => 'Carreira',
                'nivel'                 => '',
                'paginas'               => 1,
                'norma'                 => null,
                'complexidade'          => 'basica',
                'urgencia'              => 'normal',
                'descricao'            => json_encode($descricao, JSON_UNESCAPED_UNICODE),
                'estado'               => 'PENDENTE_PAGAMENTO',
                'prazo_entrega'        => date('Y-m-d H:i:s', strtotime('+7 days')),
                'referred_by_code'     => null,
                'materiais_info'       => null,
                'materiais_percentual' => null,
                'materiais_uploads'    => !empty($uploads) ? json_encode($uploads, JSON_UNESCAPED_UNICODE) : null,
            ]);

            if (!$orderId) {
                throw new \RuntimeException('Falha ao inserir ordem no banco de dados');
            }
            error_log('[Career] Ordem criada ID: ' . $orderId);

            // 8. Criar a fatura com TODOS os detalhes do formulário
            $invoiceNumber = 'CAREER-' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT);
            $invoiceId = Invoice::create([
                'order_id'    => $orderId,
                'user_id'     => $user['id'],
                'numero'      => $invoiceNumber,
                'valor_total' => $price,
                'detalhes'    => [   // ← TODOS OS CAMPOS AQUI!
                    'service'       => $type,
                    'price'         => $price,
                    'description'   => 'Serviço de ' . $type,
                    'cliente'       => $_POST['name'] ?? $user['name'] ?? '',
                    'email'         => $_POST['email'] ?? $user['email'],
                    'whatsapp'      => $_POST['whatsapp'] ?? '',
                    'instituicao'   => $_POST['institution'] ?? '',
                    'tutor'         => $_POST['tutor'] ?? '',
                    'duracao'       => $_POST['duration'] ?? '',
                    'notas'         => $_POST['notes'] ?? '',
                    'uploads'       => $uploads,   // array de URLs
                ],
                'estado'      => 'EMITIDA',
                'vencimento'  => date('Y-m-d H:i:s', strtotime('+24 hours')),
            ]);

            if (!$invoiceId) {
                throw new \RuntimeException('Falha ao inserir fatura no banco de dados');
            }
            error_log('[Career] Fatura criada ID: ' . $invoiceId);

            // 9. Associar fatura à ordem
            Order::attachInvoice($orderId, $invoiceId);
            error_log('[Career] Fatura associada à ordem');

            // 10. Auditoria
            AuditHelper::log($user['id'], 'career:order', [
                'order_id' => $orderId,
                'type'     => $type,
                'price'    => $price
            ]);

            // 11. E-mail de confirmação para o cliente
            $clienteNome = htmlspecialchars($_POST['name'] ?? 'Cliente', ENT_QUOTES, 'UTF-8');
            $emailBody = "
                <h3>Olá {$clienteNome}!</h3>
                <p>O seu pedido de serviço de <strong>{$type}</strong> foi criado com sucesso.</p>
                <p><strong>Detalhes do pedido:</strong></p>
                <ul>
                    <li>Número do pedido: #{$orderId}</li>
                    <li>Número da fatura: {$invoiceNumber}</li>
                    <li>Valor: " . number_format($price, 2, ',', '.') . " MZN</li>
                    <li>Estado: Aguardando pagamento</li>
                </ul>
                <p>Para prosseguir, aceda à sua fatura e efetue o pagamento via M-Pesa.</p>
                <p><strong>Dados para pagamento:</strong></p>
                <ul>
                    <li>Número M-Pesa: 851619970</li>
                    <li>Titular: Maria António Chicavele</li>
                </ul>
                <p>Após o pagamento, envie o comprovativo na plataforma.</p>
                <p>Atenciosamente,<br>Equipa Livre-se das Tarefas</p>
            ";

            Mailer::send($user['email'], 'Pedido de Serviço Criado - ' . $type, $emailBody);

            // 12. Notificar administradores
            $adminEmails = User::adminEmails();
            $fallbackAdmin = Config::get('ADMIN_NOTIFY_EMAIL');
            if ($fallbackAdmin && !in_array($fallbackAdmin, $adminEmails)) {
                $adminEmails[] = $fallbackAdmin;
            }

            foreach ($adminEmails as $adminEmail) {
                Mailer::send($adminEmail, 'Novo Pedido de Serviço de Carreira', "
                    <h3>Novo pedido de serviço de carreira</h3>
                    <p><strong>Cliente:</strong> {$clienteNome} ({$user['email']})</p>
                    <p><strong>Serviço:</strong> {$type}</p>
                    <p><strong>Valor:</strong> " . number_format($price, 2, ',', '.') . " MZN</p>
                    <p><strong>ID do Pedido:</strong> #{$orderId}</p>
                    <p><strong>Fatura:</strong> {$invoiceNumber}</p>
                    <p>Verifique o painel administrativo para mais detalhes.</p>
                ");
            }

            // 13. Resposta de sucesso (JSON)
            Response::json([
                'success'         => true,
                'message'         => 'Pedido criado com sucesso!',
                'order_id'        => $orderId,
                'invoice_id'      => $invoiceId,
                'invoice_number'  => $invoiceNumber,
                'valor_total'     => $price
            ], 201);

        } catch (\Throwable $e) {
            // --- Captura QUALQUER erro (Exception ou Error) e devolve JSON ---
            error_log('[CareerController] ERRO NÃO TRATADO: ' . $e->getMessage());
            error_log('[CareerController] Ficheiro: ' . $e->getFile() . ':' . $e->getLine());
            error_log('[CareerController] Stack trace: ' . $e->getTraceAsString());

            $message = 'Erro interno ao processar o pedido. Tente novamente mais tarde.';
            $debug = null;

            if (Config::get('APP_ENV') === 'development') {
                $debug = [
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                    'trace'   => explode("\n", $e->getTraceAsString())
                ];
            }

            if (!headers_sent()) {
                header('Content-Type: application/json');
            }

            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => $message,
                'debug'   => $debug
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    /**
     * Processa os uploads de ficheiros (suporte a múltiplos)
     *
     * @param array $files Array $_FILES['evidence_files']
     * @return array Lista de caminhos relativos dos ficheiros guardados
     */
    private static function handleUploads(array $files): array
    {
        $uploaded = [];
        $dir = dirname(__DIR__, 2) . '/uploads/career';

        if (!is_dir($dir)) {
            if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                error_log('[Career] Falha ao criar diretório: ' . $dir);
                return $uploaded;
            }
        }

        // Normaliza para array de ficheiros (suporta upload único ou múltiplo)
        if (is_array($files['name'])) {
            $fileCount = count($files['name']);
            for ($i = 0; $i < $fileCount; $i++) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK || empty($files['tmp_name'][$i])) {
                    continue;
                }
                $file = [
                    'name'     => $files['name'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'size'     => $files['size'][$i],
                    'error'    => $files['error'][$i]
                ];
                $path = self::processSingleUpload($file, $dir);
                if ($path) {
                    $uploaded[] = $path;
                }
            }
        } else {
            // Upload único
            if ($files['error'] === UPLOAD_ERR_OK && !empty($files['tmp_name'])) {
                $path = self::processSingleUpload($files, $dir);
                if ($path) {
                    $uploaded[] = $path;
                }
            }
        }

        return $uploaded;
    }

    /**
     * Processa um único ficheiro: valida e move
     *
     * @param array $file Array com 'name', 'tmp_name', 'size'
     * @param string $destDir Diretório de destino
     * @return string|null Caminho relativo ou null em falha
     */
    private static function processSingleUpload(array $file, string $destDir): ?string
    {
        // Tamanho máximo: 10MB
        if ($file['size'] > 10 * 1024 * 1024) {
            error_log("[Career] Upload ignorado: ficheiro muito grande ({$file['name']})");
            return null;
        }

        // Extensões permitidas
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx'])) {
            error_log("[Career] Upload ignorado: extensão não permitida ({$ext})");
            return null;
        }

        $safeName = uniqid('career_') . '_' . preg_replace('/[^a-zA-Z0-9\.\-_]/', '_', $file['name']);
        $destPath = $destDir . '/' . $safeName;

        if (move_uploaded_file($file['tmp_name'], $destPath)) {
            error_log('[Career] Upload OK: ' . $destPath);
            return '/uploads/career/' . $safeName;
        }

        error_log('[Career] Falha ao mover arquivo: ' . $file['tmp_name'] . ' -> ' . $destPath);
        return null;
    }

    /**
     * Listar pedidos de carreira do usuário autenticado
     */
    public static function listOrders(): void
    {
        try {
            $user = Auth::requireUser();

            $pdo = Database::pdo();
            $stmt = $pdo->prepare('
                SELECT o.*, i.numero as invoice_numero, i.estado as invoice_estado, i.valor_total
                FROM orders o
                LEFT JOIN invoices i ON i.id = o.invoice_id
                WHERE o.user_id = :user_id AND o.area = "Carreira"
                ORDER BY o.created_at DESC
            ');
            $stmt->execute([':user_id' => $user['id']]);
            $orders = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($orders as &$order) {
                if (!empty($order['descricao']) && is_string($order['descricao'])) {
                    $decoded = json_decode($order['descricao'], true);
                    if (is_array($decoded)) {
                        $order['descricao_decoded'] = $decoded;
                    }
                }
                if (!empty($order['materiais_uploads']) && is_string($order['materiais_uploads'])) {
                    $decoded = json_decode($order['materiais_uploads'], true);
                    if (is_array($decoded)) {
                        $order['materiais_array'] = $decoded;
                    }
                }
            }

            Response::json(['orders' => $orders]);
        } catch (\Throwable $e) {
            error_log('[Career] listOrders error: ' . $e->getMessage());
            Response::json(['message' => 'Erro ao carregar pedidos'], 500);
        }
    }

    /**
     * Obter detalhes de um pedido específico
     */
    public static function show(int $orderId): void
    {
        try {
            $user = Auth::requireUser();

            $pdo = Database::pdo();
            $stmt = $pdo->prepare('
                SELECT o.*, i.numero as invoice_numero, i.estado as invoice_estado, i.valor_total,
                       i.comprovativo, i.vencimento
                FROM orders o
                LEFT JOIN invoices i ON i.id = o.invoice_id
                WHERE o.id = :order_id AND o.user_id = :user_id AND o.area = "Carreira"
                LIMIT 1
            ');
            $stmt->execute([':order_id' => $orderId, ':user_id' => $user['id']]);
            $order = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$order) {
                Response::json(['message' => 'Pedido não encontrado'], 404);
                return;
            }

            if (!empty($order['descricao']) && is_string($order['descricao'])) {
                $decoded = json_decode($order['descricao'], true);
                if (is_array($decoded)) {
                    $order['descricao_decoded'] = $decoded;
                }
            }
            if (!empty($order['materiais_uploads']) && is_string($order['materiais_uploads'])) {
                $decoded = json_decode($order['materiais_uploads'], true);
                if (is_array($decoded)) {
                    $order['materiais_array'] = $decoded;
                }
            }

            Response::json(['order' => $order]);
        } catch (\Throwable $e) {
            error_log('[Career] show error: ' . $e->getMessage());
            Response::json(['message' => 'Erro ao carregar pedido'], 500);
        }
    }
}