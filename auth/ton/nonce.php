<?php
declare(strict_types=1);

/**
 * AdoptGold RWA
 * TON Nonce
 * File: /var/www/html/public/rwa/auth/ton/nonce.php
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

function clear_old_ton_nonce_keys(): void
{
    $keys = [
        'rwa_ton_proof_nonce',
        'ton_proof_nonce',
        'rwa_ton_nonce',
        'ton_nonce',
        'ton_login_nonce',
        'tonconnect_nonce',
        'ton_nonce_payload',
    ];
    foreach ($keys as $k) unset($_SESSION[$k]);
}

try {
    clear_old_ton_nonce_keys();

    $nonce = bin2hex(random_bytes(16));
    $_SESSION['rwa_ton_proof_nonce'] = $nonce;
    $_SESSION['rwa_ton_proof_nonce_created_at'] = time();
    $_SESSION['rwa_ton_proof_nonce_expires_at'] = time() + 180;

    json_exit([
        'ok' => true,
        'payload' => $nonce,
        'nonce' => $nonce,
        'expires_in' => 180
    ]);
} catch (Throwable $e) {
    json_exit([
        'ok' => false,
        'error' => 'TON_NONCE_FAILED',
        'message' => $e->getMessage()
    ], 500);
}
