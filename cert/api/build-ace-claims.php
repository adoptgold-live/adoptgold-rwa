<?php
declare(strict_types=1);

/**
 * POAdo RWA Cert Engine
 * File: /var/www/html/public/rwa/cert/api/build-ace-claims.php
 *
 * Purpose:
 * - Build ACE claim allocations from royalty ledger events
 * - Uses locked royalty ledger table:
 *     wems_db.poado_rwa_royalty_events_v2
 * - Uses locked ACE pool portion:
 *     ace_pool_ton
 * - Allocates by RK92-EMA sales weight
 *
 * Current design:
 * - Admin-only build endpoint
 * - Snapshot-based weighted allocation
 * - Only allocates events not yet allocated to ACE ledger
 *
 * Assumed ACE claim ledger table:
 *
 * CREATE TABLE poado_rwa_ace_claims (
 *   id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *   claim_uid VARCHAR(64) NOT NULL UNIQUE,
 *   event_uid VARCHAR(64) NOT NULL,
 *   ace_user_id BIGINT UNSIGNED NOT NULL,
 *   ace_wallet VARCHAR(128) NOT NULL,
 *   weight_value DECIMAL(20,9) NOT NULL,
 *   allocated_ton DECIMAL(20,9) NOT NULL,
 *   snapshot_time DATETIME NOT NULL,
 *   status VARCHAR(32) NOT NULL DEFAULT 'claimable',
 *   claimed_ton DECIMAL(20,9) NOT NULL DEFAULT 0,
 *   claimed_tx_hash VARCHAR(128) NULL,
 *   claimed_at DATETIME NULL,
 *   created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *   KEY idx_event_uid (event_uid),
 *   KEY idx_ace_user_id (ace_user_id),
 *   KEY idx_ace_wallet (ace_wallet),
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

if (!function_exists('poado_ace_exit')) {
    function poado_ace_exit(array $payload, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('poado_ace_is_admin_like')) {
    function poado_ace_is_admin_like(array $user): bool
    {
        return !empty($user['is_admin']) || !empty($user['is_senior']);
    }
}

if (!function_exists('poado_ace_claim_uid')) {
    function poado_ace_claim_uid(): string
    {
        return 'ACLAIM-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
    }
}

if (!function_exists('poado_ace_norm_decimal')) {
    function poado_ace_norm_decimal($value, int $scale = 9): string
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

if (!function_exists('poado_ace_mul')) {
    function poado_ace_mul(string $a, string $b, int $scale = 9): string
    {
        if (function_exists('bcmul')) {
            return bcmul($a, $b, $scale);
        }
        return number_format(((float)$a) * ((float)$b), $scale, '.', '');
    }
}

if (!function_exists('poado_ace_div')) {
    function poado_ace_div(string $a, string $b, int $scale = 9): string
    {
        if ((float)$b <= 0) {
            return number_format(0, $scale, '.', '');
        }
        if (function_exists('bcdiv')) {
            return bcdiv($a, $b, $scale);
        }
        return number_format(((float)$a) / ((float)$b), $scale, '.', '');
    }
}

try {
    $wallet = get_wallet_session();
    if (!$wallet) {
        poado_ace_exit([
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
        poado_ace_exit([
            'ok' => false,
            'error' => 'user_not_found',
            'message' => 'User not found.',
        ], 404);
    }

    if ((int)($user['is_active'] ?? 0) !== 1) {
        poado_ace_exit([
            'ok' => false,
            'error' => 'user_inactive',
            'message' => 'User inactive.',
        ], 403);
    }

    if (!poado_ace_is_admin_like($user)) {
        poado_ace_exit([
            'ok' => false,
            'error' => 'admin_only',
            'message' => 'ACE claim build is restricted to admin/senior operators.',
        ], 403);
    }

    $token = (string)($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '');
    $csrf_ok = true;
    try {
        $r = csrf_check('rwa_build_ace_claims', $token);
        if ($r === false) $csrf_ok = false;
    } catch (Throwable $e) {
        $csrf_ok = false;
    }
    if (!$csrf_ok) {
        poado_ace_exit([
            'ok' => false,
            'error' => 'csrf_failed',
            'message' => 'Security validation failed.',
        ], 419);
    }

    $limit = max(1, min(200, (int)($_POST['limit'] ?? $_GET['limit'] ?? 50)));

    /**
     * Unallocated royalty events for ACE claims
     */
    $eventStmt = $pdo->prepare("
        SELECT e.*
        FROM poado_rwa_royalty_events_v2 e
        WHERE NOT EXISTS (
            SELECT 1
            FROM poado_rwa_ace_claims ac
            WHERE ac.event_uid = e.event_uid
        )
        ORDER BY e.id ASC
        LIMIT :limit
    ");
    $eventStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $eventStmt->execute();
    $events = $eventStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (!$events) {
        poado_ace_exit([
            'ok' => true,
            'message' => 'No unallocated ACE royalty events found.',
            'processed' => 0,
            'inserted_count' => 0,
            'items' => [],
        ], 200);
    }

    /**
     * RK92-EMA sales weight source
     *
     * Locked rule says ACE pool must be weighted by RK92-EMA sales.
     *
     * Baseline implementation:
     * - read accepted bookings linked to package_key = RK92-EMA
     * - group by ace_wallet
     * - weight = count(*) of accepted RK92-EMA sales
     *
     * Uses locked canonical booking/deal baseline assumptions.
     */
    $weightStmt = $pdo->prepare("
        SELECT
            b.ace_wallet,
            COUNT(*) AS weight_count
        FROM poado_bookings b
        WHERE b.status = 'accepted'
          AND (
                b.package_key = 'RK92-EMA'
                OR b.package_code = 'RK92-EMA'
              )
          AND b.ace_wallet IS NOT NULL
          AND b.ace_wallet <> ''
        GROUP BY b.ace_wallet
    ");

    /**
     * Resolve ACE wallet -> users row
     */
    $aceUserStmt = $pdo->prepare("
        SELECT id, wallet
        FROM users
        WHERE wallet = :wallet
          AND role = 'ace'
          AND is_active = 1
        LIMIT 1
    ");

    $claimExistsStmt = $pdo->prepare("
        SELECT id
        FROM poado_rwa_ace_claims
        WHERE event_uid = :event_uid
          AND ace_user_id = :ace_user_id
        LIMIT 1
    ");

    $insertStmt = $pdo->prepare("
        INSERT INTO poado_rwa_ace_claims
        (
            claim_uid,
            event_uid,
            ace_user_id,
            ace_wallet,
            weight_value,
            allocated_ton,
            snapshot_time,
            status,
            claimed_ton,
            claimed_tx_hash,
            claimed_at
        )
        VALUES
        (
            :claim_uid,
            :event_uid,
            :ace_user_id,
            :ace_wallet,
            :weight_value,
            :allocated_ton,
            :snapshot_time,
            :status,
            :claimed_ton,
            :claimed_tx_hash,
            :claimed_at
        )
    ");

    $weightStmt->execute();
    $rawWeights = $weightStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $aceWeights = [];
    $totalWeight = 0.0;

    foreach ($rawWeights as $row) {
        $aceWallet = trim((string)($row['ace_wallet'] ?? ''));
        $weightCount = (float)($row['weight_count'] ?? 0);

        if ($aceWallet === '' || $weightCount <= 0) {
            continue;
        }

        $aceUserStmt->execute([':wallet' => $aceWallet]);
        $aceUser = $aceUserStmt->fetch(PDO::FETCH_ASSOC);
        if (!$aceUser) {
            continue;
        }

        $aceWeights[] = [
            'ace_user_id' => (int)$aceUser['id'],
            'ace_wallet' => (string)$aceUser['wallet'],
            'weight_value' => number_format($weightCount, 9, '.', ''),
        ];
        $totalWeight += $weightCount;
    }

    if ($totalWeight <= 0) {
        poado_ace_exit([
            'ok' => false,
            'error' => 'no_ace_weights',
            'message' => 'No eligible ACE RK92-EMA sales weights found.',
        ], 422);
    }

    $inserted = [];
    $errors = [];

    foreach ($events as $event) {
        try {
            $eventUid = (string)$event['event_uid'];
            $acePoolTon = poado_ace_norm_decimal($event['ace_pool_ton'] ?? '0');
            $snapshotTime = (string)($event['block_time'] ?? gmdate('Y-m-d H:i:s'));

            if ((float)$acePoolTon <= 0) {
                continue;
            }

            $runningAllocated = 0.0;
            $rowInserted = 0;
            $aceCount = count($aceWeights);

            foreach ($aceWeights as $i => $ace) {
                $aceUserId = (int)$ace['ace_user_id'];
                $aceWallet = (string)$ace['ace_wallet'];
                $weightValue = (string)$ace['weight_value'];

                $claimExistsStmt->execute([
                    ':event_uid' => $eventUid,
                    ':ace_user_id' => $aceUserId,
                ]);
                if ($claimExistsStmt->fetch(PDO::FETCH_ASSOC)) {
                    continue;
                }

                if ($i === $aceCount - 1) {
                    $allocatedTon = number_format(
                        max(0, (float)$acePoolTon - $runningAllocated),
                        9,
                        '.',
                        ''
                    );
                } else {
                    $ratio = poado_ace_div($weightValue, number_format($totalWeight, 9, '.', ''), 18);
                    $allocatedTon = poado_ace_mul($acePoolTon, $ratio, 9);
                }

                $insertStmt->execute([
                    ':claim_uid' => poado_ace_claim_uid(),
                    ':event_uid' => $eventUid,
                    ':ace_user_id' => $aceUserId,
                    ':ace_wallet' => $aceWallet,
                    ':weight_value' => $weightValue,
                    ':allocated_ton' => $allocatedTon,
                    ':snapshot_time' => $snapshotTime,
                    ':status' => 'claimable',
                    ':claimed_ton' => number_format(0, 9, '.', ''),
                    ':claimed_tx_hash' => null,
                    ':claimed_at' => null,
                ]);

                $runningAllocated += (float)$allocatedTon;
                $rowInserted++;
            }

            $inserted[] = [
                'event_uid' => $eventUid,
                'ace_pool_ton' => $acePoolTon,
                'eligible_ace_count' => count($aceWeights),
                'total_weight' => number_format($totalWeight, 9, '.', ''),
                'inserted_claim_rows' => $rowInserted,
                'snapshot_time' => $snapshotTime,
            ];
        } catch (Throwable $e) {
            $errors[] = [
                'event_uid' => $event['event_uid'] ?? null,
                'message' => $e->getMessage(),
            ];
        }
    }

    poado_ace_exit([
        'ok' => true,
        'message' => 'ACE claim build processed.',
        'processed' => count($events),
        'inserted_count' => count($inserted),
        'error_count' => count($errors),
        'items' => $inserted,
        'errors' => $errors,
    ], 200);

} catch (Throwable $e) {
    poado_ace_exit([
        'ok' => false,
        'error' => 'server_error',
        'message' => 'Failed to build ACE claims.',
        'details' => $e->getMessage(),
    ], 500);
}