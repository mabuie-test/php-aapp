<?php
namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Response;
use App\Helpers\AuditHelper;

class ToolsController
{
    public static function track(): void
    {
        $user = null;
        try {
            $user = Auth::requireUser();
        } catch (\Throwable $e) {
            // anónimo aceitável — continuamos
        }

        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $data = $_POST;
        }

        $tool = $data['tool'] ?? ($data['tool_id'] ?? 'unknown');
        if (is_string($tool)) {
            $tool = trim($tool);
        }
        if (!$tool) {
            $tool = 'unknown';
        }

        $userId = $user['id'] ?? null;

        // regista no audit para métricas (facilita visualização no admin -> audits)
        AuditHelper::log($userId, 'tool:use', ['tool' => $tool]);

        Response::json(['message' => 'tracked', 'tool' => $tool]);
    }
}
