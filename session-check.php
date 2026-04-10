<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/rwa-session.php';
require_once __DIR__ . '/inc/core/session-user.php';

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'ok' => true,
    'session_name' => session_name(),
    'session_id' => session_id(),
    'user_id' => session_user_id(),
    'wallet_address' => session_user_wallet(),
    'rwa_user' => session_user(),
    'raw_user_id' => $_SESSION['user_id'] ?? null,
    'raw_auth_method' => $_SESSION['auth_method'] ?? null,
    'raw_task' => $_SESSION['rwa_post_login_task'] ?? null,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
