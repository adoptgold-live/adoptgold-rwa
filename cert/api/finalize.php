<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/api/mint-init.php
 * Version: v1.4.0-20260408-delegate-payload-to-mint-init
 *
 * FINAL LOCK
 * - finalize.php prepares mint handoff only
 * - NEVER marks minted here
 * - payment truth = poado_rwa_cert_payments latest row
 * - artifact truth = repaired image + verify.json
 * - no queue_bucket guessing
 * - no stale cached readiness
 * - finalize.php is NOT payload authority
 * - fresh payload must be delegated to mint-init.php
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/json.php';

if (!function_exists('json_ok')) {
    function json_ok(array $data = []): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
if (!function_exists('json_fail')) {
    function json_fail(string $message, int $status = 400, array $extra = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $message] + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (function_exists('rwa_require_login')) {
    rwa_require_login();
}

function finalize_req_str(string $key): string
{
    return trim((string)($_POST[$key] ?? $_GET[$key] ?? ''));
}

function finalize_json_decode(?string $json): array
{
    $json = trim((string)$json);
    if ($json === '') {
        return [];
    }
    try {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    } catch (Throwable) {
        return [];
    }
}

function finalize_env(string $key, string $default = ''): string
{
    if (function_exists('poado_env')) {
        $v = poado_env($key, $default);
        return is_string($v) ? trim($v) : $default;
    }
    $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return is_string($v) ? trim($v) : $default;
}

function finalize_db(): ?PDO
{
    if (function_exists('rwa_db')) {
        try {
            $pdo = rwa_db();
            if ($pdo instanceof PDO) {
                return $pdo;
            }
        } catch (Throwable) {
        }
    }

    if (function_exists('db_connect')) {
        try {
            $pdo = db_connect();
            if ($pdo instanceof PDO) {
                return $pdo;
            }
        } catch (Throwable) {
        }
    }

    if (function_exists('db')) {
        try {
            $pdo = db();
            if ($pdo instanceof PDO) {
                return $pdo;
            }
        } catch (Throwable) {
        }
    }

    $pdo = $GLOBALS['pdo'] ?? null;
    return $pdo instanceof PDO ? $pdo : null;
}

function finalize_is_ton_address(string $value): bool
{
    $value = trim($value);
    if ($value === '') {
        return false;
    }

    if ((bool)preg_match('/^[-]?(?:0|1):[a-fA-F0-9]{64}$/', $value)) {
        return true;
    }

    return (bool)preg_match('/^[A-Za-z0-9\-_]{40,120}$/', $value);
}

function finalize_public_to_abs(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    if (str_starts_with($path, '/var/www/html/public/')) {
        return $path;
    }
    if (str_starts_with($path, '/')) {
        return '/var/www/html/public' . $path;
    }
    return '/var/www/html/public/' . ltrim($path, '/');
}

function finalize_rel_to_url(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }

    $base = rtrim((string)finalize_env('APP_BASE_URL', 'https://adoptgold.app'), '/');

    if (preg_match('~^https?://~i', $path)) {
        return $path;
    }

    if (str_starts_with($path, '/var/www/html/public/')) {
        $path = substr($path, strlen('/var/www/html/public'));
    }

    if (!str_starts_with($path, '/')) {
        $path = '/' . $path;
    }

    return $base . $path;
}

function finalize_payment_confirmed(array $payment, array $certMeta, array $paymentMeta): bool
{
    $status = strtolower(trim((string)(
        $payment['payment_status']
        ?? $payment['status']
        ?? $paymentMeta['payment_status']
        ?? ($paymentMeta['payment']['status'] ?? '')
        ?? $certMeta['payment_status']
        ?? ($certMeta['payment']['status'] ?? '')
        ?? ''
    )));

    $verified = (int)(
        $payment['verified']
        ?? $paymentMeta['verified']
        ?? ($paymentMeta['payment']['verified'] ?? 0)
        ?? $certMeta['verified']
        ?? ($certMeta['payment']['verified'] ?? 0)
        ?? 0
    );

    return $verified === 1 && in_array($status, ['confirmed', 'paid', 'success', 'completed', 'verified'], true);
}

function finalize_already_minted(array $cert): bool
{
    $status = strtolower(trim((string)($cert['status'] ?? '')));
    return ((int)($cert['nft_minted'] ?? 0) === 1)
        || trim((string)($cert['minted_at'] ?? '')) !== ''
        || trim((string)($cert['nft_item_address'] ?? '')) !== ''
        || $status === 'minted';
}

function finalize_find_artifact_paths(array $cert, array $certMeta, array $paymentMeta): array
{
    $candidatesImage = [
        (string)($cert['nft_image_path'] ?? ''),
        (string)($certMeta['artifacts']['image_path'] ?? ''),
        (string)($certMeta['mint_request']['signed_artifact']['image_path'] ?? ''),
        (string)($certMeta['artifact']['image_path'] ?? ''),
        (string)($paymentMeta['artifacts']['image_path'] ?? ''),
        (string)($paymentMeta['artifact']['image_path'] ?? ''),
    ];

    $candidatesMeta = [
        (string)($cert['metadata_path'] ?? ''),
        (string)($certMeta['artifacts']['metadata_path'] ?? ''),
        (string)($certMeta['mint_request']['signed_artifact']['metadata_path'] ?? ''),
        (string)($certMeta['artifact']['metadata_path'] ?? ''),
        (string)($paymentMeta['artifacts']['metadata_path'] ?? ''),
        (string)($paymentMeta['artifact']['metadata_path'] ?? ''),
    ];

    $candidatesVerify = [
        (string)($certMeta['artifacts']['verify_json_path'] ?? ''),
        (string)($certMeta['mint_request']['signed_artifact']['verify_json_path'] ?? ''),
        (string)($certMeta['artifact']['verify_json_path'] ?? ''),
        (string)($paymentMeta['artifacts']['verify_json_path'] ?? ''),
        (string)($paymentMeta['artifact']['verify_json_path'] ?? ''),
    ];

    $imagePath = '';
    foreach ($candidatesImage as $v) {
        $abs = finalize_public_to_abs($v);
        if ($abs !== '' && is_file($abs)) {
            $imagePath = $abs;
            break;
        }
    }

    $metaPath = '';
    foreach ($candidatesMeta as $v) {
        $abs = finalize_public_to_abs($v);
        if ($abs !== '' && is_file($abs)) {
            $metaPath = $abs;
            break;
        }
    }

    $verifyPath = '';
    foreach ($candidatesVerify as $v) {
        $abs = finalize_public_to_abs($v);
        if ($abs !== '' && is_file($abs)) {
            $verifyPath = $abs;
            break;
        }
    }

    if ($verifyPath === '' && $imagePath !== '') {
        $baseDir = dirname(dirname($imagePath));
        $guess = $baseDir . '/verify/verify.json';
        if (is_file($guess)) {
            $verifyPath = $guess;
        }
    }

    if ($metaPath === '' && $imagePath !== '') {
        $baseDir = dirname(dirname($imagePath));
        $guess = $baseDir . '/meta/metadata.json';
        if (is_file($guess)) {
            $metaPath = $guess;
        }
    }

    return [
        'image_path' => $imagePath,
        'meta_path' => $metaPath,
        'verify_path' => $verifyPath,
        'image_url' => $imagePath !== '' ? finalize_rel_to_url($imagePath) : '',
        'metadata_url' => $metaPath !== '' ? finalize_rel_to_url($metaPath) : '',
        'verify_json_url' => $verifyPath !== '' ? finalize_rel_to_url($verifyPath) : '',
    ];
}

function finalize_verify_truth(string $verifyJsonPath): array
{
    if ($verifyJsonPath === '' || !is_file($verifyJsonPath)) {
        return [
            'ok' => false,
            'error' => 'VERIFY_JSON_NOT_FOUND',
            'verify' => [],
        ];
    }

    $verify = finalize_json_decode((string)file_get_contents($verifyJsonPath));
    $ok = !empty($verify['ok'])
        && !empty($verify['healthy'])
        && !empty($verify['artifact_ready'])
        && !empty($verify['nft_healthy'])
        && empty($verify['used_fallback_placeholder']);

    return [
        'ok' => $ok,
        'error' => $ok ? '' : 'VERIFY_JSON_UNHEALTHY',
        'verify' => $verify,
    ];
}

function finalize_delegate_endpoint(string $uid): string
{
    $base = rtrim((string)finalize_env('APP_BASE_URL', 'https://adoptgold.app'), '/');
    return $base . '/rwa/cert/api/mint-init.php?cert_uid=' . rawurlencode($uid);
}

function finalize_http_json(string $url, int $timeout = 25): array
{
    $raw = '';
    $status = 0;
    $headers = [
        'Accept: application/json',
        'X-Requested-With: XMLHttpRequest',
    ];

    if (!empty($_SERVER['HTTP_COOKIE'])) {
        $headers[] = 'Cookie: ' . $_SERVER['HTTP_COOKIE'];
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('DELEGATE_HTTP_INIT_FAILED');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw = (string)curl_exec($ch);
        if ($raw === '' && curl_errno($ch)) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('DELEGATE_HTTP_FAILED: ' . $err);
        }

        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => implode("\r\n", $headers),
            ],
        ]);

        $raw = (string)@file_get_contents($url, false, $context);
        $meta = $http_response_header ?? [];
        foreach ($meta as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~i', (string)$line, $m)) {
                $status = (int)$m[1];
                break;
            }
        }
    }

    $json = finalize_json_decode($raw);
    if (!$json) {
        throw new RuntimeException('DELEGATE_NON_JSON_RESPONSE');
    }

    return [
        'status' => $status,
        'json' => $json,
        'raw' => $raw,
    ];
}

function finalize_delegate_mint_init(string $uid): array
{
    $delegateUrl = finalize_delegate_endpoint($uid);
    $http = finalize_http_json($delegateUrl, 25);
    $json = is_array($http['json'] ?? null) ? $http['json'] : [];

    if (($json['ok'] ?? false) !== true) {
        $error = trim((string)($json['error'] ?? 'MINT_INIT_DELEGATE_FAILED'));
        $detail = trim((string)($json['detail'] ?? ''));
        throw new RuntimeException($detail !== '' ? ($error . ': ' . $detail) : $error);
    }

    return [
        'url' => $delegateUrl,
        'status' => (int)($http['status'] ?? 0),
        'data' => $json,
    ];
}

function finalize_build_mint_request(
    string $uid,
    array $artifact,
    array $verifyTruth,
    array $delegateData,
    string $ownerWallet
): array {
    $recipient = trim((string)($delegateData['recipient'] ?? ''));
    $collectionAddress = trim((string)($delegateData['collection_address'] ?? ''));
    $amountTon = trim((string)($delegateData['amount_ton'] ?? ''));
    $amountNano = trim((string)($delegateData['amount_nano'] ?? ''));
    $payloadB64 = trim((string)($delegateData['payload_b64'] ?? ''));
    $itemIndex = (int)($delegateData['item_index'] ?? 0);
    $queryId = trim((string)($delegateData['query_id'] ?? ''));
    $validUntil = (int)($delegateData['valid_until'] ?? 0);

    $mintMeta = $delegateData['getgems_metadata'] ?? [];
    $itemSuffix = trim((string)(
        $delegateData['item_suffix']
        ?? $mintMeta['item_content_suffix']
        ?? ''
    ));

    if ($recipient === '') {
        throw new RuntimeException('MINT_INIT_RECIPIENT_MISSING');
    }
    if (!finalize_is_ton_address($recipient)) {
        throw new RuntimeException('MINT_INIT_RECIPIENT_INVALID');
    }

    if ($collectionAddress === '') {
        $collectionAddress = $recipient;
    }
    if (!finalize_is_ton_address($collectionAddress)) {
        throw new RuntimeException('MINT_INIT_COLLECTION_INVALID');
    }

    if ($amountTon === '' || !preg_match('/^\d+(\.\d{1,9})?$/', $amountTon)) {
        throw new RuntimeException('MINT_INIT_AMOUNT_TON_INVALID');
    }
    if ($amountNano === '' || !ctype_digit($amountNano)) {
        throw new RuntimeException('MINT_INIT_AMOUNT_NANO_INVALID');
    }
    if ($payloadB64 === '') {
        throw new RuntimeException('MINT_INIT_PAYLOAD_B64_MISSING');
    }

    $verifyUrl = trim((string)($delegateData['verify_url'] ?? ''));
    if ($verifyUrl === '') {
        $verifyUrl = rtrim((string)finalize_env('APP_BASE_URL', 'https://adoptgold.app'), '/') . '/rwa/cert/verify.php?uid=' . rawurlencode($uid);
    }

    return [
        'cert_uid' => $uid,
        'recipient' => $recipient,
        'collection_address' => $collectionAddress,
        'owner_wallet' => $ownerWallet,
        'amount_ton' => $amountTon,
        'amount_nano' => $amountNano,
        'payload_b64' => $payloadB64,
        'item_index' => $itemIndex,
        'query_id' => $queryId,
        'item_suffix' => $itemSuffix,
        'valid_until' => $validUntil,
        'ref' => 'MINT|' . $uid,
        'verify_url' => $verifyUrl,
        'verify_json' => (string)$artifact['verify_path'],
        'verify_json_url' => (string)$artifact['verify_json_url'],
        'image_path' => (string)$artifact['image_path'],
        'image_url' => (string)$artifact['image_url'],
        'metadata_path' => (string)$artifact['meta_path'],
        'metadata_url' => (string)$artifact['metadata_url'],
        'single_transfer_only' => true,
        'artifact_health' => [
            'ok' => true,
            'healthy' => true,
            'artifact_ready' => true,
            'nft_healthy' => true,
            'used_fallback_placeholder' => false,
            'verified_at' => (string)($verifyTruth['verify']['verified_at'] ?? gmdate('c')),
        ],
    ];
}

$uid = finalize_req_str('uid');
if ($uid === '') {
    $uid = finalize_req_str('cert_uid');
}
if ($uid === '') {
    $uid = finalize_req_str('cert');
}
if ($uid === '') {
    json_fail('Missing cert uid', 400);
}

$pdo = finalize_db();
if (!$pdo instanceof PDO) {
    json_fail('Database not ready', 500);
}

try {
    $st = $pdo->prepare("
        SELECT *
        FROM poado_rwa_certs
        WHERE cert_uid = :uid
        LIMIT 1
    ");
    $st->execute([':uid' => $uid]);
    $cert = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$cert) {
        json_fail('Certificate not found', 404);
    }

    $st = $pdo->prepare("
        SELECT *
        FROM poado_rwa_cert_payments
        WHERE cert_uid = :uid
        ORDER BY id DESC
        LIMIT 1
    ");
    $st->execute([':uid' => $uid]);
    $payment = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$payment) {
        json_fail('Payment row not found', 409);
    }

    $certMeta = finalize_json_decode((string)($cert['meta_json'] ?? ''));
    $paymentMeta = finalize_json_decode((string)($payment['meta_json'] ?? ''));

    if (!finalize_payment_confirmed($payment, $certMeta, $paymentMeta)) {
        json_fail('PAYMENT_NOT_CONFIRMED', 409, [
            'payment_status' => (string)($payment['payment_status'] ?? $payment['status'] ?? ''),
            'payment_verified' => (int)($payment['verified'] ?? 0),
        ]);
    }

    if (finalize_already_minted($cert)) {
        json_fail('CERT_ALREADY_MINTED', 409, [
            'status' => (string)($cert['status'] ?? ''),
            'nft_item_address' => (string)($cert['nft_item_address'] ?? ''),
            'minted_at' => (string)($cert['minted_at'] ?? ''),
        ]);
    }

    $artifact = finalize_find_artifact_paths($cert, $certMeta, $paymentMeta);
    if ($artifact['image_path'] === '' || $artifact['verify_path'] === '') {
        json_fail('NFT_NOT_READY', 409, [
            'image_path' => (string)$artifact['image_path'],
            'verify_path' => (string)$artifact['verify_path'],
        ]);
    }

    $verifyTruth = finalize_verify_truth((string)$artifact['verify_path']);
    if (!$verifyTruth['ok']) {
        json_fail('NFT_NOT_READY', 409, [
            'verify_error' => $verifyTruth['error'],
            'verify_path' => (string)$artifact['verify_path'],
            'verify' => $verifyTruth['verify'],
        ]);
    }

    $ownerWallet = trim((string)(
        $cert['ton_wallet']
        ?? $payment['ton_wallet']
        ?? $certMeta['owner_wallet_raw']
        ?? $certMeta['owner_wallet']
        ?? ''
    ));
    if ($ownerWallet === '') {
        json_fail('Owner TON wallet not found', 409);
    }
    if (!finalize_is_ton_address($ownerWallet)) {
        json_fail('Owner TON wallet invalid', 409, [
            'owner_wallet' => $ownerWallet,
        ]);
    }

    $delegate = finalize_delegate_mint_init($uid);
    $delegateData = is_array($delegate['data'] ?? null) ? $delegate['data'] : [];

    if (($delegateData['mint_ready'] ?? false) !== true) {
        json_fail('MINT_INIT_NOT_READY', 409, [
            'delegate_url' => (string)($delegate['url'] ?? ''),
            'delegate_status' => (int)($delegate['status'] ?? 0),
            'delegate_error' => (string)($delegateData['error'] ?? ''),
            'delegate_detail' => (string)($delegateData['detail'] ?? ''),
        ]);
    }

    $mintRequest = finalize_build_mint_request($uid, $artifact, $verifyTruth, $delegateData, $ownerWallet);

    json_ok([
        'cert_uid' => $uid,
        'status' => (string)($cert['status'] ?? ''),
        'payment_status' => (string)($payment['payment_status'] ?? $payment['status'] ?? 'confirmed'),
        'nft_ready' => true,
        'mint_ready' => true,
        'artifact' => [
            'image_path' => (string)$artifact['image_path'],
            'meta_path' => (string)$artifact['meta_path'],
            'verify_path' => (string)$artifact['verify_path'],
            'image_url' => (string)$artifact['image_url'],
            'metadata_url' => (string)$artifact['metadata_url'],
            'verify_json_url' => (string)$artifact['verify_json_url'],
        ],
        'verify' => $verifyTruth['verify'],
        'mint_request' => $mintRequest,
        'delegate' => [
            'source' => 'mint-init.php',
            'url' => (string)($delegate['url'] ?? ''),
            'http_status' => (int)($delegate['status'] ?? 0),
        ],
        'wallet_link' => (string)($delegateData['wallet_link'] ?? $delegateData['deeplink'] ?? ''),
        'deeplink' => (string)($delegateData['deeplink'] ?? $delegateData['wallet_link'] ?? ''),
        'tonconnect' => $delegateData['tonconnect'] ?? null,
        'single_transfer_only' => true,
        'next_action' => 'wallet_sign_then_call_mint_verify',
    ]);
} catch (Throwable $e) {
    $msg = trim($e->getMessage());

    if (str_starts_with($msg, 'PAYMENT_NOT_CONFIRMED')) {
        json_fail('PAYMENT_NOT_CONFIRMED', 409);
    }

    if (str_starts_with($msg, 'CERT_ALREADY_MINTED')) {
        json_fail('CERT_ALREADY_MINTED', 409);
    }

    if (str_starts_with($msg, 'MINT_INIT_')) {
        json_fail($msg, 500, [
            'cert_uid' => $uid,
            'delegate_url' => finalize_delegate_endpoint($uid),
        ]);
    }

    json_fail('Finalize mint preparation failed: ' . $msg, 500, [
        'cert_uid' => $uid,
    ]);
}
