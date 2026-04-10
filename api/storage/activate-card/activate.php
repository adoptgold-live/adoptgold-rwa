<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/api/storage/activate-card/activate.php
 * Storage Master v7.7 — Activate Card Router
 * FINAL-LOCK-1
 */

$mode = strtolower(trim((string)(
    $_POST['mode']
    ?? $_GET['mode']
    ?? ''
)));

switch ($mode) {
    case 'prepare':
        require __DIR__ . '/activate-prepare.php';
        break;

    case 'verify':
        require __DIR__ . '/activate-verify.php';
        break;

    case 'confirm':
        require __DIR__ . '/activate-confirm.php';
        break;

    default:
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'code' => 'INVALID_MODE',
            'message' => 'Supported modes: prepare, verify, confirm',
            '_version' => 'FINAL-LOCK-1',
            '_file' => __FILE__,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
}