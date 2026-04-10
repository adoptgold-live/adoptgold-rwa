<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/api/mining/ledger.php
 * Ledger reader for mining page + Storage-linked unclaimed wEMS.
 */

ini_set('display_errors', '0');
error_reporting(0);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

if (is_file($_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/mining-config.php')) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/mining-config.php';
}
if (is_file($_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/mining-lib.php')) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/mining-lib.php';
}
if (is_file($_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/mining-guards.php')) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/mining-guards.php';
}

while (ob_get_level() > 0) { @ob_end_clean(); }
header('Content-Type: application/json; charset=utf-8');

function mining_ledger_json(array $a, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mining_ledger_fail(string $error, string $message, int $code = 400): never
{
    mining_ledger_json([
        'ok' => false,
        'error' => $error,
        'message' => $message,
    ], $code);
}

function mining_ledger_wallet(array $user): string
{
    return trim((string)($user['wallet_address'] ?? $user['wallet'] ?? ''));
}

function mining_ledger_totals(PDO $pdo, int $userId, string $wallet): array
{
    try {
        $st = $pdo->prepare("
            SELECT
                COALESCE(total_mined_wems, 0) AS total_mined_wems,
                COALESCE(total_binding_wems, 0) AS total_binding_wems,
                COALESCE(total_node_bonus_wems, 0) AS total_node_bonus_wems,
                COALESCE(total_claimed_wems, 0) AS total_claimed_wems
            FROM poado_miner_profiles
            WHERE user_id = ? AND wallet = ?
            LIMIT 1
        ");
        $st->execute([$userId, $wallet]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $gross = (float)$row['total_mined_wems'] + (float)$row['total_binding_wems'] + (float)$row['total_node_bonus_wems'];
            $unclaimed = max(0, $gross - (float)$row['total_claimed_wems']);

            return [
                'total_mined_wems' => $gross,
                'storage_unclaimed_wems' => $unclaimed,
            ];
        }
    } catch (Throwable $e) {
    }

    $total = 0.0;
    try {
        $st = $pdo->prepare("
            SELECT COALESCE(SUM(amount),0)
            FROM wems_mining_log
            WHERE user_id = ?
              AND reason = 'mining'
        ");
        $st->execute([$userId]);
        $total = ((int)($st->fetchColumn() ?: 0)) / 100000000;
    } catch (Throwable $e) {
    }

    return [
        'total_mined_wems' => $total,
        'storage_unclaimed_wems' => $total,
    ];
}

db_connect();
$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) {
    mining_ledger_fail('NO_DB', 'DB connection unavailable', 500);
}

$user = session_user();
if (!is_array($user) || empty($user)) {
    mining_ledger_fail('NO_SESSION', 'Login required', 401);
}

$userId = (int)($user['id'] ?? 0);
$wallet = mining_ledger_wallet($user);
if ($userId <= 0 || $wallet === '') {
    mining_ledger_fail('NO_SESSION', 'Login required', 401);
}

$rows = [];

if (function_exists('poado_get_ledger')) {
    try {
        $ledgerRows = poado_get_ledger($pdo, $userId, $wallet, 50);
        if (is_array($ledgerRows)) {
            foreach ($ledgerRows as $r) {
                $rows[] = [
                    'entry_type' => (string)($r['entry_type'] ?? 'mining_tick'),
                    'amount_wems' => round((float)($r['amount_wems'] ?? 0), 8),
                    'created_at' => (string)($r['created_at'] ?? ''),
                    'ref_code' => $r['ref_code'] ?? null,
                ];
            }
        }
    } catch (Throwable $e) {
        $rows = [];
    }
}

if (!$rows) {
    try {
        $st = $pdo->prepare("
            SELECT id, amount, reason, created_at
            FROM wems_mining_log
            WHERE user_id = ?
              AND reason = 'mining'
            ORDER BY id DESC
            LIMIT 50
        ");
        $st->execute([$userId]);
        $legacyRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($legacyRows as $r) {
            $rows[] = [
                'entry_type' => 'mining_tick',
                'amount_wems' => round(((int)($r['amount'] ?? 0)) / 100000000, 8),
                'created_at' => (string)($r['created_at'] ?? ''),
                'ref_code' => null,
            ];
        }
    } catch (Throwable $e) {
        $rows = [];
    }
}

$totals = mining_ledger_totals($pdo, $userId, $wallet);

mining_ledger_json([
    'ok' => true,
    'rows' => $rows,
    'total_mined_wems' => round((float)$totals['total_mined_wems'], 8),
    'storage_unclaimed_wems' => round((float)$totals['storage_unclaimed_wems'], 8),
    'wallet' => $wallet,
]);
