<?php
namespace App\Helpers;

use App\Config\Config;

class DebitoGateway
{
    private static function baseUrl(): string
    {
        return rtrim((string) Config::get('DEBITO_BASE_URL', 'http://localhost:9000'), '/');
    }

    private static function credentials(): array
    {
        return [
            'email' => (string) Config::get('DEBITO_EMAIL', ''),
            'password' => (string) Config::get('DEBITO_PASSWORD', ''),
            'wallet_id' => (int) Config::get('DEBITO_WALLET_ID', 0),
        ];
    }

    private static function request(string $method, string $path, ?array $payload = null, ?string $token = null): array
    {
        $url = self::baseUrl() . $path;
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];

        if ($payload !== null) {
            $json = json_encode($payload);
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }
        if ($token) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $err) {
            return ['ok' => false, 'status' => 502, 'data' => null, 'message' => 'Falha de comunicação com gateway Débito'];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $decoded = ['raw' => $raw];
        }

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'data' => $decoded,
            'message' => $decoded['message'] ?? 'Erro na comunicação com Débito API',
        ];
    }

    private static function token(): array
    {
        $cred = self::credentials();
        if ($cred['email'] === '' || $cred['password'] === '' || $cred['wallet_id'] <= 0) {
            return ['ok' => false, 'status' => 500, 'message' => 'Débito API não configurada (DEBITO_EMAIL/DEBITO_PASSWORD/DEBITO_WALLET_ID)'];
        }

        $login = self::request('POST', '/api/v1/login', [
            'email' => $cred['email'],
            'password' => $cred['password'],
        ]);

        if (!$login['ok']) {
            return ['ok' => false, 'status' => $login['status'] ?: 401, 'message' => $login['message'], 'data' => $login['data'] ?? null];
        }

        $data = $login['data'] ?? [];
        $token = $data['token'] ?? $data['access_token'] ?? ($data['data']['token'] ?? null);
        if (!is_string($token) || $token === '') {
            return ['ok' => false, 'status' => 500, 'message' => 'Token inválido retornado pela Débito API'];
        }

        return ['ok' => true, 'status' => 200, 'token' => $token, 'wallet_id' => $cred['wallet_id']];
    }

    public static function createC2B(string $provider, string $msisdn, float $amount, string $referenceDescription, ?string $internalNotes = null): array
    {
        $auth = self::token();
        if (!$auth['ok']) {
            return ['ok' => false, 'status' => $auth['status'], 'message' => $auth['message'], 'data' => $auth['data'] ?? null];
        }

        $provider = strtolower($provider) === 'emola' ? 'emola' : 'mpesa';
        $path = '/api/v1/wallets/' . (int) $auth['wallet_id'] . '/c2b/' . $provider;
        $payload = [
            'msisdn' => $msisdn,
            'amount' => $amount,
            'reference_description' => mb_substr($referenceDescription, 0, 100),
        ];

        if ($internalNotes !== null && trim($internalNotes) !== '') {
            $payload['internal_notes'] = mb_substr(trim($internalNotes), 0, 255);
        }

        return self::request('POST', $path, $payload, $auth['token']);
    }
}
