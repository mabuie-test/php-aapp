<?php
namespace App\Helpers;

use App\Models\Audit;

class AuditHelper
{
    public static function log(?int $userId, string $action, array $meta = []): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $meta['ip'] = $ip;
        Audit::record($userId, $action, $meta);
    }
}
