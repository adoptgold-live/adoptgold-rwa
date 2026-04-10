<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/api/verify-status.php
 * Version: v16.0.0-20260407-v9-verify-status-fallback-authority
 *
 * FINAL LOCK
 * - verify-status.php is fallback / registry authority for UI
 * - returns list mode and single-cert mode
 * - backend truth only, never guessed from UI
 * - compatible with V9 flow:
 *   preview -> payment -> mint_ready -> minting -> issued
 * - verify.php unchanged
 * - local QR flow unchanged
 *
 * SOURCE OF TRUTH
 * - poado_rwa_certs
 * - poado_rwa_cert_payments
 * - meta_json mint / mint_request / mint_handoff_v9 / nft_health / unlock_rules
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
            'version' => 'v16.0.0-20260407-v9-verify-status-fallback-authority',
            'ts' => time(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

const VS_VERSION = 'v16.0.0-20260407-v9-verify-status-fallback-authority';

function vs_out(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    exit;
}

function vs_fail(string $error, string $detail = '', int $status = 400, array $extra = []): never
{
    $out = [
        'ok' => false,
        'error' => $error,
        'version' => VS_VERSION,
        'ts' => time(),
    ];
    if ($detail !== '') {
        $out['detail'] = $detail;
    }
    if ($extra) {
        $out += $extra;
    }
    vs_out($out, $status);
}

function vs_req(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function vs_req_any(array $keys, string $default = ''): string
{
    foreach ($keys as $key) {
        $v = vs_req((string)$key, '');
        if ($v !== '') {
            return $v;
        }
    }
    return $default;
}

function vs_db(): PDO
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

function vs_json_decode(?string $json): array
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

function vs_site_url(): string
{
    $base = trim((string)($_ENV['APP_BASE_URL'] ?? $_SERVER['APP_BASE_URL'] ?? getenv('APP_BASE_URL') ?: ''));
    return $base !== '' ? rtrim($base, '/') : 'https://adoptgold.app';
}

function vs_fetch_rows(PDO $pdo, ?string $certUid, ?string $wallet, ?string $ownerUserId, int $limit = 300): array
{
    $sql = "
        SELECT c.*
        FROM poado_rwa_certs c
        WHERE 1=1
    ";
    $params = [];

    if ($certUid !== null && $certUid !== '') {
        $sql .= " AND c.cert_uid = :cert_uid";
        $params[':cert_uid'] = $certUid;
    }

    if ($wallet !== null && $wallet !== '') {
        $sql .= " AND (
            COALESCE(c.wallet, '') = :wallet
            OR COALESCE(c.wallet_address, '') = :wallet
            OR COALESCE(c.ton_wallet, '') = :wallet
        )";
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

function vs_fetch_payment_map(PDO $pdo, array $certUids): array
{
    $certUids = array_values(array_unique(array_filter(array_map('strval', $certUids))));
    if (!$certUids) return [];

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

function vs_detect_rwa_code(array $row): string
{
    $raw = strtoupper(trim((string)($row['rwa_code'] ?? $row['rwa_type'] ?? '')));
    if ($raw !== '' && str_contains($raw, '-EMA')) {
        return $raw;
    }

    $uid = strtoupper(trim((string)($row['cert_uid'] ?? '')));
    $prefix = explode('-', $uid)[0] ?? '';

    return match ($prefix) {
        'RCO2C' => 'RCO2C-EMA',
        'RH2O' => 'RH2O-EMA',
        'RBLACK' => 'RBLACK-EMA',
        'RK92' => 'RK92-EMA',
        'RHRD' => 'RHRD-EMA',
        'RLIFE' => 'RLIFE-EMA',
        'RTRIP' => 'RTRIP-EMA',
        'RPROP' => 'RPROP-EMA',
        default => '',
    };
}

function vs_detect_family(array $row): string
{
    $family = strtoupper(trim((string)($row['family'] ?? '')));
    if ($family !== '') {
        return $family;
    }

    $rwaCode = vs_detect_rwa_code($row);
    if (in_array($rwaCode, ['RLIFE-EMA', 'RTRIP-EMA', 'RPROP-EMA', 'RHRD-EMA'], true)) {
        return 'SECONDARY';
    }

    return 'GENESIS';
}

function vs_verify_urls(string $certUid): array
{
    $base = vs_site_url();
    return [
        'verify_url' => $base . '/rwa/cert/verify.php?uid=' . rawurlencode($certUid),
        'verify_status_url' => $base . '/rwa/cert/api/verify-status.php?cert_uid=' . rawurlencode($certUid),
        'mint_verify_url' => $base . '/rwa/cert/api/mint-verify.php?cert_uid=' . rawurlencode($certUid),
    ];
}

function vs_payment_status(array $payment): string
{
    return strtolower(trim((string)($payment['status'] ?? '')));
}

function vs_payment_verified(array $payment): int
{
    return (int)($payment['verified'] ?? 0) === 1 ? 1 : 0;
}

function vs_payment_ready(array $payment): bool
{
    return vs_payment_status($payment) === 'confirmed' && vs_payment_verified($payment) === 1;
}

function vs_cert_status(array $row): string
{
    return strtolower(trim((string)($row['status'] ?? '')));
}

function vs_cert_minted(array $row): bool
{
    $status = strtolower(trim((string)($row['status'] ?? '')));
    $nftMinted = (int)($row['nft_minted'] ?? 0) === 1;
    $itemAddress = trim((string)($row['nft_item_address'] ?? ''));
    $mintedAt = trim((string)($row['minted_at'] ?? ''));

    return $nftMinted
        && $status === 'minted'
        && $itemAddress !== ''
        && $mintedAt !== '';
}

function vs_artifact_ready(array $meta): bool
{
    $nftHealth = is_array($meta['nft_health'] ?? null) ? $meta['nft_health'] : [];
    $mint = is_array($meta['mint'] ?? null) ? $meta['mint'] : [];
    $mintRequest = is_array($mint['mint_request'] ?? null) ? $mint['mint_request'] : (is_array($meta['mint_request'] ?? null) ? $meta['mint_request'] : []);
    $artifact = is_array($mintRequest['signed_artifact'] ?? null) ? $mintRequest['signed_artifact'] : [];
    $artifactHealth = is_array($mintRequest['artifact_health'] ?? null) ? $mintRequest['artifact_health'] : [];

    if (($nftHealth['ok'] ?? false) === true) return true;
    if (($artifactHealth['ok'] ?? false) === true) return true;
    if (trim((string)($artifact['verify_json_path'] ?? '')) !== '' && trim((string)($artifact['image_path'] ?? '')) !== '') return true;

    return false;
}

function vs_nft_healthy(array $meta): bool
{
    $nftHealth = is_array($meta['nft_health'] ?? null) ? $meta['nft_health'] : [];
    $mint = is_array($meta['mint'] ?? null) ? $meta['mint'] : [];
    $mintRequest = is_array($mint['mint_request'] ?? null) ? $mint['mint_request'] : (is_array($meta['mint_request'] ?? null) ? $meta['mint_request'] : []);
    $artifactHealth = is_array($mintRequest['artifact_health'] ?? null) ? $mintRequest['artifact_health'] : [];

    if (($nftHealth['ok'] ?? false) === true) return true;
    if (($artifactHealth['ok'] ?? false) === true) return true;

    return false;
}

function vs_queue_bucket(array $row, array $payment, array $meta): string
{
    if (vs_cert_minted($row)) {
        return 'issued';
    }

    $status = vs_cert_status($row);
    if (in_array($status, ['minting', 'mint_pending'], true)) {
        return 'minting_process';
    }

    $handoff = is_array($meta['mint_handoff_v9'] ?? null) ? $meta['mint_handoff_v9'] : [];
    $ui = is_array($handoff['ui'] ?? null) ? $handoff['ui'] : [];
    $queueHint = trim((string)($handoff['queue_bucket_hint'] ?? $ui['selected_queue_bucket'] ?? ''));
    if ($queueHint !== '' && in_array($queueHint, ['mint_ready_queue', 'minting_process', 'issued'], true)) {
        if ($queueHint === 'issued' && !vs_cert_minted($row)) {
            return 'minting_process';
        }
        return $queueHint;
    }

    if (vs_payment_ready($payment)) {
        if (vs_artifact_ready($meta) || vs_nft_healthy($meta)) {
            return 'mint_ready_queue';
        }
        return 'mint_ready_queue';
    }

    return 'issuance_factory';
}

function vs_flow_state(array $row, array $payment, array $meta): string
{
    if (vs_cert_minted($row)) return 'issued';

    $bucket = vs_queue_bucket($row, $payment, $meta);
    return match ($bucket) {
        'minting_process' => 'minting',
        'mint_ready_queue' => 'mint_ready',
        default => vs_payment_ready($payment) ? 'payment' : 'idle',
    };
}

function vs_payment_text(array $row, array $payment): string
{
    $token = trim((string)($payment['token_symbol'] ?? $payment['token'] ?? ''));
    $amount = trim((string)($payment['amount'] ?? ''));
    if ($amount !== '' || $token !== '') {
        return trim($amount . ' ' . $token);
    }

    $rwaCode = vs_detect_rwa_code($row);
    return match ($rwaCode) {
        'RCO2C-EMA' => '1000 wEMS',
        'RH2O-EMA' => '5000 wEMS',
        'RBLACK-EMA' => '10000 wEMS',
        'RK92-EMA' => '50000 wEMS',
        default => '100 EMA$',
    };
}

function vs_build_item(array $row, array $payment): array
{
    $certUid = trim((string)($row['cert_uid'] ?? ''));
    $meta = vs_json_decode((string)($row['meta_json'] ?? ''));
    $mint = is_array($meta['mint'] ?? null) ? $meta['mint'] : [];
    $mintRequest = is_array($meta['mint_request'] ?? null) ? $meta['mint_request'] : (is_array($mint['mint_request'] ?? null) ? $mint['mint_request'] : []);
    $handoff = is_array($meta['mint_handoff_v9'] ?? null) ? $meta['mint_handoff_v9'] : [];
    $unlockRules = is_array($meta['unlock_rules'] ?? null) ? $meta['unlock_rules'] : [];
    $getgems = is_array($meta['getgems_metadata'] ?? null) ? $meta['getgems_metadata'] : [];

    $minted = vs_cert_minted($row);
    $status = vs_cert_status($row);
    $queueBucket = vs_queue_bucket($row, $payment, $meta);
    $flowState = vs_flow_state($row, $payment, $meta);
    $artifactReady = vs_artifact_ready($meta);
    $nftHealthy = vs_nft_healthy($meta);
    $paymentReady = vs_payment_ready($payment);
    $urls = vs_verify_urls($certUid);

    $walletLink = trim((string)(
        $handoff['handoff']['wallet_link']
        ?? $handoff['handoff']['deeplink']
        ?? $mintRequest['wallet_link']
        ?? $mintRequest['deeplink']
        ?? ''
    ));

    return [
        'cert_uid' => $certUid,
        'uid' => $certUid,
        'cert' => $certUid,

        'rwa_type' => strtolower(trim((string)($row['rwa_type'] ?? ''))),
        'family' => vs_detect_family($row),
        'rwa_code' => vs_detect_rwa_code($row),

        'status' => $minted ? 'minted' : ($queueBucket === 'minting_process' ? 'minting' : ($queueBucket === 'mint_ready_queue' ? 'mint_ready' : ($status !== '' && $status !== 'issued' ? $status : 'issued'))),
        'cert_status' => $status,
        'flow_state' => $flowState,
        'queue_bucket' => $queueBucket,
        'verify_queue_bucket' => $queueBucket,

        'verified' => $paymentReady,
        'payment_ready' => $paymentReady,
        'payment_verified' => vs_payment_verified($payment),
        'payment_status' => vs_payment_status($payment),
        'detail_payment_status' => vs_payment_status($payment),
        'detail_payment_verified' => vs_payment_verified($payment),
        'payment_ref' => (string)($payment['payment_ref'] ?? ''),
        'detail_payment_ref' => (string)($payment['payment_ref'] ?? ''),
        'payment_token' => (string)($payment['token_symbol'] ?? $payment['token'] ?? ''),
        'payment_amount' => (string)($payment['amount'] ?? ''),
        'payment_amount_units' => (string)($payment['amount_units'] ?? ''),
        'payment_text' => vs_payment_text($row, $payment),
        'detail_payment_text' => vs_payment_text($row, $payment),

        'artifact_ready' => $artifactReady,
        'nft_healthy' => $nftHealthy,

        'minted' => $minted,
        'nft_minted' => $minted ? 1 : (int)($row['nft_minted'] ?? 0),
        'nft_item_address' => (string)($row['nft_item_address'] ?? ''),
        'minted_at' => (string)($row['minted_at'] ?? ''),

        'owner_user_id' => (string)($row['owner_user_id'] ?? ''),
        'wallet' => (string)($row['wallet'] ?? ''),
        'wallet_address' => (string)($row['wallet_address'] ?? ''),
        'ton_wallet' => (string)($row['ton_wallet'] ?? ''),

        'verify_url' => $urls['verify_url'],
        'verify_status_url' => $urls['verify_status_url'],
        'mint_verify_url' => $urls['mint_verify_url'],

        'getgems_url' => (string)($getgems['full_metadata_url'] ?? ''),
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
            'deeplink' => $walletLink,
            'wallet_link' => $walletLink,
            'metadata_url' => (string)($mintRequest['metadata_url'] ?? $getgems['metadata_url'] ?? ''),
            'verify_url' => (string)($mintRequest['verify_url'] ?? $urls['verify_url']),
        ],

        'mint_status' => $minted ? 'minted' : ($queueBucket === 'minting_process' ? 'minting' : ($queueBucket === 'mint_ready_queue' ? 'mint_ready' : ($status !== '' && $status !== 'issued' ? $status : 'pending'))),
        'wallet_link' => $walletLink,
        'deeplink' => $walletLink,

        'sections' => [
            'rwa_factory' => ($queueBucket === 'issuance_factory'),
            'mint_ready_queue' => ($queueBucket === 'mint_ready_queue'),
            'minting_process' => ($queueBucket === 'minting_process'),
            'minted' => ($queueBucket === 'issued'),
            'blocked' => ($queueBucket === 'blocked'),
        ],

        'ui' => [
            'selected_cert_uid' => $certUid,
            'selected_queue_bucket' => $queueBucket,
            'payment_status' => vs_payment_status($payment),
            'payment_verified' => vs_payment_verified($payment),
            'mint_status' => $minted ? 'issued' : ($status !== '' ? $status : 'minting'),
            'next_banner' => $minted
                ? 'Issued successfully. NFT mint confirmed.'
                : ($queueBucket === 'mint_ready_queue'
                    ? 'Payment confirmed. Finalize Mint is ready.'
                    : ($queueBucket === 'minting_process'
                        ? 'Wallet sign requested. Waiting for on-chain mint confirmation.'
                        : 'Continue with business payment.')),
            'success_banner' => 'Issued successfully. NFT mint confirmed.',
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
    $certUid = vs_req_any(['cert_uid', 'uid', 'cert'], '');
    $wallet = vs_req('wallet', '');
    $ownerUserId = vs_req_any(['owner_user_id', 'user_id'], '');

    if ($certUid === '' && $wallet === '' && $ownerUserId === '') {
        vs_fail('FILTER_REQUIRED', 'verify-status requires cert_uid or wallet or owner_user_id', 422);
    }

    $pdo = vs_db();
    $rows = vs_fetch_rows(
        $pdo,
        $certUid !== '' ? $certUid : null,
        $wallet !== '' ? $wallet : null,
        $ownerUserId !== '' ? $ownerUserId : null,
        $certUid !== '' ? 10 : 300
    );

    if ($certUid !== '' && !$rows) {
        vs_fail('CERT_NOT_FOUND', 'No cert row found for the requested cert_uid', 404);
    }

    $uids = array_values(array_filter(array_map(
        static fn(array $r): string => trim((string)($r['cert_uid'] ?? '')),
        $rows
    )));
    $paymentMap = vs_fetch_payment_map($pdo, $uids);

    $items = [];
    foreach ($rows as $row) {
        $uid = trim((string)($row['cert_uid'] ?? ''));
        $payment = $paymentMap[$uid] ?? [];
        $items[] = vs_build_item($row, $payment);
    }

    $buckets = [
        'issuance_factory' => [],
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

    $single = ($certUid !== '' && count($items) === 1) ? $items[0] : null;

    vs_out([
        'ok' => true,
        'version' => VS_VERSION,
        'ts' => time(),

        'cert_uid' => $single['cert_uid'] ?? $certUid,
        'item' => $single,
        'items' => $items,
        'rows' => $items,

        'queue_bucket' => $single['queue_bucket'] ?? '',
        'verify_queue_bucket' => $single['verify_queue_bucket'] ?? '',
        'flow_state' => $single['flow_state'] ?? '',
        'mint_status' => $single['mint_status'] ?? '',
        'nft_minted' => $single['nft_minted'] ?? 0,
        'status' => $single['status'] ?? '',

        'buckets' => $buckets,
        'counts' => [
            'issuance_factory' => count($buckets['issuance_factory']),
            'mint_ready_queue' => count($buckets['mint_ready_queue']),
            'minting_process' => count($buckets['minting_process']),
            'issued' => count($buckets['issued']),
            'blocked' => count($buckets['blocked']),
        ],
    ]);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    $status = $msg === 'CERT_NOT_FOUND' ? 404 : 500;
    vs_fail('VERIFY_STATUS_FAILED', $msg, $status);
}
