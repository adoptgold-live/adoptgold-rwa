<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cron/manual-claims-reserve-reconcile.php
 *
 * Manual Claims Reserve Reconcile Cron
 *
 * Purpose:
 * - self-heal poado_token_manual_reserves against poado_token_manual_requests
 * - release stale requested reserves
 * - consume paid reserves
 * - release terminal-status reserves
 * - log anomalies into poado_api_errors
 * - write reserve action metadata back into poado_token_manual_requests.meta
 *
 * Locked policy:
 * - ACTIVE reserve + missing request row               => RELEASE reserve
 * - request status in rejected/failed/cancelled       => RELEASE reserve
 * - request status = paid and reserve ACTIVE          => CONSUME reserve
 * - request status = requested older than 24h         => cancel request + RELEASE reserve
 * - request status = approved older than 48h          => anomaly only
 * - request status = proof_submitted older than 72h   => anomaly only
 *
 * Notes:
 * - cron must never use $_SERVER['DOCUMENT_ROOT']
 * - cron must use filesystem-safe __DIR__ traversal only
 */

date_default_timezone_set('UTC');

require_once __DIR__ . '/../inc/core/bootstrap.php';
require_once __DIR__ . '/../inc/core/error.php';

const MCRON_MODULE = 'manual_claims_reserve_cron';
const MCRON_REQUESTED_STALE_HOURS = 24;
const MCRON_APPROVED_WARN_HOURS = 48;
const MCRON_PROOF_WARN_HOURS = 72;

function mcron_pdo(): PDO
{
    if (!isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) {
        if (function_exists('db_connect')) {
            db_connect();
        }
    }

    if (!isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) {
        fwrite(STDERR, "[manual-claims-reserve-reconcile] DB unavailable\n");
        exit(1);
    }

    /** @var PDO $pdo */
    $pdo = $GLOBALS['pdo'];
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

function mcron_log(string $message, array $ctx = []): void
{
    $line = '[' . gmdate('c') . '] ' . $message;
    if ($ctx) {
        $line .= ' ' . json_encode($ctx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    fwrite(STDOUT, $line . PHP_EOL);
}

function mcron_error(string $code, array $context, string $hint): void
{
    if (function_exists('poado_error')) {
        try {
            poado_error(MCRON_MODULE, $code, $context, $hint);
            return;
        } catch (Throwable $e) {
        }
    }

    mcron_log('ERROR ' . $code . ' ' . $hint, $context);
}

function mcron_fetch_active_reserves(PDO $pdo): array
{
    $sql = "SELECT
                id,
                request_uid,
                user_id,
                flow_type,
                source_bucket,
                amount_units,
                status,
                released_at,
                consumed_at,
                created_at,
                updated_at
            FROM wems_db.poado_token_manual_reserves
            WHERE status = 'ACTIVE'
            ORDER BY id ASC";
    $st = $pdo->query($sql);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function mcron_fetch_request(PDO $pdo, string $requestUid): ?array
{
    $sql = "SELECT
                id,
                request_uid,
                user_id,
                flow_type,
                source_bucket,
                request_token,
                settle_token,
                wallet_address,
                recipient_owner,
                amount_units,
                amount_display,
                decimals,
                claim_nonce,
                claim_ref,
                proof_required,
                proof_contract,
                proof_tx_hash,
                payout_tx_hash,
                payout_wallet,
                status,
                requested_note,
                approved_by,
                approved_at,
                rejected_at,
                reject_reason,
                paid_at,
                meta,
                created_at,
                updated_at
            FROM wems_db.poado_token_manual_requests
            WHERE request_uid = :uid
            LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([':uid' => $requestUid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function mcron_decode_meta($meta): array
{
    if (is_array($meta)) {
        return $meta;
    }
    if (is_string($meta) && trim($meta) !== '') {
        $decoded = json_decode($meta, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return [];
}

function mcron_update_request_meta(PDO $pdo, string $requestUid, callable $mutator): void
{
    $st = $pdo->prepare("SELECT meta FROM wems_db.poado_token_manual_requests WHERE request_uid = :uid LIMIT 1");
    $st->execute([':uid' => $requestUid]);
    $existing = $st->fetchColumn();
    if ($existing === false) {
        return;
    }

    $meta = mcron_decode_meta($existing);
    $meta = $mutator($meta);
    if (!is_array($meta)) {
        $meta = [];
    }

    $up = $pdo->prepare("UPDATE wems_db.poado_token_manual_requests SET meta = :meta WHERE request_uid = :uid LIMIT 1");
    $up->execute([
        ':meta' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':uid' => $requestUid,
    ]);
}

function mcron_mark_request_meta_reserve(PDO $pdo, string $requestUid, string $reserveStatus, array $extra = []): void
{
    mcron_update_request_meta($pdo, $requestUid, function (array $meta) use ($reserveStatus, $extra): array {
        $meta['reserve_status'] = $reserveStatus;

        if ($reserveStatus === 'RELEASED') {
            $meta['reserve_released_at'] = gmdate('Y-m-d H:i:s');
        }
        if ($reserveStatus === 'CONSUMED') {
            $meta['reserve_consumed_at'] = gmdate('Y-m-d H:i:s');
        }

        foreach ($extra as $k => $v) {
            $meta[$k] = $v;
        }

        return $meta;
    });
}

function mcron_release_reserve(PDO $pdo, string $requestUid, string $reason): void
{
    $sql = "UPDATE wems_db.poado_token_manual_reserves
            SET status = 'RELEASED',
                released_at = UTC_TIMESTAMP()
            WHERE request_uid = :uid
              AND status = 'ACTIVE'
            LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([':uid' => $requestUid]);

    mcron_mark_request_meta_reserve($pdo, $requestUid, 'RELEASED', [
        'reserve_action_reason' => $reason,
    ]);

    mcron_log('Reserve released', [
        'request_uid' => $requestUid,
        'reason' => $reason,
    ]);
}

function mcron_consume_reserve(PDO $pdo, string $requestUid, string $reason): void
{
    $sql = "UPDATE wems_db.poado_token_manual_reserves
            SET status = 'CONSUMED',
                consumed_at = UTC_TIMESTAMP()
            WHERE request_uid = :uid
              AND status = 'ACTIVE'
            LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([':uid' => $requestUid]);

    mcron_mark_request_meta_reserve($pdo, $requestUid, 'CONSUMED', [
        'reserve_action_reason' => $reason,
    ]);

    mcron_log('Reserve consumed', [
        'request_uid' => $requestUid,
        'reason' => $reason,
    ]);
}

function mcron_cancel_request(PDO $pdo, string $requestUid, string $reason): void
{
    $sql = "UPDATE wems_db.poado_token_manual_requests
            SET status = 'cancelled',
                reject_reason = CASE
                  WHEN reject_reason IS NULL OR reject_reason = '' THEN :reason
                  ELSE reject_reason
                END
            WHERE request_uid = :uid
              AND status = 'requested'
            LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([
        ':uid' => $requestUid,
        ':reason' => $reason,
    ]);

    mcron_update_request_meta($pdo, $requestUid, function (array $meta) use ($reason): array {
        $meta['auto_cancelled_by_cron'] = true;
        $meta['auto_cancelled_at'] = gmdate('Y-m-d H:i:s');
        $meta['auto_cancel_reason'] = $reason;
        return $meta;
    });

    mcron_log('Request cancelled', [
        'request_uid' => $requestUid,
        'reason' => $reason,
    ]);
}

function mcron_hours_old(?string $createdAt): ?float
{
    if (!$createdAt) {
        return null;
    }

    $ts = strtotime($createdAt . ' UTC');
    if ($ts === false) {
        return null;
    }

    return max(0.0, (time() - $ts) / 3600);
}

function mcron_reconcile_one(PDO $pdo, array $reserve, array &$stats): void
{
    $requestUid = (string)$reserve['request_uid'];
    $request = mcron_fetch_request($pdo, $requestUid);

    if (!$request) {
        mcron_release_reserve($pdo, $requestUid, 'missing_request_row');
        $stats['released_missing_request']++;
        mcron_error('RESERVE_REQUEST_MISSING', [
            'request_uid' => $requestUid,
            'reserve_id' => $reserve['id'],
            'flow_type' => $reserve['flow_type'],
            'user_id' => $reserve['user_id'],
        ], 'ACTIVE reserve had no matching request row; reserve auto-released.');
        return;
    }

    $status = (string)$request['status'];
    $hoursOld = mcron_hours_old((string)$request['created_at']);

    if (in_array($status, ['rejected', 'failed', 'cancelled'], true)) {
        mcron_release_reserve($pdo, $requestUid, 'terminal_request_status_' . $status);
        $stats['released_terminal_status']++;
        return;
    }

    if ($status === 'paid') {
        mcron_consume_reserve($pdo, $requestUid, 'request_paid');
        $stats['consumed_paid']++;
        return;
    }

    if ($status === 'requested' && $hoursOld !== null && $hoursOld >= MCRON_REQUESTED_STALE_HOURS) {
        $reason = 'Auto-cancelled by reserve reconcile cron after stale requested window.';
        $pdo->beginTransaction();
        try {
            mcron_cancel_request($pdo, $requestUid, $reason);
            mcron_release_reserve($pdo, $requestUid, 'requested_stale_' . MCRON_REQUESTED_STALE_HOURS . 'h');
            $pdo->commit();
            $stats['cancelled_requested_stale']++;
            mcron_error('REQUEST_AUTO_CANCELLED', [
                'request_uid' => $requestUid,
                'hours_old' => $hoursOld,
                'threshold_hours' => MCRON_REQUESTED_STALE_HOURS,
                'flow_type' => $request['flow_type'],
                'user_id' => $request['user_id'],
            ], 'Stale requested manual claim was auto-cancelled and reserve released.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return;
    }

    if ($status === 'approved' && $hoursOld !== null && $hoursOld >= MCRON_APPROVED_WARN_HOURS) {
        $stats['anomaly_approved_stale']++;
        mcron_error('APPROVED_STALE', [
            'request_uid' => $requestUid,
            'hours_old' => $hoursOld,
            'threshold_hours' => MCRON_APPROVED_WARN_HOURS,
            'flow_type' => $request['flow_type'],
            'user_id' => $request['user_id'],
            'proof_required' => $request['proof_required'],
        ], 'Approved manual claim has remained unresolved beyond warning threshold.');
        return;
    }

    if ($status === 'proof_submitted' && $hoursOld !== null && $hoursOld >= MCRON_PROOF_WARN_HOURS) {
        $stats['anomaly_proof_stale']++;
        mcron_error('PROOF_SUBMITTED_STALE', [
            'request_uid' => $requestUid,
            'hours_old' => $hoursOld,
            'threshold_hours' => MCRON_PROOF_WARN_HOURS,
            'flow_type' => $request['flow_type'],
            'user_id' => $request['user_id'],
            'proof_tx_hash' => $request['proof_tx_hash'],
        ], 'Proof-submitted manual claim has remained unresolved beyond warning threshold.');
        return;
    }

    $stats['unchanged']++;
}

function mcron_run(): void
{
    $pdo = mcron_pdo();

    $stats = [
        'total_active_scanned' => 0,
        'released_missing_request' => 0,
        'released_terminal_status' => 0,
        'consumed_paid' => 0,
        'cancelled_requested_stale' => 0,
        'anomaly_approved_stale' => 0,
        'anomaly_proof_stale' => 0,
        'unchanged' => 0,
        'errors' => 0,
    ];

    $activeReserves = mcron_fetch_active_reserves($pdo);
    $stats['total_active_scanned'] = count($activeReserves);

    mcron_log('Reconcile start', [
        'active_reserves' => $stats['total_active_scanned'],
        'requested_stale_hours' => MCRON_REQUESTED_STALE_HOURS,
        'approved_warn_hours' => MCRON_APPROVED_WARN_HOURS,
        'proof_warn_hours' => MCRON_PROOF_WARN_HOURS,
    ]);

    foreach ($activeReserves as $reserve) {
        try {
            mcron_reconcile_one($pdo, $reserve, $stats);
        } catch (Throwable $e) {
            $stats['errors']++;
            mcron_error('RECONCILE_ROW_FAILED', [
                'request_uid' => $reserve['request_uid'] ?? '',
                'reserve_id' => $reserve['id'] ?? '',
                'message' => $e->getMessage(),
            ], 'Reserve reconcile row failed unexpectedly.');
        }
    }

    mcron_log('Reconcile done', $stats);
}

mcron_run();
