<?php
use App\Controllers\AuthController;
use App\Controllers\OrderController;
use App\Controllers\AdminController;
use App\Controllers\ServiceController;
use App\Controllers\CareerController;
use App\Controllers\ToolsController;
use App\Controllers\SupportChatController;
use App\Controllers\MarketingController;

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Log para debugging (remova em produção)
error_log("API Route: $uri [$method]");

// ROTAS DE AUTENTICAÇÃO
if ($uri === '/api/auth/register' && $method === 'POST') {
    AuthController::register();
    return;
}
if ($uri === '/api/auth/login' && $method === 'POST') {
    AuthController::login();
    return;
}
if ($uri === '/api/auth/password/forgot' && $method === 'POST') {
    AuthController::requestReset();
    return;
}
if ($uri === '/api/auth/password/reset' && $method === 'POST') {
    AuthController::resetPassword();
    return;
}
if ($uri === '/api/auth/admin-register' && $method === 'POST') {
    AuthController::adminRegister();
    return;
}

// ROTAS DE SERVIÇOS DE CARREIRA
if ($uri === '/api/career/order' && $method === 'POST') {
    error_log("Chamando CareerController::createOrder()");
    CareerController::createOrder();
    return;
}
if ($uri === '/api/career/orders' && $method === 'GET') {
    CareerController::listOrders();
    return;
}
if (preg_match('#^/api/career/orders/(\d+)$#', $uri, $matches) && $method === 'GET') {
    CareerController::show((int) $matches[1]);
    return;
}

// ROTAS DE ENCOMENDAS
if ($uri === '/api/orders/quote' && $method === 'POST') {
    OrderController::quote();
    return;
}
if ($uri === '/api/orders' && $method === 'POST') {
    OrderController::create();
    return;
}
if ($uri === '/api/orders' && $method === 'GET') {
    OrderController::index();
    return;
}
if ($uri === '/api/orders/proof' && $method === 'POST') {
    OrderController::uploadProof();
    return;
}
if ($uri === '/api/orders/deliveries' && $method === 'GET') {
    OrderController::deliveries();
    return;
}
if ($uri === '/api/orders/feedback' && $method === 'POST') {
    OrderController::feedback();
    return;
}
if (preg_match('#^/api/orders/(\d+)$#', $uri, $matches) && $method === 'GET') {
    OrderController::show((int) $matches[1]);
    return;
}
if (preg_match('#^/api/orders/(\d+)/pdf$#', $uri, $matches) && $method === 'GET') {
    OrderController::invoicePdf((int) $matches[1]);
    return;
}

// ROTAS DE AFILIADOS
if ($uri === '/api/affiliates/summary' && $method === 'GET') {
    OrderController::affiliateSummary();
    return;
}
if ($uri === '/api/affiliates/request-payout' && $method === 'POST') {
    OrderController::requestPayout();
    return;
}
if ($uri === '/api/affiliates/click' && $method === 'POST') {
    OrderController::trackAffiliateClick();
    return;
}

// ROTAS DE SERVIÇOS ESPECIALIZADOS
if ($uri === '/api/services' && $method === 'POST') {
    ServiceController::create();
    return;
}
if ($uri === '/api/services' && $method === 'GET') {
    ServiceController::listMine();
    return;
}

// ROTAS DE ADMINISTRAÇÃO
if ($uri === '/api/admin/orders' && $method === 'GET') {
    AdminController::listOrders();
    return;
}
if ($uri === '/api/admin/orders/final-upload' && $method === 'POST') {
    AdminController::uploadFinal();
    return;
}
if ($uri === '/api/admin/invoices/approve' && $method === 'POST') {
    AdminController::approvePayment();
    return;
}
if ($uri === '/api/admin/invoices/reject' && $method === 'POST') {
    AdminController::rejectPayment();
    return;
}
if ($uri === '/api/admin/users' && $method === 'GET') {
    AdminController::listUsers();
    return;
}
if ($uri === '/api/admin/users/toggle' && $method === 'POST') {
    AdminController::toggleUser();
    return;
}
if ($uri === '/api/admin/users/delete' && $method === 'POST') {
    AdminController::deleteUser();
    return;
}

if ($uri === '/api/admin/users/anonymize' && $method === 'POST') {
    AdminController::anonymizeUser();
    return;
}
if ($uri === '/api/admin/feedback' && $method === 'GET') {
    AdminController::feedback();
    return;
}
if ($uri === '/api/admin/commissions' && $method === 'GET') {
    AdminController::commissions();
    return;
}
if ($uri === '/api/admin/payouts' && $method === 'GET') {
    AdminController::payouts();
    return;
}
if ($uri === '/api/admin/payouts/update' && $method === 'POST') {
    AdminController::updatePayout();
    return;
}
if ($uri === '/api/admin/metrics' && $method === 'GET') {
    AdminController::metrics();
    return;
}

if ($uri === '/api/admin/growth-dashboard' && $method === 'GET') {
    AdminController::growthDashboard();
    return;
}
if ($uri === '/api/admin/audits' && $method === 'GET') {
    AdminController::audits();
    return;
}

if ($uri === '/api/admin/notifications-center' && $method === 'GET') {
    AdminController::notificationsCenter();
    return;
}
if ($uri === '/api/admin/affiliates/conversion.csv' && $method === 'GET') {
    AdminController::affiliateConversionCsv();
    return;
}
if ($uri === '/api/admin/sla' && $method === 'GET') {
    AdminController::slaPanel();
    return;
}

if ($uri === '/api/admin/affiliates/fraud' && $method === 'GET') {
    AdminController::affiliateFraudPanel();
    return;
}

if ($uri === '/api/admin/marketing/leads' && $method === 'GET') {
    AdminController::marketingLeads();
    return;
}

if ($uri === '/api/admin/marketing/recipients' && $method === 'GET') {
    AdminController::marketingRecipients();
    return;
}
if ($uri === '/api/admin/marketing/campaign/send' && $method === 'POST') {
    AdminController::sendMarketingCampaign();
    return;
}
if ($uri === '/api/admin/marketing/campaign/history' && $method === 'GET') {
    AdminController::marketingCampaignHistory();
    return;
}
if ($uri === '/api/admin/chat' && $method === 'GET') {
    AdminController::chatMessages();
    return;
}
if ($uri === '/api/admin/chat' && $method === 'POST') {
    AdminController::postChatMessage();
    return;
}
if ($uri === '/api/admin/services' && $method === 'GET') {
    ServiceController::list();
    return;
}
if ($uri === '/api/admin/services/update' && $method === 'POST') {
    ServiceController::updateStatus();
    return;
}


// ROTAS DE CHAT DE SUPORTE (cliente/admin)
if ($uri === '/api/support/chat/start' && $method === 'POST') {
    SupportChatController::start();
    return;
}
if ($uri === '/api/support/chat/message' && $method === 'POST') {
    SupportChatController::addMessage();
    return;
}
if ($uri === '/api/support/chat/messages' && $method === 'GET') {
    SupportChatController::messages();
    return;
}
if ($uri === '/api/support/chat/sessions' && $method === 'GET') {
    SupportChatController::listSessions();
    return;
}
if ($uri === '/api/support/chat/claim' && $method === 'POST') {
    SupportChatController::claim();
    return;
}
if ($uri === '/api/support/chat/transfer' && $method === 'POST') {
    SupportChatController::transfer();
    return;
}
if ($uri === '/api/support/chat/close' && $method === 'POST') {
    SupportChatController::closeSession();
    return;
}
if ($uri === '/api/support/chat/rate' && $method === 'POST') {
    SupportChatController::rate();
    return;
}
if ($uri === '/api/support/chat/agents' && $method === 'GET') {
    SupportChatController::agents();
    return;
}

// ROTAS DE NOTIFICAÇÕES
if ($uri === '/api/notifications' && $method === 'GET') {
    OrderController::notifications();
    return;
}

// ROTAS DE FERRAMENTAS
if ($uri === '/api/tools/track' && $method === 'POST') {
    ToolsController::track();
    return;
}


// ROTAS DE MARKETING
if ($uri === '/api/marketing/lead' && $method === 'POST') {
    MarketingController::captureLead();
    return;
}


if ($uri === '/api/marketing/landing-config' && $method === 'GET') {
    MarketingController::landingConfig();
    return;
}
if ($uri === '/api/marketing/offers' && $method === 'GET') {
    MarketingController::offersEngine();
    return;
}
if ($uri === '/api/marketing/ab/assign' && $method === 'GET') {
    MarketingController::abAssign();
    return;
}
if ($uri === '/api/marketing/attribution' && $method === 'POST') {
    MarketingController::trackAttribution();
    return;
}
if ($uri === '/api/marketing/funnel' && $method === 'GET') {
    MarketingController::conversionFunnel();
    return;
}

if ($uri === '/api/marketing/attribution/model' && $method === 'GET') {
    MarketingController::getAttributionModel();
    return;
}
if ($uri === '/api/marketing/attribution/model' && $method === 'POST') {
    MarketingController::saveAttributionModel();
    return;
}
if ($uri === '/api/marketing/recovery' && $method === 'POST') {
    MarketingController::checkoutRecovery();
    return;
}
if ($uri === '/api/marketing/referral/post-purchase' && $method === 'POST') {
    MarketingController::postPurchaseReferral();
    return;
}
if ($uri === '/api/marketing/nps' && $method === 'POST') {
    MarketingController::captureNps();
    return;
}
if ($uri === '/api/affiliates/smart-link' && $method === 'POST') {
    MarketingController::buildAffiliateSmartLink();
    return;
}

// ROTA NÃO ENCONTRADA
http_response_code(404);
echo json_encode(['message' => 'Not found']);