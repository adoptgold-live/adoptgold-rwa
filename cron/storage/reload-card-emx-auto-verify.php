<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cron/storage/reload-card-emx-auto-verify.php
 * Reload Card EMX Auto Verify Worker
 * FINAL-LOCK-3
 *
 * FIXED:
 * - CLI-safe root resolution
 * - force $_SERVER['DOCUMENT_ROOT'] for included bootstraps
 * - shared onchain verifier
 * - cron-safe pending reload confirm flow
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'CLI_ONLY',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ==========================================================================
 * Resolve public doc root safely for CLI
 * ========================================================================== */
$docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');

if ($docRoot === '' || !is_dir($docRoot . '/rwa')) {
    $docRoot = dirname(__DIR__, 3);
}

if ($docRoot === '' || !is_dir($docRoot . '/rwa')) {
    $docRoot = '/var/www/html/public';
}

if (!is_dir($docRoot . '/rwa')) {
    fwrite(STDERR, json_encode([
        'ok' => false,
        'error' => 'DOCROOT_NOT_FOUND',
        'docRoot' => $docRoot,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(1);
}

/* CRITICAL FIX:
 * downstream files rely on $_SERVER['DOCUMENT_ROOT']
 */
$_SERVER['DOCUMENT_ROOT'] = $docRoot;

/* ==========================================================================
 * Load core
 * ========================================================================== */
require_once $docRoot . '/rwa/inc/core/bootstrap.php';
require_once $docRoot . '/rwa/inc/core/onchain-verify.php';
require_once $docRoot . '/rwa/api/storage/_bootstrap.php';

const RELOAD_AUTO_VERIFY_VERSION = 'FINAL-LOCK-3';
const RELOAD_AUTO_VERIFY_LIMIT = 50;

/* ==========================================================================
 * Helpers
 * ========================================================================== */
function reload_worker_now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function reload_worker_pdo(): PDO
{
    if (function_exists('storage_db')) {
        $pdo = storage_db();
        if ($pdo instanceof PDO) {
            return $pdo;
        }
    }

    if (($GLOBALS['pdo'] ?? null) instanceof PDO) {
        return $GLOBALS['pdo'];
    }

    if (function_exists('db_connect')) {
        db_connect();
        if (($GLOBALS['pdo'] ?? null) instanceof PDO) {
            return $GLOBALS['pdo'];
        }
    }

    throw new RuntimeException('PDO_NOT_AVAILABLE');
}

function reload_worker_locked_treasury(): string
{
    $v = trim((string)(
        $_ENV['REWARD_POOL_VAULT_TON']
        ?? $_SERVER['REWARD_POOL_VAULT_TON']
        ?? getenv('REWARD_POOL_VAULT_TON')
        ?? ''
    ));
    return $v !== '' ? $v : 'UQBdYfGArtoCUmBs5TjYQtfPFfQuGC2Ydbj2pQr3zIlNrDta';
}

function reload_worker_locked_jetton_master(): string
{
    $v = trim((string)(
        $_ENV['EMX_JETTON_MASTER_RAW']
        ?? $_SERVER['EMX_JETTON_MASTER_RAW']
        ?? getenv('EMX_JETTON_MASTER_RAW')
        ?? ''
    ));
    if ($v !== '') {
        return $v;
    }

    $v = trim((string)(
        $_ENV['EMX_JETTON_MASTER']
        ?? $_SERVER['EMX_JETTON_MASTER']
        ?? getenv('EMX_JETTON_MASTER')
        ?? ''
    ));
    if ($v !== '') {
        return $v;
    }

    return '0:63d3319c1cebcde48b013ff040006e4d462b806bf48b06efb18ec267ec078ce2';
}

function reload_worker_hash_norm(string $h): string
{
    $h = strtolower(trim($h));
    if ($h === '') {
        return '';
    }
    return str_starts_with($h, '0x') ? $h : '0x' . $h;
}

/* ==========================================================================
 * Fetch pending
 * ========================================================================== */
function reload_worker_find_pending_rows(PDO $pdo, int $limit = RELOAD_AUTO_VERIFY_LIMIT): array
{
    $limit = max(1, min(500, $limit));

    $sql = "
        SELECT *
        FROM poado_storage_reloads
        WHERE status = 'PENDING'
        ORDER BY id ASC
        LIMIT {$limit}
    ";

    $st = $pdo->query($sql);
    $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    return is_array($rows) ? $rows : [];
}

function reload_worker_find_row_for_update(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare("
        SELECT *
        FROM poado_storage_reloads
        WHERE id = :id
        LIMIT 1
    ");
    $st->execute([':id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/* ==========================================================================
 * Confirm
 * ========================================================================== */
function reload_worker_confirm_row(PDO $pdo, int $id, string $txHash, int $confirmations, array $meta): void
{
    $sql = "
        UPDATE poado_storage_reloads
        SET
            status = 'CONFIRMED',
            tx_hash = :tx_hash,
            verify_source = 'auto_worker',
            confirmations = :confirmations,
            meta_json = :meta_json,
            confirmed_at = :confirmed_at,
            updated_at = :updated_at
        WHERE id = :id
        LIMIT 1
    ";

    $now = reload_worker_now_utc();

    $st = $pdo->prepare($sql);
    $st->execute([
        ':tx_hash'       => $txHash,
        ':confirmations' => $confirmations,
        ':meta_json'     => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':confirmed_at'  => $now,
        ':updated_at'    => $now,
        ':id'            => $id,
    ]);
}

/* ==========================================================================
 * Process one row
 * ========================================================================== */
function reload_worker_process_row(PDO $pdo, array $row): array
{
    $id = (int)($row['id'] ?? 0);
    $reloadRef = trim((string)($row['reload_ref'] ?? ''));
    $userId = (int)($row['user_id'] ?? 0);
    $wallet = trim((string)($row['wallet_address'] ?? ''));
    $treasury = trim((string)($row['treasury_address'] ?? reload_worker_locked_treasury()));
    $amountUnits = preg_replace('/\D+/', '', (string)($row['amount_units'] ?? '')) ?? '';
    $jettonMaster = reload_worker_locked_jetton_master();

    if ($id <= 0 || $reloadRef === '' || $userId <= 0 || $wallet === '' || $amountUnits === '') {
        return [
            'id' => $id,
            'reload_ref' => $reloadRef,
            'ok' => false,
            'code' => 'INVALID_PENDING_ROW',
        ];
    }

    $verify = rwa_onchain_verify_jetton_transfer([
        'owner_address' => $wallet,
        'token_key'     => 'EMX',
        'jetton_master' => $jettonMaster,
        'amount_units'  => $amountUnits,
        'ref'           => $reloadRef,
        'destination'   => $treasury,
        'limit'         => 100,
    ]);

    if (($verify['ok'] ?? false) !== true) {
        return [
            'id' => $id,
            'reload_ref' => $reloadRef,
            'ok' => false,
            'code' => (string)($verify['code'] ?? 'NO_MATCH'),
            'debug' => $verify['debug'] ?? [],
        ];
    }

    $matchedTxHash = reload_worker_hash_norm((string)($verify['tx_hash'] ?? ''));
    $confirmations = (int)($verify['confirmations'] ?? 1);
    if ($confirmations <= 0) {
        $confirmations = 1;
    }

    $meta = [
        'flow'             => 'reload_card_emx',
        'verified_via'     => 'toncenter_v3_php',
        'version'          => RELOAD_AUTO_VERIFY_VERSION,
        'reload_ref'       => $reloadRef,
        'wallet_address'   => $wallet,
        'treasury_address' => $treasury,
        'jetton_master'    => $jettonMaster,
        'token_key'        => (string)($verify['token_key'] ?? 'EMX'),
        'amount_units'     => $amountUnits,
        'amount_emx'       => (string)($row['amount_emx'] ?? ''),
        'tx_hash'          => $matchedTxHash,
        'confirmations'    => $confirmations,
        'source_raw'       => (string)($verify['source_raw'] ?? ''),
        'destination_raw'  => (string)($verify['destination_raw'] ?? ''),
        'payload_text'     => (string)($verify['payload_text'] ?? ''),
        'match_jetton'     => (bool)($verify['match_jetton'] ?? false),
        'match_amount'     => (bool)($verify['match_amount'] ?? false),
        'match_ref'        => (bool)($verify['match_ref'] ?? false),
        'match_tx_hint'    => (bool)($verify['match_tx_hint'] ?? true),
        'source_checked'   => (bool)($verify['source_checked'] ?? false),
        'source_matched'   => (bool)($verify['source_matched'] ?? false),
        'treasury_checked' => (bool)($verify['treasury_checked'] ?? false),
        'treasury_matched' => (bool)($verify['treasury_matched'] ?? false),
        'verified_at'      => reload_worker_now_utc(),
        'raw_transfer'     => $verify['raw_transfer'] ?? null,
    ];

    $pdo->beginTransaction();
    try {
        $fresh = reload_worker_find_row_for_update($pdo, $id);
        if (!$fresh) {
            throw new RuntimeException('ROW_NOT_FOUND_AFTER_LOCK');
        }

        $freshStatus = strtoupper(trim((string)($fresh['status'] ?? '')));
        if ($freshStatus === 'CONFIRMED') {
            $pdo->commit();
            return [
                'id' => $id,
                'reload_ref' => $reloadRef,
                'ok' => true,
                'code' => 'ALREADY_CONFIRMED',
                'tx_hash' => (string)($fresh['tx_hash'] ?? $matchedTxHash),
            ];
        }

        reload_worker_confirm_row($pdo, $id, $matchedTxHash, $confirmations, $meta);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [
            'id' => $id,
            'reload_ref' => $reloadRef,
            'ok' => false,
            'code' => 'CONFIRM_FAILED',
            'message' => $e->getMessage(),
        ];
    }

    if (function_exists('storage_sync_all_token_balances_live')) {
        try {
            storage_sync_all_token_balances_live([
                'id' => $userId,
                'wallet_address' => $wallet,
            ]);
        } catch (Throwable $e) {
            return [
                'id' => $id,
                'reload_ref' => $reloadRef,
                'ok' => true,
                'code' => 'CONFIRMED_SYNC_WARNING',
                'tx_hash' => $matchedTxHash,
                'sync_warning' => $e->getMessage(),
            ];
        }
    }

    return [
        'id' => $id,
        'reload_ref' => $reloadRef,
        'ok' => true,
        'code' => 'CONFIRMED',
        'tx_hash' => $matchedTxHash,
    ];
}

/* ==========================================================================
 * Main
 * ========================================================================== */
function reload_worker_main(): array
{
    $pdo = reload_worker_pdo();
    $rows = reload_worker_find_pending_rows($pdo, RELOAD_AUTO_VERIFY_LIMIT);

    $summary = [
        'ok' => true,
        'version' => RELOAD_AUTO_VERIFY_VERSION,
        'ts' => gmdate('c'),
        'doc_root' => (string)($_SERVER['DOCUMENT_ROOT'] ?? ''),
        'scanned' => count($rows),
        'confirmed' => 0,
        'already_confirmed' => 0,
        'no_match' => 0,
        'errors' => 0,
        'items' => [],
    ];

    foreach ($rows as $row) {
        try {
            $result = reload_worker_process_row($pdo, $row);
            $summary['items'][] = $result;

            $code = (string)($result['code'] ?? '');
            if (!empty($result['ok'])) {
                if ($code === 'CONFIRMED' || $code === 'CONFIRMED_SYNC_WARNING') {
                    $summary['confirmed']++;
                } elseif ($code === 'ALREADY_CONFIRMED') {
                    $summary['already_confirmed']++;
                }
            } else {
                if ($code === 'NO_MATCH') {
                    $summary['no_match']++;
                } else {
                    $summary['errors']++;
                }
            }
        } catch (Throwable $e) {
            $summary['errors']++;
            $summary['items'][] = [
                'id' => (int)($row['id'] ?? 0),
                'reload_ref' => (string)($row['reload_ref'] ?? ''),
                'ok' => false,
                'code' => 'ROW_PROCESS_FAILED',
                'message' => $e->getMessage(),
            ];
        }
    }

    return $summary;
}

try {
    $out = reload_worker_main();
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'version' => RELOAD_AUTO_VERIFY_VERSION,
        'ts' => gmdate('c'),
        'doc_root' => (string)($_SERVER['DOCUMENT_ROOT'] ?? ''),
        'error' => 'WORKER_FATAL',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}