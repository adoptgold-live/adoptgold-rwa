<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/testers/gdrive-composer-tester.php
 * Version: v1.0.0-20260329-rwa-standalone-gdrive-tester
 *
 * Standalone RWA Google Drive tester.
 * No /dashboard/* dependency.
 *
 * Params:
 * - user_id=13            required
 * - action=status         default
 * - action=probe
 * - action=upload
 * - action=list
 * - action=cleanup
 * - file_id=...           for cleanup
 * - page_size=10          for list
 * - path=RWA_TEST/...     for ensure path upload target under selected folder
 * - debug=1
 */

header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/gdrive.php';

function gt_out(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function gt_req(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function gt_bool_debug(): bool
{
    return gt_req('debug') === '1';
}

function gt_debug(array $data): array
{
    if (!gt_bool_debug()) {
        return [];
    }
    return ['_debug' => $data];
}

function gt_mask(string $value, int $keepStart = 6, int $keepEnd = 4): string
{
    $len = strlen($value);
    if ($len <= ($keepStart + $keepEnd)) {
        return $value;
    }
    return substr($value, 0, $keepStart) . str_repeat('*', max(4, $len - $keepStart - $keepEnd)) . substr($value, -$keepEnd);
}

try {
    $userId = (int)gt_req('user_id', '0');
    if ($userId <= 0) {
        gt_out(['ok' => false, 'error' => 'USER_ID_REQUIRED'], 400);
    }

    $action = strtolower(gt_req('action', 'status'));
    $row = rwa_gdrive_require_connected($userId);

    $folderId = trim((string)($row['folder_id'] ?? ''));
    $folderName = trim((string)($row['folder_name'] ?? ''));

    if ($folderId === '') {
        gt_out(['ok' => false, 'error' => 'NO_FOLDER_SELECTED'], 400);
    }

    if ($action === 'status') {
        $client = rwa_gdrive_client_for_user($userId);
        $token = $client->getAccessToken();

        gt_out([
            'ok' => true,
            'action' => 'status',
            'user' => [
                'user_id' => $userId,
                'email' => (string)($row['email'] ?? ''),
                'google_user_id' => gt_mask((string)($row['google_user_id'] ?? '')),
                'folder_id' => $folderId,
                'folder_name' => $folderName,
                'scope' => (string)($row['scope'] ?? ''),
                'has_refresh_token' => trim((string)($row['_refresh_token'] ?? '')) !== '',
                'has_access_token' => trim((string)($row['_access_token'] ?? '')) !== '',
                'is_active' => (int)($row['is_active'] ?? 0),
            ],
            'token' => [
                'has_access_token' => !empty($token['access_token']),
                'expires_in' => (int)($token['expires_in'] ?? 0),
                'created' => (int)($token['created'] ?? 0),
            ],
        ] + gt_debug([
            'table_row_keys' => array_keys($row),
        ]));
    }

    if ($action === 'probe') {
        $probe = rwa_gdrive_folder_probe_for_user($userId, $folderId);
        gt_out([
            'ok' => true,
            'action' => 'probe',
            'folder' => $probe,
        ]);
    }

    if ($action === 'upload') {
        $subPath = gt_req('path', 'RWA_TEST/COMPOSER');
        $targetFolderId = rwa_gdrive_ensure_path_for_user($userId, $folderId, $subPath);

        $name = 'rwa-gdrive-test-' . gmdate('Ymd-His') . '.txt';
        $body = implode("\n", [
            'Standalone RWA Google Drive Test',
            'UTC: ' . gmdate('c'),
            'UserID: ' . $userId,
            'FolderID: ' . $folderId,
            'SubPath: ' . $subPath,
            'Tester: /rwa/testers/gdrive-composer-tester.php',
            '',
        ]);

        $file = rwa_gdrive_upload_bytes_for_user(
            $userId,
            $targetFolderId,
            $name,
            'text/plain',
            $body
        );

        rwa_gdrive_log($userId, 'rwa_gdrive_tester_upload', true, 'upload ok', [
            'folder_id' => $folderId,
            'target_folder_id' => $targetFolderId,
            'path' => $subPath,
            'file_id' => $file['id'] ?? '',
        ]);

        gt_out([
            'ok' => true,
            'action' => 'upload',
            'selected_folder' => [
                'folder_id' => $folderId,
                'folder_name' => $folderName,
            ],
            'target' => [
                'path' => $subPath,
                'folder_id' => $targetFolderId,
            ],
            'file' => $file,
        ]);
    }

    if ($action === 'list') {
        $pageSize = (int)gt_req('page_size', '10');
        $items = rwa_gdrive_list_folder_for_user($userId, $folderId, $pageSize);

        gt_out([
            'ok' => true,
            'action' => 'list',
            'folder' => [
                'folder_id' => $folderId,
                'folder_name' => $folderName,
            ],
            'count' => count($items),
            'items' => $items,
        ]);
    }

    if ($action === 'cleanup') {
        $fileId = gt_req('file_id', '');
        if ($fileId === '') {
            gt_out(['ok' => false, 'error' => 'FILE_ID_REQUIRED'], 400);
        }

        rwa_gdrive_delete_file_for_user($userId, $fileId);
        rwa_gdrive_log($userId, 'rwa_gdrive_tester_cleanup', true, 'delete ok', [
            'file_id' => $fileId,
        ]);

        gt_out([
            'ok' => true,
            'action' => 'cleanup',
            'file_id' => $fileId,
            'deleted' => true,
        ]);
    }

    gt_out([
        'ok' => false,
        'error' => 'UNKNOWN_ACTION',
        'allowed' => ['status', 'probe', 'upload', 'list', 'cleanup'],
    ], 400);
} catch (Throwable $e) {
    if (isset($userId) && $userId > 0) {
        rwa_gdrive_log($userId, 'rwa_gdrive_tester_error', false, $e->getMessage(), [
            'action' => $action ?? '',
        ]);
    }

    gt_out([
        'ok' => false,
        'error' => 'RWA_GDRIVE_TEST_FAIL',
        'detail' => $e->getMessage(),
    ] + gt_debug([
        'action' => $action ?? '',
        'user_id' => $userId ?? 0,
    ]), 500);
}
