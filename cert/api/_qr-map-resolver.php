<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/api/_qr-map-resolver.php
 * Version: v1.2.1-20260330-master-map-only
 *
 * Lock:
 * - Only _meta-image-map.php controls QR layout
 * - No legacy fallback coordinates allowed
 */

require_once __DIR__ . '/_meta-image-map.php';

function cert_v2_normalize_rwa_layout_key(string $rwaCode): string
{
    $code = strtoupper(trim($rwaCode));

    return match ($code) {
        'RCO2C-EMA' => 'green',
        'RH2O-EMA' => 'blue',
        'RBLACK-EMA' => 'black',
        'RK92-EMA' => 'gold',
        'RHRD-EMA' => 'yellow',
        'RLIFE-EMA' => 'pink',
        'RPROP-EMA' => 'royal_blue',
        'RTRIP-EMA' => 'red',
        default => throw new RuntimeException('QR_LAYOUT_KEY_UNKNOWN: ' . $rwaCode),
    };
}

function cert_v2_resolve_qr_layout(string $rwaCode): array
{
    $map = cert_v2_meta_image_map();
    $key = cert_v2_normalize_rwa_layout_key($rwaCode);

    if (!isset($map[$key]['qr']) || !is_array($map[$key]['qr'])) {
        throw new RuntimeException('QR_LAYOUT_NOT_FOUND_FOR_KEY: ' . $key);
    }

    $qr = $map[$key]['qr'];

    if (!isset($qr['x'], $qr['y'], $qr['size'])) {
        throw new RuntimeException('QR_LAYOUT_INVALID_FOR_KEY: ' . $key);
    }

    return [
        'x' => (int)$qr['x'],
        'y' => (int)$qr['y'],
        'size' => (int)$qr['size'],
    ];
}
