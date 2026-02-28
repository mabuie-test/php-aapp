<?php
namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Response;
use App\Helpers\AuditHelper;
use App\Helpers\Mailer;
use App\Models\ServiceRequest;
use App\Config\Config;

class ServiceController
{
    public static function create(): void
    {
        $user = Auth::requireUser();
        $data = $_POST;
        if (empty($data['categoria']) || empty($data['contact_name']) || empty($data['contact_email'])) {
            Response::json(['message' => 'Dados do serviço incompletos'], 400);
            return;
        }

        $attachment = null;
        if (!empty($_FILES['attachment']['tmp_name'])) {
            $dir = dirname(__DIR__, 2) . '/uploads/servicos';
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $safe = uniqid('svc_') . '-' . preg_replace('/[^a-zA-Z0-9\.\-_]/', '_', $_FILES['attachment']['name']);
            $dest = $dir . '/' . $safe;
            if (move_uploaded_file($_FILES['attachment']['tmp_name'], $dest)) {
                $attachment = '/uploads/servicos/' . $safe;
            }
        }

        $serviceId = ServiceRequest::create([
            'user_id' => $user['id'],
            'categoria' => $data['categoria'],
            'contact_name' => $data['contact_name'],
            'contact_email' => $data['contact_email'],
            'contact_phone' => $data['contact_phone'] ?? null,
            'norma_preferida' => $data['norma_preferida'] ?? null,
            'software_preferido' => $data['software_preferido'] ?? null,
            'detalhes' => $data['detalhes'] ?? null,
            'attachment' => $attachment,
        ]);

        AuditHelper::log($user['id'], 'service:create', ['service_id' => $serviceId, 'categoria' => $data['categoria']]);
        Mailer::send($user['email'], 'Pedido recebido: ' . $data['categoria'], 'Recebemos o seu pedido especializado. Em breve retornaremos.');
        $adminEmails = \App\Models\User::adminEmails();
        $fallback = Config::get('ADMIN_NOTIFY_EMAIL');
        if ($fallback && !in_array($fallback, $adminEmails)) {
            $adminEmails[] = $fallback;
        }
        foreach ($adminEmails as $adminEmail) {
            Mailer::send($adminEmail, 'Novo serviço solicitado', 'Serviço ' . $data['categoria'] . ' solicitado por ' . $user['email']);
        }

        Response::json(['message' => 'Pedido de serviço registado', 'service_id' => $serviceId], 201);
    }

    public static function list(): void
    {
        $user = Auth::requireAdmin();
        $requests = ServiceRequest::listAll();
        Response::json(['services' => $requests]);
    }

    public static function listMine(): void
    {
        $user = Auth::requireUser();
        $requests = ServiceRequest::listForUser($user['id']);
        Response::json(['services' => $requests]);
    }

    public static function updateStatus(): void
    {
        $admin = Auth::requireAdmin();
        $id = (int) ($_POST['service_id'] ?? 0);
        $status = $_POST['status'] ?? null;
        if (!$id || !$status) {
            Response::json(['message' => 'Dados em falta'], 400);
            return;
        }
        ServiceRequest::updateStatus($id, $status);
        $service = ServiceRequest::find($id);
        if ($service) {
            $targetEmail = $service['contact_email'] ?? null;
            if ($targetEmail) {
                Mailer::send($targetEmail, 'Atualização do seu serviço', 'O pedido #' . $id . ' agora está em: ' . $status);
            }
            if (!empty($service['user_id'])) {
                AuditHelper::log((int) $service['user_id'], 'service:update', ['service_id' => $id, 'status' => $status]);
            }
        }
        AuditHelper::log($admin['id'], 'service:update', ['service_id' => $id, 'status' => $status]);
        Response::json(['message' => 'Estado atualizado']);
    }
}
