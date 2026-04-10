<?php
declare(strict_types=1);

/**
 * CURRENT POINTER MANAGER
 * - current/ is ALWAYS derived
 * - NEVER manually edited
 * - ALWAYS copied from healthy version
 */

require_once __DIR__ . '/_cert-path.php';
require_once __DIR__ . '/_cert-atomic.php';
require_once __DIR__ . '/_cert-verify-health.php';

function cert_current_clear(string $dir): void
{
    if (!is_dir($dir)) return;

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($files as $file) {
        if ($file->isDir()) {
            @rmdir($file->getRealPath());
        } else {
            @unlink($file->getRealPath());
        }
    }
}

function cert_current_copy(array $row, int $version): array
{
    $vf = cert_path_version_files($row, $version);
    $cf = cert_path_current_files($row);

    cert_path_mkdirs($cf['root']);
    cert_current_clear($cf['root']);

    cert_atomic_copy_file($vf['pdf'], $cf['pdf']);
    cert_atomic_copy_file($vf['metadata_json'], $cf['metadata_json']);
    cert_atomic_copy_file($vf['verify_json'], $cf['verify_json']);
    cert_atomic_copy_file($vf['qr_svg'], $cf['qr_svg']);

    if (is_file($vf['payment_proof_json'])) {
        cert_atomic_copy_file($vf['payment_proof_json'], $cf['payment_proof_json']);
    }

    if (is_file($vf['mint_proof_json'])) {
        cert_atomic_copy_file($vf['mint_proof_json'], $cf['mint_proof_json']);
    }

    return [
        'ok' => true,
        'version' => $version,
        'current_root' => $cf['root'],
    ];
}

function cert_current_refresh(array $row): array
{
    $latest = cert_health_latest($row);

    if (!$latest['ok']) {
        return [
            'ok' => false,
            'error' => 'NO_HEALTHY_VERSION',
        ];
    }

    return cert_current_copy($row, $latest['version']);
}
