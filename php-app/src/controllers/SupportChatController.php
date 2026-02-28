<?php
namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Auth;
use App\Helpers\Response;

class SupportChatController
{
    private static function storePath(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir . '/support-chat.json';
    }

    private static function now(): string
    {
        return date('c');
    }

    private static function readStore(): array
    {
        $path = self::storePath();
        if (!file_exists($path)) {
            return ['sessions' => []];
        }
        $fh = fopen($path, 'r');
        if (!$fh) return ['sessions' => []];
        flock($fh, LOCK_SH);
        $raw = stream_get_contents($fh) ?: '';
        flock($fh, LOCK_UN);
        fclose($fh);
        $data = json_decode($raw, true);
        return is_array($data) ? $data : ['sessions' => []];
    }

    private static function writeStore(array $data): void
    {
        $path = self::storePath();
        $fh = fopen($path, 'c+');
        if (!$fh) {
            throw new \RuntimeException('Não foi possível abrir storage de chat');
        }
        flock($fh, LOCK_EX);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    private static function requireAdmin(): array
    {
        $user = Auth::requireUser();
        if (($user['role'] ?? '') !== 'admin') {
            Response::json(['message' => 'Acesso negado'], 403);
            exit;
        }
        return $user;
    }

    private static function findSessionIndex(array $sessions, string $sessionId): int
    {
        foreach ($sessions as $idx => $s) {
            if (($s['id'] ?? '') === $sessionId) return $idx;
        }
        return -1;
    }

    private static function customerTokenFromRequest(): string
    {
        $headers = getallheaders();
        return trim($headers['X-Chat-Token'] ?? $headers['x-chat-token'] ?? ($_GET['token'] ?? $_POST['token'] ?? ''));
    }

    public static function start(): void
    {
        $name = trim($_POST['name'] ?? 'Visitante');
        $email = trim($_POST['email'] ?? '');
        $message = trim($_POST['message'] ?? '');

        if ($name === '') $name = 'Visitante';

        $id = 'chat_' . bin2hex(random_bytes(6));
        $token = bin2hex(random_bytes(16));

        $session = [
            'id' => $id,
            'token' => $token,
            'customer_name' => $name,
            'customer_email' => $email,
            'status' => 'open',
            'agent_id' => null,
            'agent_name' => null,
            'created_at' => self::now(),
            'updated_at' => self::now(),
            'rating' => null,
            'rating_comment' => null,
            'messages' => [],
        ];

        if ($message !== '') {
            $session['messages'][] = [
                'id' => uniqid('msg_', true),
                'sender_type' => 'customer',
                'sender_name' => $name,
                'message' => $message,
                'attachment' => null,
                'created_at' => self::now(),
            ];
        }

        $store = self::readStore();
        $store['sessions'][] = $session;
        self::writeStore($store);

        Response::json(['session_id' => $id, 'token' => $token, 'status' => $session['status']]);
    }

    public static function addMessage(): void
    {
        $sessionId = trim($_POST['session_id'] ?? '');
        $message = trim($_POST['message'] ?? '');
        if ($sessionId === '' || ($message === '' && empty($_FILES['attachment']['tmp_name']))) {
            Response::json(['message' => 'session_id e conteúdo são obrigatórios'], 400);
            return;
        }

        $store = self::readStore();
        $idx = self::findSessionIndex($store['sessions'], $sessionId);
        if ($idx < 0) {
            Response::json(['message' => 'Sessão não encontrada'], 404);
            return;
        }

        $session = $store['sessions'][$idx];
        $isAdmin = false;
        $actorName = $session['customer_name'] ?? 'Cliente';
        $actorType = 'customer';

        $authUser = Auth::userFromBearer();
        if ($authUser && ($authUser['role'] ?? '') === 'admin') {
            $isAdmin = true;
            $admin = $authUser;
            $actorType = 'admin';
            $actorName = $authUser['name'] ?? $authUser['email'] ?? 'Agente';
        } else {
            $token = self::customerTokenFromRequest();
            if (($session['token'] ?? '') !== $token) {
                Response::json(['message' => 'Token de chat inválido'], 401);
                return;
            }
        }

        if ($session['status'] === 'closed') {
            Response::json(['message' => 'Chat encerrado'], 400);
            return;
        }

        $attachment = null;
        if (!empty($_FILES['attachment']['tmp_name'])) {
            $dir = dirname(__DIR__, 2) . '/uploads/support-chat';
            if (!is_dir($dir)) mkdir($dir, 0775, true);
            $safeName = uniqid('chat_') . '-' . preg_replace('/[^a-zA-Z0-9\.\-_]/', '_', $_FILES['attachment']['name']);
            $dest = $dir . '/' . $safeName;
            if (move_uploaded_file($_FILES['attachment']['tmp_name'], $dest)) {
                $attachment = '/uploads/support-chat/' . $safeName;
            }
        }

        $msg = [
            'id' => uniqid('msg_', true),
            'sender_type' => $actorType,
            'sender_name' => $actorName,
            'message' => $message,
            'attachment' => $attachment,
            'created_at' => self::now(),
        ];

        $store['sessions'][$idx]['messages'][] = $msg;
        $store['sessions'][$idx]['updated_at'] = self::now();

        if ($isAdmin && empty($store['sessions'][$idx]['agent_id'])) {
            $store['sessions'][$idx]['agent_id'] = $admin['id'];
            $store['sessions'][$idx]['agent_name'] = $actorName;
        }

        self::writeStore($store);
        Response::json(['message' => 'Mensagem enviada']);
    }

    public static function messages(): void
    {
        $sessionId = trim($_GET['session_id'] ?? '');
        if ($sessionId === '') {
            Response::json(['message' => 'session_id obrigatório'], 400);
            return;
        }

        $store = self::readStore();
        $idx = self::findSessionIndex($store['sessions'], $sessionId);
        if ($idx < 0) {
            Response::json(['message' => 'Sessão não encontrada'], 404);
            return;
        }
        $session = $store['sessions'][$idx];

        $authUser = Auth::userFromBearer();
        $allowed = $authUser && (($authUser['role'] ?? '') === 'admin');
        if (!$allowed) {
            $allowed = (($session['token'] ?? '') === self::customerTokenFromRequest());
        }

        if (!$allowed) {
            Response::json(['message' => 'Não autorizado'], 401);
            return;
        }

        Response::json([
            'session' => [
                'id' => $session['id'],
                'status' => $session['status'],
                'customer_name' => $session['customer_name'],
                'agent_name' => $session['agent_name'],
                'rating' => $session['rating'],
                'rating_comment' => $session['rating_comment'],
            ],
            'messages' => $session['messages'] ?? [],
        ]);
    }

    public static function listSessions(): void
    {
        self::requireAdmin();
        $status = trim($_GET['status'] ?? '');
        $store = self::readStore();
        $sessions = $store['sessions'] ?? [];
        if ($status !== '') {
            $sessions = array_values(array_filter($sessions, fn($s) => ($s['status'] ?? '') === $status));
        }

        usort($sessions, fn($a, $b) => strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''));
        $sessions = array_map(function ($s) {
            return [
                'id' => $s['id'],
                'status' => $s['status'],
                'customer_name' => $s['customer_name'],
                'customer_email' => $s['customer_email'],
                'agent_id' => $s['agent_id'],
                'agent_name' => $s['agent_name'],
                'updated_at' => $s['updated_at'],
                'last_message' => end($s['messages'])['message'] ?? '',
                'rating' => $s['rating'],
            ];
        }, $sessions);

        Response::json(['sessions' => $sessions]);
    }

    public static function claim(): void
    {
        $admin = self::requireAdmin();
        $sessionId = trim($_POST['session_id'] ?? '');
        $store = self::readStore();
        $idx = self::findSessionIndex($store['sessions'], $sessionId);
        if ($idx < 0) {
            Response::json(['message' => 'Sessão não encontrada'], 404);
            return;
        }
        $store['sessions'][$idx]['agent_id'] = $admin['id'];
        $store['sessions'][$idx]['agent_name'] = $admin['name'] ?? $admin['email'];
        $store['sessions'][$idx]['updated_at'] = self::now();
        $store['sessions'][$idx]['messages'][] = [
            'id' => uniqid('msg_', true),
            'sender_type' => 'system',
            'sender_name' => 'Sistema',
            'message' => 'Atendimento assumido por ' . ($store['sessions'][$idx]['agent_name'] ?? 'Agente'),
            'attachment' => null,
            'created_at' => self::now(),
        ];
        self::writeStore($store);
        Response::json(['message' => 'Atendimento assumido']);
    }

    public static function transfer(): void
    {
        $admin = self::requireAdmin();
        $sessionId = trim($_POST['session_id'] ?? '');
        $targetId = (int) ($_POST['agent_id'] ?? 0);
        if (!$targetId) {
            Response::json(['message' => 'agent_id obrigatório'], 400);
            return;
        }

        $stmt = Database::pdo()->prepare('SELECT id, name, email FROM users WHERE id = :id AND role = "admin" AND active = 1 LIMIT 1');
        $stmt->execute([':id' => $targetId]);
        $target = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$target) {
            Response::json(['message' => 'Agente não encontrado'], 404);
            return;
        }

        $store = self::readStore();
        $idx = self::findSessionIndex($store['sessions'], $sessionId);
        if ($idx < 0) {
            Response::json(['message' => 'Sessão não encontrada'], 404);
            return;
        }

        $previous = $store['sessions'][$idx]['agent_name'] ?? ($admin['name'] ?? $admin['email']);
        $store['sessions'][$idx]['agent_id'] = (int) $target['id'];
        $store['sessions'][$idx]['agent_name'] = $target['name'] ?? $target['email'];
        $store['sessions'][$idx]['updated_at'] = self::now();
        $store['sessions'][$idx]['messages'][] = [
            'id' => uniqid('msg_', true),
            'sender_type' => 'system',
            'sender_name' => 'Sistema',
            'message' => 'Conversa transferida de ' . $previous . ' para ' . ($store['sessions'][$idx]['agent_name'] ?? 'Agente'),
            'attachment' => null,
            'created_at' => self::now(),
        ];

        self::writeStore($store);
        Response::json(['message' => 'Conversa transferida']);
    }

    public static function closeSession(): void
    {
        self::requireAdmin();
        $sessionId = trim($_POST['session_id'] ?? '');
        $store = self::readStore();
        $idx = self::findSessionIndex($store['sessions'], $sessionId);
        if ($idx < 0) {
            Response::json(['message' => 'Sessão não encontrada'], 404);
            return;
        }

        $store['sessions'][$idx]['status'] = 'waiting_rating';
        $store['sessions'][$idx]['updated_at'] = self::now();
        $store['sessions'][$idx]['messages'][] = [
            'id' => uniqid('msg_', true),
            'sender_type' => 'system',
            'sender_name' => 'Sistema',
            'message' => 'Atendimento finalizado pelo agente. Avalie este chat de 1 a 5 estrelas.',
            'attachment' => null,
            'created_at' => self::now(),
        ];

        self::writeStore($store);
        Response::json(['message' => 'Chat finalizado e aguardando avaliação']);
    }

    public static function rate(): void
    {
        $sessionId = trim($_POST['session_id'] ?? '');
        $rating = (int) ($_POST['rating'] ?? 0);
        $comment = trim($_POST['comment'] ?? '');

        if ($sessionId === '' || $rating < 1 || $rating > 5) {
            Response::json(['message' => 'Dados inválidos para avaliação'], 400);
            return;
        }

        $store = self::readStore();
        $idx = self::findSessionIndex($store['sessions'], $sessionId);
        if ($idx < 0) {
            Response::json(['message' => 'Sessão não encontrada'], 404);
            return;
        }

        $token = self::customerTokenFromRequest();
        if (($store['sessions'][$idx]['token'] ?? '') !== $token) {
            Response::json(['message' => 'Token de chat inválido'], 401);
            return;
        }

        $store['sessions'][$idx]['rating'] = $rating;
        $store['sessions'][$idx]['rating_comment'] = $comment;
        $store['sessions'][$idx]['status'] = 'closed';
        $store['sessions'][$idx]['updated_at'] = self::now();
        $store['sessions'][$idx]['messages'][] = [
            'id' => uniqid('msg_', true),
            'sender_type' => 'customer',
            'sender_name' => $store['sessions'][$idx]['customer_name'] ?? 'Cliente',
            'message' => "Avaliação do atendimento: {$rating}/5" . ($comment ? " · {$comment}" : ''),
            'attachment' => null,
            'created_at' => self::now(),
        ];

        self::writeStore($store);
        Response::json(['message' => 'Avaliação registada']);
    }

    public static function agents(): void
    {
        self::requireAdmin();
        $rows = Database::pdo()->query('SELECT id, name, email FROM users WHERE role = "admin" AND active = 1 ORDER BY name')->fetchAll(\PDO::FETCH_ASSOC);
        Response::json(['agents' => $rows]);
    }
}
