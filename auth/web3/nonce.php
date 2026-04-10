<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

header('Content-Type: application/json; charset=utf-8');

function out(array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    out(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED', 'ts' => time()], 405);
}

try {
    $nonce = bin2hex(random_bytes(16));
    $_SESSION['web3_nonce'] = $nonce;
    $_SESSION['web3_next_url'] = '/rwa/login-select.php';

    out([
        'ok' => true,
        'ts' => time(),
        'nonce' => $nonce,
        'domain' => 'adoptgold.app',
        'uri' => 'https://adoptgold.app/rwa/web3-login.php',
        'statement' => 'Sign in to AdoptGold RWA',
        'chain_id' => 1,
    ]);
} catch (Throwable $e) {
    out(['ok' => false, 'error' => 'NONCE_FAILED', 'ts' => time()], 500);
}