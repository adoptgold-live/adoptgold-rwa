<?php
require __DIR__ . '/../inc/rwa-session.php';
header('Content-Type: application/json; charset=utf-8');

echo json_encode([
  'ok' => true,
  'sid' => session_id(),
  'cookie' => $_COOKIE,
  'session' => [
    'wallet' => $_SESSION['wallet'] ?? null,
    'user_id' => $_SESSION['user_id'] ?? null,
    'nickname' => $_SESSION['nickname'] ?? null,
    'role' => $_SESSION['role'] ?? null,
    'tg_id' => $_SESSION['tg_id'] ?? null,
    'auth_method' => $_SESSION['auth_method'] ?? null,
  ],
], JSON_UNESCAPED_SLASHES);