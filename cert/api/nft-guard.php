<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/api/nft-guard.php
 * Version: v1.0.0-20260407-nft-guard
 *
 * Purpose:
 * - Central NFT artifact guard for cert pipeline
 * - Validate verify.json + image + metadata integrity
 * - Reject fallback placeholder artifacts
 * - Provide a single pass/fail result for mint/readiness checks
 */

function cert_nft_guard_abs(string $p): string
{
    $p = trim($p);
    if ($p === '') return '';
    if (str_starts_with($p, '/var/www/html/public/')) return $p;
    if (str_starts_with($p, '/')) return '/var/www/html/public' . $p;
    return $p;
}

function cert_nft_guard_verify_path(array $cert): string
{
    $meta = $cert['meta_json_decoded'] ?? [];
    if (!is_array($meta)) $meta = [];

    $candidates = [
        (string)($meta['artifacts']['verify_json_path'] ?? ''),
        (string)($meta['vault']['verify_json_path'] ?? ''),
        (string)($meta['vault']['verify_json'] ?? ''),
        (string)($cert['verify_json_path'] ?? ''),
    ];

    foreach ($candidates as $p) {
        $abs = cert_nft_guard_abs($p);
        if ($abs !== '' && is_file($abs)) return $abs;
    }

    $uid = (string)($cert['cert_uid'] ?? '');
    $rwaCode = (string)($cert['rwa_code'] ?? '');
    $ownerUserId = (int)($cert['owner_user_id'] ?? 0);

    if ($uid === '' || $rwaCode === '') {
        return '';
    }

    if (!preg_match('/^([A-Z0-9]+(?:-[A-Z0-9]+)+)-(\d{8})-([A-Z0-9]{8})$/', $uid, $m)) {
        return '';
    }

    $yyyy = substr($m[2], 0, 4);
    $mm   = substr($m[2], 4, 2);

    $family = 'GENESIS';
    if (in_array($rwaCode, ['RLIFE-EMA', 'RTRIP-EMA', 'RPROP-EMA'], true)) $family = 'SECONDARY';
    if (in_array($rwaCode, ['RHRD-EMA'], true)) $family = 'TERTIARY';

    $base = "/var/www/html/public/rwa/metadata/cert/RWA_CERT/{$family}/{$rwaCode}/TON/{$yyyy}/{$mm}/U" . max(1, $ownerUserId) . "/{$uid}";
    foreach ([
        $base . '/verify/verify.json',
        $base . '/v1/verify/verify.json',
    ] as $p) {
        if (is_file($p)) return $p;
    }

    return '';
}

function cert_nft_guard_check(array $cert): array
{
    $errors = [];
    $warnings = [];

    $uid = (string)($cert['cert_uid'] ?? '');
    if ($uid === '') $errors[] = 'CERT_UID_MISSING';

    $verifyAbs = cert_nft_guard_verify_path($cert);
    if ($verifyAbs === '') {
        return [
            'ok' => true,
            'pass' => false,
            'profile' => 'nft-guard-v1',
            'errors' => ['VERIFY_JSON_MISSING'],
            'warnings' => [],
            'paths' => [],
            'verify' => [],
        ];
    }

    $raw = @file_get_contents($verifyAbs);
    $verify = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
    if (!is_array($verify)) $verify = [];

    $imagePath = cert_nft_guard_abs((string)($verify['image_path'] ?? ''));
    $metaPath = cert_nft_guard_abs((string)($verify['meta_path'] ?? ''));
    $verifyJsonPath = cert_nft_guard_abs((string)($verify['verify_json_path'] ?? ''));

    if ($verifyJsonPath === '') {
        $verifyJsonPath = $verifyAbs;
    }

    if (($verify['ok'] ?? false) !== true) $errors[] = 'VERIFY_NOT_OK';
    if (($verify['healthy'] ?? false) !== true) $errors[] = 'VERIFY_NOT_HEALTHY';
    if (($verify['artifact_ready'] ?? false) !== true) $errors[] = 'ARTIFACT_NOT_READY';
    if (($verify['nft_healthy'] ?? false) !== true) $errors[] = 'NFT_NOT_HEALTHY';
    if (($verify['used_fallback_placeholder'] ?? true) === true) $errors[] = 'FALLBACK_NOT_ALLOWED';

    if ($imagePath === '' || !is_file($imagePath)) {
        $errors[] = 'IMAGE_MISSING';
    } else {
        $size = filesize($imagePath) ?: 0;
        if ($size <= 0) $errors[] = 'IMAGE_EMPTY';

        $sig = @mime_content_type($imagePath) ?: '';
        if (!str_contains(strtolower($sig), 'png')) $errors[] = 'IMAGE_NOT_PNG';

        $dim = @getimagesize($imagePath);
        if (!is_array($dim) || ($dim[0] ?? 0) <= 0 || ($dim[1] ?? 0) <= 0) {
            $errors[] = 'IMAGE_DIMENSION_INVALID';
        }
    }

    if ($metaPath === '' || !is_file($metaPath)) {
        $errors[] = 'METADATA_MISSING';
    }

    if ($verifyJsonPath === '' || !is_file($verifyJsonPath)) {
        $errors[] = 'VERIFY_JSON_PATH_INVALID';
    }

    if ((string)($verify['final_sha1'] ?? '') === '') {
        $errors[] = 'FINAL_SHA1_MISSING';
    }

    if ((int)($verify['final_size'] ?? 0) <= 0) {
        $errors[] = 'FINAL_SIZE_INVALID';
    }

    $imageUrl = (string)($verify['image_url'] ?? '');
    if ($imageUrl === '' || !str_starts_with($imageUrl, 'https://adoptgold.app/')) {
        $errors[] = 'IMAGE_URL_INVALID';
    }

    $verifyPageUrl = (string)($verify['verify_page_url'] ?? '');
    if ($verifyPageUrl === '' || !str_starts_with($verifyPageUrl, 'https://adoptgold.app/')) {
        $warnings[] = 'VERIFY_PAGE_URL_INVALID';
    }

    return [
        'ok' => true,
        'pass' => count($errors) === 0,
        'profile' => 'nft-guard-v1',
        'errors' => $errors,
        'warnings' => $warnings,
        'paths' => [
            'image' => $imagePath,
            'meta' => $metaPath,
            'verify' => $verifyJsonPath,
        ],
        'verify' => $verify,
    ];
}
