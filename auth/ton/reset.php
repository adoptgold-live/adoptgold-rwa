<?php
declare(strict_types=1);

/**
 * AdoptGold RWA
 * TON Reset
 * File: /var/www/html/public/rwa/auth/ton/reset.php
 * Version: v1.0.20260315-final-pack
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

header('Content-Type: application/json; charset=utf-8');

function json_exit(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $keys = [
        'rwa_ton_proof_nonce',
        'rwa_ton_proof_nonce_created_at',
        'rwa_ton_proof_nonce_expires_at',
        'ton_proof_nonce',
        'rwa_ton_nonce',
        'ton_nonce',
        'ton_login_nonce',
        'tonconnect_nonce',
        'ton_nonce_payload',
        'rwa_ton_bind_pending',
        'rwa_ton_bind_wallet',
        'rwa_ton_bind_proof'
    ];

    foreach ($keys as $k) unset($_SESSION[$k]);

    json_exit([
        'ok' => true,
        'msg' => 'TON session reset completed.'
    ]);
} catch (Throwable $e) {
    json_exit([
        'ok' => false,
        'error' => 'TON_RESET_FAILED',
        'message' => $e->getMessage()
    ], 500);
}
