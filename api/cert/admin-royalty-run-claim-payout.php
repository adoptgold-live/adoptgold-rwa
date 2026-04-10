<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/api/cert/admin-royalty-run-claim-payout.php
 *
 * Admin API:
 * - runs royalty-claim-payout cron on demand
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

function arrcp_json(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $user = rwa_require_login();
    if (($user['role'] ?? '') !== 'admin') {
        arrcp_json(['ok' => false, 'error' => 'FORBIDDEN'], 403);
    }

    $script = '/var/www/html/public/rwa/cron/royalty-claim-payout.php';
    if (!is_file($script)) {
        arrcp_json(['ok' => false, 'error' => 'SCRIPT_NOT_FOUND'], 404);
    }

    $cmd = '/usr/bin/php ' . escapeshellarg($script) . ' 2>&1';
    $out = [];
    $code = 0;
    exec($cmd, $out, $code);

    arrcp_json([
        'ok' => $code === 0,
        'exit_code' => $code,
        'output' => $out,
    ], $code === 0 ? 200 : 500);
} catch (Throwable $e) {
    arrcp_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
