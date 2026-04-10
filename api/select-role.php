<?php
declare(strict_types=1);

require $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/bootstrap.php';
require $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/session-user.php';

header('Content-Type: application/json; charset=utf-8');

function j(array $a, int $code = 200): void {
    http_response_code($code);
    echo json_encode($a);
    exit;
}

db_connect();
$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    j(['ok' => false, 'error' => 'NO_DB'], 500);
}

$wallet = trim((string)($_SESSION['wallet'] ?? $_SESSION['user_wallet'] ?? $_SESSION['poado_wallet'] ?? ''));
if ($wallet === '' && function_exists('get_wallet_session')) {
    try {
        $s = get_wallet_session();
        if (is_array($s)) {
            $wallet = trim((string)($s['wallet'] ?? $s['wallet_address'] ?? ''));
        }
    } catch (Throwable $e) {}
}

if ($wallet === '') {
    j(['ok' => false, 'error' => 'NO_SESSION'], 401);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '[]', true);
if (!is_array($data)) $data = [];

$industry = strtolower(trim((string)($data['industry'] ?? '')));
$nextUrl  = trim((string)($data['next_url'] ?? ''));

$allowed = [
    'health'   => '/rwa/rlife/',
    'travel'   => '/rwa/rtrip/',
    'property' => '/rwa/rprop/',
];

if (!isset($allowed[$industry])) {
    j(['ok' => false, 'error' => 'INVALID_INDUSTRY'], 400);
}

if ($nextUrl === '' || !isset($allowed[$industry]) || $nextUrl !== $allowed[$industry]) {
    $nextUrl = $allowed[$industry];
}

try {
    $st = $pdo->prepare("
        SELECT id, role
        FROM users
        WHERE wallet = ?
           OR wallet_address = ?
        LIMIT 1
    ");
    $st->execute([$wallet, $wallet]);
    $user = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) {
        j(['ok' => false, 'error' => 'USER_NOT_FOUND'], 404);
    }

    // users.role is core-role only; never overwrite it
    $coreRole = trim((string)($user['role'] ?? ''));
    if ($coreRole === '') {
        j(['ok' => false, 'error' => 'CORE_ROLE_MISSING'], 400);
    }

    $pdo->beginTransaction();

    $st = $pdo->prepare("SELECT id FROM secondary_rwa_roles WHERE user_id = ? LIMIT 1");
    $st->execute([$userId]);
    $rowId = (int)($st->fetchColumn() ?: 0);

    if ($rowId > 0) {
        $st = $pdo->prepare("
            UPDATE secondary_rwa_roles
            SET industry_key = ?, is_active = 1, updated_at = CURRENT_TIMESTAMP
            WHERE user_id = ?
            LIMIT 1
        ");
        $st->execute([$industry, $userId]);
    } else {
        $st = $pdo->prepare("
            INSERT INTO secondary_rwa_roles (user_id, industry_key, is_active)
            VALUES (?, ?, 1)
        ");
        $st->execute([$userId, $industry]);
    }

    $pdo->commit();

    j([
        'ok' => true,
        'industry' => $industry,
        'core_role' => $coreRole,
        'next_url' => $nextUrl,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    j(['ok' => false, 'error' => 'DB_UPDATE_FAIL'], 500);
}