<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../inc/rwa-session.php';

function json_out(array $a, int $code=200): void {
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$keys = array_keys($_SESSION ?? []);
foreach ($keys as $k) {
    $ks = (string)$k;
    $kl = strtolower($ks);
    if (
        str_starts_with($kl, 'ton_') ||
        str_contains($kl, 'tonconnect') ||
        str_contains($kl, 'ton_connect') ||
        in_array($kl, ['auth_method','wallet_chain','wallet_provider','login_ts'], true)
    ) {
        unset($_SESSION[$ks]);
    }
}

unset($_SESSION['rwa_user']);
unset($_SESSION['wallet']);
unset($_SESSION['wallet_type']);
unset($_SESSION['role']);
unset($_SESSION['user_id']);

json_out(['ok'=>true]);
