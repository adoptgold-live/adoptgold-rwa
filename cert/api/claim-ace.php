<?php
declare(strict_types=1);

/**
 * POAdo RWA Cert Engine
 * File: /var/www/html/public/rwa/cert/api/claim-ace.php
 *
 * Purpose:
 * - Claim ACE TON rewards from ACE claim ledger
 * - Reads/writes:
 *     wems_db.poado_rwa_ace_claims
 *
 * Current design:
 * - ACE owner-only
 * - Claim one or multiple ACE claim rows
 * - Marks rows as claimed using submitted treasury/admin payout tx hash
 * - This is ledger settlement only; it does not broadcast chain tx itself
 *
 * Assumed ACE claim ledger table:
 *   poado_rwa_ace_claims
 *
 * Fields used:
 *   id
 *   claim_uid
 *   event_uid
 *   ace_user_id
 *   ace_wallet
 *   weight_value
 *   allocated_ton
 *   snapshot_time
 *   status
 *   claimed_ton
 *   claimed_tx_hash
 *   claimed_at
 *   created_at
 */

header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/validators.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/guards.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/json.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/session-user.php';

if (!function_exists('poado_claim_ace_exit')) {
    function poado_claim_ace_exit(array $payload, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('poado_claim_ace_norm_decimal')) {
    function poado_claim_ace_norm_decimal($value, int $scale = 9): string
    {
        if ($value === null || $value === '') {
            return number_format(0, $scale, '.', '');
        }
        $v = trim((string)$value);
        $v = str_replace(',', '', $v);
        if (!is_numeric($v)) {
            throw new InvalidArgumentException('Invalid decimal value: ' . $v);
        }
        return number_format((float)$v, $scale, '.', '');
    }
}

if (!function_exists('poado_claim_ace_sub')) {
    function poado_claim_ace_sub(string $a, string $b, int $scale = 9): string
    {
        if (function_exists('bcsub')) {
            return bcsub($a, $b, $scale);
        }
        return number_format(((float)$a - (float)$b), $scale, '.', '');
    }
}

if (!function_exists('poado_claim_ace_add')) {
    function poado_claim_ace_add(string $a, string $b, int $scale = 9): string
    {
        if (function_exists('bcadd')) {
            return bcadd($a, $b, $scale);
        }
        return number_format(((float)$a + (float)$b), $scale, '.', '');
    }
}

try {
    $wallet = get_wallet_session();
    if (!$wallet) {
        poado_claim_ace_exit([
            'ok' => false,
            'error' => 'not_logged_in',
            'message' => 'Login required.',
        ], 401);
    }

    db_connect();
    /** @var PDO $pdo */
    $pdo = $GLOBALS['pdo'];

    $userStmt = $pdo->prepare("
        SELECT id, wallet, nickname, role, is_active
        FROM users
        WHERE wallet = :wallet
        LIMIT 1
    ");
    $userStmt->execute([':wallet' => $wallet]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        poado_claim_ace_exit([
            'ok' => false,
            'error' => 'user_not_found',
            'message' => 'User not found.',
        ], 404);
    }

    if ((int)($user['is_active'] ?? 0) !== 1) {
        poado_claim_ace_exit([
            'ok' => false,
            'error' => 'user_inactive',
            'message' => 'User inactive.',
        ], 403);
    }

    $token = (string)($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '');
    $csrf_ok = true;
    try {
        $r = csrf_check('rwa_claim_ace', $token);
        if ($r === false) $csrf_ok = false;
    } catch (Throwable $e) {
        $csrf_ok = false;
    }
    if (!$csrf_ok) {
        poado_claim_ace_exit([
            'ok' => false,
            'error' => 'csrf_failed',
            'message' => 'Security validation failed.',
        ], 419);
    }

    $rawBody = file_get_contents('php://input');
    $jsonBody = json_decode((string)$rawBody, true);

    $claimUids = [];
    if (is_array($jsonBody) && isset($jsonBody['claim_uids']) && is_array($jsonBody['claim_uids'])) {
        $claimUids = $jsonBody['claim_uids'];
    } elseif (!empty($_POST['claim_uids']) && is_array($_POST['claim_uids'])) {
        $claimUids = $_POST['claim_uids'];
    } elseif (!empty($_GET['claim_uids']) && is_array($_GET['claim_uids'])) {
        $claimUids = $_GET['claim_uids'];
    } elseif (!empty($_POST['claim_uid'])) {
        $claimUids = [$_POST['claim_uid']];
    } elseif (!empty($_GET['claim_uid'])) {
        $claimUids = [$_GET['claim_uid']];
    }

    $claimedTxHash = trim((string)(
        $_POST['claimed_tx_hash']
        ?? $_GET['claimed_tx_hash']
        ?? ($jsonBody['claimed_tx_hash'] ?? '')
    ));

    if (!$claimUids) {
        poado_claim_ace_exit([
            'ok' => false,
            'error' => 'missing_claim_uids',
            'message' => 'At least one claim UID is required.',
        ], 422);
    }

    if ($claimedTxHash === '') {
        poado_claim_ace_exit([
            'ok' => false,
            'error' => 'missing_claimed_tx_hash',
            'message' => 'claimed_tx_hash is required.',
        ], 422);
    }

    $claimUids = array_values(array_unique(array_filter(array_map(
        static fn($v) => preg_replace('/[^A-Za-z0-9\-]/', '', (string)$v) ?: '',
        $claimUids
    ))));

    if (!$claimUids) {
        poado_claim_ace_exit([
            'ok' => false,
            'error' => 'invalid_claim_uids',
            'message' => 'No valid claim UIDs supplied.',
        ], 422);
    }

    $placeholders = implode(',', array_fill(0, count($claimUids), '?'));

    $sql = "
        SELECT
            id,
            claim_uid,
            event_uid,
            ace_user_id,
            ace_wallet,
            weight_value,
            allocated_ton,
            status,
            claimed_ton,
            claimed_tx_hash,
            claimed_at
        FROM poado_rwa_ace_claims
        WHERE claim_uid IN ($placeholders)
          AND ace_user_id = ?
        ORDER BY id ASC
    ";

    $stmt = $pdo->prepare($sql);
    $bind = array_merge($claimUids, [(int)$user['id']]);
    $stmt->execute($bind);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (!$rows) {
        poado_claim_ace_exit([
            'ok' => false,
            'error' => 'claims_not_found',
            'message' => 'No matching ACE claims found for current user.',
        ], 404);
    }

    $foundUids = array_map(static fn($r) => (string)$r['claim_uid'], $rows);
    $missingUids = array_values(array_diff($claimUids, $foundUids));

    $claimAt = gmdate('Y-m-d H:i:s');
    $updated = [];
    $skipped = [];
    $totalClaimedNow = number_format(0, 9, '.', '');

    $updateStmt = $pdo->prepare("
        UPDATE poado_rwa_ace_claims
        SET
            status = :status,
            claimed_ton = :claimed_ton,
            claimed_tx_hash = :claimed_tx_hash,
            claimed_at = :claimed_at
        WHERE id = :id
        LIMIT 1
    ");

    foreach ($rows as $row) {
        $allocatedTon = poado_claim_ace_norm_decimal($row['allocated_ton'] ?? '0');
        $alreadyClaimedTon = poado_claim_ace_norm_decimal($row['claimed_ton'] ?? '0');
        $remainingTon = poado_claim_ace_sub($allocatedTon, $alreadyClaimedTon, 9);
        $status = strtolower((string)($row['status'] ?? ''));

        if ((float)$remainingTon <= 0 || in_array($status, ['claimed', 'void'], true)) {
            $skipped[] = [
                'claim_uid' => (string)$row['claim_uid'],
                'status' => $status,
                'remaining_ton' => $remainingTon,
                'message' => 'ACE claim row is not claimable.',
            ];
            continue;
        }

        $newClaimedTon = poado_claim_ace_add($alreadyClaimedTon, $remainingTon, 9);

        $updateStmt->execute([
            ':status' => 'claimed',
            ':claimed_ton' => $newClaimedTon,
            ':claimed_tx_hash' => $claimedTxHash,
            ':claimed_at' => $claimAt,
            ':id' => (int)$row['id'],
        ]);

        $totalClaimedNow = poado_claim_ace_add($totalClaimedNow, $remainingTon, 9);

        $updated[] = [
            'claim_uid' => (string)$row['claim_uid'],
            'event_uid' => (string)$row['event_uid'],
            'weight_value' => poado_claim_ace_norm_decimal($row['weight_value'] ?? '0'),
            'claimed_now_ton' => $remainingTon,
            'claimed_total_ton' => $newClaimedTon,
            'claimed_tx_hash' => $claimedTxHash,
            'claimed_at' => $claimAt,
            'status' => 'claimed',
        ];
    }

    poado_claim_ace_exit([
        'ok' => true,
        'message' => 'ACE claim settlement processed.',
        'user' => [
            'id' => (int)$user['id'],
            'wallet' => (string)$user['wallet'],
            'nickname' => (string)($user['nickname'] ?? ''),
            'role' => (string)($user['role'] ?? ''),
        ],
        'requested_count' => count($claimUids),
        'found_count' => count($rows),
        'updated_count' => count($updated),
        'skipped_count' => count($skipped),
        'missing_count' => count($missingUids),
        'total_claimed_now_ton' => $totalClaimedNow,
        'claimed_tx_hash' => $claimedTxHash,
        'claimed_at' => $claimAt,
        'updated' => $updated,
        'skipped' => $skipped,
        'missing_claim_uids' => $missingUids,
    ], 200);

} catch (Throwable $e) {
    poado_claim_ace_exit([
        'ok' => false,
        'error' => 'server_error',
        'message' => 'Failed to process ACE claim.',
        'details' => $e->getMessage(),
    ], 500);
}