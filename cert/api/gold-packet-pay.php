<?php
declare(strict_types=1);

/**
 * POAdo RWA Cert Engine
 * File: /rwa/cert/api/gold-packet-pay.php
 *
 * Purpose:
 * - Settle daily Gold Packet distribution rows
 * - Reads/writes:
 *     wems_db.poado_rwa_gold_packet_distributions
 *
 * Current design:
 * - Admin-only
 * - Marks one or multiple pending distribution rows as paid
 * - Records treasury/admin payout tx hash
 * - Ledger settlement only; does not broadcast chain tx itself
 */

header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/validators.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/guards.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/json.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/session-user.php';

if (!function_exists('poado_gpp_exit')) {
    function poado_gpp_exit(array $payload, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('poado_gpp_is_admin_like')) {
    function poado_gpp_is_admin_like(array $user): bool
    {
        return !empty($user['is_admin']) || !empty($user['is_senior']);
    }
}

if (!function_exists('poado_gpp_norm_decimal')) {
    function poado_gpp_norm_decimal($value, int $scale = 9): string
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

try {
    $wallet = get_wallet_session();
    if (!$wallet) {
        poado_gpp_exit([
            'ok' => false,
            'error' => 'not_logged_in',
            'message' => 'Login required.',
        ], 401);
    }

    db_connect();
    /** @var PDO $pdo */
    $pdo = $GLOBALS['pdo'];

    $userStmt = $pdo->prepare("
        SELECT id, wallet, nickname, role, is_active, is_admin, is_senior
        FROM users
        WHERE wallet = :wallet
        LIMIT 1
    ");
    $userStmt->execute([':wallet' => $wallet]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        poado_gpp_exit([
            'ok' => false,
            'error' => 'user_not_found',
            'message' => 'User not found.',
        ], 404);
    }

    if ((int)($user['is_active'] ?? 0) !== 1) {
        poado_gpp_exit([
            'ok' => false,
            'error' => 'user_inactive',
            'message' => 'User inactive.',
        ], 403);
    }

    if (!poado_gpp_is_admin_like($user)) {
        poado_gpp_exit([
            'ok' => false,
            'error' => 'admin_only',
            'message' => 'Gold Packet payout is restricted to admin/senior operators.',
        ], 403);
    }

    $token = (string)($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '');
    $csrf_ok = true;
    try {
        $r = csrf_check('rwa_gold_packet_pay', $token);
        if ($r === false) $csrf_ok = false;
    } catch (Throwable $e) {
        $csrf_ok = false;
    }

    if (!$csrf_ok) {
        poado_gpp_exit([
            'ok' => false,
            'error' => 'csrf_failed',
            'message' => 'Security validation failed.',
        ], 419);
    }

    $rawBody = file_get_contents('php://input');
    $jsonBody = json_decode((string)$rawBody, true);

    $distributionUids = [];
    if (is_array($jsonBody) && isset($jsonBody['distribution_uids']) && is_array($jsonBody['distribution_uids'])) {
        $distributionUids = $jsonBody['distribution_uids'];
    } elseif (!empty($_POST['distribution_uids']) && is_array($_POST['distribution_uids'])) {
        $distributionUids = $_POST['distribution_uids'];
    } elseif (!empty($_GET['distribution_uids']) && is_array($_GET['distribution_uids'])) {
        $distributionUids = $_GET['distribution_uids'];
    } elseif (!empty($_POST['distribution_uid'])) {
        $distributionUids = [$_POST['distribution_uid']];
    } elseif (!empty($_GET['distribution_uid'])) {
        $distributionUids = [$_GET['distribution_uid']];
    }

    $payoutTxHash = trim((string)(
        $_POST['payout_tx_hash']
        ?? $_GET['payout_tx_hash']
        ?? ($jsonBody['payout_tx_hash'] ?? '')
    ));

    if (!$distributionUids) {
        poado_gpp_exit([
            'ok' => false,
            'error' => 'missing_distribution_uids',
            'message' => 'At least one distribution UID is required.',
        ], 422);
    }

    if ($payoutTxHash === '') {
        poado_gpp_exit([
            'ok' => false,
            'error' => 'missing_payout_tx_hash',
            'message' => 'payout_tx_hash is required.',
        ], 422);
    }

    $distributionUids = array_values(array_unique(array_filter(array_map(
        static fn($v) => preg_replace('/[^A-Za-z0-9\-]/', '', (string)$v) ?: '',
        $distributionUids
    ))));

    if (!$distributionUids) {
        poado_gpp_exit([
            'ok' => false,
            'error' => 'invalid_distribution_uids',
            'message' => 'No valid distribution UIDs supplied.',
        ], 422);
    }

    $placeholders = implode(',', array_fill(0, count($distributionUids), '?'));

    $selectSql = "
        SELECT
            id,
            distribution_uid,
            distribution_date,
            owner_user_id,
            owner_wallet,
            cert_count,
            allocated_ton,
            status,
            payout_tx_hash,
            paid_at,
            created_at
        FROM poado_rwa_gold_packet_distributions
        WHERE distribution_uid IN ($placeholders)
        ORDER BY id ASC
    ";

    $stmt = $pdo->prepare($selectSql);
    $stmt->execute($distributionUids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (!$rows) {
        poado_gpp_exit([
            'ok' => false,
            'error' => 'rows_not_found',
            'message' => 'No matching Gold Packet distribution rows found.',
        ], 404);
    }

    $foundUids = array_map(static fn($r) => (string)$r['distribution_uid'], $rows);
    $missingUids = array_values(array_diff($distributionUids, $foundUids));

    $paidAt = gmdate('Y-m-d H:i:s');
    $updated = [];
    $skipped = [];
    $totalPaidTon = number_format(0, 9, '.', '');

    $updateStmt = $pdo->prepare("
        UPDATE poado_rwa_gold_packet_distributions
        SET
            status = :status,
            payout_tx_hash = :payout_tx_hash,
            paid_at = :paid_at
        WHERE id = :id
        LIMIT 1
    ");

    foreach ($rows as $row) {
        $status = strtolower((string)($row['status'] ?? ''));
        $allocatedTon = poado_gpp_norm_decimal($row['allocated_ton'] ?? '0');

        if (in_array($status, ['paid', 'void'], true)) {
            $skipped[] = [
                'distribution_uid' => (string)$row['distribution_uid'],
                'status' => $status,
                'allocated_ton' => $allocatedTon,
                'message' => 'Distribution row is not payable.',
            ];
            continue;
        }

        $updateStmt->execute([
            ':status' => 'paid',
            ':payout_tx_hash' => $payoutTxHash,
            ':paid_at' => $paidAt,
            ':id' => (int)$row['id'],
        ]);

        $totalPaidTon = number_format(
            (float)$totalPaidTon + (float)$allocatedTon,
            9,
            '.',
            ''
        );

        $updated[] = [
            'distribution_uid' => (string)$row['distribution_uid'],
            'distribution_date' => (string)$row['distribution_date'],
            'owner_user_id' => (int)$row['owner_user_id'],
            'owner_wallet' => (string)$row['owner_wallet'],
            'cert_count' => (int)$row['cert_count'],
            'allocated_ton' => $allocatedTon,
            'payout_tx_hash' => $payoutTxHash,
            'paid_at' => $paidAt,
            'status' => 'paid',
        ];
    }

    poado_gpp_exit([
        'ok' => true,
        'message' => 'Gold Packet payout settlement processed.',
        'requested_count' => count($distributionUids),
        'found_count' => count($rows),
        'updated_count' => count($updated),
        'skipped_count' => count($skipped),
        'missing_count' => count($missingUids),
        'total_paid_ton' => $totalPaidTon,
        'payout_tx_hash' => $payoutTxHash,
        'paid_at' => $paidAt,
        'updated' => $updated,
        'skipped' => $skipped,
        'missing_distribution_uids' => $missingUids,
    ], 200);

} catch (Throwable $e) {
    poado_gpp_exit([
        'ok' => false,
        'error' => 'server_error',
        'message' => 'Failed to process Gold Packet payout.',
        'details' => $e->getMessage(),
    ], 500);
}