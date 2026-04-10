<?php
declare(strict_types=1);

/**
 * Path: /var/www/html/public/rwa/inc/core/gdrive.php
 * Version: v1.1.0-20260329-rwa-standalone-gdrive-db-fixed
 *
 * Standalone Google Drive helper for RWA only.
 * No /dashboard/* dependency.
 *
 * Live DB schema used:
 *   poado_user_gdrive(
 *     user_id, drive_email, refresh_token_enc, folder_id, folder_name,
 *     connected_at, revoked_at, updated_at
 *   )
 *
 *   poado_drive_logs(
 *     id, user_id, action, ok, message, meta, created_at
 *   )
 */

if (defined('RWA_CORE_GDRIVE_LOADED')) {
    return;
}
define('RWA_CORE_GDRIVE_LOADED', true);

$__rwa_gdrive_autoloads = [
    '/var/www/html/public/rwa/vendor/autoload.php',
    dirname(__DIR__, 2) . '/vendor/autoload.php',
    '/var/www/html/public/vendor/autoload.php',
];

$__rwa_gdrive_loaded = false;
foreach ($__rwa_gdrive_autoloads as $__autoload) {
    if (is_file($__autoload)) {
        require_once $__autoload;
        $__rwa_gdrive_loaded = true;
        break;
    }
}
unset($__rwa_gdrive_autoloads, $__autoload);

if (!$__rwa_gdrive_loaded || !class_exists(\Google\Client::class)) {
    throw new RuntimeException('google/apiclient not installed for standalone RWA');
}
unset($__rwa_gdrive_loaded);

function rwa_gdrive_env(string $key, string $default = ''): string
{
    if (function_exists('poado_env')) {
        $v = (string)poado_env($key, $default);
        return $v !== '' ? $v : $default;
    }
    $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return is_string($v) && $v !== '' ? trim($v) : $default;
}

function rwa_gdrive_db(): PDO
{
    if (function_exists('rwa_db')) {
        $pdo = rwa_db();
        if ($pdo instanceof PDO) {
            return $pdo;
        }
    }
    if (function_exists('db')) {
        $pdo = db();
        if ($pdo instanceof PDO) {
            return $pdo;
        }
    }
    if (($GLOBALS['pdo'] ?? null) instanceof PDO) {
        return $GLOBALS['pdo'];
    }
    throw new RuntimeException('RWA DB not available');
}

function rwa_gdrive_app_secret(): string
{
    $secret = rwa_gdrive_env('APP_SECRET', '');
    if (strlen($secret) < 16) {
        throw new RuntimeException('APP_SECRET missing or too short');
    }
    return $secret;
}

function rwa_gdrive_encrypt(string $plain): string
{
    if ($plain === '') {
        return '';
    }

    $key = hash('sha256', rwa_gdrive_app_secret(), true);
    $iv  = random_bytes(12);
    $tag = '';

    $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) {
        throw new RuntimeException('rwa_gdrive_encrypt failed');
    }

    return rtrim(strtr(base64_encode($iv . $tag . $ct), '+/', '-_'), '=');
}

function rwa_gdrive_decrypt(?string $enc): string
{
    $enc = trim((string)$enc);
    if ($enc === '') {
        return '';
    }

    $raw = base64_decode(strtr($enc, '-_', '+/'), true);
    if ($raw === false || strlen($raw) < 29) {
        return '';
    }

    $iv  = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct  = substr($raw, 28);
    $key = hash('sha256', rwa_gdrive_app_secret(), true);

    $pt = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $pt === false ? '' : $pt;
}

function rwa_gdrive_log(int $userId, string $action, bool $ok, string $message = '', array $meta = []): void
{
    try {
        $pdo = rwa_gdrive_db();
        $st = $pdo->prepare("
            INSERT INTO poado_drive_logs (user_id, action, ok, message, meta, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $st->execute([
            $userId,
            $action,
            $ok ? 1 : 0,
            mb_substr($message, 0, 255),
            json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    } catch (Throwable) {
        // silent log fallback
    }
}

function rwa_gdrive_get_user(int $userId): ?array
{
    $pdo = rwa_gdrive_db();
    $st = $pdo->prepare("SELECT * FROM poado_user_gdrive WHERE user_id = ? LIMIT 1");
    $st->execute([$userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    $row['_refresh_token'] = rwa_gdrive_decrypt((string)($row['refresh_token_enc'] ?? ''));
    return $row;
}

function rwa_gdrive_is_connected_row(array $row): bool
{
    return
        !empty($row['user_id']) &&
        trim((string)($row['_refresh_token'] ?? '')) !== '' &&
        trim((string)($row['folder_id'] ?? '')) !== '' &&
        empty($row['revoked_at']);
}

function rwa_gdrive_require_connected(int $userId): array
{
    $row = rwa_gdrive_get_user($userId);
    if (!$row) {
        throw new RuntimeException('NO_GDRIVE_ROW');
    }
    if (!rwa_gdrive_is_connected_row($row)) {
        throw new RuntimeException('GDRIVE_NOT_CONNECTED');
    }
    return $row;
}

function rwa_gdrive_save_connection(
    int $userId,
    string $driveEmail,
    string $refreshToken,
    string $folderId,
    string $folderName
): void {
    if ($userId <= 0) {
        throw new RuntimeException('INVALID_USER_ID');
    }
    if ($refreshToken === '') {
        throw new RuntimeException('EMPTY_REFRESH_TOKEN');
    }

    $pdo = rwa_gdrive_db();
    $st = $pdo->prepare("
        INSERT INTO poado_user_gdrive
            (user_id, drive_email, refresh_token_enc, folder_id, folder_name, connected_at, revoked_at, updated_at)
        VALUES
            (:user_id, :drive_email, :refresh_token_enc, :folder_id, :folder_name, NOW(), NULL, NOW())
        ON DUPLICATE KEY UPDATE
            drive_email       = VALUES(drive_email),
            refresh_token_enc = VALUES(refresh_token_enc),
            folder_id         = VALUES(folder_id),
            folder_name       = VALUES(folder_name),
            connected_at      = IF(connected_at IS NULL, NOW(), connected_at),
            revoked_at        = NULL,
            updated_at        = NOW()
    ");
    $st->execute([
        ':user_id'           => $userId,
        ':drive_email'       => $driveEmail,
        ':refresh_token_enc' => rwa_gdrive_encrypt($refreshToken),
        ':folder_id'         => $folderId,
        ':folder_name'       => $folderName,
    ]);
}

function rwa_gdrive_set_folder(int $userId, string $folderId, string $folderName): void
{
    $pdo = rwa_gdrive_db();
    $st = $pdo->prepare("
        UPDATE poado_user_gdrive
        SET folder_id = ?, folder_name = ?, revoked_at = NULL, updated_at = NOW()
        WHERE user_id = ?
    ");
    $st->execute([$folderId, $folderName, $userId]);
}

function rwa_gdrive_revoke(int $userId): void
{
    $pdo = rwa_gdrive_db();
    $st = $pdo->prepare("
        UPDATE poado_user_gdrive
        SET revoked_at = NOW(), updated_at = NOW()
        WHERE user_id = ?
    ");
    $st->execute([$userId]);
}

function rwa_gdrive_google_client(): Google\Client
{
    $clientId     = rwa_gdrive_env('GOOGLE_OAUTH_CLIENT_ID', '');
    $clientSecret = rwa_gdrive_env('GOOGLE_OAUTH_CLIENT_SECRET', '');
    $redirectUri  = rwa_gdrive_env('GOOGLE_OAUTH_REDIRECT', '');

    if ($clientId === '' || $clientSecret === '') {
        throw new RuntimeException('GOOGLE_OAUTH_ENV_MISSING');
    }

    $client = new Google\Client();
    $client->setClientId($clientId);
    $client->setClientSecret($clientSecret);

    if ($redirectUri !== '') {
        $client->setRedirectUri($redirectUri);
    }

    $client->setAccessType('offline');
    $client->setPrompt('consent');
    $client->setIncludeGrantedScopes(true);
    $client->setScopes([
        Google\Service\Drive::DRIVE_FILE,
        Google\Service\Drive::DRIVE_METADATA_READONLY,
    ]);

    return $client;
}

function rwa_gdrive_client_for_user(int $userId): Google\Client
{
    $row = rwa_gdrive_require_connected($userId);
    $refreshToken = trim((string)($row['_refresh_token'] ?? ''));
    if ($refreshToken === '') {
        throw new RuntimeException('MISSING_REFRESH_TOKEN');
    }

    $client = rwa_gdrive_google_client();
    $token = $client->fetchAccessTokenWithRefreshToken($refreshToken);

    if (!is_array($token) || empty($token['access_token'])) {
        throw new RuntimeException('ACCESS_TOKEN_REFRESH_FAILED');
    }

    return $client;
}

function rwa_gdrive_service_for_user(int $userId): Google\Service\Drive
{
    return new Google\Service\Drive(rwa_gdrive_client_for_user($userId));
}

function rwa_gdrive_folder_probe_for_user(int $userId, string $folderId): array
{
    $drive = rwa_gdrive_service_for_user($userId);

    $folder = $drive->files->get($folderId, [
        'fields' => 'id,name,mimeType,webViewLink,parents,driveId',
        'supportsAllDrives' => true,
    ]);

    return [
        'id' => (string)$folder->getId(),
        'name' => (string)$folder->getName(),
        'mimeType' => (string)$folder->getMimeType(),
        'webViewLink' => (string)$folder->getWebViewLink(),
        'parents' => $folder->getParents(),
        'driveId' => method_exists($folder, 'getDriveId') ? (string)$folder->getDriveId() : '',
    ];
}

function rwa_gdrive_find_child_folder(Google\Service\Drive $drive, string $parentId, string $name): ?string
{
    $safeName = str_replace("'", "\\'", $name);
    $q = sprintf(
        "'%s' in parents and trashed = false and mimeType = 'application/vnd.google-apps.folder' and name = '%s'",
        $parentId,
        $safeName
    );

    $res = $drive->files->listFiles([
        'q' => $q,
        'pageSize' => 1,
        'fields' => 'files(id,name)',
        'supportsAllDrives' => true,
        'includeItemsFromAllDrives' => true,
    ]);

    $files = $res->getFiles();
    if (!$files || !isset($files[0])) {
        return null;
    }

    return (string)$files[0]->getId();
}

function rwa_gdrive_create_folder(Google\Service\Drive $drive, string $parentId, string $name): string
{
    $folder = new Google\Service\Drive\DriveFile([
        'name' => $name,
        'mimeType' => 'application/vnd.google-apps.folder',
        'parents' => [$parentId],
    ]);

    $created = $drive->files->create($folder, [
        'fields' => 'id,name',
        'supportsAllDrives' => true,
    ]);

    return (string)$created->getId();
}

function rwa_gdrive_ensure_path_for_user(int $userId, string $rootFolderId, string $path): string
{
    $drive = rwa_gdrive_service_for_user($userId);
    $current = $rootFolderId;
    $parts = array_values(array_filter(array_map('trim', explode('/', trim($path, '/'))), static fn($v) => $v !== ''));

    foreach ($parts as $part) {
        $next = rwa_gdrive_find_child_folder($drive, $current, $part);
        if ($next === null) {
            $next = rwa_gdrive_create_folder($drive, $current, $part);
        }
        $current = $next;
    }

    return $current;
}

function rwa_gdrive_upload_bytes_for_user(
    int $userId,
    string $folderId,
    string $name,
    string $mime,
    string $bytes
): array {
    if ($name === '') {
        throw new RuntimeException('EMPTY_FILENAME');
    }

    $drive = rwa_gdrive_service_for_user($userId);

    $file = new Google\Service\Drive\DriveFile([
        'name' => $name,
        'parents' => [$folderId],
    ]);

    $created = $drive->files->create($file, [
        'data' => $bytes,
        'mimeType' => $mime,
        'uploadType' => 'multipart',
        'fields' => 'id,name,mimeType,webViewLink,webContentLink,size,createdTime,parents',
        'supportsAllDrives' => true,
    ]);

    return [
        'id' => (string)$created->getId(),
        'name' => (string)$created->getName(),
        'mimeType' => (string)$created->getMimeType(),
        'webViewLink' => (string)$created->getWebViewLink(),
        'webContentLink' => (string)$created->getWebContentLink(),
        'size' => (string)$created->getSize(),
        'createdTime' => (string)$created->getCreatedTime(),
        'parents' => $created->getParents(),
        'url' => (string)$created->getWebViewLink(),
    ];
}

function rwa_gdrive_list_folder_for_user(int $userId, string $folderId, int $pageSize = 20): array
{
    $drive = rwa_gdrive_service_for_user($userId);
    $pageSize = max(1, min(100, $pageSize));

    $res = $drive->files->listFiles([
        'q' => sprintf("'%s' in parents and trashed = false", str_replace("'", "\\'", $folderId)),
        'pageSize' => $pageSize,
        'orderBy' => 'createdTime desc',
        'fields' => 'files(id,name,mimeType,webViewLink,size,createdTime)',
        'supportsAllDrives' => true,
        'includeItemsFromAllDrives' => true,
    ]);

    $items = [];
    foreach ((array)$res->getFiles() as $f) {
        $items[] = [
            'id' => (string)$f->getId(),
            'name' => (string)$f->getName(),
            'mimeType' => (string)$f->getMimeType(),
            'webViewLink' => (string)$f->getWebViewLink(),
            'size' => (string)$f->getSize(),
            'createdTime' => (string)$f->getCreatedTime(),
        ];
    }

    return $items;
}

function rwa_gdrive_delete_file_for_user(int $userId, string $fileId): void
{
    $drive = rwa_gdrive_service_for_user($userId);
    $drive->files->delete($fileId, [
        'supportsAllDrives' => true,
    ]);
}
