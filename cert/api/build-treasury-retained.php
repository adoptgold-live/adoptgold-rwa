<?php
declare(strict_types=1);

/**
 * POAdo RWA Cert Engine
 * File: /var/www/html/public/rwa/cert/api/build-treasury-retained.php
 *
 * Purpose:
 * - Build Treasury/Admin retained ledger allocations from royalty events
 * - Uses locked royalty ledger table:
 *     wems_db.poado_rwa_royalty_events_v2
 * - Uses locked retained portion:
 *     treasury_retained_ton
 * - Writes one retained-ledger row per royalty event
 *
 * Current design:
 * - Admin-only build endpoint
 * - One retained row per royalty event
 * - This is accounting/ledger only; no payout action is performed here
 *
 * Assumed Treasury retained ledger table:
 *
 * CREATE TABLE poado_rwa_treasury_retained (
 *   id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *   retain_uid VARCHAR(64) NOT NULL UNIQUE,
 *   event_uid VARCHAR(64) NOT NULL UNIQUE,
 *   cert_uid VARCHAR(64) NOT NULL,
 *   marketplace VARCHAR(32) NOT NULL,
 *   treasury_wallet VARCHAR(128) NOT NULL,
 *   retained_ton DECIMAL(20,9) NOT NULL,
 *   snapshot_time DATETIME NOT NULL,
 *   status VARCHAR(32) NOT NULL DEFAULT 'retained',
 *   note VARCHAR(255) NULL,
 *   created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *   KEY idx_event_uid (event_uid),
 *   KEY idx_cert_uid (cert_uid),
 *   KEY idx_status (status)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 */

header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/validators.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/guards.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/json.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/session-user.php';

if (!function_exists('poado_tr_exit')) {
    function poado_tr_exit(array $payload, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('poado_tr_is_admin_like')) {
    function poado_tr_is_admin_like(array $user): bool
    {
        return !empty($user['is_admin']) || !empty($user['is_senior']);
    }
}

if (!function_exists('poado_tr_uid')) {
    function poado_tr_uid(): string
    {
        return 'TRETAIN-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
    }
}

if (!function_exists('poado_tr_norm_decimal')) {
    function poado_tr_norm_decimal($value, int $scale = 9): string
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
        poado_tr_exit([
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
        poado_tr_exit([
            'ok' => false,
            'error' => 'user_not_found',
            'message' => 'User not found.',
        ], 404);
    }

    if ((int)($user['is_active'] ?? 0) !== 1) {
        poado_tr_exit([
            'ok' => false,
            'error' => 'user_inactive',
            'message' => 'User inactive.',
        ], 403);
    }

    if (!poado_tr_is_admin_like($user)) {
        poado_tr_exit([
            'ok' => false,
            'error' => 'admin_only',
            'message' => 'Treasury retained build is restricted to admin/senior operators.',
        ], 403);
    }

    $token = (string)($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '');
    $csrf_ok = true;
    try {
        $r = csrf_check('rwa_build_treasury_retained', $token);
        if ($r === false) $csrf_ok = false;
    } catch (Throwable $e) {
        $csrf_ok = false;
    }
    if (!$csrf_ok) {
        poado_tr_exit([
            'ok' => false,
            'error' => 'csrf_failed',
            'message' => 'Security validation failed.',
        ], 419);
    }

    $limit = max(1, min(500, (int)($_POST['limit'] ?? $_GET['limit'] ?? 100)));
    $treasuryWallet = 'UQBdYfGArtoCUmBs5TjYQtfPFfQuGC2Ydbj2pQr3zIlNrDta';

    /**
     * Unallocated royalty events for treasury retained ledger
     */
    $eventStmt = $pdo->prepare("
        SELECT e.*
        FROM poado_rwa_royalty_events_v2 e
        WHERE NOT EXISTS (
            SELECT 1
            FROM poado_rwa_treasury_retained t
            WHERE t.event_uid = e.event_uid
        )
        ORDER BY e.id ASC
        LIMIT :limit
    ");
    $eventStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $eventStmt->execute();
    $events = $eventStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (!$events) {
        poado_tr_exit([
            'ok' => true,
            'message' => 'No unallocated Treasury retained royalty events found.',
            'processed' => 0,
            'inserted_count' => 0,
            'items' => [],
        ], 200);
    }

    $insertStmt = $pdo->prepare("
        INSERT INTO poado_rwa_treasury_retained
        (
            retain_uid,
            event_uid,
            cert_uid,
            marketplace,
            treasury_wallet,
            retained_ton,
            snapshot_time,
            status,
            note
        )
        VALUES
        (
            :retain_uid,
            :event_uid,
            :cert_uid,
            :marketplace,
            :treasury_wallet,
            :retained_ton,
            :snapshot_time,
            :status,
            :note
        )
    ");

    $inserted = [];
    $errors = [];

    foreach ($events as $event) {
        try {
            $eventUid = (string)($event['event_uid'] ?? '');
            $certUid = (string)($event['cert_uid'] ?? '');
            $marketplace = trim((string)($event['marketplace'] ?? ''));
            $retainedTon = poado_tr_norm_decimal($event['treasury_retained_ton'] ?? '0');
            $snapshotTime = (string)($event['block_time'] ?? gmdate('Y-m-d H:i:s'));

            if ($eventUid === '' || $certUid === '' || $marketplace === '') {
                throw new RuntimeException('Missing event_uid, cert_uid, or marketplace.');
            }

            if ((float)$retainedTon <= 0) {
                continue;
            }

            $insertStmt->execute([
                ':retain_uid' => poado_tr_uid(),
                ':event_uid' => $eventUid,
                ':cert_uid' => $certUid,
                ':marketplace' => $marketplace,
                ':treasury_wallet' => $treasuryWallet,
                ':retained_ton' => $retainedTon,
                ':snapshot_time' => $snapshotTime,
                ':status' => 'retained',
                ':note' => 'Locked 5% Treasury/Admin retained allocation from royalty ledger.',
            ]);

            $inserted[] = [
                'event_uid' => $eventUid,
                'cert_uid' => $certUid,
                'marketplace' => $marketplace,
                'treasury_wallet' => $treasuryWallet,
                'retained_ton' => $retainedTon,
                'snapshot_time' => $snapshotTime,
                'status' => 'retained',
            ];
        } catch (Throwable $e) {
            $errors[] = [
                'event_uid' => $event['event_uid'] ?? null,
                'cert_uid' => $event['cert_uid'] ?? null,
                'message' => $e->getMessage(),
            ];
        }
    }

    poado_tr_exit([
        'ok' => true,
        'message' => 'Treasury retained build processed.',
        'processed' => count($events),
        'inserted_count' => count($inserted),
        'error_count' => count($errors),
        'items' => $inserted,
        'errors' => $errors,
    ], 200);

} catch (Throwable $e) {
    poado_tr_exit([
        'ok' => false,
        'error' => 'server_error',
        'message' => 'Failed to build Treasury retained ledger.',
        'details' => $e->getMessage(),
    ], 500);
}