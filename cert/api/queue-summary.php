<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/api/queue-summary.php
 * Version: v4.0.0-20260410-final-db-truth-read-model
 *
 * FINAL LOCK
 * - Read model only
 * - No writes
 * - Queue truth derives from:
 *   1) latest poado_rwa_cert_payments row
 *   2) payment.status
 *   3) payment.verified
 *   4) payment_ref presence
 *   5) metadata_path + nft_image_path presence
 *   6) nft_minted
 *
 * DO NOT use poado_rwa_certs.status as payment lifecycle truth.
 * poado_rwa_certs.status is NFT lifecycle only:
 *   issued / minted / revoked
 *
 * Queue mapping:
 * - issuance_factory:
 *     cert exists, but no payment row OR no payment_ref
 * - payment_confirmation:
 *     payment_ref exists, but latest payment row missing
 *     OR payment.status != confirmed
 *     OR payment.verified != 1
 * - payment_confirmed_pending_artifact:
 *     payment.status = confirmed
 *     AND payment.verified = 1
 *     AND required mint artifacts not ready
 * - mint_ready_queue:
 *     payment.status = confirmed
 *     AND payment.verified = 1
 *     AND metadata_path present
 *     AND nft_image_path present
 *     AND nft_minted = 0
 * - issued:
 *     nft_minted = 1 OR cert.status = minted
 * - blocked:
 *     revoked or structurally inconsistent rows
 */

header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/bootstrap.php';

function qs_json(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function qs_fail(string $error, int $status = 500, array $extra = []): never
{
    qs_json(array_merge([
        'ok' => false,
        'error' => $error,
        'ts' => time(),
    ], $extra), $status);
}

function qs_str(mixed $v): string
{
    return trim((string)($v ?? ''));
}

function qs_int(mixed $v): int
{
    return (int)($v ?? 0);
}

function qs_boolish(mixed $v): bool
{
    if (is_bool($v)) return $v;
    $s = strtolower(trim((string)($v ?? '')));
    return in_array($s, ['1', 'true', 'yes', 'y', 'ok'], true);
}

function qs_decode_json(?string $json): array
{
    if (!$json) return [];
    try {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : [];
    } catch (Throwable) {
        return [];
    }
}

function qs_arr_get(array $arr, array $path, mixed $default = null): mixed
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

function qs_build_mint_meta(array $certMeta): array
{
    $mint = is_array($certMeta['mint'] ?? null) ? $certMeta['mint'] : [];

    return [
        'recipient'   => qs_str($mint['recipient'] ?? ''),
        'amount_ton'  => qs_str($mint['amount_ton'] ?? ''),
        'amount_nano' => qs_str($mint['amount_nano'] ?? ''),
        'item_index'  => qs_str($mint['item_index'] ?? ''),
        'payload_b64' => qs_str($mint['payload_b64'] ?? ''),
        'deeplink'    => qs_str($mint['deeplink'] ?? ($mint['wallet_link'] ?? '')),
        'wallet_link' => qs_str($mint['wallet_link'] ?? ($mint['deeplink'] ?? '')),
    ];
}

function qs_has_live_finalize_marker(array $certMeta): bool
{
    $handoff = is_array($certMeta['mint_handoff_v9'] ?? null) ? $certMeta['mint_handoff_v9'] : [];
    $events = is_array($handoff['events'] ?? null) ? $handoff['events'] : [];
    $dispatch = is_array($events['dispatch'] ?? null) ? $events['dispatch'] : [];

    $currentStep = strtolower(qs_str(
        $handoff['handoff']['step']
        ?? ($certMeta['mint']['final']['step'] ?? '')
    ));

    $flowState = strtolower(qs_str(
        $handoff['flow_state'] ?? ($certMeta['mint']['flow_state'] ?? '')
    ));

    if (in_array('cert:wallet-sign', $dispatch, true)) {
        return true;
    }
    if ($currentStep === 'wallet_sign') {
        return true;
    }
    if ($flowState === 'minting') {
        return true;
    }

    return false;
}

function qs_has_required_artifacts(array $row): bool
{
    return qs_str($row['metadata_path'] ?? '') !== ''
        && qs_str($row['nft_image_path'] ?? '') !== '';
}

function qs_compute_bucket(array $row, array $certMeta = []): string
{
    $certStatus = strtolower(qs_str($row['cert_status'] ?? ''));
    $paymentId = qs_int($row['payment_id'] ?? 0);
    $paymentRef = qs_str($row['payment_ref'] ?? '');
    $paymentStatus = strtolower(qs_str($row['payment_status'] ?? ''));
    $paymentVerified = qs_int($row['payment_verified'] ?? 0) === 1;
    $hasArtifacts = qs_has_required_artifacts($row);
    $nftMinted = qs_int($row['nft_minted'] ?? 0) === 1;

    if ($nftMinted || $certStatus === 'minted') {
        return 'issued';
    }

    if ($certStatus === 'revoked') {
        return 'blocked';
    }

    if ($paymentRef === '') {
        return 'issuance_factory';
    }

    if ($paymentId <= 0) {
        return 'payment_confirmation';
    }

    if ($paymentStatus !== 'confirmed' || !$paymentVerified) {
        return 'payment_confirmation';
    }

    if (!$hasArtifacts) {
        return 'payment_confirmed_pending_artifact';
    }

    if (qs_has_live_finalize_marker($certMeta)) {
        return 'minting_process';
    }

    return 'mint_ready_queue';
}

function qs_bucket_label(string $bucket): string
{
    return match ($bucket) {
        'issuance_factory' => 'Check & Preview',
        'payment_confirmation' => 'Awaiting payment confirmation',
        'payment_confirmed_pending_artifact' => 'Payment confirmed, artifact pending',
        'mint_ready_queue' => 'Mint ready',
        'minting_process' => 'Minting',
        'issued' => 'Issued / Minted',
        default => 'Blocked',
    };
}

try {
    db_connect();
    $pdo = $GLOBALS['pdo'] ?? null;

    if (!$pdo instanceof PDO) {
        qs_fail('DB_NOT_READY', 500);
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
        $certMeta = qs_decode_json($row['cert_meta_json'] ?? null);
        $paymentMeta = qs_decode_json($row['payment_meta_json'] ?? null);
        $bucket = qs_compute_bucket($row, $certMeta);

        $artifactReady = qs_has_required_artifacts($row);
        $nftHealthy = $artifactReady || qs_boolish(qs_arr_get($certMeta, ['nft_health', 'healthy'], false));
        $mint = qs_build_mint_meta($certMeta);

        $item = [
            'cert_uid' => qs_str($row['cert_uid'] ?? ''),
            'uid' => qs_str($row['cert_uid'] ?? ''),
            'rwa_type' => qs_str($row['rwa_type'] ?? ''),
            'family' => qs_str($row['family'] ?? ''),
            'rwa_code' => qs_str($row['rwa_code'] ?? ''),
            'queue_bucket' => $bucket,

            'payment_ref' => qs_str($row['payment_ref'] ?? ''),
            'payment_token' => qs_str($row['payment_token'] ?? ($row['token_symbol'] ?? '')),
            'payment_amount' => qs_str($row['payment_amount'] ?? ($row['amount'] ?? '')),
            'payment_amount_units' => qs_str($row['amount_units'] ?? ''),
            'payment_text' => trim(qs_str($row['payment_amount'] ?? ($row['amount'] ?? '')) . ' ' . qs_str($row['payment_token'] ?? ($row['token_symbol'] ?? ''))),
            'payment_status' => strtolower(qs_str($row['payment_status'] ?? '')),
            'payment_verified' => qs_int($row['payment_verified'] ?? 0),
            'payment_ready' => strtolower(qs_str($row['payment_status'] ?? '')) === 'confirmed' && qs_int($row['payment_verified'] ?? 0) === 1,
            'tx_hash' => qs_str($row['tx_hash'] ?? ''),

            'token_symbol' => qs_str($row['token_symbol'] ?? ''),
            'token_master' => qs_str($row['token_master'] ?? ''),
            'decimals' => qs_int($row['decimals'] ?? 0),
            'amount' => qs_str($row['amount'] ?? ''),
            'amount_units' => qs_str($row['amount_units'] ?? ''),

            'cert_status' => strtolower(qs_str($row['cert_status'] ?? '')),
            'nft_item_address' => qs_str($row['nft_item_address'] ?? ''),
            'nft_minted' => qs_int($row['nft_minted'] ?? 0),
            'artifact_ready' => $artifactReady,
            'nft_healthy' => $nftHealthy,

            'pdf_path' => qs_str($row['pdf_path'] ?? ''),
            'nft_image_path' => qs_str($row['nft_image_path'] ?? ''),
            'metadata_path' => qs_str($row['metadata_path'] ?? ''),
            'verify_url' => qs_str($row['verify_url'] ?? ''),

            'owner_user_id' => qs_int($row['owner_user_id'] ?? 0),
            'payment_owner_user_id' => qs_int($row['payment_owner_user_id'] ?? 0),
            'ton_wallet' => qs_str($row['ton_wallet'] ?? ''),
            'payment_ton_wallet' => qs_str($row['payment_ton_wallet'] ?? ''),
            'issued_at' => qs_str($row['issued_at'] ?? ''),
            'paid_at' => qs_str($row['payment_paid_at'] ?? ($row['cert_paid_at'] ?? '')),
            'minted_at' => qs_str($row['minted_at'] ?? ''),
            'revoked_at' => qs_str($row['revoked_at'] ?? ''),
            'updated_at' => qs_str($row['updated_at'] ?? ''),

            'mint' => $mint,

            'meta' => [
                'cert_meta_present' => !empty($certMeta),
                'payment_meta_present' => !empty($paymentMeta),
                'artifact_state_ok' => qs_boolish(qs_arr_get($certMeta, ['artifact_state', 'ok'], false)),
                'nft_health_healthy' => qs_boolish(qs_arr_get($certMeta, ['nft_health', 'healthy'], false)),
                'mint_artifact_health_ok' => qs_boolish(qs_arr_get($certMeta, ['mint', 'artifact_health', 'ok'], false)),
            ],

            'ui' => [
                'next_banner' => qs_bucket_label($bucket),
            ],
        ];

        $buckets[$bucket][] = $item;
    }

    $counts = [];
    foreach ($buckets as $name => $items) {
        $counts[$name] = count($items);
    }

    qs_json([
        'ok' => true,
        'version' => 'v4.0.0-20260410-final-db-truth-read-model',
        'counts' => $counts,
        'buckets' => $buckets,
        'ts' => time(),
    ]);
} catch (Throwable $e) {
    qs_fail('QUEUE_SUMMARY_FAILED', 500, [
        'detail' => $e->getMessage(),
    ]);
}
