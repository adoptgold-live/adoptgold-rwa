<?php
declare(strict_types=1);

    define('RWA_API_VERIFY_BOOTSTRAPPED', true);

    $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? '/var/www/html/public'), '/');
    $coreBootstrap = $docRoot . '/rwa/inc/core/bootstrap.php';

    if (is_file($coreBootstrap)) {
        require_once $coreBootstrap;
    } else {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        date_default_timezone_set('Asia/Kuala_Lumpur');
    }
}

function verify_api_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}
