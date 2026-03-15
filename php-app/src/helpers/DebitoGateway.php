<?php
namespace App\Helpers;

use App\Config\Config;

class DebitoGateway
{
    private static function baseUrl(): string
    {
        return rtrim((string) Config::get('DEBITO_BASE_URL', 'http://localhost:9000'), '/');
    }

    private static function normalizedProvider(string $provider): string
    {
        return strtolower($provider) === 'emola' ? 'emola' : 'mpesa';
    }

    private static function walletId(string $provider): int
    {
        $provider = self::normalizedProvider($provider);
        $specificKey = $provider === 'emola' ? 'DEBITO_WALLET_ID_EMOLA' : 'DEBITO_WALLET_ID_MPESA';
        return (int) Config::get($specificKey, 0);
    }

    private static function bearerToken(): string
    {
        return trim((string) Config::get('DEBITO_TOKEN', ''));
    }

    private static function request(string $method, string $path, ?array $payload = null): array
    {
        $token = self::bearerToken();
        if ($token === '') {
            return ['ok' => false, 'status' => 500, 'data' => null, 'message' => 'Débito API não configurada (DEBITO_TOKEN)'];
        }

        $url = self::baseUrl() . $path;
        $ch = curl_init($url);
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ];

        if ($payload !== null) {
            $json = json_encode($payload);
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_TCP_KEEPALIVE => 1,
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);

        if (($raw === false || $err || $status === 429 || $status >= 500) && !$err) {
            usleep(150000);
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
        }

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

    public static function createC2B(string $provider, string $msisdn, float $amount, string $referenceDescription, ?string $internalNotes = null, ?string $callbackUrl = null): array
    {
        $provider = self::normalizedProvider($provider);
        $walletId = self::walletId($provider);
        if ($walletId <= 0) {
            return ['ok' => false, 'status' => 500, 'message' => 'Débito API não configurada (DEBITO_WALLET_ID_MPESA/DEBITO_WALLET_ID_EMOLA)', 'data' => null];
        }

        $path = '/api/v1/wallets/' . $walletId . '/c2b/' . $provider;
        $payload = [
            'msisdn' => $msisdn,
            'amount' => $amount,
            'reference_description' => mb_substr($referenceDescription, 0, 100),
        ];

        if ($internalNotes !== null && trim($internalNotes) !== '') {
            $payload['internal_notes'] = mb_substr(trim($internalNotes), 0, 255);
        }
        if ($callbackUrl !== null && trim($callbackUrl) !== '') {
            $payload['callback_url'] = trim($callbackUrl);
        }

        return self::request('POST', $path, $payload);
    }

    public static function transactionStatus(string $debitoReference): array
    {
        $ref = trim($debitoReference);
        if ($ref === '') {
            return ['ok' => false, 'status' => 422, 'message' => 'debito_reference inválida', 'data' => null];
        }

        return self::request('GET', '/api/v1/transactions/' . rawurlencode($ref) . '/status');
    }
}
