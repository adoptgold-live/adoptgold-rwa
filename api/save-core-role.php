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

$role = trim((string)($data['role'] ?? ''));
$allowed = ['Adoptee', 'Adopter', 'ACE'];

if (!in_array($role, $allowed, true)) {
    j(['ok' => false, 'error' => 'INVALID_ROLE'], 400);
}

try {
    $st = $pdo->prepare("
        UPDATE users
        SET role = ?, updated_at = CURRENT_TIMESTAMP
        WHERE wallet = ?
           OR wallet_address = ?
        LIMIT 1
    ");
    $st->execute([$role, $wallet, $wallet]);

    j([
        'ok' => true,
        'role' => $role,
    ]);
} catch (Throwable $e) {
    j(['ok' => false, 'error' => 'DB_UPDATE_FAIL'], 500);
}