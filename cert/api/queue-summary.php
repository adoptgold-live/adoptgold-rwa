<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/api/queue-summary.php
 * Version: v3.0.0-20260410-db-truth-queue-lock
 *
 * MASTER LOCK
 * - Queue truth is DB-driven only
 * - Do NOT use poado_rwa_certs.status as payment lifecycle truth
 * - Cert status enum is NFT lifecycle only: issued / minted / revoked
 * - Payment truth must come from poado_rwa_cert_payments.status + verified
 * - Mint Ready requires:
 *     payment.status='confirmed'
 *     payment.verified=1
 *     metadata_path present
 *     nft_image_path present
 *     nft_minted=0
 * - Issued/Minted requires:
 *     nft_minted=1 OR cert.status='minted'
 */

header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/bootstrap.php';

if (!function_exists('db_connect')) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'DB_CONNECT_UNAVAILABLE',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

db_connect();
$pdo = $GLOBALS['pdo'] ?? null;

if (!$pdo instanceof PDO) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'DB_NOT_READY',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

function qs_str(mixed $v): string
{
    return trim((string)($v ?? ''));
}

function qs_bool_flag(mixed $v): bool
{
    if (is_bool($v)) return $v;
    $s = strtolower(trim((string)($v ?? '')));
    return in_array($s, ['1', 'true', 'yes', 'y', 'ok'], true);
}

function qs_meta_arr(?string $json): array
{
    if (!$json) return [];
    try {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : [];
    } catch (Throwable) {
        return [];
    }
}

function qs_meta_path(array $arr, array $path, mixed $default = null): mixed
{
    $cur = $arr;
    foreach ($path as $seg) {
        if (!is_array($cur) || !array_key_exists($seg, $cur)) {
            return $default;
        }
        $cur = $cur[$seg];
    }
    return $cur;
}

function qs_artifact_ready(array $row, array $meta): bool
{
    if (qs_str($row['metadata_path'] ?? '') === '' || qs_str($row['nft_image_path'] ?? '') === '') {
        return false;
    }

    $metaArtifactOk = qs_bool_flag(qs_meta_path($meta, ['artifact_state', 'ok'], false));
    $metaMintArtifactOk = qs_bool_flag(qs_meta_path($meta, ['mint', 'artifact_health', 'ok'], false));

    if ($metaArtifactOk || $metaMintArtifactOk) {
        return true;
    }

    // Path presence is the minimum locked requirement for Mint Ready.
    return true;
}

function qs_nft_healthy(array $row, array $meta): bool
{
    if ((int)($row['nft_minted'] ?? 0) === 1) {
        return true;
    }

    if (qs_str($row['nft_image_path'] ?? '') === '') {
        return false;
    }

    $metaHealthy = qs_bool_flag(qs_meta_path($meta, ['nft_health', 'healthy'], false));
    if ($metaHealthy) {
        return true;
    }

    // If image exists and not minted yet, treat as healthy enough for queue display.
    return true;
}

function qs_payment_text(array $row): string
{
    $token = qs_str($row['payment_token'] ?? $row['token_symbol'] ?? '');
    $amount = qs_str($row['payment_amount'] ?? $row['amount'] ?? '');
    if ($token === '' && $amount === '') {
        return '';
    }
    return trim($amount . ' ' . $token);
}

function qs_queue_bucket(array $row, bool $artifactReady, bool $nftHealthy): string
{
    $paymentStatus = strtolower(qs_str($row['payment_status'] ?? ''));
    $paymentVerified = (int)($row['payment_verified'] ?? 0) === 1;
    $certStatus = strtolower(qs_str($row['cert_status'] ?? ''));
    $nftMinted = (int)($row['nft_minted'] ?? 0) === 1;
    $paymentRef = qs_str($row['payment_ref'] ?? '');

    // Issued / Minted final truth
    if ($nftMinted || $certStatus === 'minted') {
        return 'issued';
    }

    // Revoked stays blocked
    if ($certStatus === 'revoked') {
        return 'blocked';
    }

    // No payment reference yet = issuance factory
    if ($paymentRef === '') {
        return 'issuance_factory';
    }

    // Payment not yet confirmed = payment confirmation queue
    if ($paymentStatus !== 'confirmed' || !$paymentVerified) {
        return 'payment_confirmation';
    }

    // Payment confirmed, but artifacts not ready
    if (!$artifactReady) {
        return 'payment_confirmed_pending_artifact';
    }

    // Payment confirmed + artifacts ready + not minted
    if ($artifactReady && !$nftMinted) {
        return 'mint_ready_queue';
    }

    return 'blocked';
}

$sql = "
SELECT
    c.id,
    c.cert_uid,
    c.rwa_type,
    c.family,
    c.rwa_code,
    c.payment_ref,
    c.payment_token,
    c.payment_amount,
    c.owner_user_id,
    c.ton_wallet,
    c.pdf_path,
    c.nft_image_path,
    c.metadata_path,
    c.verify_url,
    c.meta_json AS cert_meta_json,
    c.nft_item_address,
    c.nft_minted,
    c.status AS cert_status,
    c.issued_at,
    c.paid_at AS cert_paid_at,
    c.minted_at,
    c.revoked_at,
    c.updated_at,

    p.id AS payment_id,
    p.owner_user_id AS payment_owner_user_id,
    p.ton_wallet AS payment_ton_wallet,
    p.token_symbol,
    p.token_master,
    p.decimals,
    p.amount,
    p.amount_units,
    p.status AS payment_status,
    p.tx_hash,
    p.verified AS payment_verified,
    p.paid_at AS payment_paid_at,
    p.created_at AS payment_created_at,
    p.updated_at AS payment_updated_at,
    p.meta_json AS payment_meta_json
FROM poado_rwa_certs c
LEFT JOIN (
    SELECT p1.*
    FROM poado_rwa_cert_payments p1
    INNER JOIN (
        SELECT cert_uid, MAX(id) AS max_id
        FROM poado_rwa_cert_payments
        GROUP BY cert_uid
    ) latest
      ON latest.cert_uid = p1.cert_uid
     AND latest.max_id = p1.id
) p
  ON p.cert_uid = c.cert_uid
ORDER BY c.id DESC
";

$stmt = $pdo->query($sql);
$rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

$buckets = [
    'issuance_factory' => [],
    'payment_confirmation' => [],
    'payment_confirmed_pending_artifact' => [],
    'mint_ready_queue' => [],
    'minting_process' => [],
    'issued' => [],
    'blocked' => [],
];

foreach ($rows as $row) {
    $certMeta = qs_meta_arr($row['cert_meta_json'] ?? null);
    $paymentMeta = qs_meta_arr($row['payment_meta_json'] ?? null);

    $artifactReady = qs_artifact_ready($row, $certMeta);
    $nftHealthy = qs_nft_healthy($row, $certMeta);
    $bucket = qs_queue_bucket($row, $artifactReady, $nftHealthy);

    $mint = [];
    $mintRecipient = qs_meta_path($certMeta, ['mint', 'recipient'], '');
    $mintAmountTon = qs_meta_path($certMeta, ['mint', 'amount_ton'], '');
    $mintAmountNano = qs_meta_path($certMeta, ['mint', 'amount_nano'], '');
    $mintItemIndex = qs_meta_path($certMeta, ['mint', 'item_index'], '');
    $mintPayloadB64 = qs_meta_path($certMeta, ['mint', 'payload_b64'], '');
    $mintDeeplink = qs_meta_path($certMeta, ['mint', 'deeplink'], qs_meta_path($certMeta, ['mint', 'wallet_link'], ''));

    if (
        $mintRecipient !== '' || $mintAmountTon !== '' || $mintAmountNano !== '' ||
        $mintItemIndex !== '' || $mintPayloadB64 !== '' || $mintDeeplink !== ''
    ) {
        $mint = [
            'recipient' => (string)$mintRecipient,
            'amount_ton' => (string)$mintAmountTon,
            'amount_nano' => (string)$mintAmountNano,
            'item_index' => (string)$mintItemIndex,
            'payload_b64' => (string)$mintPayloadB64,
            'deeplink' => (string)$mintDeeplink,
            'wallet_link' => (string)$mintDeeplink,
        ];
    }

    $item = [
        'cert_uid' => qs_str($row['cert_uid'] ?? ''),
        'uid' => qs_str($row['cert_uid'] ?? ''),
        'rwa_type' => qs_str($row['rwa_type'] ?? ''),
        'family' => qs_str($row['family'] ?? ''),
        'rwa_code' => qs_str($row['rwa_code'] ?? ''),
        'queue_bucket' => $bucket,

        'payment_ref' => qs_str($row['payment_ref'] ?? ''),
        'payment_token' => qs_str($row['payment_token'] ?? $row['token_symbol'] ?? ''),
        'payment_amount' => qs_str($row['payment_amount'] ?? $row['amount'] ?? ''),
        'payment_amount_units' => qs_str($row['amount_units'] ?? ''),
        'payment_text' => qs_payment_text($row),
        'payment_status' => strtolower(qs_str($row['payment_status'] ?? '')),
        'payment_verified' => (int)($row['payment_verified'] ?? 0),
        'tx_hash' => qs_str($row['tx_hash'] ?? ''),

        'token_symbol' => qs_str($row['token_symbol'] ?? ''),
        'token_master' => qs_str($row['token_master'] ?? ''),
        'decimals' => (int)($row['decimals'] ?? 0),
        'amount' => qs_str($row['amount'] ?? ''),
        'amount_units' => qs_str($row['amount_units'] ?? ''),

        'cert_status' => strtolower(qs_str($row['cert_status'] ?? '')),
        'nft_item_address' => qs_str($row['nft_item_address'] ?? ''),
        'nft_minted' => (int)($row['nft_minted'] ?? 0),
        'artifact_ready' => $artifactReady,
        'nft_healthy' => $nftHealthy,

        'pdf_path' => qs_str($row['pdf_path'] ?? ''),
        'nft_image_path' => qs_str($row['nft_image_path'] ?? ''),
        'metadata_path' => qs_str($row['metadata_path'] ?? ''),
        'verify_url' => qs_str($row['verify_url'] ?? ''),

        'owner_user_id' => (int)($row['owner_user_id'] ?? 0),
        'ton_wallet' => qs_str($row['ton_wallet'] ?? ''),
        'issued_at' => qs_str($row['issued_at'] ?? ''),
        'paid_at' => qs_str($row['payment_paid_at'] ?? $row['cert_paid_at'] ?? ''),
        'minted_at' => qs_str($row['minted_at'] ?? ''),
        'updated_at' => qs_str($row['updated_at'] ?? ''),

        'mint' => $mint,
        'meta' => [
            'artifact_state_ok' => qs_bool_flag(qs_meta_path($certMeta, ['artifact_state', 'ok'], false)),
            'nft_health_healthy' => qs_bool_flag(qs_meta_path($certMeta, ['nft_health', 'healthy'], false)),
            'mint_artifact_health_ok' => qs_bool_flag(qs_meta_path($certMeta, ['mint', 'artifact_health', 'ok'], false)),
            'payment_meta_present' => !empty($paymentMeta),
        ],
        'ui' => [
            'next_banner' => match ($bucket) {
                'issuance_factory' => 'Check & Preview',
                'payment_confirmation' => 'Awaiting payment confirmation',
                'payment_confirmed_pending_artifact' => 'Payment confirmed, artifact pending',
                'mint_ready_queue' => 'Mint ready',
                'minting_process' => 'Minting',
                'issued' => 'Issued / Minted',
                default => 'Blocked',
            },
        ],
    ];

    $buckets[$bucket][] = $item;
}

$counts = [];
foreach ($buckets as $name => $items) {
    $counts[$name] = count($items);
}

echo json_encode([
    'ok' => true,
    'version' => 'v3.0.0-20260410-db-truth-queue-lock',
    'counts' => $counts,
    'buckets' => $buckets,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
