<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

function j(array $d, int $c = 200): never {
    http_response_code($c);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$pdo = $GLOBALS['pdo'] ?? (function_exists('rwa_db') ? rwa_db() : null);
if (!$pdo instanceof PDO) {
    j(['ok' => false, 'error' => 'DB_NOT_READY'], 500);
}

$user = function_exists('rwa_session_user') ? rwa_session_user() : null;
$userId = (int)($user['id'] ?? 0);
if ($userId <= 0) {
    j(['ok' => false, 'error' => 'AUTH_REQUIRED'], 401);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
    $st = $pdo->prepare("
        SELECT card_mode, mode_locked
        FROM rwa_storage_cards
        WHERE user_id = ?
        LIMIT 1
    ");
    $st->execute([$userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $mode = (string)($row['card_mode'] ?? 'U€');
    if (!in_array($mode, ['U€','M€','V€'], true)) {
        $mode = 'U€';
    }

    j([
        'ok' => true,
        'mode' => $mode,
        'mode_locked' => (int)($row['mode_locked'] ?? 0),
    ]);
}

if ($method === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) {
        $in = $_POST;
    }

    $mode = trim((string)($in['mode'] ?? ''));
    if (!in_array($mode, ['U€','M€','V€'], true)) {
        j(['ok' => false, 'error' => 'INVALID_MODE'], 422);
    }

    $st = $pdo->prepare("
        SELECT mode_locked
        FROM rwa_storage_cards
        WHERE user_id = ?
        LIMIT 1
    ");
    $st->execute([$userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    if ((int)($row['mode_locked'] ?? 0) === 1) {
        j([
            'ok' => false,
            'error' => 'CARD_MODE_LOCKED',
            'mode_locked' => 1,
        ], 409);
    }

    $st = $pdo->prepare("
        INSERT INTO rwa_storage_cards (user_id, card_mode, mode_locked, created_at, updated_at)
        VALUES (?, ?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE
            card_mode = VALUES(card_mode),
            updated_at = CURRENT_TIMESTAMP
    ");
    $st->execute([$userId, $mode]);

    j([
        'ok' => true,
        'mode' => $mode,
        'mode_locked' => 0,
    ]);
}

j(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], 405);