<?php
declare(strict_types=1);

namespace App\Config;

use Dotenv\Dotenv;

// Define o fuso horário oficial de Moçambique
date_default_timezone_set('Africa/Maputo');

class Config
{
    private static array $env = [];

    public static function load(string $basePath): void
    {
        $dotenv = Dotenv::createImmutable($basePath);
        $dotenv->safeLoad();

        self::$env = array_merge($_SERVER, $_ENV);
    }

    public static function get(string $key, $default = null)
    {
        return self::$env[$key] ?? $default;
    }
}