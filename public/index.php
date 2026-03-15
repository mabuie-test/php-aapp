

<?php
// php-app/public/index.php - ADICIONAR NO INÍCIO

// Debug temporário (REMOVA EM PRODUÇÃO)
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

session_start();

// Log da requisição
file_put_contents(__DIR__ . '/../requests.log', 
    date('Y-m-d H:i:s') . " - " . 
    $_SERVER['REQUEST_METHOD'] . " " . 
    $_SERVER['REQUEST_URI'] . " - " . 
    json_encode(['POST' => $_POST, 'FILES' => $_FILES]) . "\n", 
    FILE_APPEND
);

require __DIR__ . '/../vendor/autoload.php';

use App\Config\Config;
use App\Config\Database;

Config::load(dirname(__DIR__));
Database::pdo();

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

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
        header('Content-Type: application/octet-stream');
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
    readfile($target);
    return;
}

http_response_code(404);
echo 'Not found';
