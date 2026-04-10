<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/api/_getgems-metadata.php
 * Version: v1.1.0-20260410-getgems-standard-aligned
 *
 * Purpose
 * - Build lean off-chain metadata for TON / Getgems compatible NFT minting
 * - Keep on-chain item content tiny by storing only URL suffix
 * - Align helper metadata schema with _image-bundle.php canonical metadata output
 *
 * Locked usage direction
 * - Collection stores common off-chain prefix
 * - NFT item stores only suffix such as: RCO2C-EMA-20260401-ABC12345.json
 * - Full metadata JSON is hosted off-chain under /rwa/metadata/cert/
 */

if (!function_exists('ggm_h')) {
    function ggm_h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('ggm_str')) {
    function ggm_str(mixed $v, int $max = 255, string $fallback = ''): string
    {
        $s = trim((string)$v);
        if ($s === '') {
            return $fallback;
        }
        if (mb_strlen($s, 'UTF-8') > $max) {
            $s = mb_substr($s, 0, $max, 'UTF-8');
        }
        return $s;
    }
}

if (!function_exists('ggm_num')) {
    function ggm_num(mixed $v, int $scale = 6): string
    {
        if ($v === null || $v === '') {
            return '0';
        }
        if (!is_numeric((string)$v)) {
            return '0';
        }
        return number_format((float)$v, $scale, '.', '');
    }
}

if (!function_exists('ggm_attr')) {
    function ggm_attr(string $trait, string|int|float $value): array
    {
        return [
            'trait_type' => $trait,
            'value' => is_string($value) ? ggm_str($value, 120) : $value,
        ];
    }
}

if (!function_exists('ggm_detect_family')) {
    function ggm_detect_family(string $rwaCode, string $fallback = 'GENESIS'): string
    {
        $code = strtoupper(trim($rwaCode));
        if ($code === '') {
            return strtoupper(trim($fallback)) ?: 'GENESIS';
        }

        return match ($code) {
            'RLIFE-EMA', 'RTRIP-EMA', 'RPROP-EMA', 'RHRD-EMA' => 'SECONDARY',
            default => 'GENESIS',
        };
    }
}

if (!function_exists('ggm_rwa_unit_label')) {
    function ggm_rwa_unit_label(string $rwaCode): string
    {
        $map = [
            'RCO2C-EMA' => '10 kg tCO2e',
            'RH2O-EMA' => '100 liters or m³',
            'RBLACK-EMA' => '1 MWh or energy-unit',
            'RK92-EMA' => '1 gram Gold Nugget',
            'RLIFE-EMA' => '1 day health-right unit by BMI',
            'RTRIP-EMA' => '1 km travel-right unit',
            'RPROP-EMA' => '1 sqft property-right unit',
            'RHRD-EMA' => '10 hours Labor Contribution',
        ];

        $code = strtoupper(trim($rwaCode));
        return $map[$code] ?? $code;
    }
}

if (!function_exists('ggm_snapshot_weight')) {
    function ggm_snapshot_weight(string $rwaCode): int
    {
        $map = [
            'RCO2C-EMA' => 1,
            'RH2O-EMA' => 2,
            'RBLACK-EMA' => 3,
            'RK92-EMA' => 5,
            'RLIFE-EMA' => 10,
            'RTRIP-EMA' => 10,
            'RPROP-EMA' => 10,
            'RHRD-EMA' => 7,
        ];

        $code = strtoupper(trim($rwaCode));
        return $map[$code] ?? 1;
    }
}

if (!function_exists('ggm_cert_metadata_filename')) {
    function ggm_cert_metadata_filename(string $certUid): string
    {
        $safe = preg_replace('~[^A-Z0-9._-]+~', '-', strtoupper(trim($certUid))) ?: 'CERT';
        return $safe . '.json';
    }
}

if (!function_exists('ggm_cert_metadata_suffix')) {
    function ggm_cert_metadata_suffix(string $certUid): string
    {
        return ggm_cert_metadata_filename($certUid);
    }
}

if (!function_exists('ggm_cert_metadata_url')) {
    function ggm_cert_metadata_url(string $basePrefix, string $certUid): string
    {
        $basePrefix = rtrim(trim($basePrefix), '/') . '/';
        return $basePrefix . rawurlencode(ggm_cert_metadata_filename($certUid));
    }
}

if (!function_exists('ggm_build_getgems_metadata')) {
    function ggm_build_getgems_metadata(array $row, array $opts = []): array
    {
        $certUid = ggm_str($row['cert_uid'] ?? '', 64);
        $rwaCode = strtoupper(ggm_str($row['rwa_code'] ?? '', 32));
        $family = strtoupper(ggm_str($row['family'] ?? '', 16, ggm_detect_family($rwaCode)));
        $verifyUrl = ggm_str(
            $opts['external_url'] ?? $row['external_url'] ?? $row['verify_url'] ?? '',
            500
        );
        $imageUrl = ggm_str(
            $opts['image_url'] ?? $row['image'] ?? $row['image_url'] ?? $row['nft_image_url'] ?? '',
            500
        );
        $description = ggm_str(
            $opts['description'] ?? 'AdoptGold RWA Certificate NFT',
            1200
        );
        $displayName = ggm_str(
            $opts['name'] ?? ($certUid !== '' ? $certUid : ($rwaCode !== '' ? $rwaCode : 'RWA Certificate')),
            120
        );
        $unitOfResponsibility = ggm_str(
            $opts['unit_of_responsibility'] ?? ggm_rwa_unit_label($rwaCode),
            120
        );

        $attrs = [
            ggm_attr('RWA Code', $rwaCode),
            ggm_attr('Family', $family),
            ggm_attr('Cert UID', $certUid),
            ggm_attr('Unit of Responsibility', $unitOfResponsibility),
        ];

        if (!empty($opts['include_snapshot_weight']) || !empty($row['include_snapshot_weight'])) {
            $attrs[] = ggm_attr('Snapshot Weight', ggm_snapshot_weight($rwaCode));
        }

        $meta = [
            'name' => $displayName,
            'description' => $description,
            'image' => $imageUrl,
            'external_url' => $verifyUrl,
            'attributes' => $attrs,
        ];

        return array_filter(
            $meta,
            static fn($v) => !($v === '' || $v === null || $v === [])
        );
    }
}

if (!function_exists('ggm_write_getgems_metadata_file')) {
    function ggm_write_getgems_metadata_file(string $dir, string $certUid, array $metadata): array
    {
        $dir = rtrim($dir, '/');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('GETGEMS_METADATA_DIR_CREATE_FAILED: ' . $dir);
        }

        $filename = ggm_cert_metadata_filename($certUid);
        $path = $dir . '/' . $filename;

        $json = json_encode(
            $metadata,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
        if ($json === false) {
            throw new RuntimeException('GETGEMS_METADATA_JSON_ENCODE_FAILED');
        }

        if (@file_put_contents($path, $json) === false) {
            throw new RuntimeException('GETGEMS_METADATA_WRITE_FAILED: ' . $path);
        }

        return [
            'filename' => $filename,
            'path' => $path,
            'bytes' => filesize($path) ?: 0,
        ];
    }
}

if (!function_exists('ggm_build_mint_content_payload')) {
    function ggm_build_mint_content_payload(string $certUid, string $basePrefix): array
    {
        $suffix = ggm_cert_metadata_suffix($certUid);

        return [
            'content_mode' => 'OFFCHAIN_SUFFIX_ONLY',
            'item_content_suffix' => $suffix,
            'full_metadata_url' => ggm_cert_metadata_url($basePrefix, $certUid),
            'warning' => 'Mint using suffix only; do not embed full JSON/base64 in cell payload.',
        ];
    }
}
