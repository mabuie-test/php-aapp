<?php
namespace App\Controllers;

use App\Helpers\Response;
use App\Helpers\Auth;
use App\Helpers\AuditHelper;
use App\Helpers\Mailer;
use App\Models\User;
use App\Config\Config;
use App\Models\PasswordReset;

class AuthController
{
    private static function performRegister(array $data): void
    {
        if (!isset($data['name'], $data['email'], $data['password'])) {
            Response::json(['message' => 'Dados incompletos'], 400);
            return;
        }
        if (User::findByEmail($data['email'])) {
            Response::json(['message' => 'Email já registado'], 400);
            return;
        }
        if (!empty($data['referred_by'])) {
            $referrer = User::findByReferralCode($data['referred_by']);
            if (!$referrer) {
                Response::json(['message' => 'Código de indicação inválido'], 400);
                return;
            }
            $data['referred_by'] = $referrer['referral_code'];
        }
        $roleInput = $data['role'] ?? 'cliente';
        $role = in_array($roleInput, ['cliente', 'admin'], true) ? $roleInput : 'cliente';
        $userId = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => $role,
            'referral_code' => $data['referral_code'] ?? null,
            'referred_by' => $data['referred_by'] ?? null,
        ]);
        $user = User::findById($userId);
        $token = Auth::issueToken($user);
        AuditHelper::log($userId, 'signup', ['email' => $user['email'], 'role' => $role]);
        Response::json(['token' => $token, 'user' => $user], 201);
    }

    public static function register(): void
    {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        self::performRegister($data);
    }

  public static function login(): void
{
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $user = User::findByEmail($data['email'] ?? '');
    if (!$user) {
        Response::json(['message' => 'Credenciais inválidas'], 401);
        return;
    }
    if (!password_verify($data['password'] ?? '', $user['password_hash'])) {
        Response::json(['message' => 'Credenciais inválidas'], 401);
        return;
    }
    if (isset($user['active']) && !$user['active']) {
        Response::json(['message' => 'Conta desativada'], 403);
        return;
    }
    $token = Auth::issueToken($user);
    AuditHelper::log($user['id'], 'login', ['email' => $user['email']]);
    Response::json(['token' => $token, 'user' => $user]);
}

    public static function requestReset(): void
    {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $email = $data['email'] ?? '';
        if (!$email) {
            Response::json(['message' => 'Email obrigatório'], 400);
            return;
        }
        $user = User::findByEmail($email);
        if (!$user) {
            Response::json(['message' => 'Conta não encontrada'], 404);
            return;
        }
        $token = bin2hex(random_bytes(8));
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = date('Y-m-d H:i:s', time() + 600);
        PasswordReset::create($email, $token, $code, $expires);
        $appUrl = rtrim(Config::get('APP_URL', ''), '/');
        $link = $appUrl ? $appUrl . "/reset.html?code={$code}&email=" . urlencode($email) : '';
        $body = "<p>Olá,</p><p>Recebemos um pedido para redefinir a sua palavra-passe.</p><p>Código: <strong>{$code}</strong></p>";
        if ($link) {
            $body .= "<p>Pode também clicar neste link: <a href='{$link}'>Redefinir palavra-passe</a></p>";
        }
        $body .= '<p>O código expira em 10 minutos.</p>';
        Mailer::send($email, 'Recuperar acesso - Livre-se das Tarefas', $body);
        AuditHelper::log($user['id'], 'password_reset_request', ['email' => $email]);
        Response::json(['message' => 'Código enviado para o email.']);
    }

    public static function resetPassword(): void
    {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $email = $data['email'] ?? '';
        $code = $data['code'] ?? '';
        $newPassword = $data['new_password'] ?? '';
        if (!$email || !$code || !$newPassword) {
            Response::json(['message' => 'Dados incompletos'], 400);
            return;
        }
        $reset = PasswordReset::findValid($email, $code);
        if (!$reset) {
            Response::json(['message' => 'Pedido inválido ou expirado'], 400);
            return;
        }
        $user = User::findByEmail($email);
        if (!$user) {
            Response::json(['message' => 'Utilizador não encontrado'], 404);
            return;
        }
        User::updatePassword((int) $user['id'], $newPassword);
        PasswordReset::markUsed((int) $reset['id']);
        AuditHelper::log($user['id'], 'password_reset_confirmed', ['email' => $email]);
        Mailer::send($email, 'Palavra-passe atualizada', '<p>A sua palavra-passe foi redefinida com sucesso.</p>');
        Response::json(['message' => 'Palavra-passe atualizada com sucesso.']);
    }

    public static function adminRegister(): void
    {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $setupToken = Config::get('ADMIN_SETUP_TOKEN');
        if ($setupToken && ($data['setupToken'] ?? '') !== $setupToken) {
            Response::json(['message' => 'Token de configuração inválido'], 401);
            return;
        }
        $data['role'] = 'admin';
        self::performRegister($data);
    }
}
