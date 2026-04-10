<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/api/mint-verify.php
 * Version: v14.0.0-20260408-production-final-authority
 *
 * FINAL AUTHORITY
 * - Never report minted=true unless DB final truth is complete
 * - Final minted truth requires:
 *     status = minted
 *     nft_minted = 1
 *     nft_item_address != ''
 *     minted_at != ''
 *
 * PRODUCTION RULE
 * - This endpoint may finalize from:
 *   1) already-final DB state
 *   2) explicit request nft_item_address
 *   3) stored verified mint evidence in meta_json
 *
 * SAFE RULE
 * - If no trustworthy NFT item address is available, stay honest:
 *     minted=false
 *     queue_bucket=minting_process
 */

header('Content-Type: application/json; charset=utf-8');

if (!defined('RWA_CORE_BOOTSTRAPPED')) {
    $bootstrapCandidates = [
        dirname(__DIR__, 2) . '/inc/core/bootstrap.php',
        dirname(__DIR__, 3) . '/rwa/inc/core/bootstrap.php',
        dirname(__DIR__, 3) . '/dashboard/inc/bootstrap.php',
    ];
    $loaded = false;
    foreach ($bootstrapCandidates as $file) {
        if (is_file($file)) {
            require_once $file;
            $loaded = true;
            break;
        }
    }
    if (!$loaded) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'BOOTSTRAP_NOT_FOUND',
            'version' => 'v14.0.0-20260408-production-final-authority',
            'ts' => time(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

const MV_VERSION = 'v14.0.0-20260408-production-final-authority';

function mv_out(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    exit;
}

function mv_fail(string $error, string $detail = '', int $status = 400, array $extra = []): never
{
    $out = [
        'ok' => false,
        'error' => $error,
        'version' => MV_VERSION,
        'ts' => time(),
    ];
    if ($detail !== '') {
        $out['detail'] = $detail;
    }
    if ($extra) {
        $out += $extra;
    }
    mv_out($out, $status);
}

function mv_db(): PDO
{
    foreach (['rwa_db', 'db_connect', 'db'] as $fn) {
        if (function_exists($fn)) {
            try {
                $pdo = $fn();
                if ($pdo instanceof PDO) {
                    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    return $pdo;
                }
            } catch (Throwable) {}
        }
    }

    if (($GLOBALS['pdo'] ?? null) instanceof PDO) {
        $GLOBALS['pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $GLOBALS['pdo'];
    }

    mv_fail('DB_NOT_AVAILABLE', 'PDO handle not available', 500);
}

function mv_site_url(): string
{
    $base = '';
    foreach (['APP_BASE_URL'] as $k) {
        $v = $_ENV[$k] ?? $_SERVER[$k] ?? getenv($k);
        if (is_string($v) && trim($v) !== '') {
            $base = trim($v);
            break;
        }
    }
    if ($base === '') {
        $base = 'https://adoptgold.app';
    }
    return rtrim($base, '/');
}

function mv_cert_uid(): string
{
    return trim((string)($_GET['cert_uid'] ?? $_POST['cert_uid'] ?? $_GET['uid'] ?? $_POST['uid'] ?? ''));
}

function mv_request_nft_item_address(): string
{
    return trim((string)($_GET['nft_item_address'] ?? $_POST['nft_item_address'] ?? ''));
}

function mv_request_tx_hash(): string
{
    return trim((string)($_GET['tx_hash'] ?? $_POST['tx_hash'] ?? ''));
}

function mv_now_iso(): string
{
    return gmdate('c');
}

function mv_json_decode(?string $raw): array
{
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
}

function mv_json_encode(array $data): string
{
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function mv_cert(PDO $pdo, string $certUid): array
{
    $st = $pdo->prepare("
        SELECT *
        FROM poado_rwa_certs
        WHERE cert_uid = :uid
        LIMIT 1
    ");
    $st->execute([':uid' => $certUid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        mv_fail('CERT_NOT_FOUND', 'No certificate row found', 404, ['cert_uid' => $certUid]);
    }
    return $row;
}

function mv_latest_payment(PDO $pdo, string $certUid): array
{
    $st = $pdo->prepare("
        SELECT *
        FROM poado_rwa_cert_payments
        WHERE cert_uid = :uid
        ORDER BY id DESC
        LIMIT 1
    ");
    $st->execute([':uid' => $certUid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

function mv_payment_confirmed(array $payment): bool
{
    return strtolower(trim((string)($payment['status'] ?? ''))) === 'confirmed'
        && (int)($payment['verified'] ?? 0) === 1;
}

function mv_is_final_minted(array $cert): bool
{
    return strtolower(trim((string)($cert['status'] ?? ''))) === 'minted'
        && (int)($cert['nft_minted'] ?? 0) === 1
        && trim((string)($cert['nft_item_address'] ?? '')) !== ''
        && trim((string)($cert['minted_at'] ?? '')) !== '';
}

function mv_verify_url(string $certUid): string
{
    return mv_site_url() . '/rwa/cert/verify.php?uid=' . rawurlencode($certUid);
}

function mv_verify_status_url(string $certUid): string
{
    return mv_site_url() . '/rwa/cert/api/verify-status.php?cert_uid=' . rawurlencode($certUid);
}

function mv_mint_verify_url(string $certUid): string
{
    return mv_site_url() . '/rwa/cert/api/mint-verify.php?cert_uid=' . rawurlencode($certUid);
}

function mv_read_meta(array $cert): array
{
    return mv_json_decode((string)($cert['meta_json'] ?? ''));
}

function mv_read_mint_request(array $cert): array
{
    $meta = mv_read_meta($cert);
    $mintRequest = $meta['mint']['mint_request'] ?? $meta['mint_request'] ?? [];
    return is_array($mintRequest) ? $mintRequest : [];
}

function mv_append_lifecycle(array $meta, string $event, array $extra = []): array
{
    if (!isset($meta['lifecycle']) || !is_array($meta['lifecycle'])) {
        $meta['lifecycle'] = [];
    }
    if (!isset($meta['lifecycle']['history']) || !is_array($meta['lifecycle']['history'])) {
        $meta['lifecycle']['history'] = [];
    }

    $row = array_merge([
        'event' => $event,
        'at' => mv_now_iso(),
    ], $extra);

    $meta['lifecycle']['history'][] = $row;
    $meta['lifecycle']['current'] = $extra['status'] ?? ($meta['lifecycle']['current'] ?? '');
    return $meta;
}

function mv_clean_tx_hash(string $v): string
{
    return trim($v);
}

function mv_extract_stored_match(array $meta): array
{
    $candidates = [];

    $paths = [
        ['mint', 'verified'],
        ['mint', 'final'],
        ['mint', 'onchain_match'],
        ['mint_verify'],
        ['mint_verify_result'],
        ['mint_handoff_v9', 'verify_result'],
        ['mint_handoff_v9', 'onchain_match'],
    ];

    foreach ($paths as $path) {
        $node = $meta;
        $ok = true;
        foreach ($path as $seg) {
            if (!is_array($node) || !array_key_exists($seg, $node)) {
                $ok = false;
                break;
            }
            $node = $node[$seg];
        }
        if ($ok && is_array($node)) {
            $candidates[] = $node;
        }
    }

    foreach ($candidates as $node) {
        $addr = trim((string)(
            $node['nft_item_address']
            ?? $node['item_address']
            ?? $node['nft_address']
            ?? ''
        ));
        if ($addr !== '') {
            return [
                'nft_item_address' => $addr,
                'tx_hash' => mv_clean_tx_hash((string)($node['tx_hash'] ?? '')),
                'source' => 'stored_match',
                'raw' => $node,
            ];
        }
    }

    return [
        'nft_item_address' => '',
        'tx_hash' => '',
        'source' => '',
        'raw' => [],
    ];
}

function mv_finalize_cert(PDO $pdo, array $cert, array $payment, string $nftItemAddress, string $txHash, string $source = 'mint-verify.php'): array
{
    $certUid = (string)$cert['cert_uid'];
    $meta = mv_read_meta($cert);

    if (!isset($meta['mint']) || !is_array($meta['mint'])) {
        $meta['mint'] = [];
    }
    if (!isset($meta['mint']['final']) || !is_array($meta['mint']['final'])) {
        $meta['mint']['final'] = [];
    }
    if (!isset($meta['mint']['verified']) || !is_array($meta['mint']['verified'])) {
        $meta['mint']['verified'] = [];
    }

    $finalPayload = [
        'finalized_at' => mv_now_iso(),
        'nft_item_address' => $nftItemAddress,
        'tx_hash' => $txHash,
        'source' => $source,
    ];

    $meta['mint']['final'] = array_merge($meta['mint']['final'], $finalPayload);
    $meta['mint']['verified'] = array_merge($meta['mint']['verified'], $finalPayload);

    $meta = mv_append_lifecycle($meta, 'mint_verified', [
        'status' => 'minted',
        'nft_item_address' => $nftItemAddress,
        'tx_hash' => $txHash,
        'source' => $source,
    ]);

    $pdo->beginTransaction();

    try {
        $st = $pdo->prepare("
            UPDATE poado_rwa_certs
            SET
                status = 'minted',
                nft_minted = 1,
                nft_item_address = :nft_item_address,
                minted_at = NOW(),
                meta_json = :meta_json,
                updated_at = NOW()
            WHERE cert_uid = :uid
            LIMIT 1
        ");
        $st->execute([
            ':nft_item_address' => $nftItemAddress,
            ':meta_json' => mv_json_encode($meta),
            ':uid' => $certUid,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        mv_fail('MINT_DB_UPDATE_FAILED', $e->getMessage(), 500, ['cert_uid' => $certUid]);
    }

    return mv_cert($pdo, $certUid);
}

try {
    $pdo = mv_db();
    $certUid = mv_cert_uid();

    if ($certUid === '') {
        mv_fail('CERT_UID_REQUIRED', 'cert_uid is required', 422);
    }

    $cert = mv_cert($pdo, $certUid);
    $payment = mv_latest_payment($pdo, $certUid);
    $mintRequest = mv_read_mint_request($cert);
    $meta = mv_read_meta($cert);

    $manualNftItemAddress = mv_request_nft_item_address();
    $manualTxHash = mv_request_tx_hash();

    $alreadyFinal = mv_is_final_minted($cert);

    if (!$payment || !mv_payment_confirmed($payment)) {
        mv_out([
            'ok' => true,
            'version' => MV_VERSION,
            'ts' => time(),
            'cert_uid' => $certUid,
            'status' => (string)($cert['status'] ?? ''),
            'cert_status' => (string)($cert['status'] ?? ''),
            'mint_status' => 'pending_payment',
            'queue_bucket' => 'issuance_factory',
            'verify_queue_bucket' => 'issuance_factory',
            'minted' => false,
            'nft_minted' => (int)($cert['nft_minted'] ?? 0),
            'nft_item_address' => (string)($cert['nft_item_address'] ?? ''),
            'minted_at' => (string)($cert['minted_at'] ?? ''),
            'payment' => [
                'status' => (string)($payment['status'] ?? ''),
                'verified' => (int)($payment['verified'] ?? 0),
                'payment_ref' => (string)($payment['payment_ref'] ?? ''),
                'tx_hash' => (string)($payment['tx_hash'] ?? ''),
            ],
            'mint_request' => $mintRequest,
            'flow_state' => 'payment',
            'ui' => [
                'selected_cert_uid' => $certUid,
                'selected_queue_bucket' => 'issuance_factory',
                'payment_status' => (string)($payment['status'] ?? ''),
                'payment_verified' => (int)($payment['verified'] ?? 0),
                'mint_status' => 'pending_payment',
                'next_banner' => 'Business payment not confirmed yet.',
                'success_banner' => '',
            ],
            'verify_poll' => [
                'recommended_source' => 'mint-verify',
                'mint_verify_url' => mv_mint_verify_url($certUid),
                'verify_status_url' => mv_verify_status_url($certUid),
                'poll_interval_ms' => 5000,
                'timeout_ms' => 480000,
                'stop_on' => [
                    'nft_minted = 1',
                    'status = minted',
                    'queue_bucket = issued'
                ],
            ],
            'events' => [
                'dispatch' => [],
                'cert_uid' => $certUid,
                'queue_bucket' => 'issuance_factory',
                'mint_status' => 'pending_payment',
                'nft_minted' => (int)($cert['nft_minted'] ?? 0),
            ],
            'reason' => 'PAYMENT_NOT_CONFIRMED',
        ]);
    }

    if ($alreadyFinal) {
        mv_out([
            'ok' => true,
            'version' => MV_VERSION,
            'ts' => time(),
            'cert_uid' => $certUid,
            'status' => 'minted',
            'cert_status' => 'minted',
            'mint_status' => 'minted',
            'queue_bucket' => 'issued',
            'verify_queue_bucket' => 'issued',
            'minted' => true,
            'nft_minted' => 1,
            'nft_item_address' => (string)$cert['nft_item_address'],
            'minted_at' => (string)$cert['minted_at'],
            'payment' => [
                'status' => (string)($payment['status'] ?? ''),
                'verified' => (int)($payment['verified'] ?? 0),
                'payment_ref' => (string)($payment['payment_ref'] ?? ''),
                'tx_hash' => (string)($manualTxHash !== '' ? $manualTxHash : ($payment['tx_hash'] ?? '')),
            ],
            'mint_request' => $mintRequest,
            'flow_state' => 'issued',
            'ui' => [
                'selected_cert_uid' => $certUid,
                'selected_queue_bucket' => 'issued',
                'payment_status' => (string)($payment['status'] ?? ''),
                'payment_verified' => (int)($payment['verified'] ?? 0),
                'mint_status' => 'minted',
                'next_banner' => 'Issued successfully. NFT mint confirmed.',
                'success_banner' => 'Issued successfully. NFT mint confirmed.',
            ],
            'verify_poll' => [
                'recommended_source' => 'mint-verify',
                'mint_verify_url' => mv_mint_verify_url($certUid),
                'verify_status_url' => mv_verify_status_url($certUid),
                'poll_interval_ms' => 5000,
                'timeout_ms' => 480000,
                'stop_on' => [
                    'nft_minted = 1',
                    'status = minted',
                    'queue_bucket = issued'
                ],
            ],
            'events' => [
                'dispatch' => ['cert:mint-complete'],
                'cert_uid' => $certUid,
                'queue_bucket' => 'issued',
                'mint_status' => 'minted',
                'nft_minted' => 1,
            ],
            'already_final' => true,
        ]);
    }

    $storedMatch = mv_extract_stored_match($meta);
    $resolvedNftItemAddress = '';
    $resolvedTxHash = '';

    if ($manualNftItemAddress !== '') {
        $resolvedNftItemAddress = $manualNftItemAddress;
        $resolvedTxHash = $manualTxHash !== '' ? $manualTxHash : mv_clean_tx_hash((string)($payment['tx_hash'] ?? ''));
        $source = 'request';
    } elseif ($storedMatch['nft_item_address'] !== '') {
        $resolvedNftItemAddress = $storedMatch['nft_item_address'];
        $resolvedTxHash = $manualTxHash !== '' ? $manualTxHash : ($storedMatch['tx_hash'] !== '' ? $storedMatch['tx_hash'] : mv_clean_tx_hash((string)($payment['tx_hash'] ?? '')));
        $source = 'stored_match';
    } else {
        $source = '';
    }

    if ($resolvedNftItemAddress === '') {
        mv_out([
            'ok' => true,
            'version' => MV_VERSION,
            'ts' => time(),
            'cert_uid' => $certUid,
            'status' => (string)($cert['status'] ?? ''),
            'cert_status' => (string)($cert['status'] ?? ''),
            'mint_status' => 'minting',
            'queue_bucket' => 'minting_process',
            'verify_queue_bucket' => 'minting_process',
            'minted' => false,
            'nft_minted' => 0,
            'nft_item_address' => '',
            'minted_at' => '',
            'payment' => [
                'status' => (string)($payment['status'] ?? ''),
                'verified' => (int)($payment['verified'] ?? 0),
                'payment_ref' => (string)($payment['payment_ref'] ?? ''),
                'tx_hash' => (string)($manualTxHash !== '' ? $manualTxHash : ($payment['tx_hash'] ?? '')),
            ],
            'mint_request' => $mintRequest,
            'flow_state' => 'minting',
            'ui' => [
                'selected_cert_uid' => $certUid,
                'selected_queue_bucket' => 'minting_process',
                'payment_status' => (string)($payment['status'] ?? ''),
                'payment_verified' => (int)($payment['verified'] ?? 0),
                'mint_status' => 'minting',
                'next_banner' => 'Wallet sign requested. Waiting for on-chain mint confirmation.',
                'success_banner' => 'Issued successfully. NFT mint confirmed.',
            ],
            'verify_poll' => [
                'recommended_source' => 'mint-verify',
                'mint_verify_url' => mv_mint_verify_url($certUid),
                'verify_status_url' => mv_verify_status_url($certUid),
                'poll_interval_ms' => 5000,
                'timeout_ms' => 480000,
                'stop_on' => [
                    'nft_minted = 1',
                    'status = minted',
                    'queue_bucket = issued'
                ],
            ],
            'events' => [
                'dispatch' => ['cert:mint-init', 'cert:wallet-sign'],
                'cert_uid' => $certUid,
                'queue_bucket' => 'minting_process',
                'mint_status' => 'minting',
                'nft_minted' => 0,
            ],
            'reason' => 'AWAITING_VERIFIED_ITEM_ADDRESS',
            'detail' => 'No trusted NFT item address available yet.',
        ]);
    }

    $finalCert = mv_finalize_cert(
        $pdo,
        $cert,
        $payment,
        $resolvedNftItemAddress,
        $resolvedTxHash,
        'mint-verify.php:' . ($source !== '' ? $source : 'unknown')
    );

    if (!mv_is_final_minted($finalCert)) {
        mv_fail('FINAL_STATE_INCOMPLETE', 'DB write did not produce final minted truth', 500, [
            'cert_uid' => $certUid,
        ]);
    }

    $mintRequest = mv_read_mint_request($finalCert);

    mv_out([
        'ok' => true,
        'version' => MV_VERSION,
        'ts' => time(),
        'cert_uid' => $certUid,
        'status' => 'minted',
        'cert_status' => 'minted',
        'mint_status' => 'minted',
        'queue_bucket' => 'issued',
        'verify_queue_bucket' => 'issued',
        'minted' => true,
        'nft_minted' => 1,
        'nft_item_address' => (string)$finalCert['nft_item_address'],
        'minted_at' => (string)$finalCert['minted_at'],
        'payment' => [
            'status' => (string)($payment['status'] ?? ''),
            'verified' => (int)($payment['verified'] ?? 0),
            'payment_ref' => (string)($payment['payment_ref'] ?? ''),
            'tx_hash' => (string)$resolvedTxHash,
        ],
        'mint_request' => $mintRequest,
        'flow_state' => 'issued',
        'ui' => [
            'selected_cert_uid' => $certUid,
            'selected_queue_bucket' => 'issued',
            'payment_status' => (string)($payment['status'] ?? ''),
            'payment_verified' => (int)($payment['verified'] ?? 0),
            'mint_status' => 'minted',
            'next_banner' => 'Issued successfully. NFT mint confirmed.',
            'success_banner' => 'Issued successfully. NFT mint confirmed.',
        ],
        'verify_poll' => [
            'recommended_source' => 'mint-verify',
            'mint_verify_url' => mv_mint_verify_url($certUid),
            'verify_status_url' => mv_verify_status_url($certUid),
            'poll_interval_ms' => 5000,
            'timeout_ms' => 480000,
            'stop_on' => [
                'nft_minted = 1',
                'status = minted',
                'queue_bucket = issued'
            ],
        ],
        'events' => [
            'dispatch' => ['cert:mint-complete'],
            'cert_uid' => $certUid,
            'queue_bucket' => 'issued',
            'mint_status' => 'minted',
            'nft_minted' => 1,
        ],
        'finalized' => true,
        'finalize_source' => $source !== '' ? $source : 'unknown',
    ]);
} catch (Throwable $e) {
    mv_fail('MINT_VERIFY_FATAL', $e->getMessage(), 500);
}
