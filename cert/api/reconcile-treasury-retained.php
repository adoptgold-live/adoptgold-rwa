<?php
declare(strict_types=1);

/**
 * POAdo RWA Cert Engine
 * File: /var/www/html/public/rwa/cert/api/reconcile-treasury-retained.php
 *
 * Purpose:
 * - Reconcile Treasury/Admin retained ledger rows
 * - Reads/writes:
 *     wems_db.poado_rwa_treasury_retained
 *
 * Current design:
 * - Admin-only
 * - Reconciles one or multiple retained rows
 * - Marks rows as reconciled using submitted treasury/accounting reference
 * - This is ledger/accounting settlement only; no chain tx is broadcast here
 *
 * Assumed Treasury retained ledger table:
 *   poado_rwa_treasury_retained
 *
 * Fields used:
 *   id
 *   retain_uid
 *   event_uid
 *   cert_uid
 *   marketplace
 *   treasury_wallet
 *   retained_ton
 *   snapshot_time
 *   status
 *   note
 *   created_at
 *
 * Recommended schema extension for full reconciliation tracking:
 *   reconciled_ref VARCHAR(128) NULL
 *   reconciled_at DATETIME NULL
 *   reconciled_by_wallet VARCHAR(128) NULL
 *
 * If those columns do not exist yet, this file still works by:
 * - setting status='reconciled'
 * - appending reconciliation info into note
 */

header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/validators.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/guards.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/json.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/session-user.php';

if (!function_exists('poado_retain_reconcile_exit')) {
    function poado_retain_reconcile_exit(array $payload, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('poado_retain_reconcile_is_admin_like')) {
    function poado_retain_reconcile_is_admin_like(array $user): bool
    {
        return !empty($user['is_admin']) || !empty($user['is_senior']);
    }
}

if (!function_exists('poado_retain_reconcile_norm_decimal')) {
    function poado_retain_reconcile_norm_decimal($value, int $scale = 9): string
    {
        if ($value === null || $value === '') {
            return number_format(0, $scale, '.', '');
        }
        $v = trim((string)$value);
        $v = str_replace(',', '', $v);
        if (!is_numeric($v)) {
            return number_format(0, $scale, '.', '');
        }
        return number_format((float)$v, $scale, '.', '');
    }
}

try {
    $wallet = get_wallet_session();
    if (!$wallet) {
        poado_retain_reconcile_exit([
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
        poado_retain_reconcile_exit([
            'ok' => false,
            'error' => 'user_not_found',
            'message' => 'User not found.',
        ], 404);
    }

    if ((int)($user['is_active'] ?? 0) !== 1) {
        poado_retain_reconcile_exit([
            'ok' => false,
            'error' => 'user_inactive',
            'message' => 'User inactive.',
        ], 403);
    }

    if (!poado_retain_reconcile_is_admin_like($user)) {
        poado_retain_reconcile_exit([
            'ok' => false,
            'error' => 'admin_only',
            'message' => 'Treasury retained reconciliation is restricted to admin/senior operators.',
        ], 403);
    }

    $token = (string)($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '');
    $csrf_ok = true;
    try {
        $r = csrf_check('rwa_reconcile_treasury_retained', $token);
        if ($r === false) $csrf_ok = false;
    } catch (Throwable $e) {
        $csrf_ok = false;
    }
    if (!$csrf_ok) {
        poado_retain_reconcile_exit([
            'ok' => false,
            'error' => 'csrf_failed',
            'message' => 'Security validation failed.',
        ], 419);
    }

    $rawBody = file_get_contents('php://input');
    $jsonBody = json_decode((string)$rawBody, true);

    $retainUids = [];
    if (is_array($jsonBody) && isset($jsonBody['retain_uids']) && is_array($jsonBody['retain_uids'])) {
        $retainUids = $jsonBody['retain_uids'];
    } elseif (!empty($_POST['retain_uids']) && is_array($_POST['retain_uids'])) {
        $retainUids = $_POST['retain_uids'];
    } elseif (!empty($_GET['retain_uids']) && is_array($_GET['retain_uids'])) {
        $retainUids = $_GET['retain_uids'];
    } elseif (!empty($_POST['retain_uid'])) {
        $retainUids = [$_POST['retain_uid']];
    } elseif (!empty($_GET['retain_uid'])) {
        $retainUids = [$_GET['retain_uid']];
    }

    $reconciledRef = trim((string)(
        $_POST['reconciled_ref']
        ?? $_GET['reconciled_ref']
        ?? ($jsonBody['reconciled_ref'] ?? '')
    ));

    $noteAppend = trim((string)(
        $_POST['note']
        ?? $_GET['note']
        ?? ($jsonBody['note'] ?? '')
    ));

    if (!$retainUids) {
        poado_retain_reconcile_exit([
            'ok' => false,
            'error' => 'missing_retain_uids',
            'message' => 'At least one retain UID is required.',
        ], 422);
    }

    if ($reconciledRef === '') {
        poado_retain_reconcile_exit([
            'ok' => false,
            'error' => 'missing_reconciled_ref',
            'message' => 'reconciled_ref is required.',
        ], 422);
    }

    $retainUids = array_values(array_unique(array_filter(array_map(
        static fn($v) => preg_replace('/[^A-Za-z0-9\-]/', '', (string)$v) ?: '',
        $retainUids
    ))));

    if (!$retainUids) {
        poado_retain_reconcile_exit([
            'ok' => false,
            'error' => 'invalid_retain_uids',
            'message' => 'No valid retain UIDs supplied.',
        ], 422);
    }

    $placeholders = implode(',', array_fill(0, count($retainUids), '?'));

    $selectSql = "
        SELECT
            id,
            retain_uid,
            event_uid,
            cert_uid,
            marketplace,
            treasury_wallet,
            retained_ton,
            snapshot_time,
            status,
            note,
            created_at
        FROM poado_rwa_treasury_retained
        WHERE retain_uid IN ($placeholders)
        ORDER BY id ASC
    ";

    $stmt = $pdo->prepare($selectSql);
    $stmt->execute($retainUids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (!$rows) {
        poado_retain_reconcile_exit([
            'ok' => false,
            'error' => 'rows_not_found',
            'message' => 'No matching Treasury retained rows found.',
        ], 404);
    }

    $foundUids = array_map(static fn($r) => (string)$r['retain_uid'], $rows);
    $missingUids = array_values(array_diff($retainUids, $foundUids));

    $reconciledAt = gmdate('Y-m-d H:i:s');
    $updated = [];
    $skipped = [];
    $totalReconciledTon = number_format(0, 9, '.', '');

    /**
     * Safe baseline:
     * - always update status + note
     * - do not assume optional columns exist
     *
     * If you later add reconciled_ref / reconciled_at / reconciled_by_wallet columns,
     * this UPDATE can be upgraded directly.
     */
    $updateStmt = $pdo->prepare("
        UPDATE poado_rwa_treasury_retained
        SET
            status = :status,
            note = :note
        WHERE id = :id
        LIMIT 1
    ");

    foreach ($rows as $row) {
        $status = strtolower((string)($row['status'] ?? ''));
        $retainedTon = poado_retain_reconcile_norm_decimal($row['retained_ton'] ?? '0');

        if (in_array($status, ['reconciled', 'void'], true)) {
            $skipped[] = [
                'retain_uid' => (string)$row['retain_uid'],
                'status' => $status,
                'retained_ton' => $retainedTon,
                'message' => 'Treasury retained row is not reconcilable.',
            ];
            continue;
        }

        $existingNote = trim((string)($row['note'] ?? ''));
        $parts = [];

        if ($existingNote !== '') {
            $parts[] = $existingNote;
        }

        $parts[] = 'Reconciled ref=' . $reconciledRef;
        $parts[] = 'at=' . $reconciledAt;
        $parts[] = 'by=' . (string)$user['wallet'];

        if ($noteAppend !== '') {
            $parts[] = 'note=' . $noteAppend;
        }

        $newNote = implode(' | ', $parts);

        $updateStmt->execute([
            ':status' => 'reconciled',
            ':note' => $newNote,
            ':id' => (int)$row['id'],
        ]);

        $totalReconciledTon = number_format(
            (float)$totalReconciledTon + (float)$retainedTon,
            9,
            '.',
            ''
        );

        $updated[] = [
            'retain_uid' => (string)$row['retain_uid'],
            'event_uid' => (string)$row['event_uid'],
            'cert_uid' => (string)$row['cert_uid'],
            'marketplace' => (string)$row['marketplace'],
            'treasury_wallet' => (string)$row['treasury_wallet'],
            'retained_ton' => $retainedTon,
            'reconciled_ref' => $reconciledRef,
            'reconciled_at' => $reconciledAt,
            'reconciled_by_wallet' => (string)$user['wallet'],
            'status' => 'reconciled',
        ];
    }

    poado_retain_reconcile_exit([
        'ok' => true,
        'message' => 'Treasury retained reconciliation processed.',
        'requested_count' => count($retainUids),
        'found_count' => count($rows),
        'updated_count' => count($updated),
        'skipped_count' => count($skipped),
        'missing_count' => count($missingUids),
        'total_reconciled_ton' => $totalReconciledTon,
        'reconciled_ref' => $reconciledRef,
        'reconciled_at' => $reconciledAt,
        'reconciled_by_wallet' => (string)$user['wallet'],
        'updated' => $updated,
        'skipped' => $skipped,
        'missing_retain_uids' => $missingUids,
    ], 200);

} catch (Throwable $e) {
    poado_retain_reconcile_exit([
        'ok' => false,
        'error' => 'server_error',
        'message' => 'Failed to reconcile Treasury retained rows.',
        'details' => $e->getMessage(),
    ], 500);
}