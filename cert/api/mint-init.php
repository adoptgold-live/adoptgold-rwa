<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/api/mint-init.php
 * Version: v19.0.0-20260409-final-no-duplicate-metadata-write
 *
 * FINAL LOCK
 * - mint-init.php is gatekeeper only
 * - payment truth comes only from poado_rwa_cert_payments
 * - artifact truth comes only from signed verify.json
 * - no repair, no heal, no fallback inference
 * - accepts cert_uid / uid / cert aliases
 * - enforces mint prerequisite rules:
 *   * RH2O-EMA requires 10 minted RCO2C-EMA
 *   * RBLACK-EMA requires 1 minted RK92-EMA
 *
 * FINAL HOTFIX
 * - NO duplicate root-level GetGems metadata write
 * - canonical artifact metadata remains the only metadata authority
 * - payload build uses canonical metadata path only
 * - preserve wallet handoff / UI continuity contract
 * - does NOT change verify.php
 * - does NOT change local QR behavior
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
            'version' => 'v19.0.0-20260409-final-no-duplicate-metadata-write',
            'ts' => time(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

$miGetgemsHelper = __DIR__ . '/_getgems-metadata.php';
if (is_file($miGetgemsHelper)) {
    require_once $miGetgemsHelper;
}

const MI_VERSION = 'v19.0.0-20260409-final-no-duplicate-metadata-write';
const MI_PUBLIC_ROOT = '/var/www/html/public';
const MI_SITE_URL = 'https://adoptgold.app';

function mi_out(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    exit;
}

function mi_fail(string $error, string $detail = '', int $status = 400, array $extra = []): never
{
    $out = [
        'ok' => false,
        'error' => $error,
        'version' => MI_VERSION,
        'ts' => time(),
    ];
    if ($detail !== '') {
        $out['detail'] = $detail;
    }
    if ($extra) {
        $out += $extra;
    }
    mi_out($out, $status);
}

function mi_req(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function mi_req_any(array $keys, string $default = ''): string
{
    foreach ($keys as $key) {
        $v = mi_req((string)$key, '');
        if ($v !== '') {
            return $v;
        }
    }
    return $default;
}

function mi_db(): PDO
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

function mi_env(string $key, string $default = ''): string
{
    $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return is_string($v) ? trim($v) : $default;
}

function mi_json_decode(?string $json): array
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

function mi_json_encode(array $data): string
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('JSON_ENCODE_FAILED');
    }
    return $json;
}

function mi_site_url(): string
{
    $base = trim(mi_env('APP_BASE_URL', ''));
    return $base !== '' ? rtrim($base, '/') : MI_SITE_URL;
}

function mi_slash(string $v): string
{
    return str_replace('\\', '/', $v);
}

function mi_abs_path(string $path): string
{
    $path = trim(mi_slash($path));
    if ($path === '') {
        return '';
    }
    if (str_starts_with($path, MI_PUBLIC_ROOT . '/')) {
        return $path;
    }
    if ($path[0] === '/') {
        return MI_PUBLIC_ROOT . $path;
    }
    return MI_PUBLIC_ROOT . '/' . ltrim($path, '/');
}

function mi_rel_path(string $abs): string
{
    $abs = mi_slash($abs);
    $root = mi_slash(MI_PUBLIC_ROOT);
    if (str_starts_with($abs, $root . '/')) {
        return substr($abs, strlen($root));
    }
    return $abs;
}

function mi_normalize_public_path(string $path): string
{
    $path = trim(mi_slash($path));
    if ($path === '') {
        return '';
    }

    if (preg_match('~^https?://~i', $path)) {
        $u = parse_url($path, PHP_URL_PATH);
        $path = is_string($u) ? trim(mi_slash($u)) : '';
        if ($path === '') {
            return '';
        }
    }

    if (str_starts_with($path, MI_PUBLIC_ROOT . '/')) {
        $path = substr($path, strlen(MI_PUBLIC_ROOT));
    }

    if ($path !== '' && $path[0] !== '/') {
        $path = '/' . ltrim($path, '/');
    }

    return $path;
}

function mi_same_public_path(string $left, string $right): bool
{
    $a = mi_normalize_public_path($left);
    $b = mi_normalize_public_path($right);

    if ($a === '' || $b === '') {
        return false;
    }

    return $a === $b;
}

function mi_url_from_abs(string $abs): string
{
    return mi_site_url() . mi_rel_path($abs);
}

function mi_url_from_public_path(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    if (preg_match('~^https?://~i', $path)) {
        return $path;
    }
    if ($path[0] !== '/') {
        $path = '/' . ltrim($path, '/');
    }
    return mi_site_url() . $path;
}

function mi_sha1_file_safe(string $path): string
{
    if (!is_file($path)) {
        return '';
    }
    $hash = @sha1_file($path);
    return is_string($hash) ? strtolower($hash) : '';
}

function mi_png_info(string $path): array
{
    if (!is_file($path)) {
        return ['ok' => false, 'width' => 0, 'height' => 0, 'mime' => '', 'bytes' => 0];
    }
    $bytes = (int)@filesize($path);
    if ($bytes <= 0) {
        return ['ok' => false, 'width' => 0, 'height' => 0, 'mime' => '', 'bytes' => 0];
    }
    $info = @getimagesize($path);
    if (!is_array($info) || ($info['mime'] ?? '') !== 'image/png') {
        return ['ok' => false, 'width' => 0, 'height' => 0, 'mime' => '', 'bytes' => $bytes];
    }
    return [
        'ok' => true,
        'width' => (int)($info[0] ?? 0),
        'height' => (int)($info[1] ?? 0),
        'mime' => (string)($info['mime'] ?? ''),
        'bytes' => $bytes,
    ];
}

function mi_fetch_cert(PDO $pdo, string $certUid): array
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
        throw new RuntimeException('CERT_NOT_FOUND');
    }
    $row['meta_json_decoded'] = mi_json_decode((string)($row['meta_json'] ?? ''));
    return $row;
}

function mi_select_cert_for_update(PDO $pdo, string $certUid): array
{
    $st = $pdo->prepare("
        SELECT *
        FROM poado_rwa_certs
        WHERE cert_uid = :uid
        LIMIT 1
        FOR UPDATE
    ");
    $st->execute([':uid' => $certUid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('CERT_NOT_FOUND');
    }
    $row['meta_json_decoded'] = mi_json_decode((string)($row['meta_json'] ?? ''));
    return $row;
}

function mi_fetch_payment(PDO $pdo, string $certUid): array
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
    if (!is_array($row)) {
        throw new RuntimeException('PAYMENT_ROW_NOT_FOUND');
    }
    return $row;
}

function mi_payment_is_ready(array $payment): bool
{
    return strtolower(trim((string)($payment['status'] ?? ''))) === 'confirmed'
        && (int)($payment['verified'] ?? 0) === 1;
}

function mi_assert_payment_ready(array $payment): void
{
    if (!mi_payment_is_ready($payment)) {
        throw new RuntimeException('PAYMENT_NOT_CONFIRMED_VERIFIED');
    }
}

function mi_assert_not_minted(array $cert): void
{
    $status = strtolower(trim((string)($cert['status'] ?? '')));
    $nftMinted = (int)($cert['nft_minted'] ?? 0);
    if ($status === 'minted' || $nftMinted === 1 || trim((string)($cert['nft_item_address'] ?? '')) !== '') {
        throw new RuntimeException('CERT_ALREADY_MINTED');
    }
}

function mi_assert_ton_wallet_present(array $cert, array $payment): string
{
    $wallet = trim((string)($cert['ton_wallet'] ?? ''));
    if ($wallet === '') {
        $wallet = trim((string)($payment['ton_wallet'] ?? ''));
    }
    if ($wallet === '') {
        throw new RuntimeException('TON_WALLET_REQUIRED');
    }
    return $wallet;
}

function mi_detect_rwa_code(array $row): string
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

function mi_detect_family(array $row): string
{
    $family = strtoupper(trim((string)($row['family'] ?? '')));
    if ($family !== '') {
        return $family;
    }

    $rwaCode = mi_detect_rwa_code($row);
    if (in_array($rwaCode, ['RLIFE-EMA', 'RTRIP-EMA', 'RPROP-EMA', 'RHRD-EMA'], true)) {
        return 'SECONDARY';
    }

    return 'GENESIS';
}

function mi_user_bucket(array $row, array $meta): string
{
    $userId = trim((string)($row['owner_user_id'] ?? ''));

    $candidates = [
        trim((string)($meta['user_bucket'] ?? '')),
        trim((string)($meta['vault']['user_bucket'] ?? '')),
        trim((string)($meta['user']['bucket'] ?? '')),
    ];

    foreach ($candidates as $v) {
        if ($v !== '') {
            return preg_replace('/[^A-Za-z0-9_-]/', '', $v) ?: 'U13';
        }
    }

    if ($userId !== '' && ctype_digit($userId)) {
        return 'U' . $userId;
    }

    return 'U13';
}

function mi_canonical_paths(array $cert): array
{
    $uid = trim((string)$cert['cert_uid']);
    $meta = $cert['meta_json_decoded'] ?? [];
    $rwaCode = mi_detect_rwa_code($cert);
    $family = mi_detect_family($cert);
    $bucket = mi_user_bucket($cert, is_array($meta) ? $meta : []);

    if ($uid === '' || $rwaCode === '' || $family === '') {
        throw new RuntimeException('CERT_PATH_CONTEXT_INVALID');
    }

    if (!preg_match('/^([A-Z0-9]+(?:-[A-Z0-9]+)+)-(\d{8})-([A-Z0-9]{8})$/', strtoupper($uid), $m)) {
        throw new RuntimeException('CERT_UID_FORMAT_INVALID');
    }

    $yyyymmdd = $m[2];
    $year = substr($yyyymmdd, 0, 4);
    $month = substr($yyyymmdd, 4, 2);

    $baseRel = '/rwa/metadata/cert/RWA_CERT/'
        . $family . '/'
        . $rwaCode . '/TON/'
        . $year . '/'
        . $month . '/'
        . $bucket . '/'
        . $uid;

    $baseAbs = mi_abs_path($baseRel);

    return [
        'base_dir' => $baseAbs,
        'image_path' => $baseAbs . '/nft/image.png',
        'meta_path' => $baseAbs . '/meta/metadata.json',
        'verify_path' => $baseAbs . '/verify/verify.json',
        'qr_png_path' => $baseAbs . '/verify/qr.png',
        'debug_path' => $baseAbs . '/verify/debug-composited.png',
        'verify_page_url' => mi_site_url() . '/rwa/cert/verify.php?uid=' . rawurlencode($uid),
        'verify_json_url' => mi_url_from_abs($baseAbs . '/verify/verify.json'),
        'image_url' => mi_url_from_abs($baseAbs . '/nft/image.png'),
        'metadata_url' => mi_url_from_abs($baseAbs . '/meta/metadata.json'),
        'qr_png_url' => mi_url_from_abs($baseAbs . '/verify/qr.png'),
        'debug_url' => mi_url_from_abs($baseAbs . '/verify/debug-composited.png'),
    ];
}

function mi_load_verify_json(array $paths): array
{
    $path = $paths['verify_path'];
    if (!is_file($path)) {
        throw new RuntimeException('VERIFY_JSON_MISSING');
    }

    if (str_contains($path, '/devtest/')) {
        throw new RuntimeException('DEVTEST_VERIFY_JSON_NOT_ALLOWED');
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        throw new RuntimeException('VERIFY_JSON_EMPTY');
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('VERIFY_JSON_INVALID');
        }
        return $decoded;
    } catch (Throwable) {
        throw new RuntimeException('VERIFY_JSON_INVALID');
    }
}

function mi_assert_signed_artifact(array $verify, array $paths): array
{
    if (($verify['image_authority'] ?? '') !== '_image-bundle.php') {
        throw new RuntimeException('VERIFY_IMAGE_AUTHORITY_INVALID');
    }
    if (($verify['compose_engine'] ?? '') !== 'imagemagick') {
        throw new RuntimeException('VERIFY_COMPOSE_ENGINE_INVALID');
    }
    if (($verify['healthy'] ?? false) !== true) {
        throw new RuntimeException('VERIFY_HEALTH_FALSE');
    }
    if (($verify['artifact_ready'] ?? false) !== true) {
        throw new RuntimeException('VERIFY_ARTIFACT_READY_FALSE');
    }
    if (($verify['nft_healthy'] ?? false) !== true) {
        throw new RuntimeException('VERIFY_NFT_HEALTH_FALSE');
    }
    if (($verify['used_fallback_placeholder'] ?? true) !== false) {
        throw new RuntimeException('VERIFY_FALLBACK_FORBIDDEN');
    }

    $templateSha1 = strtolower(trim((string)($verify['template_sha1'] ?? '')));
    $qrSha1 = strtolower(trim((string)($verify['qr_sha1'] ?? '')));
    $finalSha1 = strtolower(trim((string)($verify['final_sha1'] ?? '')));

    $finalSize = (int)($verify['final_size'] ?? 0);

    if ($templateSha1 === '' || $qrSha1 === '' || $finalSha1 === '') {
        $candidatesSha1 = [
            $verify['final_sha1'] ?? null,
            $verify['hashes']['final_sha1'] ?? null,
            $verify['artifact']['final_sha1'] ?? null,
            $verify['final']['sha1'] ?? null,
            $verify['image']['sha1'] ?? null,
        ];

        $candidatesSize = [
            $verify['final_size'] ?? null,
            $verify['sizes']['final_size'] ?? null,
            $verify['artifact']['final_size'] ?? null,
            $verify['final']['size'] ?? null,
            $verify['image']['size'] ?? null,
        ];

        foreach ($candidatesSha1 as $v) {
            if (is_string($v) && $v !== '') {
                $finalSha1 = strtolower(trim($v));
                break;
            }
        }

        foreach ($candidatesSize as $v) {
            if (is_numeric($v) && (int)$v > 0) {
                $finalSize = (int)$v;
                break;
            }
        }

        if ($finalSha1 === '' || $finalSize <= 0) {
            throw new RuntimeException('VERIFY_HASH_FIELDS_MISSING');
        }
    }

    if ($templateSha1 === $finalSha1) {
        throw new RuntimeException('VERIFY_FINAL_EQUALS_TEMPLATE');
    }

    if (($verify['verify_json_path'] ?? '') !== '' && !mi_same_public_path((string)$verify['verify_json_path'], (string)$paths['verify_path'])) {
        throw new RuntimeException('VERIFY_JSON_PATH_MISMATCH');
    }
    if (($verify['metadata_path'] ?? '') !== '' && !mi_same_public_path((string)$verify['metadata_path'], (string)$paths['meta_path'])) {
        throw new RuntimeException('VERIFY_METADATA_PATH_MISMATCH');
    }
    if (($verify['image_path'] ?? '') !== '' && !mi_same_public_path((string)$verify['image_path'], (string)$paths['image_path'])) {
        throw new RuntimeException('VERIFY_IMAGE_PATH_MISMATCH');
    }

    if (($verify['verify_page_url'] ?? '') !== '' && (string)$verify['verify_page_url'] !== (string)$paths['verify_page_url']) {
        throw new RuntimeException('VERIFY_PAGE_URL_MISMATCH');
    }
    if (($verify['verify_json_url'] ?? '') !== '' && (string)$verify['verify_json_url'] !== (string)$paths['verify_json_url']) {
        throw new RuntimeException('VERIFY_JSON_URL_MISMATCH');
    }
    if (($verify['metadata_url'] ?? '') !== '' && (string)$verify['metadata_url'] !== (string)$paths['metadata_url']) {
        throw new RuntimeException('VERIFY_METADATA_URL_MISMATCH');
    }
    if (($verify['image_url'] ?? '') !== '' && (string)$verify['image_url'] !== (string)$paths['image_url']) {
        throw new RuntimeException('VERIFY_IMAGE_URL_MISMATCH');
    }

    return [
        'template_sha1' => $templateSha1,
        'qr_sha1' => $qrSha1,
        'final_sha1' => $finalSha1,
    ];
}

function mi_assert_artifact_files(array $paths, array $hashes): array
{
    foreach (['verify_path', 'meta_path', 'image_path'] as $k) {
        if (!is_file($paths[$k])) {
            throw new RuntimeException(strtoupper($k) . '_MISSING');
        }
        if (str_contains($paths[$k], '/devtest/')) {
            throw new RuntimeException('DEVTEST_ARTIFACT_NOT_ALLOWED');
        }
    }

    if (($paths['qr_png_path'] ?? '') !== '' && str_contains((string)$paths['qr_png_path'], '/devtest/')) {
        throw new RuntimeException('DEVTEST_ARTIFACT_NOT_ALLOWED');
    }

    $imageInfo = mi_png_info($paths['image_path']);
    $qrInfo = ['ok' => false, 'width' => 0, 'height' => 0, 'mime' => '', 'bytes' => 0];

    if (($paths['qr_png_path'] ?? '') !== '' && is_file($paths['qr_png_path'])) {
        $qrInfo = mi_png_info($paths['qr_png_path']);
        if (!$qrInfo['ok']) {
            throw new RuntimeException('QR_PNG_INVALID');
        }
    }

    if (!$imageInfo['ok']) {
        throw new RuntimeException('FINAL_IMAGE_INVALID');
    }

    $liveFinalSha1 = mi_sha1_file_safe($paths['image_path']);
    if ($liveFinalSha1 === '' || $liveFinalSha1 !== $hashes['final_sha1']) {
        throw new RuntimeException('FINAL_SHA1_MISMATCH');
    }

    $metaRaw = @file_get_contents($paths['meta_path']);
    if (!is_string($metaRaw) || trim($metaRaw) === '') {
        throw new RuntimeException('METADATA_JSON_EMPTY');
    }

    try {
        $metadata = json_decode($metaRaw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($metadata)) {
            throw new RuntimeException('METADATA_JSON_INVALID');
        }
    } catch (Throwable) {
        throw new RuntimeException('METADATA_JSON_INVALID');
    }

    $metadataImage = trim((string)($metadata['image'] ?? ''));
    $metadataExternalUrl = trim((string)($metadata['external_url'] ?? ''));

    if ($metadataImage === '' || !preg_match('~^https://~i', $metadataImage)) {
        throw new RuntimeException('METADATA_IMAGE_URL_INVALID');
    }
    if ($metadataImage !== $paths['image_url']) {
        throw new RuntimeException('METADATA_IMAGE_URL_MISMATCH');
    }
    if ($metadataExternalUrl !== '' && $metadataExternalUrl !== $paths['verify_page_url']) {
        throw new RuntimeException('METADATA_EXTERNAL_URL_MISMATCH');
    }

    return [
        'metadata' => $metadata,
        'metadata_image' => $metadataImage,
        'metadata_external_url' => $metadataExternalUrl,
        'image_bytes' => (int)($imageInfo['bytes'] ?? 0),
        'image_width' => (int)($imageInfo['width'] ?? 0),
        'image_height' => (int)($imageInfo['height'] ?? 0),
        'qr_bytes' => (int)($qrInfo['bytes'] ?? 0),
        'qr_present' => (($paths['qr_png_path'] ?? '') !== '' && is_file($paths['qr_png_path'])),
        'live_final_sha1' => $liveFinalSha1,
    ];
}

function mi_build_metadata_relpath_from_paths(array $paths): string
{
    return ltrim(mi_rel_path($paths['meta_path']), '/');
}

function mi_minted_truth_sql(): string
{
    return "
        (
            COALESCE(nft_minted, 0) = 1
            OR LOWER(COALESCE(status, '')) = 'minted'
            OR COALESCE(nft_item_address, '') <> ''
            OR minted_at IS NOT NULL
        )
    ";
}

function mi_count_owner_minted_by_code(PDO $pdo, int $ownerUserId, string $rwaCode): int
{
    $sql = "
        SELECT COUNT(*) AS c
        FROM poado_rwa_certs
        WHERE owner_user_id = :owner_user_id
          AND rwa_code = :rwa_code
          AND " . mi_minted_truth_sql();

    $st = $pdo->prepare($sql);
    $st->execute([
        ':owner_user_id' => $ownerUserId,
        ':rwa_code' => $rwaCode,
    ]);
    return (int)($st->fetchColumn() ?: 0);
}

function mi_unlock_status(PDO $pdo, array $cert): array
{
    $rwaCode = mi_detect_rwa_code($cert);
    $ownerUserId = (int)($cert['owner_user_id'] ?? 0);

    $greenMinted = $ownerUserId > 0 ? mi_count_owner_minted_by_code($pdo, $ownerUserId, 'RCO2C-EMA') : 0;
    $goldMinted  = $ownerUserId > 0 ? mi_count_owner_minted_by_code($pdo, $ownerUserId, 'RK92-EMA') : 0;

    $blueEligible = ($greenMinted >= 10);
    $blackEligible = ($goldMinted >= 1);

    $requiredRule = 'none';
    $eligible = true;

    if ($rwaCode === 'RH2O-EMA') {
        $requiredRule = 'requires_10_green_minted';
        $eligible = $blueEligible;
    } elseif ($rwaCode === 'RBLACK-EMA') {
        $requiredRule = 'requires_1_gold_minted';
        $eligible = $blackEligible;
    }

    return [
        'owner_user_id' => $ownerUserId,
        'target_rwa_code' => $rwaCode,
        'green_minted' => $greenMinted,
        'gold_minted' => $goldMinted,
        'blue_eligible' => $blueEligible,
        'black_eligible' => $blackEligible,
        'required_rule' => $requiredRule,
        'eligible' => $eligible,
    ];
}

function mi_assert_unlock_rules(PDO $pdo, array $cert): array
{
    $unlock = mi_unlock_status($pdo, $cert);
    $rwaCode = (string)$unlock['target_rwa_code'];

    if ($rwaCode === 'RH2O-EMA' && !$unlock['blue_eligible']) {
        throw new RuntimeException('RH2O_REQUIRES_10_GREEN_MINTED');
    }

    if ($rwaCode === 'RBLACK-EMA' && !$unlock['black_eligible']) {
        throw new RuntimeException('RBLACK_REQUIRES_1_GOLD_MINTED');
    }

    return $unlock;
}

function mi_find_payload_script(): string
{
    $js = $_SERVER['DOCUMENT_ROOT'] . '/rwa/ton-v10/scripts/buildMintPayload.v10.js';
    if (is_file($js)) {
        return $js;
    }

    $override = trim(mi_env('RWA_CERT_BUILD_MINT_PAYLOAD_SCRIPT', ''));
    if ($override !== '' && is_file($override)) {
        return $override;
    }

    $ts = $_SERVER['DOCUMENT_ROOT'] . '/rwa/ton-v10/scripts/buildMintPayload.v10.ts';
    if (is_file($ts)) {
        return $ts;
    }

    throw new RuntimeException('BUILD_MINT_PAYLOAD_V10_SCRIPT_NOT_FOUND');
}

function mi_is_js_file(string $path): bool
{
    $p = strtolower($path);
    return str_ends_with($p, '.js') || str_ends_with($p, '.mjs') || str_ends_with($p, '.cjs');
}

function mi_command_exists(string $command): bool
{
    $cmd = 'command -v ' . escapeshellarg($command) . ' >/dev/null 2>&1';
    $rc = 1;
    @exec($cmd, $out, $rc);
    return $rc === 0;
}

function mi_detect_runner(string $scriptPath): array
{
    if (mi_is_js_file($scriptPath)) {
        return ['/usr/bin/node'];
    }

    $override = trim(mi_env('RWA_CERT_TS_RUNNER', ''));
    if ($override !== '') {
        return preg_split('/\s+/', $override) ?: [$override];
    }

    $tsxLocal = $_SERVER['DOCUMENT_ROOT'] . '/rwa/ton-v10/node_modules/.bin/tsx';
    if (is_file($tsxLocal) && is_executable($tsxLocal)) {
        return [$tsxLocal];
    }

    if (mi_command_exists('tsx')) {
        return ['tsx'];
    }

    if (mi_command_exists('ts-node')) {
        return ['ts-node'];
    }

    throw new RuntimeException('NO_SAFE_TS_RUNNER_AVAILABLE');
}

function mi_run_process(array $cmd, string $cwd, array $env = [], int $timeout = 20): array
{
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = @proc_open($cmd, $descriptor, $pipes, $cwd, array_replace($_ENV, $env));
    if (!is_resource($proc)) {
        throw new RuntimeException('PROC_OPEN_FAILED');
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $start = microtime(true);

    try {
        while (true) {
            $stdout .= (string)stream_get_contents($pipes[1]);
            $stderr .= (string)stream_get_contents($pipes[2]);

            $status = proc_get_status($proc);
            if (!is_array($status) || empty($status['running'])) {
                break;
            }

            if ((microtime(true) - $start) > $timeout) {
                @proc_terminate($proc, 9);
                throw new RuntimeException('PROCESS_TIMEOUT');
            }

            usleep(100000);
        }

        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
    } finally {
        fclose($pipes[1]);
        fclose($pipes[2]);
    }

    $exit = proc_close($proc);

    return [
        'exit_code' => (int)$exit,
        'stdout' => trim($stdout),
        'stderr' => trim($stderr),
    ];
}

function mi_build_real_payload_v10(string $certUid, string $metadataRelPath, string $queryId): array
{
    $scriptPath = mi_find_payload_script();
    $runner = mi_detect_runner($scriptPath);
    $scriptDir = dirname($scriptPath);
    $projectRoot = dirname($scriptDir);

    $rpcUrl = trim(mi_env('TON_RPC_URL', mi_env('TONCENTER_RPC_URL', 'https://mainnet-v4.tonhubapi.com')));
    $collectionAddress = trim(mi_env('V10_COLLECTION_ADDRESS', 'EQBHMH4g3xy-uOJpPN0XGcDhMifdKio_kYWk3uywaXz2aUrY'));
    $amountTon = trim(mi_env('V10_PUBLIC_MINT_ATTACH_TON', '0.5'));

    $cmd = array_merge($runner, [
        $scriptPath,
        '--cert', $certUid,
        '--metadata-path', $metadataRelPath,
        '--query-id', $queryId,
        '--amount-ton', $amountTon,
        '--collection-address', $collectionAddress,
        '--rpc-url', $rpcUrl,
    ]);

    $result = mi_run_process($cmd, $projectRoot, [
        'V10_COLLECTION_ADDRESS' => $collectionAddress,
        'V10_PUBLIC_MINT_ATTACH_TON' => $amountTon,
        'TON_RPC_URL' => $rpcUrl,
        'TONCENTER_RPC_URL' => $rpcUrl,
    ], 20);

    if ((int)$result['exit_code'] !== 0) {
        throw new RuntimeException('BUILD_MINT_PAYLOAD_V10_FAILED: ' . ($result['stderr'] !== '' ? $result['stderr'] : 'UNKNOWN'));
    }

    $json = mi_json_decode($result['stdout']);
    if (!$json || empty($json['ok'])) {
        throw new RuntimeException('BUILD_MINT_PAYLOAD_V10_INVALID_JSON');
    }

    foreach (['recipient','amount_ton','amount_nano','payload_b64','valid_until','item_index','query_id','collection_address','metadata_path'] as $k) {
        if (!array_key_exists($k, $json) || trim((string)$json[$k]) === '') {
            throw new RuntimeException('BUILD_MINT_PAYLOAD_V10_MISSING_' . strtoupper($k));
        }
    }

    return $json;
}

function mi_getgems_helper_available(): bool
{
    return function_exists('ggm_build_getgems_metadata');
}

function mi_getgems_prepare_metadata(array $cert, array $artifact): array
{
    $certUid = trim((string)($cert['cert_uid'] ?? ''));
    if ($certUid === '') {
        throw new RuntimeException('CERT_UID_REQUIRED');
    }

    $paths = $artifact['paths'] ?? [];
    $metadataAbs = trim((string)($paths['meta_path'] ?? ''));
    $metadataUrl = trim((string)($paths['metadata_url'] ?? ''));
    $verifyUrl = trim((string)($paths['verify_page_url'] ?? ''));
    $imageUrl = trim((string)($paths['image_url'] ?? ''));
    $metadataPath = mi_build_metadata_relpath_from_paths($paths);

    if ($metadataAbs === '' || !is_file($metadataAbs)) {
        throw new RuntimeException('META_PATH_MISSING');
    }
    if ($metadataPath === '' || $metadataUrl === '') {
        throw new RuntimeException('CANONICAL_METADATA_CONTEXT_INVALID');
    }
    if (str_contains($metadataAbs, '/devtest/') || str_contains($metadataPath, '/devtest/') || str_contains($metadataUrl, '/devtest/')) {
        throw new RuntimeException('DEVTEST_METADATA_NOT_ALLOWED');
    }

    $helperAvailable = mi_getgems_helper_available();
    $previewMetadata = null;
    $warning = 'Canonical artifact metadata reused; duplicate GetGems root write disabled.';

    if ($helperAvailable) {
        try {
            $previewMetadata = ggm_build_getgems_metadata($cert, [
                'image_url' => $imageUrl,
                'external_url' => $verifyUrl,
                'description' => 'AdoptGold RWA Certificate NFT. Canonical artifact metadata reused for minting.',
            ]);
        } catch (Throwable $e) {
            $warning = 'Canonical artifact metadata reused; helper preview build skipped: ' . $e->getMessage();
            $previewMetadata = null;
        }
    }

    return [
        'ok' => true,
        'helper_used' => false,
        'helper_available' => $helperAvailable,
        'mode' => 'canonical_metadata_only',
        'item_content_mode' => 'canonical_metadata_only',
        'item_content_suffix' => basename($metadataPath),
        'full_metadata_url' => $metadataUrl,
        'metadata_url' => $metadataUrl,
        'metadata_path' => $metadataPath,
        'written' => null,
        'existing_file' => [
            'filename' => basename($metadataAbs),
            'path' => $metadataAbs,
            'bytes' => @filesize($metadataAbs) ?: 0,
        ],
        'preview_metadata' => $previewMetadata,
        'warning' => $warning,
    ];
}

function mi_collect_artifact_truth(array $cert): array
{
    $paths = mi_canonical_paths($cert);
    $verify = mi_load_verify_json($paths);
    $hashes = mi_assert_signed_artifact($verify, $paths);
    $files = mi_assert_artifact_files($paths, $hashes);

    return [
        'ok' => true,
        'paths' => $paths,
        'verify' => $verify,
        'hashes' => $hashes,
        'files' => $files,
    ];
}

function mi_merge_history(array $meta, array $entry): array
{
    $history = $meta['history'] ?? [];
    if (!is_array($history)) {
        $history = [];
    }
    $history[] = $entry;
    if (count($history) > 50) {
        $history = array_slice($history, -50);
    }
    $meta['history'] = array_values($history);
    return $meta;
}

function mi_rawurlencode_preserve_colon(string $value): string
{
    return str_replace('%3A', ':', rawurlencode($value));
}

function mi_build_ton_transfer_deeplink(string $recipient, string $amountNano, string $payloadB64): string
{
    $recipient = trim($recipient);
    $amountNano = trim($amountNano);
    $payloadB64 = trim($payloadB64);

    if ($recipient === '' || $amountNano === '' || $payloadB64 === '') {
        return '';
    }

    return 'ton://transfer/'
        . mi_rawurlencode_preserve_colon($recipient)
        . '?amount=' . rawurlencode($amountNano)
        . '&bin=' . rawurlencode($payloadB64);
}

function mi_build_v9_handoff(array $cert, array $payment, array $artifact, array $payload, string $tonWallet, string $queryId, array $unlock, array $getgemsMeta): array
{
    $certUid = trim((string)($cert['cert_uid'] ?? ''));
    $recipient = trim((string)($payload['recipient'] ?? ''));
    $amountTon = trim((string)($payload['amount_ton'] ?? ''));
    $amountNano = trim((string)($payload['amount_nano'] ?? ''));
    $payloadB64 = trim((string)($payload['payload_b64'] ?? ''));
    $validUntil = (int)($payload['valid_until'] ?? 0);

    $walletLink = mi_build_ton_transfer_deeplink($recipient, $amountNano, $payloadB64);
    $verifyStatusUrl = mi_site_url() . '/rwa/cert/api/verify-status.php?cert_uid=' . rawurlencode($certUid);
    $mintVerifyUrl = mi_site_url() . '/rwa/cert/api/mint-verify.php?cert_uid=' . rawurlencode($certUid);
    $verifyPageUrl = (string)($artifact['paths']['verify_page_url'] ?? '');
    $metadataUrl = !empty($getgemsMeta['metadata_url']) ? (string)$getgemsMeta['metadata_url'] : (string)($artifact['paths']['metadata_url'] ?? '');
    $imageUrl = (string)($artifact['paths']['image_url'] ?? '');

    return [
        'flow_state' => 'minting',
        'queue_bucket_hint' => 'minting_process',
        'handoff' => [
            'step' => 'wallet_sign',
            'next_step' => 'mint_verify_poll',
            'wallet_open_mode' => 'deeplink',
            'wallet_link' => $walletLink,
            'deeplink' => $walletLink,
            'open_wallet' => $walletLink !== '',
            'ton_wallet' => $tonWallet,
            'recipient' => $recipient,
            'amount_ton' => $amountTon,
            'amount_nano' => $amountNano,
            'payload_b64' => $payloadB64,
            'valid_until' => $validUntil,
        ],
        'ui' => [
            'selected_cert_uid' => $certUid,
            'selected_queue_bucket' => 'minting_process',
            'selected_rwa_code' => (string)($cert['rwa_code'] ?? ''),
            'payment_status' => (string)($payment['status'] ?? ''),
            'payment_verified' => (int)($payment['verified'] ?? 0),
            'mint_status' => 'minting',
            'next_banner' => 'Wallet sign requested. Waiting for on-chain mint confirmation.',
            'success_banner' => 'Issued successfully. NFT mint confirmed.',
        ],
        'verify_poll' => [
            'recommended_source' => 'mint-verify',
            'mint_verify_url' => $mintVerifyUrl,
            'verify_status_url' => $verifyStatusUrl,
            'poll_interval_ms' => 5000,
            'timeout_ms' => 480000,
            'stop_on' => [
                'nft_minted = 1',
                'status = issued',
                'queue_bucket = issued',
            ],
        ],
        'preview' => [
            'verify_url' => $verifyPageUrl,
            'metadata_url' => $metadataUrl,
            'image_url' => $imageUrl,
        ],
        'events' => [
            'dispatch' => [
                'cert:mint-init',
                'cert:wallet-sign',
            ],
            'cert_uid' => $certUid,
            'queue_bucket' => 'minting_process',
            'payment_status' => (string)($payment['status'] ?? ''),
            'payment_verified' => (int)($payment['verified'] ?? 0),
            'mint_status' => 'minting',
            'query_id' => $queryId,
            'unlock_required_rule' => (string)($unlock['required_rule'] ?? 'none'),
        ],
    ];
}

try {
    $certUid = mi_req_any(['cert_uid', 'uid', 'cert'], '');
    if ($certUid === '') {
        mi_fail('CERT_UID_REQUIRED', 'mint-init requires cert_uid or uid or cert', 422);
    }

    $pdo = mi_db();

    $cert = mi_fetch_cert($pdo, $certUid);
    $payment = mi_fetch_payment($pdo, $certUid);

    mi_assert_not_minted($cert);
    mi_assert_payment_ready($payment);
    $tonWallet = mi_assert_ton_wallet_present($cert, $payment);
    $unlock = mi_assert_unlock_rules($pdo, $cert);

    $artifact = mi_collect_artifact_truth($cert);

    $metadataPath = mi_build_metadata_relpath_from_paths($artifact['paths']);
    $metadataUrl = (string)($artifact['paths']['metadata_url'] ?? '');
    $verifyUrl = (string)($artifact['paths']['verify_page_url'] ?? '');

    if ($metadataPath === '' || $metadataUrl === '' || str_contains($metadataPath, '/devtest/') || str_contains($metadataUrl, '/devtest/')) {
        throw new RuntimeException('DEVTEST_METADATA_NOT_ALLOWED');
    }

    $getgemsMeta = mi_getgems_prepare_metadata($cert, $artifact);
    $metadataPath = ltrim((string)($getgemsMeta['metadata_path'] ?? $metadataPath), '/');
    $metadataUrl = (string)($getgemsMeta['metadata_url'] ?? $metadataUrl);

    if ($metadataPath === '' || $metadataUrl === '') {
        throw new RuntimeException('CANONICAL_METADATA_CONTEXT_INVALID');
    }

    $queryId = (string)time();
    $payload = mi_build_real_payload_v10($certUid, $metadataPath, $queryId);

    $recipient = trim((string)($payload['recipient'] ?? ''));
    $collection = trim((string)($payload['collection_address'] ?? ''));
    $amountTon = trim((string)($payload['amount_ton'] ?? ''));
    $amountNano = trim((string)($payload['amount_nano'] ?? ''));
    $payloadB64 = trim((string)($payload['payload_b64'] ?? ''));
    $itemIndex = trim((string)($payload['item_index'] ?? ''));
    $validUntil = (int)($payload['valid_until'] ?? 0);
    $bodyOpcode = trim((string)($payload['body_opcode'] ?? '0x504d494e'));
    $preparedAt = gmdate('c');

    $pdo->beginTransaction();

    $cert = mi_select_cert_for_update($pdo, $certUid);
    $payment = mi_fetch_payment($pdo, $certUid);

    mi_assert_not_minted($cert);
    mi_assert_payment_ready($payment);
    $tonWallet = mi_assert_ton_wallet_present($cert, $payment);
    $unlock = mi_assert_unlock_rules($pdo, $cert);

    $artifact = mi_collect_artifact_truth($cert);

    $verify = is_array($artifact['verify'] ?? null) ? $artifact['verify'] : [];
    $hashes = is_array($artifact['hashes'] ?? null) ? $artifact['hashes'] : [];

    $meta = mi_json_decode((string)($cert['meta_json'] ?? ''));

    $walletLink = mi_build_ton_transfer_deeplink($recipient, $amountNano, $payloadB64);
    $handoffV9 = mi_build_v9_handoff($cert, $payment, $artifact, $payload, $tonWallet, $queryId, $unlock, $getgemsMeta);

    $mintPrepared = [
        'prepared_at' => $preparedAt,
        'mint_request' => [
            'mode' => 'v10_public_mint',
            'single_transfer_only' => true,
            'cert_uid' => $certUid,
            'item_index' => $itemIndex,
            'query_id' => $queryId,
            'valid_until' => $validUntil,
            'recipient' => $recipient,
            'collection_address' => $collection,
            'amount_ton' => $amountTon,
            'amount_nano' => $amountNano,
            'metadata_path' => $metadataPath,
            'metadata_url' => $metadataUrl,
            'verify_url' => $verifyUrl,
            'payload_b64' => $payloadB64,
            'body_opcode' => $bodyOpcode,
            'ton_wallet' => $tonWallet,
            'payment_ref' => (string)($payment['payment_ref'] ?? ''),
            'payment_status' => (string)($payment['status'] ?? ''),
            'payment_verified' => (int)($payment['verified'] ?? 0),
            'wallet_link' => $walletLink,
            'deeplink' => $walletLink,
            'signed_artifact' => [
                'image_authority' => (string)($verify['image_authority'] ?? ''),
                'compose_engine' => (string)($verify['compose_engine'] ?? ''),
                'template_sha1' => (string)($hashes['template_sha1'] ?? ''),
                'qr_sha1' => (string)($hashes['qr_sha1'] ?? ''),
                'final_sha1' => (string)($hashes['final_sha1'] ?? ''),
                'verify_json_path' => (string)$artifact['paths']['verify_path'],
                'metadata_path' => (string)$artifact['paths']['meta_path'],
                'image_path' => (string)$artifact['paths']['image_path'],
                'verify_page_url' => (string)$artifact['paths']['verify_page_url'],
                'verify_json_url' => (string)$artifact['paths']['verify_json_url'],
                'metadata_url' => (string)$artifact['paths']['metadata_url'],
                'image_url' => (string)$artifact['paths']['image_url'],
            ],
            'unlock_rules' => $unlock,
            'getgems_metadata' => [
                'enabled' => false,
                'helper_available' => !empty($getgemsMeta['helper_available']),
                'mode' => (string)($getgemsMeta['mode'] ?? 'canonical_metadata_only'),
                'item_content_mode' => (string)($getgemsMeta['item_content_mode'] ?? 'canonical_metadata_only'),
                'item_content_suffix' => (string)($getgemsMeta['item_content_suffix'] ?? ''),
                'full_metadata_url' => (string)($getgemsMeta['full_metadata_url'] ?? $metadataUrl),
                'warning' => (string)($getgemsMeta['warning'] ?? ''),
                'existing_file' => $getgemsMeta['existing_file'] ?? null,
            ],
            'v9_handoff' => $handoffV9,
        ],
    ];

    $meta['mint'] = $mintPrepared;
    $meta['mint_request'] = $mintPrepared['mint_request'];
    $meta['mint_handoff_v9'] = $handoffV9;
    $meta['unlock_rules'] = [
        'checked_at' => $preparedAt,
        'target_rwa_code' => (string)$unlock['target_rwa_code'],
        'required_rule' => (string)$unlock['required_rule'],
        'green_minted' => (int)$unlock['green_minted'],
        'gold_minted' => (int)$unlock['gold_minted'],
        'blue_eligible' => (bool)$unlock['blue_eligible'],
        'black_eligible' => (bool)$unlock['black_eligible'],
        'eligible' => (bool)$unlock['eligible'],
    ];
    $meta['getgems_metadata'] = [
        'prepared_at' => $preparedAt,
        'enabled' => false,
        'helper_available' => !empty($getgemsMeta['helper_available']),
        'mode' => (string)($getgemsMeta['mode'] ?? 'canonical_metadata_only'),
        'item_content_mode' => (string)($getgemsMeta['item_content_mode'] ?? 'canonical_metadata_only'),
        'item_content_suffix' => (string)($getgemsMeta['item_content_suffix'] ?? ''),
        'full_metadata_url' => (string)($getgemsMeta['full_metadata_url'] ?? $metadataUrl),
        'metadata_path' => $metadataPath,
        'metadata_url' => $metadataUrl,
        'warning' => (string)($getgemsMeta['warning'] ?? ''),
        'written' => null,
        'existing_file' => $getgemsMeta['existing_file'] ?? null,
    ];
    $meta['nft_health'] = [
        'checked_at' => $preparedAt,
        'ok' => true,
        'trust_mode' => 'signed_verify_json_only',
        'image_authority' => (string)($verify['image_authority'] ?? ''),
        'compose_engine' => (string)($verify['compose_engine'] ?? ''),
        'verify_json_path' => (string)$artifact['paths']['verify_path'],
        'verify_json_url' => (string)$artifact['paths']['verify_json_url'],
        'verify_healthy' => true,
        'used_fallback_placeholder' => false,
        'template_sha1' => (string)($hashes['template_sha1'] ?? ''),
        'qr_sha1' => (string)($hashes['qr_sha1'] ?? ''),
        'final_sha1' => (string)($hashes['final_sha1'] ?? ''),
        'image_abs_ok' => true,
        'fallback_vault_only' => false,
        'devtest_leak' => false,
    ];
    $meta['payment_truth'] = [
        'checked_at' => $preparedAt,
        'source' => 'poado_rwa_cert_payments',
        'payment_ref' => (string)($payment['payment_ref'] ?? ''),
        'status' => (string)($payment['status'] ?? ''),
        'verified' => (int)($payment['verified'] ?? 0),
        'tx_hash' => (string)($payment['tx_hash'] ?? ''),
    ];
    $meta = mi_merge_history($meta, [
        'ts' => $preparedAt,
        'event' => 'mint_prepared',
        'mode' => 'v10_public_mint',
        'cert_uid' => $certUid,
        'item_index' => $itemIndex,
        'query_id' => $queryId,
        'payment_ref' => (string)($payment['payment_ref'] ?? ''),
        'artifact_trust_mode' => 'signed_verify_json_only',
        'image_authority' => (string)($verify['image_authority'] ?? ''),
        'compose_engine' => (string)($verify['compose_engine'] ?? ''),
        'final_sha1' => (string)($hashes['final_sha1'] ?? ''),
        'unlock_required_rule' => (string)($unlock['required_rule'] ?? 'none'),
        'green_minted' => (int)($unlock['green_minted'] ?? 0),
        'gold_minted' => (int)($unlock['gold_minted'] ?? 0),
        'getgems_item_content_mode' => (string)($getgemsMeta['item_content_mode'] ?? 'canonical_metadata_only'),
        'getgems_item_content_suffix' => (string)($getgemsMeta['item_content_suffix'] ?? ''),
        'wallet_link' => $walletLink,
        'queue_bucket_hint' => 'minting_process',
        'flow_state' => 'minting',
    ]);

    $up = $pdo->prepare("
        UPDATE poado_rwa_certs
        SET ton_wallet = CASE WHEN COALESCE(ton_wallet, '') = '' THEN :ton_wallet ELSE ton_wallet END,
            meta_json = :meta
        WHERE cert_uid = :uid
        LIMIT 1
    ");
    $up->execute([
        ':ton_wallet' => $tonWallet,
        ':meta' => mi_json_encode($meta),
        ':uid' => $certUid,
    ]);

    $pdo->commit();

    mi_out([
        'ok' => true,
        'version' => MI_VERSION,
        'cert_uid' => $certUid,
        'status' => (string)($cert['status'] ?? 'issued'),
        'mint_ready' => true,

        'recipient' => $recipient,
        'collection_address' => $collection,
        'amount_ton' => $amountTon,
        'amount_nano' => $amountNano,
        'payload_b64' => $payloadB64,

        'item_index' => $itemIndex,
        'query_id' => $queryId,
        'valid_until' => $validUntil,

        'metadata_path' => $metadataPath,
        'metadata_url' => $metadataUrl,
        'verify_url' => $verifyUrl,

        'wallet_link' => $walletLink,
        'deeplink' => $walletLink,

        'artifact_health' => [
            'ok' => true,
            'trust_mode' => 'signed_verify_json_only',
            'image_authority' => (string)($verify['image_authority'] ?? ''),
            'compose_engine' => (string)($verify['compose_engine'] ?? ''),
            'verify_json_exists' => true,
            'verify_healthy' => true,
            'used_fallback_placeholder' => false,
            'template_sha1' => (string)($hashes['template_sha1'] ?? ''),
            'qr_sha1' => (string)($hashes['qr_sha1'] ?? ''),
            'final_sha1' => (string)($hashes['final_sha1'] ?? ''),
            'verify_json_path' => (string)$artifact['paths']['verify_path'],
            'metadata_path' => (string)$artifact['paths']['meta_path'],
            'image_path' => (string)$artifact['paths']['image_path'],
            'metadata_image' => (string)$artifact['paths']['image_url'],
            'image_exists' => true,
            'image_abs_ok' => true,
            'fallback_vault_only' => false,
            'devtest_leak' => false,
        ],

        'unlock_rules' => [
            'target_rwa_code' => (string)$unlock['target_rwa_code'],
            'required_rule' => (string)$unlock['required_rule'],
            'green_minted' => (int)$unlock['green_minted'],
            'gold_minted' => (int)$unlock['gold_minted'],
            'blue_eligible' => (bool)$unlock['blue_eligible'],
            'black_eligible' => (bool)$unlock['black_eligible'],
            'eligible' => (bool)$unlock['eligible'],
        ],

        'payment' => [
            'payment_ref' => (string)($payment['payment_ref'] ?? ''),
            'status' => (string)($payment['status'] ?? ''),
            'verified' => (int)($payment['verified'] ?? 0),
            'token_symbol' => (string)($payment['token_symbol'] ?? ''),
            'amount' => (string)($payment['amount'] ?? ''),
            'amount_units' => (string)($payment['amount_units'] ?? ''),
            'tx_hash' => (string)($payment['tx_hash'] ?? ''),
            'paid_at' => (string)($payment['paid_at'] ?? ''),
        ],

        'ton_wallet' => $tonWallet,

        'getgems_metadata' => [
            'enabled' => false,
            'helper_available' => !empty($getgemsMeta['helper_available']),
            'mode' => (string)($getgemsMeta['mode'] ?? 'canonical_metadata_only'),
            'item_content_mode' => (string)($getgemsMeta['item_content_mode'] ?? 'canonical_metadata_only'),
            'item_content_suffix' => (string)($getgemsMeta['item_content_suffix'] ?? ''),
            'full_metadata_url' => (string)($getgemsMeta['full_metadata_url'] ?? $metadataUrl),
            'written' => null,
            'existing_file' => $getgemsMeta['existing_file'] ?? null,
            'warning' => (string)($getgemsMeta['warning'] ?? ''),
        ],

        'single_transfer_only' => true,
        'payload_source' => mi_is_js_file(mi_find_payload_script()) ? 'buildMintPayload.v10.js' : 'buildMintPayload.v10.ts',
        'verification_mode' => 'v10_public_mint',
        'body_opcode' => $bodyOpcode !== '' ? $bodyOpcode : '0x504d494e',

        'flow_state' => 'minting',
        'queue_bucket_hint' => 'minting_process',
        'mint_status' => 'minting',

        'handoff' => $handoffV9['handoff'],
        'ui' => $handoffV9['ui'],
        'verify_poll' => $handoffV9['verify_poll'],
        'events' => $handoffV9['events'],
        'preview' => $handoffV9['preview'],

        'tonconnect' => [
            'validUntil' => $validUntil,
            'messages' => [[
                'address' => $recipient,
                'amount' => $amountNano,
                'payload' => $payloadB64,
            ]],
        ],
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $msg = $e->getMessage();
    $status = 500;

    if (in_array($msg, [
        'CERT_NOT_FOUND',
        'PAYMENT_ROW_NOT_FOUND',
    ], true)) {
        $status = 404;
    } elseif ($msg === 'CERT_UID_REQUIRED') {
        $status = 422;
    } elseif (
        in_array($msg, [
            'PAYMENT_NOT_CONFIRMED_VERIFIED',
            'CERT_ALREADY_MINTED',
            'TON_WALLET_REQUIRED',
            'VERIFY_JSON_MISSING',
            'VERIFY_JSON_EMPTY',
            'VERIFY_JSON_INVALID',
            'VERIFY_IMAGE_AUTHORITY_INVALID',
            'VERIFY_COMPOSE_ENGINE_INVALID',
            'VERIFY_HEALTH_FALSE',
            'VERIFY_ARTIFACT_READY_FALSE',
            'VERIFY_NFT_HEALTH_FALSE',
            'VERIFY_FALLBACK_FORBIDDEN',
            'VERIFY_HASH_FIELDS_MISSING',
            'VERIFY_FINAL_EQUALS_TEMPLATE',
            'VERIFY_JSON_PATH_MISMATCH',
            'VERIFY_METADATA_PATH_MISMATCH',
            'VERIFY_IMAGE_PATH_MISMATCH',
            'VERIFY_PAGE_URL_MISMATCH',
            'VERIFY_JSON_URL_MISMATCH',
            'VERIFY_METADATA_URL_MISMATCH',
            'VERIFY_IMAGE_URL_MISMATCH',
            'VERIFY_PATH_MISSING',
            'META_PATH_MISSING',
            'IMAGE_PATH_MISSING',
            'QR_PNG_PATH_MISSING',
            'DEVTEST_VERIFY_JSON_NOT_ALLOWED',
            'DEVTEST_ARTIFACT_NOT_ALLOWED',
            'FINAL_IMAGE_INVALID',
            'QR_PNG_INVALID',
            'FINAL_SHA1_MISMATCH',
            'METADATA_JSON_EMPTY',
            'METADATA_JSON_INVALID',
            'METADATA_IMAGE_URL_INVALID',
            'METADATA_IMAGE_URL_MISMATCH',
            'METADATA_EXTERNAL_URL_MISMATCH',
            'DEVTEST_METADATA_NOT_ALLOWED',
            'CANONICAL_METADATA_CONTEXT_INVALID',
            'NO_SAFE_TS_RUNNER_AVAILABLE',
            'PROCESS_TIMEOUT',
            'RH2O_REQUIRES_10_GREEN_MINTED',
            'RBLACK_REQUIRES_1_GOLD_MINTED',
        ], true)
        || str_starts_with($msg, 'BUILD_MINT_PAYLOAD_V10_FAILED')
    ) {
        $status = 409;
    }

    mi_fail('MINT_INIT_FAILED', $msg, $status);
}
