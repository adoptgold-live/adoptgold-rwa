<?php
declare(strict_types=1);

require_once __DIR__ . '/_cert-path.php';
require_once __DIR__ . '/_cert-atomic.php';
require_once __DIR__ . '/_cert-current-manager.php';
require_once __DIR__ . '/_cert-verify-health.php';

const CERT_CDN_LOCAL_ROOT = '/var/www/html/public/rwa/cdn/cert/RWA_CERT';
const CERT_CDN_PUBLIC_BASE = '/rwa/cdn/cert/RWA_CERT';

function cert_cdn_root(array $row): string
{
    return CERT_CDN_LOCAL_ROOT . '/' . cert_path_rel_root($row);
}

function cert_cdn_current_root(array $row): string
{
    return cert_cdn_root($row) . '/current';
}

function cert_cdn_version_root(array $row, int $version): string
{
    return cert_cdn_root($row) . '/v' . max(1, $version);
}

function cert_cdn_public_current_base(array $row): string
{
    return CERT_CDN_PUBLIC_BASE . '/' . cert_path_rel_root($row) . '/current';
}

function cert_cdn_public_version_base(array $row, int $version): string
{
    return CERT_CDN_PUBLIC_BASE . '/' . cert_path_rel_root($row) . '/v' . max(1, $version);
}

function cert_cdn_clear(string $dir): void
{
    if (!is_dir($dir)) return;

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($it as $node) {
        $path = $node->getRealPath();
        if ($node->isDir()) {
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
}

function cert_cdn_copy_tree(string $src, string $dst): void
{
    if (!is_dir($src)) {
        throw new RuntimeException('CDN_SOURCE_DIR_MISSING:' . $src);
    }

    cert_atomic_mkdir($dst);
    cert_cdn_clear($dst);

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($it as $node) {
        $srcPath = $node->getRealPath();
        $relPath = substr($srcPath, strlen(rtrim($src, '/')) + 1);
        $dstPath = rtrim($dst, '/') . '/' . $relPath;

        if ($node->isDir()) {
            cert_atomic_mkdir($dstPath);
            continue;
        }

        cert_atomic_copy_file($srcPath, $dstPath);
    }
}

function cert_cdn_publish_version(array $row, int $version): array
{
    $health = cert_health_check($row, $version);
    if (!$health['ok']) {
        return [
            'ok' => false,
            'error' => 'VERSION_NOT_HEALTHY',
            'health' => $health,
        ];
    }

    $src = cert_path_local_version($row, $version);
    $dst = cert_cdn_version_root($row, $version);

    cert_cdn_copy_tree($src, $dst);

    return [
        'ok' => true,
        'version' => $version,
        'cdn_root' => $dst,
        'public_base' => cert_cdn_public_version_base($row, $version),
    ];
}

function cert_cdn_publish_current(array $row): array
{
    $refresh = cert_current_refresh($row);
    if (!($refresh['ok'] ?? false)) {
        return [
            'ok' => false,
            'error' => 'CURRENT_REFRESH_FAILED',
            'refresh' => $refresh,
        ];
    }

    $version = (int)($refresh['version'] ?? 0);
    if ($version <= 0) {
        return [
            'ok' => false,
            'error' => 'CURRENT_VERSION_INVALID',
        ];
    }

    $src = cert_path_local_current($row);
    $dst = cert_cdn_current_root($row);

    cert_cdn_copy_tree($src, $dst);

    return [
        'ok' => true,
        'version' => $version,
        'cdn_root' => $dst,
        'public_base' => cert_cdn_public_current_base($row),
        'pdf_url' => cert_cdn_public_current_base($row) . '/pdf/' . ($row['cert_uid'] ?? '') . '.pdf',
        'metadata_url' => cert_cdn_public_current_base($row) . '/meta/metadata.json',
        'verify_url' => cert_cdn_public_current_base($row) . '/verify/verify.json',
        'qr_url' => cert_cdn_public_current_base($row) . '/qr/verify.svg',
    ];
}

function cert_cdn_publish_all(array $row, int $version): array
{
    $v = cert_cdn_publish_version($row, $version);
    $c = cert_cdn_publish_current($row);

    return [
        'ok' => ($v['ok'] ?? false) && ($c['ok'] ?? false),
        'version' => $v,
        'current' => $c,
    ];
}
