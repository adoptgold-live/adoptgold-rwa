<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/verify.php
 *
 * RWA Cert Verify Lounge
 * Version: v4.8.0-20260330-verify-single-qr-preview-image-only
 *
 * Changelog:
 * - keep premium banking verify lounge layout
 * - keep EN / 中文 language switcher
 * - keep wallet fallback ton_wallet -> wallet_address -> wallet
 * - keep cert-local /rwa/cert/api/_qr-local.php dependency
 * - keep verify.json as the sole NFT health truth
 * - remove QR overlay generator from NFT preview panel
 * - NFT preview panel now shows preview image.png only
 * - keep previous PDF viewer / audit / actions / data signals
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/db.php';
require_once __DIR__ . '/api/_qr-local.php';
require_once __DIR__ . '/api/_pdf-template-map.php';
require_once __DIR__ . '/api/_qr-map-resolver.php';

function v_e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function v_fmt_dt($value): string
{
    $value = trim((string)$value);
    if ($value === '' || $value === '0000-00-00 00:00:00') {
        return '—';
    }
    $ts = strtotime($value);
    if (!$ts) {
        return $value;
    }
    return gmdate('Y-m-d H:i:s', $ts) . ' UTC';
}

function v_mask_wallet(?string $wallet): string
{
    $wallet = trim((string)$wallet);
    if ($wallet === '') {
        return '—';
    }
    if (strlen($wallet) <= 26) {
        return $wallet;
    }
    return substr($wallet, 0, 16) . '…' . substr($wallet, -12);
}

function v_resolve_owner_wallet(array $row): array
{
    $sources = [
        'ton_wallet'     => trim((string)($row['ton_wallet'] ?? '')),
        'wallet_address' => trim((string)($row['wallet_address'] ?? '')),
        'wallet'         => trim((string)($row['wallet'] ?? '')),
    ];

    foreach ($sources as $key => $value) {
        if ($value !== '') {
            return [$value, $key];
        }
    }

    return ['', 'none'];
}

function v_compute_fingerprint(array $row): string
{
    [$ownerWallet] = v_resolve_owner_wallet($row);

    return hash(
        'sha256',
        (string)($row['cert_uid'] ?? '') . '|' .
        $ownerWallet . '|' .
        (string)($row['nft_item_address'] ?? '') . '|' .
        (string)($row['rwa_code'] ?? $row['rwa_type'] ?? '')
    );
}

function v_abs_url(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    if (preg_match('~^https?://~i', $path)) {
        return $path;
    }
    if (str_starts_with($path, '//')) {
        return 'https:' . $path;
    }
    return 'https://adoptgold.app' . (str_starts_with($path, '/') ? $path : '/' . $path);
}

function v_fs_path_from_public(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    if (str_starts_with($path, '/var/www/')) {
        return $path;
    }
    if (str_starts_with($path, '/')) {
        return rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . $path;
    }
    return '';
}

function v_is_public_image_candidate(string $path): bool
{
    $path = trim($path);
    if ($path === '') {
        return false;
    }
    if (str_starts_with($path, 'data:image/')) {
        return true;
    }
    if (preg_match('~^https?://~i', $path)) {
        return true;
    }
    if (str_starts_with($path, '//')) {
        return true;
    }
    if (str_starts_with($path, '/')) {
        return true;
    }
    return false;
}

function v_resolve_image_url(?string $path): string
{
    $path = trim((string)$path);
    if ($path === '') {
        return '';
    }
    if (str_starts_with($path, 'data:image/')) {
        return $path;
    }
    if (preg_match('~^https?://~i', $path)) {
        return $path;
    }
    if (str_starts_with($path, '//')) {
        return 'https:' . $path;
    }
    if (str_starts_with($path, '/')) {
        return v_abs_url($path);
    }
    return '';
}

function v_fallback_nft_template_by_code(string $rwaCode): string
{
    $map = [
        'RCO2C-EMA'  => '/rwa/metadata/nft/rco2c.png',
        'RH2O-EMA'   => '/rwa/metadata/nft/rh2o.png',
        'RBLACK-EMA' => '/rwa/metadata/nft/rblack.png',
        'RK92-EMA'   => '/rwa/metadata/nft/rk92.png',
        'RHRD-EMA'   => '/rwa/metadata/nft/rhrd.png',
        'RLIFE-EMA'  => '/rwa/metadata/nft/rlife.png',
        'RPROP-EMA'  => '/rwa/metadata/nft/rprop.png',
        'RTRIP-EMA'  => '/rwa/metadata/nft/rtrip.png',
    ];

    $rwaCode = strtoupper(trim($rwaCode));
    return $map[$rwaCode] ?? '';
}

function v_truth_label(bool $value): array
{
    return $value ? ['YES', 'ok'] : ['NO', 'bad'];
}

function v_status_tone(string $status): array
{
    $s = strtoupper(trim($status));
    return match ($s) {
        'MINTED', 'ISSUED', 'PAID', 'LISTED' => ['label' => $s, 'class' => 'ok'],
        'MINT_PENDING', 'PAYMENT_PENDING', 'INITIATED' => ['label' => $s, 'class' => 'warn'],
        'REVOKED', 'FAILED', 'CANCELLED' => ['label' => $s, 'class' => 'bad'],
        default => ['label' => ($s !== '' ? $s : 'UNKNOWN'), 'class' => 'neutral'],
    };
}

function v_detect_price_label(array $row): string
{
    $rwaCode = strtoupper(trim((string)($row['rwa_code'] ?? $row['rwa_type'] ?? '')));
    $units   = trim((string)($row['price_units'] ?? ''));
    $amount  = trim((string)($row['price_wems'] ?? ''));
    $payAmt  = trim((string)($row['payment_amount'] ?? ''));
    $payTok  = trim((string)($row['payment_token'] ?? ''));

    if ($amount !== '' && $units !== '') {
        return $amount . ' ' . $units;
    }
    if ($amount !== '') {
        return $amount . ' wEMS';
    }
    if ($payAmt !== '' && $payTok !== '') {
        return $payAmt . ' ' . $payTok;
    }

    $genesisDefaults = [
        'RCO2C-EMA'  => '1000 wEMS',
        'RH2O-EMA'   => '5000 wEMS',
        'RBLACK-EMA' => '10000 wEMS',
        'RK92-EMA'   => '50000 wEMS',
    ];

    if (isset($genesisDefaults[$rwaCode])) {
        return $genesisDefaults[$rwaCode];
    }

    if (in_array($rwaCode, ['RHRD-EMA', 'RLIFE-EMA', 'RPROP-EMA', 'RTRIP-EMA'], true)) {
        return '100 EMA$';
    }

    return '—';
}

function v_is_ton_wallet_format_ok(string $wallet): bool
{
    $wallet = trim($wallet);
    if ($wallet === '') {
        return false;
    }
    return (bool) preg_match('/^(EQ|UQ|0:)[A-Za-z0-9\-_:\+\/=]+$/', $wallet);
}

function v_cert_uid_parts(string $uid): array
{
    $m = [];
    if (!preg_match('/^([A-Z0-9]+(?:-[A-Z0-9]+)+)-(\d{8})-([A-Z0-9]{8})$/', strtoupper($uid), $m)) {
        return ['', '', '', ''];
    }
    return [$m[1], $m[2], $m[3], $m[4]];
}

function v_collect_string_values(mixed $data, array &$out): void
{
    if (is_array($data)) {
        foreach ($data as $value) {
            v_collect_string_values($value, $out);
        }
        return;
    }
    if (is_string($data)) {
        $s = trim($data);
        if ($s !== '') {
            $out[] = $s;
        }
    }
}

function v_try_decode_json_string(?string $json): array
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

function v_find_verify_json_path(array $row): string
{
    $candidates = [];

    foreach ([
        'verify_json_path',
        'verify_path',
        'artifact_verify_path',
        'metadata_path',
        'artifact_path',
        'storage_path',
        'vault_path',
        'image_path',
        'nft_image_path',
        'preview_image_path',
    ] as $key) {
        $value = trim((string)($row[$key] ?? ''));
        if ($value !== '') {
            $candidates[] = $value;
        }
    }

    foreach (['meta_json', 'payment_meta_json', 'nft_meta_json', 'artifact_meta_json'] as $jsonKey) {
        $decoded = v_try_decode_json_string((string)($row[$jsonKey] ?? ''));
        if ($decoded) {
            v_collect_string_values($decoded, $candidates);
        }
    }

    $normalized = [];
    foreach ($candidates as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate === '') {
            continue;
        }

        if (str_ends_with($candidate, '/verify/verify.json')) {
            $normalized[] = $candidate;
            continue;
        }
        if (str_ends_with($candidate, 'verify.json')) {
            $normalized[] = $candidate;
            continue;
        }
        if (str_ends_with($candidate, '/verify/')) {
            $normalized[] = rtrim($candidate, '/') . '/verify.json';
            continue;
        }
        if (preg_match('~/RWA_CERT/.*$~', $candidate)) {
            $normalized[] = rtrim($candidate, '/') . '/verify/verify.json';
            continue;
        }
    }

    foreach ($normalized as $path) {
        $fs = v_fs_path_from_public($path);
        if ($fs !== '' && is_file($fs)) {
            return $fs;
        }
        if (is_file($path)) {
            return $path;
        }
    }

    $uid = trim((string)($row['cert_uid'] ?? ''));
    [$prefix, $yyyy, $mm] = v_cert_uid_parts($uid);
    $ownerUserId = (int)($row['owner_user_id'] ?? 0);

    if ($uid !== '' && $prefix !== '' && $yyyy !== '' && $mm !== '') {
        $globBase = '/var/www/html/public/rwa/metadata/cert/RWA_CERT/*/' .
            $prefix . '/TON/' . $yyyy . '/' . $mm . '/U' . max(1, $ownerUserId) . '/' . $uid . '/verify/verify.json';
        $hits = glob($globBase) ?: [];
        foreach ($hits as $hit) {
            if (is_file($hit)) {
                return $hit;
            }
        }

        $globWide = '/var/www/html/public/rwa/metadata/cert/RWA_CERT/*/' .
            $prefix . '/TON/' . $yyyy . '/' . $mm . '/U*/' . $uid . '/verify/verify.json';
        $hits = glob($globWide) ?: [];
        foreach ($hits as $hit) {
            if (is_file($hit)) {
                return $hit;
            }
        }
    }

    return '';
}

function v_load_verify_json(array $row): array
{
    $path = v_find_verify_json_path($row);
    if ($path === '' || !is_file($path)) {
        return ['_path' => '', '_exists' => false];
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return ['_path' => $path, '_exists' => true, '_decode_ok' => false];
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            return ['_path' => $path, '_exists' => true, '_decode_ok' => false];
        }
        $decoded['_path'] = $path;
        $decoded['_exists'] = true;
        $decoded['_decode_ok'] = true;
        return $decoded;
    } catch (Throwable) {
        return ['_path' => $path, '_exists' => true, '_decode_ok' => false];
    }
}

function v_verify_truth(array $verifyJson): bool
{
    return (($verifyJson['ok'] ?? false) === true)
        && (($verifyJson['used_fallback_placeholder'] ?? true) === false);
}

function v_verify_layout(array $verifyJson, string $rwaCode): array
{
    $layout = $verifyJson['layout'] ?? null;
    if (is_array($layout) && isset($layout['x'], $layout['y'], $layout['size'])) {
        return [
            'x' => (int)$layout['x'],
            'y' => (int)$layout['y'],
            'size' => (int)$layout['size'],
            'source' => 'verify.json',
        ];
    }

    if (function_exists('cert_v2_resolve_qr_layout')) {
        try {
            $resolved = cert_v2_resolve_qr_layout($rwaCode);
            if (is_array($resolved) && isset($resolved['x'], $resolved['y'], $resolved['size'])) {
                return [
                    'x' => (int)$resolved['x'],
                    'y' => (int)$resolved['y'],
                    'size' => (int)$resolved['size'],
                    'source' => 'resolver',
                ];
            }
        } catch (Throwable) {
        }
    }

    return [
        'x' => 422,
        'y' => 392,
        'size' => 200,
        'source' => 'fallback',
    ];
}

function v_verify_image_url(array $verifyJson): string
{
    $candidates = [
        (string)($verifyJson['image_url'] ?? ''),
        (string)($verifyJson['preview_image_url'] ?? ''),
        (string)($verifyJson['nft_image_url'] ?? ''),
        (string)($verifyJson['image_path'] ?? ''),
        (string)($verifyJson['preview_image_path'] ?? ''),
        (string)($verifyJson['nft_image_path'] ?? ''),
    ];

    foreach ($candidates as $candidate) {
        if (!v_is_public_image_candidate($candidate)) {
            continue;
        }
        $resolved = v_resolve_image_url($candidate);
        if ($resolved !== '') {
            return $resolved;
        }
    }

    return '';
}

$uid = trim((string)($_GET['uid'] ?? ''));
if ($uid === '') {
    http_response_code(400);
    echo 'Missing UID';
    exit;
}

$pdo = rwa_db();

$stmt = $pdo->prepare("
    SELECT *
    FROM poado_rwa_certs
    WHERE cert_uid = :uid
    LIMIT 1
");
$stmt->execute([':uid' => $uid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    echo 'Certificate not found';
    exit;
}

if (empty($row['rwa_code']) && !empty($row['rwa_type'])) {
    $row['rwa_code'] = (string)$row['rwa_type'];
}

[$wallet, $walletSource] = v_resolve_owner_wallet($row);

if (trim((string)($row['fingerprint_hash'] ?? '')) === '') {
    $row['fingerprint_hash'] = v_compute_fingerprint($row);
    try {
        $up = $pdo->prepare("
            UPDATE poado_rwa_certs
            SET fingerprint_hash = :fp, updated_at = UTC_TIMESTAMP()
            WHERE id = :id
            LIMIT 1
        ");
        $up->execute([
            ':fp' => $row['fingerprint_hash'],
            ':id' => $row['id'],
        ]);
    } catch (Throwable) {
    }
}

$theme = poado_cert_pdf_theme($row);
$theme['accent_line'] = (string)($theme['accent_line'] ?? '#d4af37');
$theme['title'] = (string)($theme['title'] ?? 'RWA Responsibility Certificate');
$theme['subtitle'] = (string)($theme['subtitle'] ?? 'Official verification bridge for the original certificate artifact and linked NFT representation.');
$theme['unit'] = (string)($theme['unit'] ?? '—');
$theme['family_label'] = (string)($theme['family_label'] ?? 'RWA');

$pdfUrl = '/rwa/cert/pdf.php?uid=' . rawurlencode($uid);
$pdfDebugUrl = '/rwa/cert/pdf.php?uid=' . rawurlencode($uid) . '&debug=1';
$verifyUrl = 'https://adoptgold.app/rwa/cert/verify.php?uid=' . rawurlencode($uid);

$qrDataUri = '';
if (function_exists('cert_local_qr_png_data_uri')) {
    $qrDataUri = (string) cert_local_qr_png_data_uri($verifyUrl, 420, 10);
} elseif (function_exists('poado_qr_png_data_uri')) {
    $qrDataUri = (string) poado_qr_png_data_uri($verifyUrl, 420, 10);
} elseif (function_exists('poado_qr_svg_data_uri')) {
    $qrDataUri = (string) poado_qr_svg_data_uri($verifyUrl, 420, 10);
}

$rwaCode = (string)($row['rwa_code'] ?? $row['rwa_type'] ?? '—');
$status = strtoupper(trim((string)($row['status'] ?? 'ISSUED')));
$statusTone = v_status_tone($status);
$fingerprint = trim((string)($row['fingerprint_hash'] ?? ''));
$nftItemAddress = trim((string)($row['nft_item_address'] ?? ''));
$collectionAddress = 'EQDEFDIF0F1JhiH0hCaGHEIzM6tAKeX0SWK5LuOFQEZ8iD6q';
$getgemsUrl = $nftItemAddress !== '' ? 'https://getgems.io/' . rawurlencode($nftItemAddress) : '';
$networkLabel = 'TON (Mainnet)';
$issuedAt = v_fmt_dt($row['issued_at'] ?? '');
$mintedAt = v_fmt_dt($row['minted_at'] ?? '');
$updatedAt = v_fmt_dt($row['updated_at'] ?? '');
$priceLabel = v_detect_price_label($row);

$verifyJson = v_load_verify_json($row);
$verifyJsonPath = (string)($verifyJson['_path'] ?? '');
$verifyJsonExists = (bool)($verifyJson['_exists'] ?? false);
$verifyJsonDecodeOk = (bool)($verifyJson['_decode_ok'] ?? false);
$nftHealthReady = v_verify_truth($verifyJson);
$verifyLayout = v_verify_layout($verifyJson, $rwaCode);

$verifyImage = v_verify_image_url($verifyJson);
$nftImgCandidates = [
    $verifyImage,
    (string)($row['nft_image_path'] ?? ''),
    (string)($row['image_path'] ?? ''),
    (string)($row['preview_image_path'] ?? ''),
    (string)($row['cover_image_path'] ?? ''),
];

$nftImg = '';
$nftImgSource = '—';

foreach ($nftImgCandidates as $candidate) {
    if (!v_is_public_image_candidate($candidate)) {
        continue;
    }
    $resolved = v_resolve_image_url($candidate);
    if ($resolved !== '') {
        $nftImg = $resolved;
        $nftImgSource = ($candidate === $verifyImage && $verifyImage !== '') ? 'verify.json' : 'stored_path';
        break;
    }
}

if ($nftImg === '') {
    $fallbackTemplate = v_fallback_nft_template_by_code($rwaCode);
    if ($fallbackTemplate !== '') {
        $nftImg = v_abs_url($fallbackTemplate);
        $nftImgSource = 'template_fallback';
    }
}

$verifyOk = $verifyUrl !== '';
$pdfOk = $pdfUrl !== '';
$qrOk = $qrDataUri !== '';
$nftPreviewOk = $nftImg !== '';
$nftItemOk = $nftItemAddress !== '';
$fingerprintOk = $fingerprint !== '';
$priceOk = $priceLabel !== '—';

$tonWalletFormatOk = v_is_ton_wallet_format_ok($wallet);
$tonWalletReady = $wallet !== '' && $tonWalletFormatOk;

$getgemsReady = $nftItemAddress !== '';
$nftMintReady = $nftItemAddress !== '' && in_array(strtolower(trim((string)($row['status'] ?? ''))), ['minted', 'listed'], true);

[$verifyOkLabel, $verifyOkClass] = v_truth_label($verifyOk);
[$pdfOkLabel, $pdfOkClass] = v_truth_label($pdfOk);
[$qrOkLabel, $qrOkClass] = v_truth_label($qrOk);
[$nftPreviewOkLabel, $nftPreviewOkClass] = v_truth_label($nftPreviewOk);
[$nftItemOkLabel, $nftItemOkClass] = v_truth_label($nftItemOk);
[$fingerprintOkLabel, $fingerprintOkClass] = v_truth_label($fingerprintOk);
[$priceOkLabel, $priceOkClass] = v_truth_label($priceOk);
[$tonWalletReadyLabel, $tonWalletReadyClass] = v_truth_label($tonWalletReady);
[$getgemsReadyLabel, $getgemsReadyClass] = v_truth_label($getgemsReady);
[$nftMintReadyLabel, $nftMintReadyClass] = v_truth_label($nftMintReady);
[$verifyJsonExistsLabel, $verifyJsonExistsClass] = v_truth_label($verifyJsonExists);
[$verifyJsonDecodeOkLabel, $verifyJsonDecodeOkClass] = v_truth_label($verifyJsonDecodeOk);
[$nftHealthReadyLabel, $nftHealthReadyClass] = v_truth_label($nftHealthReady);

$integrityScore = 0;
foreach ([
    $verifyOk,
    $pdfOk,
    $qrOk,
    $verifyJsonExists,
    $verifyJsonDecodeOk,
    $nftHealthReady,
    $nftPreviewOk,
    $nftItemOk,
    $fingerprintOk,
    $priceOk,
    $tonWalletReady,
    $getgemsReady,
] as $flag) {
    if ($flag) {
        $integrityScore += 1;
    }
}
$integrityMax = 12;
$integrityPercent = (int) round(($integrityScore / $integrityMax) * 100);

$slotX = (int)($verifyLayout['x'] ?? 422);
$slotY = (int)($verifyLayout['y'] ?? 392);
$slotSize = (int)($verifyLayout['size'] ?? 200);
$slotSource = (string)($verifyLayout['source'] ?? 'fallback');

$canvasWidth = 1000;
$canvasHeight = 1000;
$slotLeftPercent = round(($slotX / $canvasWidth) * 100, 4);
$slotTopPercent = round(($slotY / $canvasHeight) * 100, 4);
$slotSizePercent = round(($slotSize / $canvasWidth) * 100, 4);

$jsonIndicators = [
    'verify_ok' => $verifyOk,
    'pdf_ok' => $pdfOk,
    'qr_ok' => $qrOk,
    'verify_json_exists' => $verifyJsonExists,
    'verify_json_decode_ok' => $verifyJsonDecodeOk,
    'nft_health_ready' => $nftHealthReady,
    'verify_json_path' => $verifyJsonPath,
    'nft_preview_ok' => $nftPreviewOk,
    'nft_preview_source' => $nftImgSource,
    'nft_item_ok' => $nftItemOk,
    'fingerprint_ok' => $fingerprintOk,
    'price_ok' => $priceOk,
    'ton_wallet_ready' => $tonWalletReady,
    'ton_wallet_format_ok' => $tonWalletFormatOk,
    'wallet_source' => $walletSource,
    'getgems_ready' => $getgemsReady,
    'nft_mint_ready' => $nftMintReady,
    'integrity_percent' => $integrityPercent,
    'integrity_score' => $integrityScore,
    'integrity_max' => $integrityMax,
    'status' => $statusTone['label'],
    'network' => $networkLabel,
    'rwa_code' => $rwaCode,
    'cert_uid' => (string)$row['cert_uid'],
    'mint_price_label' => $priceLabel,
    'getgems_url' => $getgemsUrl,
    'verify_health_truth' => [
        'ok' => $verifyJson['ok'] ?? null,
        'used_fallback_placeholder' => $verifyJson['used_fallback_placeholder'] ?? null,
    ],
    'locked_qr_slot' => [
        'x' => $slotX,
        'y' => $slotY,
        'size' => $slotSize,
        'source' => $slotSource,
        'left_percent' => $slotLeftPercent,
        'top_percent' => $slotTopPercent,
        'size_percent' => $slotSizePercent,
    ],
];

$i18n = [
    'en' => [
        'page_kicker' => 'PREMIUM VERIFY LOUNGE',
        'network' => 'TON (Mainnet)',
        'integrity_score' => 'INTEGRITY SCORE',
        'live_checks_passed' => 'live checks passed',
        'verify_route' => 'VERIFY ROUTE',
        'official_public_verify_page' => 'Official public verify page',
        'qr_status' => 'QR STATUS',
        'machine_readable_verify_route' => 'Machine-readable verify route',
        'verify_json' => 'VERIFY.JSON',
        'verify_json_truth_file' => 'Locked NFT truth file',
        'verify_json_decode' => 'VERIFY.JSON DECODE',
        'json_parse_health' => 'JSON parse health',
        'nft_health' => 'NFT HEALTH',
        'verify_json_only_truth' => 'verify.json only truth',
        'nft_preview' => 'NFT PREVIEW',
        'nft_item' => 'NFT ITEM',
        'minted_token_address' => 'Minted token address',
        'fingerprint' => 'FINGERPRINT',
        'certificate_audit_hash' => 'Certificate audit hash',
        'mint_price' => 'MINT PRICE',
        'ton_wallet_ready' => 'TON WALLET READY',
        'ton_wallet_ready_caption' => 'TON wallet is present and format-valid',
        'getgems_ready' => 'GETGEMS READY',
        'getgems_ready_caption' => 'GetGems route can be constructed from NFT item',
        'certificate_artifact_viewer' => 'Certificate Artifact Viewer',
        'original_certificate_pdf' => 'Original certificate artifact rendered through the current PDF engine.',
        'open_pdf' => 'Open PDF',
        'open_html_debug' => 'Open HTML Debug',
        'open_verify_url' => 'Open Verify URL',
        'view_nft_item' => 'View NFT Item',
        'open_getgems' => 'Open GetGems',
        'certificate_data_panel' => 'Certificate Data Panel',
        'cert_uid' => 'Cert UID',
        'rwa_code' => 'RWA Code',
        'unit_of_responsibility' => 'Unit of Responsibility',
        'family' => 'Family',
        'wallet' => 'Wallet',
        'display_wallet' => 'Display Wallet',
        'wallet_source' => 'Wallet Source',
        'status' => 'Status',
        'issued_at' => 'Issued At',
        'minted_at' => 'Minted At',
        'updated_at' => 'Updated At',
        'mint_asset_price' => 'Mint Asset / Price',
        'live_verify_qr' => 'Live Verify QR',
        'qr_caption' => 'Machine-readable route to the official public verification page.',
        'nft_preview_panel' => 'NFT Preview Panel',
        'nft_preview_caption' => 'Resolved preview image for the linked NFT representation artifact.',
        'nft_preview_not_available' => 'NFT preview image not available.',
        'resolved_image_url' => 'Resolved Image URL:',
        'preview_source' => 'Preview Source:',
        'locked_qr_position' => 'Locked QR Position:',
        'layout_source' => 'Layout Source:',
        'verification_signals' => 'Verification Signals',
        'pdf_route' => 'PDF Route',
        'qr_render' => 'QR Render',
        'nft_mint_ready' => 'NFT Mint Ready',
        'audit_console' => 'Audit Console',
        'verify_url' => 'Verify URL',
        'collection' => 'Collection',
        'getgems_url' => 'GetGems URL',
        'verify_json_path' => 'Verify JSON Path',
        'banking_note' => 'Custody-grade verification dashboard for certificate, payment, mint, and marketplace readiness.',
        'yes' => 'YES',
        'no' => 'NO',
    ],
    'zh' => [
        'page_kicker' => '高级验证中心',
        'network' => 'TON（主网）',
        'integrity_score' => '完整性评分',
        'live_checks_passed' => '项实时检查通过',
        'verify_route' => '验证路由',
        'official_public_verify_page' => '官方公开验证页面',
        'qr_status' => '二维码状态',
        'machine_readable_verify_route' => '机器可读验证路由',
        'verify_json' => 'VERIFY.JSON',
        'verify_json_truth_file' => '锁定 NFT 真相文件',
        'verify_json_decode' => 'VERIFY.JSON 解析',
        'json_parse_health' => 'JSON 解析健康状态',
        'nft_health' => 'NFT 健康状态',
        'verify_json_only_truth' => '仅以 verify.json 为准',
        'nft_preview' => 'NFT 预览',
        'nft_item' => 'NFT 项目',
        'minted_token_address' => '已铸造代币地址',
        'fingerprint' => '指纹哈希',
        'certificate_audit_hash' => '证书审计哈希',
        'mint_price' => '铸造价格',
        'ton_wallet_ready' => 'TON 钱包就绪',
        'ton_wallet_ready_caption' => 'TON 钱包已存在且格式有效',
        'getgems_ready' => 'GETGEMS 就绪',
        'getgems_ready_caption' => '可根据 NFT 项目地址构建 GetGems 路由',
        'certificate_artifact_viewer' => '证书文件查看器',
        'original_certificate_pdf' => '通过当前 PDF 引擎渲染的原始证书文件。',
        'open_pdf' => '打开 PDF',
        'open_html_debug' => '打开 HTML 调试',
        'open_verify_url' => '打开验证网址',
        'view_nft_item' => '查看 NFT 项目',
        'open_getgems' => '打开 GetGems',
        'certificate_data_panel' => '证书资料面板',
        'cert_uid' => '证书 UID',
        'rwa_code' => 'RWA 编码',
        'unit_of_responsibility' => '责任单位',
        'family' => '分类',
        'wallet' => '钱包',
        'display_wallet' => '显示钱包',
        'wallet_source' => '钱包来源',
        'status' => '状态',
        'issued_at' => '签发时间',
        'minted_at' => '铸造时间',
        'updated_at' => '更新时间',
        'mint_asset_price' => '铸造资产 / 价格',
        'live_verify_qr' => '实时验证二维码',
        'qr_caption' => '机器可读的官方公开验证页面路由。',
        'nft_preview_panel' => 'NFT 预览面板',
        'nft_preview_caption' => '已解析的 NFT 表示图像预览。',
        'nft_preview_not_available' => 'NFT 预览图像不可用。',
        'resolved_image_url' => '解析后的图像网址：',
        'preview_source' => '预览来源：',
        'locked_qr_position' => '锁定二维码位置：',
        'layout_source' => '位置来源：',
        'verification_signals' => '验证信号',
        'pdf_route' => 'PDF 路由',
        'qr_render' => '二维码渲染',
        'nft_mint_ready' => 'NFT 铸造就绪',
        'audit_console' => '审计控制台',
        'verify_url' => '验证网址',
        'collection' => '合集地址',
        'getgems_url' => 'GetGems 网址',
        'verify_json_path' => 'Verify JSON 路径',
        'banking_note' => '面向证书、支付、铸造与市场就绪状态的托管级验证仪表板。',
        'yes' => '是',
        'no' => '否',
    ],
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>RWA Verify Lounge</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#09111f">
<style>
:root{
    --bg:#07101b;
    --panel:#0d1726;
    --panel-2:#101d31;
    --line:#21324d;
    --line-gold:<?= v_e($theme['accent_line']) ?>;
    --text:#f3f6fb;
    --muted:#9cb0c9;
    --ok:#3fd18c;
    --warn:#f0be4b;
    --bad:#ff6b6b;
    --neutral:#93a1b6;
}
*{box-sizing:border-box}
body{
    margin:0;
    background:
        radial-gradient(circle at 15% 0%, rgba(38,69,113,.35) 0%, rgba(0,0,0,0) 24%),
        radial-gradient(circle at 100% 0%, rgba(212,175,55,.12) 0%, rgba(0,0,0,0) 18%),
        linear-gradient(180deg, #09111f 0%, #0a1220 38%, #07101b 100%);
    color:var(--text);
    font-family:Arial, Helvetica, sans-serif;
}
.wrap{max-width:1440px;margin:0 auto;padding:18px}
.top-tools{
    display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px
}
.lang-switcher{
    display:inline-flex;align-items:center;gap:10px;border:1px solid var(--line);
    background:rgba(255,255,255,.03);padding:8px 12px;border-radius:12px
}
.lang-btn{
    appearance:none;border:0;background:transparent;color:#b9c8db;cursor:pointer;
    font-weight:700;font-size:13px;padding:0
}
.lang-btn.active{color:#ffffff}
.bank-note{
    border:1px solid var(--line);background:rgba(255,255,255,.03);
    padding:10px 14px;border-radius:12px;color:#c8d3e3;font-size:13px;line-height:1.5
}
.hero{
    border:1px solid var(--line);
    background:
        linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,.015)),
        linear-gradient(90deg, rgba(20,38,65,.85), rgba(16,29,49,.92) 55%, rgba(212,175,55,.10));
    padding:18px;
    margin-bottom:16px;
    border-radius:18px;
    box-shadow:0 16px 50px rgba(0,0,0,.22), inset 0 0 0 1px rgba(255,255,255,.02);
}
.hero-top{display:flex;justify-content:space-between;align-items:flex-start;gap:16px}
.kicker{color:#d5e0ee;letter-spacing:4px;font-size:12px;margin-bottom:6px;font-weight:700}
.title{font-size:30px;line-height:1.1;font-weight:800;color:#fff;margin-bottom:8px}
.subtitle{color:#d4deeb;font-size:14px;line-height:1.6;max-width:840px}
.uidbox{
    min-width:320px;border:1px solid rgba(255,255,255,.10);padding:14px 16px;background:rgba(255,255,255,.04);
    border-radius:16px
}
.uidlabel{color:#bfd0e4;font-size:11px;letter-spacing:3px;margin-bottom:6px}
.uidvalue{font-size:18px;font-weight:800;color:#fff;word-break:break-word}
.badgerow{margin-top:12px;display:flex;flex-wrap:wrap;gap:8px}
.badge{
    display:inline-flex;align-items:center;min-height:32px;padding:0 10px;border:1px solid rgba(255,255,255,.10);
    background:rgba(255,255,255,.04);color:#fff;font-size:12px;font-weight:700;border-radius:999px
}
.status.ok{color:var(--ok)}
.status.warn{color:var(--warn)}
.status.bad{color:var(--bad)}
.status.neutral{color:var(--neutral)}

.kpi-grid{display:grid;grid-template-columns:repeat(10,1fr);gap:12px;margin-bottom:16px}
.kpi{
    border:1px solid var(--line);background:linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,.015));
    padding:14px;min-height:104px;border-radius:16px
}
.kpi-label{color:#b2c2d7;font-size:11px;letter-spacing:1.2px;margin-bottom:10px}
.kpi-value{font-size:24px;font-weight:800;color:#fff}
.kpi-sub{margin-top:8px;font-size:12px;color:#97abc4;line-height:1.45}

.grid-main{display:grid;grid-template-columns:1.12fr .88fr;gap:16px}
.stack{display:grid;gap:16px}
.card{
    border:1px solid var(--line);
    background:linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,.015));
    padding:16px;border-radius:18px;
    box-shadow:0 8px 24px rgba(0,0,0,.16), inset 0 0 0 1px rgba(255,255,255,.02)
}
.card-title{font-size:18px;font-weight:700;margin-bottom:12px;color:#fff}
.card-sub{color:#bfd0e4;font-size:13px;line-height:1.55;margin-bottom:12px}
.action-row{display:flex;flex-wrap:wrap;gap:10px}
.btn{
    display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:0 16px;border:1px solid rgba(255,255,255,.12);
    text-decoration:none;color:#fff;background:linear-gradient(180deg, #1b2d4a, #15263f);font-weight:700;border-radius:12px
}
.btn:hover{background:linear-gradient(180deg, #213759, #183051)}
.btn.gold{background:linear-gradient(180deg, rgba(212,175,55,.24), rgba(212,175,55,.16));border-color:rgba(212,175,55,.34)}
.kv-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px 18px}
.kv{font-size:13px;line-height:1.45;word-break:break-word}
.kv .k{color:var(--muted);display:block;margin-bottom:4px}
.kv .v{color:#fff}
.mono{font-family:Consolas, Menlo, monospace}
.qr-box{
    width:240px;height:240px;margin:0 auto 12px;padding:10px;background:#fff;border:2px solid var(--line-gold);
    box-shadow:0 0 20px rgba(212,175,55,.18);border-radius:18px
}
.qr-box img{width:100%;height:100%;object-fit:contain;display:block}
.qr-fallback{
    width:100%;height:100%;display:flex;align-items:center;justify-content:center;border:1px dashed #999;
    color:#777;font-weight:700;letter-spacing:4px;border-radius:12px
}
.preview-shell{
    position:relative;
    aspect-ratio:1 / 1;
    width:100%;
    min-height:320px;
    border:1px solid rgba(255,255,255,.10);
    background:
        linear-gradient(180deg, rgba(255,255,255,.02), rgba(255,255,255,.01)),
        radial-gradient(circle at 50% 30%, rgba(212,175,55,.08), rgba(0,0,0,0) 70%);
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
    border-radius:18px
}
.preview-shell img.preview-main{
    width:100%;
    height:100%;
    object-fit:contain;
    display:block
}
.preview-empty{color:#98a4b8;font-size:14px;text-align:center;line-height:1.6;padding:20px}
iframe{width:100%;height:760px;border:0;background:#fff;border-radius:14px}
.signal-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
.signal{
    border:1px solid rgba(255,255,255,.08);padding:12px;background:rgba(255,255,255,.02);border-radius:14px
}
.signal .name{color:#bfc8d6;font-size:12px;margin-bottom:6px}
.signal .val{font-weight:800;font-size:16px}
.signal .val.ok{color:var(--ok)}
.signal .val.warn{color:var(--warn)}
.signal .val.bad{color:var(--bad)}
.signal .val.neutral{color:var(--neutral)}
.jsonbox{
    border:1px solid rgba(255,255,255,.08);background:#0a1321;padding:14px;color:#d7dde7;overflow:auto;
    white-space:pre-wrap;word-break:break-word;font-family:Consolas, Menlo, monospace;font-size:12px;line-height:1.55;border-radius:14px
}
.footer-note{color:#b9c2d0;font-size:12px;line-height:1.6}
@media (max-width: 1380px){
    .kpi-grid{grid-template-columns:repeat(5,1fr)}
}
@media (max-width: 1180px){
    .grid-main{grid-template-columns:1fr}
}
@media (max-width: 760px){
    .wrap{padding:12px}
    .top-tools{flex-direction:column;align-items:stretch}
    .hero-top{flex-direction:column}
    .uidbox{min-width:100%;width:100%}
    .kpi-grid{grid-template-columns:repeat(2,1fr)}
    .kv-grid{grid-template-columns:1fr}
    .signal-grid{grid-template-columns:1fr}
    .action-row{flex-direction:column;align-items:stretch}
    .btn{width:100%}
    .lang-switcher{justify-content:center}
    iframe{height:520px}
}
</style>
</head>
<body>
<div class="wrap">

    <div class="top-tools">
        <div class="lang-switcher" aria-label="Language switcher">
            <button type="button" class="lang-btn active" data-lang="en">EN</button>
            <span style="color:#7f8a9e;">|</span>
            <button type="button" class="lang-btn" data-lang="zh">中</button>
        </div>
        <div class="bank-note" data-i18n="banking_note"><?= v_e($i18n['en']['banking_note']) ?></div>
    </div>

    <div class="hero">
        <div class="hero-top">
            <div>
                <div class="kicker" data-i18n="page_kicker"><?= v_e($i18n['en']['page_kicker']) ?></div>
                <div class="title"><?= v_e($theme['title']) ?></div>
                <div class="subtitle"><?= v_e($theme['subtitle']) ?></div>
                <div class="badgerow">
                    <span class="badge"><?= v_e($theme['family_label']) ?></span>
                    <span class="badge" data-i18n="network"><?= v_e($i18n['en']['network']) ?></span>
                    <span class="badge status <?= v_e($statusTone['class']) ?>"><?= v_e($statusTone['label']) ?></span>
                    <span class="badge"><?= v_e($rwaCode) ?></span>
                    <span class="badge status <?= v_e($nftHealthReadyClass) ?>" data-i18n="nft_health"><?= v_e($i18n['en']['nft_health']) ?></span>
                </div>
            </div>
            <div class="uidbox">
                <div class="uidlabel" data-i18n="cert_uid"><?= v_e($i18n['en']['cert_uid']) ?></div>
                <div class="uidvalue mono"><?= v_e((string)$row['cert_uid']) ?></div>
            </div>
        </div>
    </div>

    <div class="kpi-grid">
        <div class="kpi">
            <div class="kpi-label" data-i18n="integrity_score"><?= v_e($i18n['en']['integrity_score']) ?></div>
            <div class="kpi-value"><?= v_e((string)$integrityPercent) ?>%</div>
            <div class="kpi-sub">
                <span><?= v_e((string)$integrityScore) ?>/<?= v_e((string)$integrityMax) ?></span>
                <span data-i18n="live_checks_passed"><?= v_e($i18n['en']['live_checks_passed']) ?></span>
            </div>
        </div>
        <div class="kpi">
            <div class="kpi-label" data-i18n="verify_route"><?= v_e($i18n['en']['verify_route']) ?></div>
            <div class="kpi-value status <?= v_e($verifyOkClass) ?>"><?= v_e($verifyOk ? $i18n['en']['yes'] : $i18n['en']['no']) ?></div>
            <div class="kpi-sub" data-i18n="official_public_verify_page"><?= v_e($i18n['en']['official_public_verify_page']) ?></div>
        </div>
        <div class="kpi">
            <div class="kpi-label" data-i18n="qr_status"><?= v_e($i18n['en']['qr_status']) ?></div>
            <div class="kpi-value status <?= v_e($qrOkClass) ?>"><?= v_e($qrOk ? $i18n['en']['yes'] : $i18n['en']['no']) ?></div>
            <div class="kpi-sub" data-i18n="machine_readable_verify_route"><?= v_e($i18n['en']['machine_readable_verify_route']) ?></div>
        </div>
        <div class="kpi">
            <div class="kpi-label" data-i18n="verify_json"><?= v_e($i18n['en']['verify_json']) ?></div>
            <div class="kpi-value status <?= v_e($verifyJsonExistsClass) ?>"><?= v_e($verifyJsonExists ? $i18n['en']['yes'] : $i18n['en']['no']) ?></div>
            <div class="kpi-sub" data-i18n="verify_json_truth_file"><?= v_e($i18n['en']['verify_json_truth_file']) ?></div>
        </div>
        <div class="kpi">
            <div class="kpi-label" data-i18n="verify_json_decode"><?= v_e($i18n['en']['verify_json_decode']) ?></div>
            <div class="kpi-value status <?= v_e($verifyJsonDecodeOkClass) ?>"><?= v_e($verifyJsonDecodeOk ? $i18n['en']['yes'] : $i18n['en']['no']) ?></div>
            <div class="kpi-sub" data-i18n="json_parse_health"><?= v_e($i18n['en']['json_parse_health']) ?></div>
        </div>
        <div class="kpi">
            <div class="kpi-label" data-i18n="nft_health"><?= v_e($i18n['en']['nft_health']) ?></div>
            <div class="kpi-value status <?= v_e($nftHealthReadyClass) ?>"><?= v_e($nftHealthReady ? $i18n['en']['yes'] : $i18n['en']['no']) ?></div>
            <div class="kpi-sub" data-i18n="verify_json_only_truth"><?= v_e($i18n['en']['verify_json_only_truth']) ?></div>
        </div>
        <div class="kpi">
            <div class="kpi-label" data-i18n="ton_wallet_ready"><?= v_e($i18n['en']['ton_wallet_ready']) ?></div>
            <div class="kpi-value status <?= v_e($tonWalletReadyClass) ?>"><?= v_e($tonWalletReady ? $i18n['en']['yes'] : $i18n['en']['no']) ?></div>
            <div class="kpi-sub" data-i18n="ton_wallet_ready_caption"><?= v_e($i18n['en']['ton_wallet_ready_caption']) ?></div>
        </div>
        <div class="kpi">
            <div class="kpi-label" data-i18n="getgems_ready"><?= v_e($i18n['en']['getgems_ready']) ?></div>
            <div class="kpi-value status <?= v_e($getgemsReadyClass) ?>"><?= v_e($getgemsReady ? $i18n['en']['yes'] : $i18n['en']['no']) ?></div>
            <div class="kpi-sub" data-i18n="getgems_ready_caption"><?= v_e($i18n['en']['getgems_ready_caption']) ?></div>
        </div>
        <div class="kpi">
            <div class="kpi-label" data-i18n="fingerprint"><?= v_e($i18n['en']['fingerprint']) ?></div>
            <div class="kpi-value status <?= v_e($fingerprintOkClass) ?>"><?= v_e($fingerprintOk ? $i18n['en']['yes'] : $i18n['en']['no']) ?></div>
            <div class="kpi-sub" data-i18n="certificate_audit_hash"><?= v_e($i18n['en']['certificate_audit_hash']) ?></div>
        </div>
        <div class="kpi">
            <div class="kpi-label" data-i18n="mint_price"><?= v_e($i18n['en']['mint_price']) ?></div>
            <div class="kpi-value status <?= v_e($priceOkClass) ?>"><?= v_e($priceOk ? $i18n['en']['yes'] : $i18n['en']['no']) ?></div>
            <div class="kpi-sub"><?= v_e($priceLabel) ?></div>
        </div>
    </div>

    <div class="grid-main">
        <div class="stack">
            <div class="card">
                <div class="card-title" data-i18n="certificate_artifact_viewer"><?= v_e($i18n['en']['certificate_artifact_viewer']) ?></div>
                <div class="card-sub" data-i18n="original_certificate_pdf"><?= v_e($i18n['en']['original_certificate_pdf']) ?></div>
                <div class="action-row" style="margin-bottom:12px;">
                    <a class="btn" href="<?= v_e($pdfUrl) ?>" target="_blank" rel="noopener" data-i18n="open_pdf"><?= v_e($i18n['en']['open_pdf']) ?></a>
                    <a class="btn" href="<?= v_e($pdfDebugUrl) ?>" target="_blank" rel="noopener" data-i18n="open_html_debug"><?= v_e($i18n['en']['open_html_debug']) ?></a>
                    <a class="btn" href="<?= v_e($verifyUrl) ?>" target="_blank" rel="noopener" data-i18n="open_verify_url"><?= v_e($i18n['en']['open_verify_url']) ?></a>
                    <?php if ($nftItemAddress !== ''): ?>
                        <a class="btn" href="https://tonviewer.com/<?= rawurlencode($nftItemAddress) ?>" target="_blank" rel="noopener" data-i18n="view_nft_item"><?= v_e($i18n['en']['view_nft_item']) ?></a>
                    <?php endif; ?>
                    <?php if ($getgemsReady && $getgemsUrl !== ''): ?>
                        <a class="btn gold" href="<?= v_e($getgemsUrl) ?>" target="_blank" rel="noopener" data-i18n="open_getgems"><?= v_e($i18n['en']['open_getgems']) ?></a>
                    <?php endif; ?>
                </div>
                <iframe src="<?= v_e($pdfUrl) ?>"></iframe>
            </div>

            <div class="card">
                <div class="card-title" data-i18n="certificate_data_panel"><?= v_e($i18n['en']['certificate_data_panel']) ?></div>
                <div class="kv-grid">
                    <div class="kv"><span class="k" data-i18n="cert_uid"><?= v_e($i18n['en']['cert_uid']) ?></span><span class="v mono"><?= v_e((string)$row['cert_uid']) ?></span></div>
                    <div class="kv"><span class="k" data-i18n="rwa_code"><?= v_e($i18n['en']['rwa_code']) ?></span><span class="v"><?= v_e($rwaCode) ?></span></div>
                    <div class="kv"><span class="k" data-i18n="unit_of_responsibility"><?= v_e($i18n['en']['unit_of_responsibility']) ?></span><span class="v"><?= v_e($theme['unit']) ?></span></div>
                    <div class="kv"><span class="k" data-i18n="family"><?= v_e($i18n['en']['family']) ?></span><span class="v"><?= v_e($theme['family_label']) ?></span></div>
                    <div class="kv"><span class="k" data-i18n="wallet"><?= v_e($i18n['en']['wallet']) ?></span><span class="v mono"><?= v_e($wallet) ?></span></div>
                    <div class="kv"><span class="k" data-i18n="display_wallet"><?= v_e($i18n['en']['display_wallet']) ?></span><span class="v mono"><?= v_e(v_mask_wallet($wallet)) ?></span></div>
                    <div class="kv"><span class="k" data-i18n="wallet_source"><?= v_e($i18n['en']['wallet_source']) ?></span><span class="v"><?= v_e($walletSource) ?></span></div>
                    <div class="kv"><span class="k" data-i18n="status"><?= v_e($i18n['en']['status']) ?></span><span class="v status <?= v_e($statusTone['class']) ?>"><?= v_e($statusTone['label']) ?></span></div>
                    <div class="kv"><span class="k" data-i18n="network"><?= v_e($i18n['en']['network']) ?></span><span class="v"><?= v_e($networkLabel) ?></span></div>
                    <div class="kv"><span class="k" data-i18n="issued_at"><?= v_e($i18n['en']['issued_at']) ?></span><span class="v"><?= v_e($issuedAt) ?></span></div>
                    <div class="kv"><span class="k" data-i18n="minted_at"><?= v_e($i18n['en']['minted_at']) ?></span><span class="v"><?= v_e($mintedAt) ?></span></div>
                    <div class="kv"><span class="k" data-i18n="updated_at"><?= v_e($i18n['en']['updated_at']) ?></span><span class="v"><?= v_e($updatedAt) ?></span></div>
                    <div class="kv"><span class="k" data-i18n="mint_asset_price"><?= v_e($i18n['en']['mint_asset_price']) ?></span><span class="v"><?= v_e($priceLabel) ?></span></div>
                    <div class="kv"><span class="k" data-i18n="verify_json_path"><?= v_e($i18n['en']['verify_json_path']) ?></span><span class="v mono"><?= v_e($verifyJsonPath !== '' ? $verifyJsonPath : '—') ?></span></div>
                </div>
            </div>
        </div>

        <div class="stack">
            <div class="card">
                <div class="card-title" data-i18n="live_verify_qr"><?= v_e($i18n['en']['live_verify_qr']) ?></div>
                <div class="card-sub" data-i18n="qr_caption"><?= v_e($i18n['en']['qr_caption']) ?></div>
                <div class="qr-box">
                    <?php if ($qrDataUri !== ''): ?>
                        <img src="<?= v_e($qrDataUri) ?>" alt="Verify QR">
                    <?php else: ?>
                        <div class="qr-fallback">QR</div>
                    <?php endif; ?>
                </div>
                <div class="footer-note mono"><?= v_e($verifyUrl) ?></div>
            </div>

            <div class="card">
                <div class="card-title" data-i18n="nft_preview_panel"><?= v_e($i18n['en']['nft_preview_panel']) ?></div>
                <div class="card-sub" data-i18n="nft_preview_caption"><?= v_e($i18n['en']['nft_preview_caption']) ?></div>
                <div class="preview-shell">
                    <?php if ($nftImg !== ''): ?>
                        <img class="preview-main" src="<?= v_e($nftImg) ?>" alt="NFT Preview">
                    <?php else: ?>
                        <div class="preview-empty" data-i18n="nft_preview_not_available"><?= v_e($i18n['en']['nft_preview_not_available']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="footer-note" style="margin-top:12px;">
                    <strong data-i18n="resolved_image_url"><?= v_e($i18n['en']['resolved_image_url']) ?></strong><br>
                    <span class="mono"><?= v_e($nftImg !== '' ? $nftImg : '—') ?></span><br><br>
                    <strong data-i18n="preview_source"><?= v_e($i18n['en']['preview_source']) ?></strong> <?= v_e($nftImgSource) ?><br>
                    <strong data-i18n="locked_qr_position"><?= v_e($i18n['en']['locked_qr_position']) ?></strong>
                    <span class="mono">x=<?= v_e((string)$slotX) ?>, y=<?= v_e((string)$slotY) ?>, size=<?= v_e((string)$slotSize) ?></span><br>
                    <strong data-i18n="layout_source"><?= v_e($i18n['en']['layout_source']) ?></strong> <span class="mono"><?= v_e($slotSource) ?></span>
                </div>
            </div>

            <div class="card">
                <div class="card-title" data-i18n="verification_signals"><?= v_e($i18n['en']['verification_signals']) ?></div>
                <div class="signal-grid">
                    <div class="signal"><div class="name" data-i18n="verify_route"><?= v_e($i18n['en']['verify_route']) ?></div><div class="val <?= v_e($verifyOkClass) ?>"><?= v_e($verifyOk ? $i18n['en']['yes'] : $i18n['en']['no']) ?></div></div>
                    <div class="signal"><div class="name" data-i18n="pdf_route"><?= v_e($i18n['en']['pdf_route']) ?></div><div class="val <?= v_e($pdfOkClass) ?>"><?= v_e($pdfOk ? $i18n['en']['yes'] : $i18n['en']['no']) ?></div></div>
                    <div class="signal"><div class="name" data-i18n="qr_render"><?= v_e($i18n['en']['qr_render']) ?></div><div class="val <?= v_e($qrOkClass) ?>"><?= v_e($qrOk ? $i18n['en']['yes'] : $i18n['en']['no']) ?></div></div>
                    <div class="signal"><div class="name" data-i18n="verify_json"><?= v_e($i18n['en']['verify_json']) ?></div><div class="val <?= v_e($verifyJsonExistsClass) ?>"><?= v_e($verifyJsonExists ? $i18n['en']['yes'] : $i18n['en']['no']) ?></div></div>
                    <div class="signal"><div class="name" data-i18n="verify_json_decode"><?= v_e($i18n['en']['verify_json_decode']) ?></div><div class="val <?= v_e($verifyJsonDecodeOkClass) ?>"><?= v_e($verifyJsonDecodeOk ? $i18n['en']['yes'] : $i18n['en']['no']) ?></div></div>
                    <div class="signal"><div class="name" data-i18n="nft_health"><?= v_e($i18n['en']['nft_health']) ?></div><div class="val <?= v_e($nftHealthReadyClass) ?>"><?= v_e($nftHealthReady ? $i18n['en']['yes'] : $i18n['en']['no']) ?></div></div>
                    <div class="signal"><div class="name" data-i18n="ton_wallet_ready"><?= v_e($i18n['en']['ton_wallet_ready']) ?></div><div class="val <?= v_e($tonWalletReadyClass) ?>"><?= v_e($tonWalletReady ? $i18n['en']['yes'] : $i18n['en']['no']) ?></div></div>
                    <div class="signal"><div class="name" data-i18n="getgems_ready"><?= v_e($i18n['en']['getgems_ready']) ?></div><div class="val <?= v_e($getgemsReadyClass) ?>"><?= v_e($getgemsReady ? $i18n['en']['yes'] : $i18n['en']['no']) ?></div></div>
                    <div class="signal"><div class="name" data-i18n="nft_mint_ready"><?= v_e($i18n['en']['nft_mint_ready']) ?></div><div class="val <?= v_e($nftMintReadyClass) ?>"><?= v_e($nftMintReady ? $i18n['en']['yes'] : $i18n['en']['no']) ?></div></div>
                </div>
            </div>

            <div class="card">
                <div class="card-title" data-i18n="audit_console"><?= v_e($i18n['en']['audit_console']) ?></div>
                <div class="kv-grid" style="margin-bottom:14px;">
                    <div class="kv"><span class="k" data-i18n="fingerprint"><?= v_e($i18n['en']['fingerprint']) ?></span><span class="v mono"><?= v_e($fingerprint !== '' ? $fingerprint : '—') ?></span></div>
                    <div class="kv"><span class="k" data-i18n="verify_url"><?= v_e($i18n['en']['verify_url']) ?></span><span class="v mono"><?= v_e($verifyUrl) ?></span></div>
                    <div class="kv"><span class="k" data-i18n="nft_item"><?= v_e($i18n['en']['nft_item']) ?></span><span class="v mono"><?= v_e($nftItemAddress !== '' ? $nftItemAddress : '—') ?></span></div>
                    <div class="kv"><span class="k" data-i18n="collection"><?= v_e($i18n['en']['collection']) ?></span><span class="v mono"><?= v_e($collectionAddress) ?></span></div>
                    <div class="kv"><span class="k" data-i18n="getgems_url"><?= v_e($i18n['en']['getgems_url']) ?></span><span class="v mono"><?= v_e($getgemsUrl !== '' ? $getgemsUrl : '—') ?></span></div>
                    <div class="kv"><span class="k" data-i18n="verify_json_path"><?= v_e($i18n['en']['verify_json_path']) ?></span><span class="v mono"><?= v_e($verifyJsonPath !== '' ? $verifyJsonPath : '—') ?></span></div>
                </div>
                <div class="jsonbox"><?= v_e(json_encode($jsonIndicators, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></div>
            </div>
        </div>
    </div>

</div>

<script>
(function () {
    const I18N = <?= json_encode($i18n, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const KEY = 'poado_verify_lang';
    const defaultLang = 'en';

    function applyLang(lang) {
        const pack = I18N[lang] || I18N[defaultLang];
        document.documentElement.lang = lang;
        document.querySelectorAll('[data-i18n]').forEach(function (el) {
            const key = el.getAttribute('data-i18n');
            if (typeof pack[key] !== 'undefined') {
                el.textContent = pack[key];
            }
        });
        document.querySelectorAll('.lang-btn').forEach(function (btn) {
            btn.classList.toggle('active', btn.getAttribute('data-lang') === lang);
        });
        try {
            localStorage.setItem(KEY, lang);
        } catch (e) {}
    }

    let currentLang = defaultLang;
    try {
        const saved = localStorage.getItem(KEY);
        if (saved && I18N[saved]) {
            currentLang = saved;
        }
    } catch (e) {}

    applyLang(currentLang);

    document.querySelectorAll('.lang-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const lang = btn.getAttribute('data-lang') || defaultLang;
            applyLang(lang);
        });
    });
})();



async function forceVerify(uid) {
  try {
    const r = await fetch('/rwa/cert/api/verify-status.php?cert_uid=' + encodeURIComponent(uid), { cache: 'no-store' });
    const j = await r.json();
    if (j?.item?.status === 'minted') {
      location.reload();
      return true;
    }
  } catch (e) {}
  return false;
}

function startVerifyPolling(uid) {
  let tries = 0;
  const maxTries = 24;
  const timer = setInterval(async () => {
    tries++;
    const done = await forceVerify(uid);
    if (done || tries >= maxTries) {
      clearInterval(timer);
    }
  }, 5000);
}

if (typeof CERT_UID !== 'undefined' && CERT_UID) {
  forceVerify(CERT_UID);
  startVerifyPolling(CERT_UID);
}

</script>
