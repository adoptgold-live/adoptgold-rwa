<?php
declare(strict_types=1);

/**
 * Path: /var/www/html/public/rwa/inc/core/vault.php
 * Version: v1.0.0-20260329-rwa-standalone-vault
 *
 * Standalone Drive-backed vault helper.
 * No /dashboard/* dependency.
 */

if (defined('RWA_CORE_VAULT_LOADED')) {
    return;
}
define('RWA_CORE_VAULT_LOADED', true);

require_once __DIR__ . '/gdrive.php';
require_once __DIR__ . '/vault-policy.php';

function rwa_vault_user_root_folder_id(int $userId): string
{
    $row = rwa_gdrive_require_connected($userId);
    $folderId = trim((string)($row['folder_id'] ?? ''));

    if ($folderId === '') {
        throw new RuntimeException('NO_GDRIVE_ROOT_FOLDER');
    }

    return $folderId;
}

function rwa_vault_drive_email(int $userId): string
{
    $row = rwa_gdrive_require_connected($userId);
    return trim((string)($row['drive_email'] ?? ''));
}

function rwa_vault_ensure_dir(int $userId, string $relativeDir): array
{
    $relativeDir = rwa_vault_policy_assert_relpath($relativeDir);
    $rootFolderId = rwa_vault_user_root_folder_id($userId);
    $folderId = rwa_gdrive_ensure_path_for_user($userId, $rootFolderId, $relativeDir);

    $out = [
        'ok' => true,
        'user_id' => $userId,
        'drive_email' => rwa_vault_drive_email($userId),
        'root_folder_id' => $rootFolderId,
        'relative_dir' => $relativeDir,
        'folder_id' => $folderId,
    ];

    rwa_gdrive_log($userId, 'rwa_vault_ensure_dir', true, 'vault dir ok', $out);
    return $out;
}

function rwa_vault_put_bytes(
    int $userId,
    string $relativeDir,
    string $filename,
    string $bytes,
    string $mime = 'application/octet-stream'
): array {
    $relativeDir = rwa_vault_policy_assert_relpath($relativeDir);
    $filename = rwa_vault_policy_filename($filename);

    $dirInfo = rwa_vault_ensure_dir($userId, $relativeDir);
    $file = rwa_gdrive_upload_bytes_for_user(
        $userId,
        (string)$dirInfo['folder_id'],
        $filename,
        $mime,
        $bytes
    );

    $out = [
        'ok' => true,
        'relative_dir' => $relativeDir,
        'filename' => $filename,
        'path' => $relativeDir . '/' . $filename,
        'folder_id' => (string)$dirInfo['folder_id'],
        'file' => $file,
        'url' => (string)($file['url'] ?? ''),
    ];

    rwa_gdrive_log($userId, 'rwa_vault_put_bytes', true, 'vault upload ok', [
        'path' => $out['path'],
        'file_id' => (string)($file['id'] ?? ''),
        'mime' => $mime,
    ]);

    return $out;
}

function rwa_vault_put_json(
    int $userId,
    string $relativeDir,
    string $filename,
    array $payload
): array {
    return rwa_vault_put_bytes(
        $userId,
        $relativeDir,
        $filename,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        'application/json'
    );
}

function rwa_vault_put_text(
    int $userId,
    string $relativeDir,
    string $filename,
    string $text
): array {
    return rwa_vault_put_bytes(
        $userId,
        $relativeDir,
        $filename,
        $text,
        'text/plain'
    );
}

function rwa_vault_list(int $userId, string $relativeDir, int $pageSize = 20): array
{
    $relativeDir = rwa_vault_policy_assert_relpath($relativeDir);
    $dirInfo = rwa_vault_ensure_dir($userId, $relativeDir);
    $items = rwa_gdrive_list_folder_for_user($userId, (string)$dirInfo['folder_id'], $pageSize);

    return [
        'ok' => true,
        'relative_dir' => $relativeDir,
        'folder_id' => (string)$dirInfo['folder_id'],
        'count' => count($items),
        'items' => $items,
    ];
}
