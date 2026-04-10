<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/api/_cert-path.php
 * Version: v1.0.0-20260404-rwa-issuance-master-lock
 *
 * MASTER LOCK
 * - canonical issuance root is /var/www/html/public/rwa/metadata/cert/RWA_CERT
 * - drive root is gdrive:ADG/rwa/cert/RWA_CERT
 * - structure:
 *   RWA_CERT/{CATEGORY}/{RWA_CODE}/{CHAIN}/{YYYY}/{MM}/U{USER_ID}/{CERT_UID}/
 *     current/
 *     v{VERSION}/
 */

const CERT_PATH_LOCAL_ROOT = '/var/www/html/public/rwa/metadata/cert/RWA_CERT';
const CERT_PATH_DRIVE_ROOT = 'gdrive:ADG/rwa/cert/RWA_CERT';

function cert_path_normalize_category(string $family, string $rwaCode = ''): string
{
    $family = strtoupper(trim($family));
    if ($family !== '') {
        return match ($family) {
            'GENESIS'   => 'GENESIS',
            'SECONDARY' => 'SECONDARY',
            'TERTIARY'  => 'TERTIARY',
            default     => 'GENESIS',
        };
    }

    $rwaCode = strtoupper(trim($rwaCode));
    return match ($rwaCode) {
        'RLIFE-EMA', 'RTRIP-EMA', 'RPROP-EMA' => 'SECONDARY',
        'RHRD-EMA'                             => 'TERTIARY',
        default                                => 'GENESIS',
    };
}

function cert_path_detect_rwa_code(array $row): string
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
        'RLIFE'  => 'RLIFE-EMA',
        'RTRIP'  => 'RTRIP-EMA',
        'RPROP'  => 'RPROP-EMA',
        'RHRD'   => 'RHRD-EMA',
        default  => '',
    };
}

function cert_path_chain(array $row): string
{
    $chain = strtoupper(trim((string)($row['chain'] ?? $row['meta_json_decoded']['chain'] ?? 'TON')));
    return $chain !== '' ? $chain : 'TON';
}

function cert_path_user_bucket(array $row): string
{
    $meta = $row['meta_json_decoded'] ?? [];
    $candidates = [
        trim((string)($meta['user_bucket'] ?? '')),
        trim((string)($meta['vault']['user_bucket'] ?? '')),
        trim((string)($meta['user']['bucket'] ?? '')),
    ];

    foreach ($candidates as $v) {
        if ($v !== '') {
            $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $v);
            if ($safe !== '') {
                return $safe;
            }
        }
    }

    $userId = (int)($row['owner_user_id'] ?? 0);
    return $userId > 0 ? ('U' . $userId) : 'U0';
}

function cert_path_uid_parts(string $certUid): array
{
    $uid = strtoupper(trim($certUid));

    // Locked production UID pattern:
    // {RWA_CODE}-{YYYYMMDD}-{8CHARS}
    // Examples:
    // RCO2C-EMA-20260331-D3F0BCFC
    // RH2O-EMA-20260404-45D7D6BF
    // RK92-EMA-20260327-REAL0001
    if (!preg_match('/^([A-Z0-9]+(?:-[A-Z0-9]+)+)-(\d{8})-([A-Z0-9]{8})$/', $uid, $m)) {
        throw new RuntimeException('CERT_UID_FORMAT_INVALID');
    }

    $ymd = $m[1];

    return [
        'year'  => substr($ymd, 0, 4),
        'month' => substr($ymd, 4, 2),
    ];
}

function cert_path_rel_root(array $row): string
{
    $certUid = trim((string)($row['cert_uid'] ?? ''));
    if ($certUid === '') {
        throw new RuntimeException('CERT_UID_REQUIRED');
    }

    $rwaCode  = cert_path_detect_rwa_code($row);
    $category = cert_path_normalize_category((string)($row['family'] ?? ''), $rwaCode);
    $chain    = cert_path_chain($row);
    $bucket   = cert_path_user_bucket($row);
    $parts    = cert_path_uid_parts($certUid);

    if ($rwaCode === '') {
        throw new RuntimeException('RWA_CODE_REQUIRED');
    }

    return $category
        . '/' . $rwaCode
        . '/' . $chain
        . '/' . $parts['year']
        . '/' . $parts['month']
        . '/' . $bucket
        . '/' . $certUid;
}

function cert_path_local_root(array $row): string
{
    return CERT_PATH_LOCAL_ROOT . '/' . cert_path_rel_root($row);
}

function cert_path_drive_root(array $row): string
{
    return CERT_PATH_DRIVE_ROOT . '/' . cert_path_rel_root($row);
}

function cert_path_local_current(array $row): string
{
    return cert_path_local_root($row) . '/current';
}

function cert_path_drive_current(array $row): string
{
    return cert_path_drive_root($row) . '/current';
}

function cert_path_local_version(array $row, int $version): string
{
    return cert_path_local_root($row) . '/v' . max(1, $version);
}

function cert_path_drive_version(array $row, int $version): string
{
    return cert_path_drive_root($row) . '/v' . max(1, $version);
}

function cert_path_current_files(array $row): array
{
    $root = cert_path_local_current($row);
    $uid  = trim((string)($row['cert_uid'] ?? ''));

    return [
        'root'               => $root,
        'pdf'                => $root . '/pdf/' . $uid . '.pdf',
        'metadata_json'      => $root . '/meta/metadata.json',
        'verify_json'        => $root . '/verify/verify.json',
        'qr_svg'             => $root . '/qr/verify.svg',
        'payment_proof_json' => $root . '/proof/payment-proof.json',
        'mint_proof_json'    => $root . '/proof/mint-proof.json',
    ];
}

function cert_path_version_files(array $row, int $version): array
{
    $root = cert_path_local_version($row, $version);
    $uid  = trim((string)($row['cert_uid'] ?? ''));

    return [
        'root'               => $root,
        'pdf'                => $root . '/pdf/' . $uid . '.pdf',
        'metadata_json'      => $root . '/meta/metadata.json',
        'verify_json'        => $root . '/verify/verify.json',
        'qr_svg'             => $root . '/qr/verify.svg',
        'payment_proof_json' => $root . '/proof/payment-proof.json',
        'mint_proof_json'    => $root . '/proof/mint-proof.json',
        'manifest_json'      => $root . '/manifest.json',
    ];
}

function cert_path_mkdirs(string $root): void
{
    $dirs = [
        $root,
        $root . '/pdf',
        $root . '/meta',
        $root . '/qr',
        $root . '/proof',
    ];

    foreach ($dirs as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('MKDIR_FAILED:' . $dir);
        }
    }
}

function cert_path_write_json_atomic(string $path, array $payload): void
{
    $tmp = $path . '.tmp';
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        throw new RuntimeException('JSON_ENCODE_FAILED');
    }
    if (file_put_contents($tmp, $json . PHP_EOL) === false) {
        throw new RuntimeException('WRITE_FAILED:' . $tmp);
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('RENAME_FAILED:' . $path);
    }
}
