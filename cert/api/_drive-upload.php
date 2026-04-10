<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/api/_drive-upload.php
 * Version: v1.0.0-20260404-rwa-drive-replication
 *
 * LOCK
 * - local VPS is source of truth
 * - Google Drive is replication only
 * - uploads current/ and v{VERSION}/ only
 * - never mutates cert business truth
 */

require_once __DIR__ . '/_cert-path.php';

function cert_drive_exec(string $cmd): array
{
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);

    return [
        'ok'     => ($code === 0),
        'code'   => $code,
        'output' => implode("\n", $out),
        'cmd'    => $cmd,
    ];
}

function cert_drive_rclone_ready(): bool
{
    $r = cert_drive_exec('rclone lsd gdrive:');
    return (bool)($r['ok'] ?? false);
}

function cert_drive_copy(string $src, string $dst): array
{
    $cmd = sprintf(
        'rclone copy -P %s %s --transfers 4 --checkers 8',
        escapeshellarg($src),
        escapeshellarg($dst)
    );
    return cert_drive_exec($cmd);
}

function cert_drive_expected_local_ok(array $row, int $version): array
{
    $vf = cert_path_version_files($row, $version);
    $cf = cert_path_current_files($row);

    $checks = [
        'version_pdf'           => is_file($vf['pdf']),
        'version_metadata_json' => is_file($vf['metadata_json']),
        'version_verify_json'   => is_file($vf['verify_json']),
        'current_pdf'           => is_file($cf['pdf']),
        'current_metadata_json' => is_file($cf['metadata_json']),
        'current_verify_json'   => is_file($cf['verify_json']),
    ];

    return [
        'ok' => !in_array(false, $checks, true),
        'checks' => $checks,
    ];
}

function cert_drive_upload_version(array $row, int $version): array
{
    $local = cert_path_local_version($row, $version);
    $drive = cert_path_drive_version($row, $version);

    if (!is_dir($local)) {
        return [
            'ok' => false,
            'error' => 'LOCAL_VERSION_DIR_MISSING',
            'path' => $local,
        ];
    }

    return cert_drive_copy($local, $drive);
}

function cert_drive_upload_current(array $row): array
{
    $local = cert_path_local_current($row);
    $drive = cert_path_drive_current($row);

    if (!is_dir($local)) {
        return [
            'ok' => false,
            'error' => 'LOCAL_CURRENT_DIR_MISSING',
            'path' => $local,
        ];
    }

    return cert_drive_copy($local, $drive);
}

function cert_drive_upload_all(array $row, int $version): array
{
    if (!cert_drive_rclone_ready()) {
        return [
            'ok' => false,
            'error' => 'RCLONE_GDRIVE_NOT_READY',
        ];
    }

    $localCheck = cert_drive_expected_local_ok($row, $version);
    if (!$localCheck['ok']) {
        return [
            'ok' => false,
            'error' => 'LOCAL_ARTIFACTS_INCOMPLETE',
            'local' => $localCheck,
        ];
    }

    $versionUpload = cert_drive_upload_version($row, $version);
    $currentUpload = cert_drive_upload_current($row);

    return [
        'ok' => ($versionUpload['ok'] ?? false) && ($currentUpload['ok'] ?? false),
        'drive_root' => cert_path_drive_root($row),
        'version' => $versionUpload,
        'current' => $currentUpload,
    ];
}
