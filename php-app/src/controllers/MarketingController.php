<?php
namespace App\Controllers;

use App\Helpers\Response;
use App\Helpers\AuditHelper;
use App\Config\Database;

class MarketingController
{
    private static function readJsonStorage(string $name, array $fallback = []): array
    {
        $path = dirname(__DIR__, 2) . '/storage/' . $name;
        if (!is_file($path)) {
            return $fallback;
        }
        $raw = file_get_contents($path) ?: '';
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : $fallback;
    }

    private static function writeJsonStorage(string $name, array $data): void
    {
        $path = dirname(__DIR__, 2) . '/storage/' . $name;
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public static function captureLead(): void
    {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $data = $_POST;
        }

        $name = trim((string)($data['name'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $phone = trim((string)($data['phone'] ?? ''));
        $interest = trim((string)($data['interest'] ?? 'lead_magnet_tcc'));
        $source = trim((string)($data['source'] ?? 'website_home'));

        if ($name === '' || $email === '') {
            Response::json(['message' => 'Nome e email são obrigatórios.'], 422);
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::json(['message' => 'Email inválido.'], 422);
            return;
        }

        $utm = [
            'utm_source' => trim((string)($data['utm_source'] ?? '')),
            'utm_medium' => trim((string)($data['utm_medium'] ?? '')),
            'utm_campaign' => trim((string)($data['utm_campaign'] ?? '')),
            'utm_term' => trim((string)($data['utm_term'] ?? '')),
            'utm_content' => trim((string)($data['utm_content'] ?? '')),
            'gclid' => trim((string)($data['gclid'] ?? '')),
            'fbclid' => trim((string)($data['fbclid'] ?? '')),
        ];

        $score = 10;
        if (str_contains($source, 'affiliate')) $score += 20;
        if ($interest !== '' && $interest !== 'lead_magnet_tcc') $score += 15;
        if ($phone !== '') $score += 10;
        if (($utm['utm_medium'] ?? '') === 'cpc') $score += 20;
        if (($utm['utm_campaign'] ?? '') !== '') $score += 5;

        AuditHelper::log(null, 'marketing:lead', [
            'name' => $name,
            'email' => $email,
            'phone' => $phone ?: null,
            'interest' => $interest,
            'source' => $source,
            'utm' => $utm,
            'score' => $score,
            'segment' => $score >= 50 ? 'hot' : ($score >= 30 ? 'warm' : 'cold'),
        ]);

        AuditHelper::log(null, 'marketing:nurture:triggered', [
            'email' => $email,
            'channels' => ['email', 'whatsapp', 'internal'],
            'flow' => $score >= 50 ? 'fast-track-offer' : 'educational-sequence',
            'score' => $score,
        ]);

        Response::json([
            'message' => 'Lead registado com sucesso.',
            'score' => $score,
            'segment' => $score >= 50 ? 'hot' : ($score >= 30 ? 'warm' : 'cold'),
            'next' => '/assets/checklist-tcc.txt'
        ]);
    }

    public static function landingConfig(): void
    {
        $persona = trim((string)($_GET['persona'] ?? 'tcc'));
        $configs = self::readJsonStorage('landing-personas.json', [
            'tcc' => [
                'headline' => 'Conclua o TCC com suporte especializado e prazos reais',
                'cta' => 'Quero apoio para TCC',
                'social_proof' => ['+120 TCCs orientados', '4.8/5 satisfação média'],
                'arguments' => ['Plano por etapas', 'Orientação metodológica', 'Revisões incluídas'],
            ],
            'estagio' => [
                'headline' => 'Relatórios de estágio com padrão académico',
                'cta' => 'Quero apoio no relatório',
                'social_proof' => ['+300 relatórios finalizados'],
                'arguments' => ['Estrutura validada', 'Normas institucionais', 'Entrega com acompanhamento'],
            ],
            'concurso' => [
                'headline' => 'Destaque-se no concurso com materiais de alto impacto',
                'cta' => 'Preparar candidatura agora',
                'social_proof' => ['Templates prontos para personalização'],
                'arguments' => ['Revisão estratégica', 'Clareza e objetividade', 'Rapidez com qualidade'],
            ],
        ]);

        $selected = $configs[$persona] ?? $configs['tcc'];
        Response::json(['persona' => $persona, 'config' => $selected]);
    }

    public static function offersEngine(): void
    {
        $campaign = trim((string)($_GET['campaign'] ?? 'default'));
        $catalog = self::readJsonStorage('marketing-offers.json', [
            'default' => [
                'coupon' => 'BEMVINDO10',
                'discount_percent' => 10,
                'expires_at' => date('c', strtotime('+72 hours')),
                'first_order_bonus' => 'Revisão premium',
                'bundles' => [
                    ['name' => 'TCC + Apresentação', 'discount_percent' => 15],
                    ['name' => 'Relatório + Carta', 'discount_percent' => 12],
                ],
            ],
        ]);

        $offer = $catalog[$campaign] ?? $catalog['default'];
        Response::json(['campaign' => $campaign, 'offer' => $offer, 'urgency' => ['real' => true, 'expires_at' => $offer['expires_at'] ?? null]]);
    }

    public static function abAssign(): void
    {
        $test = trim((string)($_GET['test'] ?? 'homepage_headline'));
        $visitor = trim((string)($_GET['visitor_id'] ?? ''));
        if ($visitor === '') {
            $visitor = 'anon_' . substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '0') . ($_SERVER['HTTP_USER_AGENT'] ?? 'ua')), 0, 12);
        }

        $variants = [
            'homepage_headline' => ['A', 'B'],
            'homepage_cta' => ['A', 'B'],
            'price_anchor' => ['A', 'B'],
            'social_proof_block' => ['A', 'B'],
        ];
        $pool = $variants[$test] ?? ['A', 'B'];
        $idx = hexdec(substr(hash('sha256', $test . ':' . $visitor), 0, 2)) % count($pool);
        $variant = $pool[$idx];

        AuditHelper::log(null, 'marketing:ab:assign', [
            'test' => $test,
            'visitor' => $visitor,
            'variant' => $variant,
        ]);

        Response::json(['test' => $test, 'visitor_id' => $visitor, 'variant' => $variant]);
    }

    public static function trackAttribution(): void
    {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        if (!is_array($data)) $data = $_POST;

        $visitor = trim((string)($data['visitor_id'] ?? ''));
        $funnelStep = trim((string)($data['funnel_step'] ?? 'landing'));
        $origin = [
            'utm_source' => $data['utm_source'] ?? null,
            'utm_medium' => $data['utm_medium'] ?? null,
            'utm_campaign' => $data['utm_campaign'] ?? null,
            'utm_term' => $data['utm_term'] ?? null,
            'utm_content' => $data['utm_content'] ?? null,
            'gclid' => $data['gclid'] ?? null,
            'fbclid' => $data['fbclid'] ?? null,
            'ref' => $data['ref'] ?? null,
        ];

        AuditHelper::log(null, 'marketing:attribution', [
            'visitor_id' => $visitor,
            'funnel_step' => $funnelStep,
            'origin' => $origin,
        ]);

        Response::json(['message' => 'Atribuição registada', 'visitor_id' => $visitor, 'funnel_step' => $funnelStep]);
    }

    public static function buildAffiliateSmartLink(): void
    {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        if (!is_array($data)) $data = $_POST;

        $code = trim((string)($data['affiliate_code'] ?? ''));
        $campaign = trim((string)($data['campaign'] ?? 'default'));
        $channel = trim((string)($data['channel'] ?? 'organic'));
        $offer = trim((string)($data['offer'] ?? 'default'));

        if ($code === '') {
            Response::json(['message' => 'affiliate_code é obrigatório'], 422);
            return;
        }

        $base = rtrim((string)($_SERVER['HTTP_ORIGIN'] ?? ''), '/');
        if ($base === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $base = $scheme . '://' . $host;
        }

        $qs = http_build_query([
            'ref' => $code,
            'camp' => $campaign,
            'ch' => $channel,
            'offer' => $offer,
        ]);
        $url = $base . '/register.html?' . $qs;

        AuditHelper::log(null, 'affiliate:smart-link', [
            'affiliate_code' => $code,
            'campaign' => $campaign,
            'channel' => $channel,
            'offer' => $offer,
            'url' => $url,
        ]);

        Response::json(['smart_link' => $url]);
    }

    public static function conversionFunnel(): void
    {
        $pdo = Database::pdo();

        $steps = [
            'landing' => 0,
            'lead' => 0,
            'checkout_started' => 0,
            'order_created' => 0,
            'paid' => 0,
        ];

        try {
            $stmt = $pdo->query("SELECT funnel_step, COUNT(*) as total FROM audits WHERE action='marketing:attribution' GROUP BY funnel_step");
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $k = $row['funnel_step'] ?? '';
                if (isset($steps[$k])) $steps[$k] = (int)$row['total'];
            }
        } catch (\Throwable $e) {
            // keep defaults
        }

        try {
            $steps['lead'] = (int) $pdo->query("SELECT COUNT(*) FROM audits WHERE action='marketing:lead'")->fetchColumn();
        } catch (\Throwable $e) {}

        try {
            $steps['order_created'] = (int) $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
            $steps['paid'] = (int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE estado='PAGA'")->fetchColumn();
        } catch (\Throwable $e) {}

        $conv = [];
        $keys = array_keys($steps);
        for ($i = 1; $i < count($keys); $i++) {
            $prev = $steps[$keys[$i - 1]];
            $cur = $steps[$keys[$i]];
            $conv[$keys[$i - 1] . '_to_' . $keys[$i]] = $prev > 0 ? round(($cur / $prev) * 100, 2) : 0;
        }

        Response::json(['funnel' => $steps, 'conversion' => $conv]);
    }

    public static function saveAttributionModel(): void
    {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        if (!is_array($data)) $data = $_POST;

        $model = strtolower(trim((string)($data['model'] ?? 'last_click')));
        if (!in_array($model, ['first_click', 'last_click'], true)) {
            Response::json(['message' => 'Modelo inválido'], 422);
            return;
        }

        $windowDays = max(1, min(90, (int)($data['window_days'] ?? 30)));
        $cfg = self::readJsonStorage('affiliate-attribution.json', []);
        $cfg['model'] = $model;
        $cfg['window_days'] = $windowDays;
        $cfg['updated_at'] = date('c');
        self::writeJsonStorage('affiliate-attribution.json', $cfg);

        AuditHelper::log(null, 'affiliate:attribution:config', $cfg);
        Response::json(['message' => 'Configuração guardada', 'config' => $cfg]);
    }

    public static function getAttributionModel(): void
    {
        $cfg = self::readJsonStorage('affiliate-attribution.json', [
            'model' => 'last_click',
            'window_days' => 30,
            'updated_at' => null,
        ]);
        Response::json(['config' => $cfg]);
    }

    public static function checkoutRecovery(): void
    {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        if (!is_array($data)) $data = $_POST;

        $email = trim((string)($data['email'] ?? ''));
        $orderDraftId = trim((string)($data['order_draft_id'] ?? ''));
        $stage = trim((string)($data['stage'] ?? 'abandoned'));

        if ($email === '' && $orderDraftId === '') {
            Response::json(['message' => 'email ou order_draft_id obrigatório'], 422);
            return;
        }

        $cadence = [
            ['t_plus_minutes' => 30, 'channel' => 'email'],
            ['t_plus_minutes' => 180, 'channel' => 'whatsapp'],
            ['t_plus_minutes' => 1440, 'channel' => 'internal'],
        ];

        AuditHelper::log(null, 'marketing:recovery:scheduled', [
            'email' => $email ?: null,
            'order_draft_id' => $orderDraftId ?: null,
            'stage' => $stage,
            'cadence' => $cadence,
        ]);

        Response::json(['message' => 'Recuperação agendada', 'cadence' => $cadence]);
    }

    public static function postPurchaseReferral(): void
    {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        if (!is_array($data)) $data = $_POST;

        $customerEmail = trim((string)($data['customer_email'] ?? ''));
        $friendEmail = trim((string)($data['friend_email'] ?? ''));
        $incentive = [
            'customer_bonus_percent' => 10,
            'friend_bonus_percent' => 10,
            'expires_days' => 14,
        ];

        AuditHelper::log(null, 'marketing:referral:post_purchase', [
            'customer_email' => $customerEmail ?: null,
            'friend_email' => $friendEmail ?: null,
            'incentive' => $incentive,
        ]);

        Response::json(['message' => 'Referral pós-compra registado', 'incentive' => $incentive]);
    }

    public static function captureNps(): void
    {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        if (!is_array($data)) $data = $_POST;

        $score = max(0, min(10, (int)($data['score'] ?? -1)));
        if ($score < 0) {
            Response::json(['message' => 'score obrigatório (0-10)'], 422);
            return;
        }

        $email = trim((string)($data['email'] ?? ''));
        $comment = trim((string)($data['comment'] ?? ''));
        $segment = $score >= 9 ? 'promoter' : ($score >= 7 ? 'passive' : 'detractor');

        $triggers = [];
        if ($segment === 'promoter') {
            $triggers[] = 'solicitar_depoimento';
            $triggers[] = 'upsell_bundle';
        } elseif ($segment === 'detractor') {
            $triggers[] = 'abrir_ticket_suporte';
            $triggers[] = 'oferta_recuperacao';
        }

        AuditHelper::log(null, 'marketing:nps', [
            'email' => $email ?: null,
            'score' => $score,
            'segment' => $segment,
            'comment' => $comment ?: null,
            'triggers' => $triggers,
        ]);

        Response::json(['message' => 'NPS/CSAT registado', 'segment' => $segment, 'triggers' => $triggers]);
    }

}
