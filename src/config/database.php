<?php
namespace App\Config;

use PDO;
use PDOException;

class Database
{
    /**
     * Legacy alias maintained for models that still call getInstance().
     * Returns the shared PDO connection.
     */
    public static function getInstance(): PDO
    {
        return self::pdo();
    }

    public static function pdo(): PDO
    {
        static $pdo = null;
        if ($pdo === null) {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                Config::get('DB_HOST', 'localhost'),
                Config::get('DB_PORT', '3306'),
                Config::get('DB_NAME', 'flux_academy')
            );
            $user = Config::get('DB_USER', 'root');
            $pass = Config::get('DB_PASSWORD', '');
            try {
                $pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
            } catch (PDOException $e) {
                http_response_code(500);
                echo json_encode(['error' => 'DB connection failed', 'details' => $e->getMessage()]);
                exit;
            }
        }
        return $pdo;
    }
}
