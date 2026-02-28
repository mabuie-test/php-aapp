<?php
namespace App\Helpers;

use App\Config\Config;

class Pricing
{
    private static array $levelFactors = [
        'tecnico' => 0.9,
        'licenciatura' => 1.0,
        'mestrado' => 1.3,
        'doutoramento' => 1.6
    ];
    private static array $complexFactors = [
        'basica' => 1.0,
        'intermedia' => 1.2,
        'avancada' => 1.4
    ];
    private static array $urgencyFactors = [
        'normal' => 1.0,
        '72h' => 1.3,
        '48h' => 1.5,
        '24h' => 1.8
    ];

    public static function quote(int $pages, string $level, string $complexity, string $urgency): array
    {
        $base = (float) Config::get('BASE_PRICE_PER_PAGE', 35);
        $levelFactor = self::$levelFactors[$level] ?? 1.0;
        $complexFactor = self::$complexFactors[$complexity] ?? 1.0;
        $urgencyFactor = self::$urgencyFactors[$urgency] ?? 1.0;
        $subtotal = $base * $pages;
        $total = $subtotal * $levelFactor * $complexFactor * $urgencyFactor;
        return [
            'base' => $base,
            'pages' => $pages,
            'levelFactor' => $levelFactor,
            'complexFactor' => $complexFactor,
            'urgencyFactor' => $urgencyFactor,
            'total' => round($total, 2)
        ];
    }
}
