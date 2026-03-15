<?php
namespace App\Helpers;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Config\Config;
use App\Models\User;

class Auth
{

    private static function jwtSecret(): string
    {
        $raw = (string) Config::get('JWT_SECRET', '');
        if ($raw === '') {
            $raw = 'livre-se-das-tarefas-default-secret';
            error_log('JWT_SECRET ausente; a usar segredo derivado temporário. Configure JWT_SECRET no ambiente.');
        }

        // firebase/php-jwt >=7 exige chave HMAC com comprimento adequado.
        // Para manter compatibilidade com segredos legados curtos, derivamos 32 bytes estáveis.
        if (strlen($raw) < 32) {
            return hash('sha256', $raw, true);
        }

        return $raw;
    }

    public static function issueToken(array $user): string
    {
        $payload = [
            'sub' => $user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
            'iat' => time(),
            'exp' => time() + 60 * 60 * 24 * 3
        ];
        return JWT::encode($payload, self::jwtSecret(), 'HS256');
    }

    public static function requireUser(): array
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        if (!str_starts_with($authHeader, 'Bearer ')) {
            Response::json(['message' => 'Token não fornecido'], 401);
            exit;
        }
        $token = substr($authHeader, 7);
        try {
            $decoded = JWT::decode($token, new Key(self::jwtSecret(), 'HS256'));
            $user = User::findById($decoded->sub);
            if (!$user || !$user['active']) {
                Response::json(['message' => 'Conta inativa ou inexistente'], 401);
                exit;
            }
            return $user;
        } catch (\Throwable $e) {
            Response::json(['message' => 'Token inválido', 'detail' => $e->getMessage()], 401);
            exit;
        }
    }


    public static function userFromBearer(): ?array
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        if (!str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }
        $token = substr($authHeader, 7);
        try {
            $decoded = JWT::decode($token, new Key(self::jwtSecret(), 'HS256'));
            $user = User::findById($decoded->sub);
            if (!$user || !$user['active']) {
                return null;
            }
            return $user;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function requireAdmin(): array
    {
        $user = self::requireUser();
        if ($user['role'] !== 'admin') {
            Response::json(['message' => 'Acesso restrito a administradores'], 403);
            exit;
        }
        return $user;
    }
}
