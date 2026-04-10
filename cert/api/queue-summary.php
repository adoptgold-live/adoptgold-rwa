<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/api/queue-summary.php
 * Version: v14.0.0-20260410-locked-queue-authority
 *
 * FINAL LOCK
 * - queue-summary is queue authority for router / queue panels / mint-ready handoff
 * - no guessed UI truth
 * - verify.php unchanged
 * - local QR unchanged
 * - exact DB schema only
 * - preserves existing item contract as much as possible
 *
 * CANONICAL QUEUE ORDER
 * - issuance_factory
 * - payment_confirmation
 * - payment_confirmed_pending_artifact
 * - mint_ready_queue
 * - minting_process
 * - issued
 * - blocked
 *
 * DB TRUTH
 * - poado_rwa_certs
 * - poado_rwa_cert_payments latest row per cert
 *
 * CERT TABLE EXACT COLUMNS USED
 * - cert_uid, rwa_type, family, rwa_code
 * - payment_ref, payment_token, payment_amount
 * - owner_user_id, ton_wallet
 * - nft_image_path, metadata_path, verify_url
 * - meta_json, nft_item_address, nft_minted
 * - status, paid_at, minted_at, updated_at
 *
 * PAYMENT TABLE EXACT COLUMNS USED
 * - cert_uid, payment_ref, owner_user_id, ton_wallet
 * - token_symbol, amount, amount_units
 * - status, verified, paid_at, updated_at, meta_json
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
            'version' => 'v14.0.0-20260410-locked-queue-authority',
            'ts' => time(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

const QS_VERSION = 'v14.0.0-20260410-locked-queue-authority';

function qs_out(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    exit;
}

function qs_fail(string $error, string $detail = '', int $status = 400, array $extra = []): never
{
    $out = [
        'ok' => false,
        'error' => $error,
        'version' => QS_VERSION,
        'ts' => time(),
    ];
    if ($detail !== '') {
        $out['detail'] = $detail;
    }
    if ($extra) {
        $out += $extra;
    }
    qs_out($out, $status);
}

function qs_req(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function qs_req_int(string $key, int $default = 0): int
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_numeric($v) ? (int)$v : $default;
}

function qs_db(): PDO
{
    if (function_exists('db')) {
        $pdo = db();
        if ($pdo instanceof PDO) {
            return $pdo;
        }
    }
    if (($GLOBALS['pdo'] ?? null) instanceof PDO) {
        return $GLOBALS['pdo'];
    }

    $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
    $name = $_ENV['DB_NAME'] ?? 'wems_db';
    $user = $_ENV['DB_USER'] ?? '';
    $pass = $_ENV['DB_PASS'] ?? '';
    $charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';

    $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function qs_json_decode(?string $json): array
{
    $json = trim((string)$json);
    if ($json === '') {
        return [];
    }
    try {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : [];
    } catch (Throwable) {
        return [];
    }
}

function qs_site_url(): string
{
    $base = trim((string)($_ENV['APP_BASE_URL'] ?? $_SERVER['APP_BASE_URL'] ?? getenv('APP_BASE_URL') ?: ''));
    return $base !== '' ? rtrim($base, '/') : 'https://adoptgold.app';
}

function qs_fetch_rows(PDO $pdo, ?string $wallet, ?string $ownerUserId, int $limit): array
{
    $sql = "
        SELECT
            c.id,
            c.cert_uid,
            c.rwa_type,
            c.family,
            c.rwa_code,
            c.price_wems,
            c.price_units,
            c.payment_ref,
            c.payment_token,
            c.payment_amount,
            c.owner_user_id,
            c.ton_wallet,
            c.nft_image_path,
            c.metadata_path,
            c.verify_url,
            c.meta_json,
            c.nft_item_address,
            c.nft_minted,
            c.status,
            c.paid_at,
            c.minted_at,
            c.updated_at
        FROM poado_rwa_certs c
        WHERE 1=1
    ";
    $params = [];

    if ($wallet !== null && $wallet !== '') {
        $sql .= " AND COALESCE(c.ton_wallet, '') = :wallet";
        $params[':wallet'] = $wallet;
    }

    if ($ownerUserId !== null && $ownerUserId !== '') {
        $sql .= " AND CAST(COALESCE(c.owner_user_id, 0) AS CHAR) = :owner_user_id";
        $params[':owner_user_id'] = $ownerUserId;
    }

    $sql .= " ORDER BY c.id DESC LIMIT " . max(1, min(1000, $limit));

    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function qs_fetch_payment_map(PDO $pdo, array $certUids): array
{
    $certUids = array_values(array_unique(array_filter(array_map('strval', $certUids))));
    if (!$certUids) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($certUids), '?'));
    $sql = "
        SELECT p.*
        FROM poado_rwa_cert_payments p
        INNER JOIN (
            SELECT cert_uid, MAX(id) AS max_id
            FROM poado_rwa_cert_payments
            WHERE cert_uid IN ($placeholders)
            GROUP BY cert_uid
        ) x ON x.max_id = p.id
    ";

    $st = $pdo->prepare($sql);
    $st->execute($certUids);

    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $uid = trim((string)($row['cert_uid'] ?? ''));
        if ($uid !== '') {
            $map[$uid] = $row;
        }
    }
    return $map;
}

function qs_detect_rwa_code(array $row): string
{
    $raw = strtoupper(trim((string)($row['rwa_code'] ?? $row['rwa_type'] ?? '')));
    if ($raw !== '' && str_contains($raw, '-EMA')) {
        return $raw;
    }

    $uid = strtoupper(trim((string)($row['cert_uid'] ?? '')));
    $prefix = explode('-', $uid)[0] ?? '';

    return match ($prefix) {
        'RCO2C'  => 'RCO2C-EMA',
        'RH2O'   => 'RH2O-EMA',
        'RBLACK' => 'RBLACK-EMA',
        'RK92'   => 'RK92-EMA',
        'RHRD'   => 'RHRD-EMA',
        'RLIFE'  => 'RLIFE-EMA',
        'RTRIP'  => 'RTRIP-EMA',
        'RPROP'  => 'RPROP-EMA',
        default  => $raw,
    };
}

function qs_detect_family(array $row): string
{
    $family = strtoupper(trim((string)($row['family'] ?? '')));
    if ($family !== '') {
        return $family;
    }

    $rwaCode = qs_detect_rwa_code($row);
    return match ($rwaCode) {
        'RLIFE-EMA', 'RTRIP-EMA', 'RPROP-EMA' => 'SECONDARY',
        'RHRD-EMA' => 'TERTIARY',
        default => 'GENESIS',
    };
}

function qs_payment_status(array $payment): string
{
    return strtolower(trim((string)($payment['status'] ?? '')));
}

function qs_payment_verified(array $payment): int
{
    return (int)($payment['verified'] ?? 0) === 1 ? 1 : 0;
}

function qs_has_payment_ref(array $row, array $payment): bool
{
    $certRef = trim((string)($row['payment_ref'] ?? ''));
    $payRef = trim((string)($payment['payment_ref'] ?? ''));
    return $certRef !== '' || $payRef !== '';
}

function qs_business_payment_done(array $row, array $payment): bool
{
    if (!$payment) {
        return false;
    }

    $status = qs_payment_status($payment);
    if ($status !== '') {
        return true;
    }

    $txHash = trim((string)($payment['tx_hash'] ?? ''));
    $paidAt = trim((string)($payment['paid_at'] ?? ''));
    return $txHash !== '' || $paidAt !== '';
}

function qs_payment_ready(array $payment): bool
{
    return qs_payment_status($payment) === 'confirmed' && qs_payment_verified($payment) === 1;
}

function qs_cert_status(array $row): string
{
    return strtolower(trim((string)($row['status'] ?? '')));
}

function qs_cert_minted(array $row): bool
{
    $status = qs_cert_status($row);
    return ((int)($row['nft_minted'] ?? 0) === 1)
        || ($status === 'minted');
}

function qs_meta_mint_artifact_ok(array $meta): bool
{
    $artifactState = is_array($meta['artifact_state'] ?? null) ? $meta['artifact_state'] : [];
    $nftHealth = is_array($meta['nft_health'] ?? null) ? $meta['nft_health'] : [];
    $mint = is_array($meta['mint'] ?? null) ? $meta['mint'] : [];

    $mintArtifactHealth = is_array($mint['artifact_health'] ?? null) ? $mint['artifact_health'] : [];
    $mintRequest = is_array($mint['mint_request'] ?? null) ? $mint['mint_request'] : [];
    $mintRequestArtifactHealth = is_array($mintRequest['artifact_health'] ?? null) ? $mintRequest['artifact_health'] : [];
    $signedArtifact = is_array($mintRequest['signed_artifact'] ?? null) ? $mintRequest['signed_artifact'] : [];

    if (($artifactState['ok'] ?? false) === true) {
        return true;
    }
    if (($nftHealth['ok'] ?? false) === true) {
        return true;
    }
    if (($mintArtifactHealth['ok'] ?? false) === true) {
        return true;
    }
    if (($mintRequestArtifactHealth['ok'] ?? false) === true) {
        return true;
    }

    $verifyJsonPath = trim((string)($signedArtifact['verify_json_path'] ?? ''));
    $imagePath = trim((string)($signedArtifact['image_path'] ?? ''));
    if ($verifyJsonPath !== '' && $imagePath !== '') {
        return true;
    }

    return false;
}

function qs_artifact_ready(array $row, array $meta): bool
{
    $metadataPath = trim((string)($row['metadata_path'] ?? ''));
    $nftImagePath = trim((string)($row['nft_image_path'] ?? ''));

    if ($metadataPath === '' || $nftImagePath === '') {
        return false;
    }

    return qs_meta_mint_artifact_ok($meta);
}

function qs_nft_healthy(array $row, array $meta): bool
{
    $nftHealth = is_array($meta['nft_health'] ?? null) ? $meta['nft_health'] : [];
    if (($nftHealth['ok'] ?? false) === true) {
        return true;
    }
    return qs_artifact_ready($row, $meta);
}

function qs_has_live_finalize_marker(array $meta): bool
{
    $handoff = is_array($meta['mint_handoff_v9'] ?? null) ? $meta['mint_handoff_v9'] : [];
    $events = is_array($handoff['events'] ?? null) ? $handoff['events'] : [];
    $dispatch = is_array($events['dispatch'] ?? null) ? $events['dispatch'] : [];

    $currentStep = strtolower(trim((string)(
        $handoff['handoff']['step']
        ?? $meta['mint']['final']['step']
        ?? ''
    )));

    $flowState = strtolower(trim((string)($handoff['flow_state'] ?? '')));

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

function qs_queue_bucket(array $row, array $payment, array $meta): string
{
    if (qs_cert_minted($row)) {
        return 'issued';
    }

    $status = qs_cert_status($row);
    if (in_array($status, ['revoked', 'blocked'], true)) {
        return 'blocked';
    }

    $hasPaymentRef = qs_has_payment_ref($row, $payment);
    $businessPaymentDone = qs_business_payment_done($row, $payment);
    $paymentReady = qs_payment_ready($payment);
    $artifactReady = qs_artifact_ready($row, $meta);

    if (!$hasPaymentRef) {
        return 'issuance_factory';
    }

    if (!$businessPaymentDone || !$paymentReady) {
        return 'payment_confirmation';
    }

    if (!$artifactReady) {
        return 'payment_confirmed_pending_artifact';
    }

    if (in_array($status, ['minting', 'mint_pending', 'finalizing'], true)) {
        return 'minting_process';
    }

    if (qs_has_live_finalize_marker($meta) && in_array($status, ['issued'], true)) {
        return 'minting_process';
    }

    return 'mint_ready_queue';
}

function qs_flow_state(array $row, array $payment, array $meta): string
{
    return match (qs_queue_bucket($row, $payment, $meta)) {
        'issuance_factory' => 'issue',
        'payment_confirmation' => 'payment_confirmation',
        'payment_confirmed_pending_artifact' => 'payment_confirmed_pending_artifact',
        'mint_ready_queue' => 'mint_ready',
        'minting_process' => 'minting',
        'issued' => 'issued',
        'blocked' => 'blocked',
        default => 'issue',
    };
}

function qs_payment_text(array $row, array $payment): string
{
    $token = trim((string)($payment['token_symbol'] ?? ''));
    $amount = trim((string)($payment['amount'] ?? ''));
    if ($amount !== '' || $token !== '') {
        return trim($amount . ' ' . $token);
    }

    $token = trim((string)($row['payment_token'] ?? ''));
    $amount = trim((string)($row['payment_amount'] ?? ''));
    if ($amount !== '' || $token !== '') {
        return trim($amount . ' ' . $token);
    }

    $rwaCode = qs_detect_rwa_code($row);
    return match ($rwaCode) {
        'RCO2C-EMA' => '1000 wEMS',
        'RH2O-EMA' => '5000 wEMS',
        'RBLACK-EMA' => '10000 wEMS',
        'RK92-EMA' => '50000 wEMS',
        default => '100 EMA$',
    };
}

function qs_urls(array $row, string $certUid): array
{
    $base = qs_site_url();
    $verifyUrl = trim((string)($row['verify_url'] ?? ''));
    if ($verifyUrl === '') {
        $verifyUrl = $base . '/rwa/cert/verify.php?uid=' . rawurlencode($certUid);
    }

    return [
        'verify_url' => $verifyUrl,
        'verify_status_url' => $base . '/rwa/cert/api/verify-status.php?cert_uid=' . rawurlencode($certUid),
        'mint_verify_url' => $base . '/rwa/cert/api/mint-verify.php?cert_uid=' . rawurlencode($certUid),
    ];
}

function qs_allowed_actions(string $queueBucket, bool $minted): array
{
    if ($minted || $queueBucket === 'issued') {
        return ['preview_nft'];
    }

    return match ($queueBucket) {
        'issuance_factory' => ['check_preview', 'issue_pay'],
        'payment_confirmation' => ['preview_nft', 'reconfirm_payment'],
        'payment_confirmed_pending_artifact' => ['preview_nft', 'repair_nft'],
        'mint_ready_queue' => ['preview_nft', 'repair_nft', 'finalize_mint'],
        'minting_process' => ['preview_nft'],
        default => [],
    };
}

function qs_next_banner(string $queueBucket): string
{
    return match ($queueBucket) {
        'issuance_factory' => 'Continue with Check & Preview / Issue & Pay.',
        'payment_confirmation' => 'Business payment done but not yet verified for mint readiness.',
        'payment_confirmed_pending_artifact' => 'Payment confirmed. Waiting for mint artifacts to become ready.',
        'mint_ready_queue' => 'Payment confirmed. Finalize Mint is ready.',
        'minting_process' => 'Wallet sign requested. Waiting for on-chain mint confirmation.',
        'issued' => 'Issued successfully. NFT mint confirmed.',
        'blocked' => 'This cert is blocked.',
        default => 'Continue with the next required step.',
    };
}

function qs_build_item(array $row, array $payment): array
{
    $certUid = trim((string)($row['cert_uid'] ?? ''));
    $meta = qs_json_decode((string)($row['meta_json'] ?? ''));
    $mint = is_array($meta['mint'] ?? null) ? $meta['mint'] : [];
    $mintRequest = is_array($mint['mint_request'] ?? null) ? $mint['mint_request'] : [];
    $handoff = is_array($meta['mint_handoff_v9'] ?? null) ? $meta['mint_handoff_v9'] : [];
    $unlockRules = is_array($meta['unlock_rules'] ?? null) ? $meta['unlock_rules'] : [];
    $getgems = is_array($meta['getgems_metadata'] ?? null) ? $meta['getgems_metadata'] : [];

    $minted = qs_cert_minted($row);
    $status = qs_cert_status($row);
    $queueBucket = qs_queue_bucket($row, $payment, $meta);
    $flowState = qs_flow_state($row, $payment, $meta);
    $artifactReady = qs_artifact_ready($row, $meta);
    $nftHealthy = qs_nft_healthy($row, $meta);
    $paymentReady = qs_payment_ready($payment);
    $urls = qs_urls($row, $certUid);

    $walletLink = trim((string)(
        $handoff['handoff']['wallet_link']
        ?? $handoff['handoff']['deeplink']
        ?? $mintRequest['wallet_link']
        ?? $mintRequest['deeplink']
        ?? ''
    ));

    $allowedActions = qs_allowed_actions($queueBucket, $minted);

    return [
        'cert_uid' => $certUid,
        'uid' => $certUid,
        'cert' => $certUid,

        'rwa_type' => strtolower(trim((string)($row['rwa_type'] ?? ''))),
        'rwa_code' => qs_detect_rwa_code($row),
        'family' => qs_detect_family($row),

        'status' => $minted ? 'minted' : ($status !== '' ? $status : 'issued'),
        'cert_status' => $status,
        'flow_state' => $flowState,
        'queue_bucket' => $queueBucket,
        'verify_queue_bucket' => $queueBucket,

        'verified' => $paymentReady,
        'payment_ready' => $paymentReady,
        'payment_status' => qs_payment_status($payment),
        'payment_verified' => qs_payment_verified($payment),
        'detail_payment_status' => qs_payment_status($payment),
        'detail_payment_verified' => qs_payment_verified($payment),
        'payment_ref' => (string)($payment['payment_ref'] ?? $row['payment_ref'] ?? ''),
        'detail_payment_ref' => (string)($payment['payment_ref'] ?? $row['payment_ref'] ?? ''),
        'payment_text' => qs_payment_text($row, $payment),
        'detail_payment_text' => qs_payment_text($row, $payment),
        'payment_token' => (string)($payment['token_symbol'] ?? $row['payment_token'] ?? ''),
        'payment_amount' => (string)($payment['amount'] ?? $row['payment_amount'] ?? ''),
        'payment_amount_units' => (string)($payment['amount_units'] ?? $row['price_units'] ?? ''),

        'artifact_ready' => $artifactReady,
        'nft_healthy' => $nftHealthy,

        'minted' => $minted,
        'nft_minted' => $minted ? 1 : (int)($row['nft_minted'] ?? 0),
        'nft_item_address' => (string)($row['nft_item_address'] ?? ''),
        'minted_at' => (string)($row['minted_at'] ?? ''),

        'owner_user_id' => (string)($row['owner_user_id'] ?? ''),
        'ton_wallet' => (string)($row['ton_wallet'] ?? ''),

        'verify_url' => $urls['verify_url'],
        'verify_status_url' => $urls['verify_status_url'],
        'mint_verify_url' => $urls['mint_verify_url'],
        'getgems_url' => (string)($getgems['marketplace_url'] ?? ''),
        'collection_address' => (string)($mintRequest['collection_address'] ?? ''),
        'item_index' => (string)($mintRequest['item_index'] ?? ''),
        'query_id' => (string)($mintRequest['query_id'] ?? ''),
        'valid_until' => (int)($mintRequest['valid_until'] ?? 0),

        'mint' => [
            'recipient' => (string)($mintRequest['recipient'] ?? ''),
            'amount_ton' => (string)($mintRequest['amount_ton'] ?? ''),
            'amount_nano' => (string)($mintRequest['amount_nano'] ?? ''),
            'payload_b64' => (string)($mintRequest['payload_b64'] ?? ''),
            'item_index' => (string)($mintRequest['item_index'] ?? ''),
            'wallet_link' => $walletLink,
            'deeplink' => $walletLink,
            'metadata_url' => (string)($mintRequest['metadata_url'] ?? $getgems['metadata_url'] ?? ''),
            'verify_url' => $urls['verify_url'],
        ],

        'mint_status' => $minted
            ? 'minted'
            : ($queueBucket === 'minting_process'
                ? 'minting'
                : ($queueBucket === 'mint_ready_queue'
                    ? 'mint_ready'
                    : ($queueBucket === 'payment_confirmed_pending_artifact'
                        ? 'artifact_pending'
                        : ($queueBucket === 'payment_confirmation'
                            ? 'payment_confirmation'
                            : 'pending')))),

        'wallet_link' => $walletLink,
        'deeplink' => $walletLink,

        'allowed_actions' => $allowedActions,
        'ui' => [
            'selected_cert_uid' => $certUid,
            'selected_queue_bucket' => $queueBucket,
            'payment_status' => qs_payment_status($payment),
            'payment_verified' => qs_payment_verified($payment),
            'mint_status' => $minted
                ? 'minted'
                : ($queueBucket === 'minting_process'
                    ? 'minting'
                    : ($queueBucket === 'mint_ready_queue'
                        ? 'mint_ready'
                        : ($queueBucket === 'payment_confirmed_pending_artifact'
                            ? 'artifact_pending'
                            : ($queueBucket === 'payment_confirmation'
                                ? 'payment_confirmation'
                                : 'pending')))),
            'next_banner' => qs_next_banner($queueBucket),
            'success_banner' => 'Issued successfully. NFT mint confirmed.',
            'allowed_actions' => $allowedActions,
            'show_preview_nft' => in_array('preview_nft', $allowedActions, true),
            'show_finalize_mint' => in_array('finalize_mint', $allowedActions, true),
            'show_reconfirm_payment' => in_array('reconfirm_payment', $allowedActions, true),
            'show_view_verify' => false,
            'show_issue_pay' => in_array('issue_pay', $allowedActions, true),
            'show_check_preview' => in_array('check_preview', $allowedActions, true),
        ],

        'sections' => [
            'rwa_factory' => $queueBucket === 'issuance_factory',
            'payment_confirmation' => $queueBucket === 'payment_confirmation',
            'payment_confirmed_pending_artifact' => $queueBucket === 'payment_confirmed_pending_artifact',
            'mint_ready_queue' => $queueBucket === 'mint_ready_queue',
            'minting_process' => $queueBucket === 'minting_process',
            'minted' => $queueBucket === 'issued',
            'blocked' => $queueBucket === 'blocked',
        ],

        'unlock_rules' => [
            'target_rwa_code' => (string)($unlockRules['target_rwa_code'] ?? ''),
            'required_rule' => (string)($unlockRules['required_rule'] ?? 'none'),
            'green_minted' => (int)($unlockRules['green_minted'] ?? 0),
            'gold_minted' => (int)($unlockRules['gold_minted'] ?? 0),
            'blue_eligible' => (bool)($unlockRules['blue_eligible'] ?? false),
            'black_eligible' => (bool)($unlockRules['black_eligible'] ?? false),
            'eligible' => (bool)($unlockRules['eligible'] ?? true),
        ],

        'meta_json' => $meta,
    ];
}

try {
    $wallet = qs_req('wallet', '');
    $ownerUserId = qs_req('owner_user_id', '');
    $limit = qs_req_int('limit', 300);

    $pdo = qs_db();
    $rows = qs_fetch_rows(
        $pdo,
        $wallet !== '' ? $wallet : null,
        $ownerUserId !== '' ? $ownerUserId : null,
        $limit
    );

    $certUids = array_values(array_filter(array_map(
        static fn(array $r): string => trim((string)($r['cert_uid'] ?? '')),
        $rows
    )));

    $paymentMap = qs_fetch_payment_map($pdo, $certUids);

    $items = [];
    foreach ($rows as $row) {
        $uid = trim((string)($row['cert_uid'] ?? ''));
        $payment = $paymentMap[$uid] ?? [];
        $items[] = qs_build_item($row, $payment);
    }

    $buckets = [
        'issuance_factory' => [],
        'payment_confirmation' => [],
        'payment_confirmed_pending_artifact' => [],
        'mint_ready_queue' => [],
        'minting_process' => [],
        'issued' => [],
        'blocked' => [],
    ];

    foreach ($items as $item) {
        $bucket = (string)($item['queue_bucket'] ?? 'issuance_factory');
        if (!array_key_exists($bucket, $buckets)) {
            $bucket = 'issuance_factory';
        }
        $buckets[$bucket][] = $item;
    }

    $summary = [
        'issuance_factory' => count($buckets['issuance_factory']),
        'payment_confirmation' => count($buckets['payment_confirmation']),
        'payment_confirmed_pending_artifact' => count($buckets['payment_confirmed_pending_artifact']),
        'mint_ready_queue' => count($buckets['mint_ready_queue']),
        'minting_process' => count($buckets['minting_process']),
        'issued' => count($buckets['issued']),
        'blocked' => count($buckets['blocked']),
        'total' => count($items),
    ];

    qs_out([
        'ok' => true,
        'version' => QS_VERSION,
        'ts' => time(),
        'filters' => [
            'wallet' => $wallet,
            'owner_user_id' => $ownerUserId,
            'limit' => $limit,
        ],
        'summary' => $summary,
        'buckets' => $buckets,
        'items' => $items,
    ]);
} catch (Throwable $e) {
    qs_fail('QUEUE_SUMMARY_FAILED', $e->getMessage(), 500);
}
