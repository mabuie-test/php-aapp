<?php

session_start();

require __DIR__ . '/../vendor/autoload.php';

use App\Config\Config;
use App\Config\Database;

Config::load(dirname(__DIR__));
Database::pdo();

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function applySecurityHeaders(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

applySecurityHeaders();

// API handling
if (str_starts_with($uri, '/api/')) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    if ($method === 'OPTIONS') {
        exit;
    }
    require __DIR__ . '/../src/routes/api.php';
    exit;
}

$publicRoot = realpath(__DIR__);
$uploadsRoot = realpath(dirname(__DIR__) . '/uploads');

if ($uploadsRoot && str_starts_with($uri, '/uploads/')) {
    $file = realpath($uploadsRoot . str_replace('..', '', substr($uri, 8)));
    if ($file && is_file($file) && str_starts_with($file, $uploadsRoot)) {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime = match ($extension) {
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'txt' => 'text/plain; charset=utf-8',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($file));
        $inline = in_array($extension, ['jpg', 'jpeg', 'png', 'pdf'], true);
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . basename($file) . '"');
        header('Cache-Control: private, max-age=300');
        readfile($file);
        return;
    }
}

$target = $uri === '/' ? $publicRoot . '/index.html' : realpath($publicRoot . $uri);

if ($target && is_file($target) && str_starts_with($target, $publicRoot)) {
    $extension = strtolower(pathinfo($target, PATHINFO_EXTENSION));
    $mime = match ($extension) {
        'html' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'svg' => 'image/svg+xml',
        'pdf' => 'application/pdf',
        default => 'application/octet-stream',
    };
    header('Content-Type: ' . $mime);
    if (in_array($extension, ['css', 'js', 'png', 'jpg', 'jpeg', 'svg', 'pdf'], true)) {
        header('Cache-Control: public, max-age=3600');
    } else {
        header('Cache-Control: no-cache');
    }
    readfile($target);
    return;
}

http_response_code(404);
echo 'Not found';
