<?php
// /dashboard/inc/drive.php
// POAdo Google Drive Shared Drive helper (Composer google/apiclient)
// Global helper for ALL modules
// v2.0.20260224 (ULTIMATE)

declare(strict_types=1);

/**
 * NOTE:
 * - Uses Composer google/apiclient
 * - Shared Drive mode ONLY (supportsAllDrives=true, driveId=shared_id)
 * - Never depends on bootstrap (but works fine with it)
 * - Never logs secrets
 */

if (!function_exists('poado_drive_log')) {
  function poado_drive_log(string $msg, array $ctx = []): void {
    // Keep lightweight logging; do NOT log secrets.
    $redact = ['private_key', 'token', 'password', 'pass', 'secret', 'key', 'authorization'];
    foreach ($redact as $k) {
      if (isset($ctx[$k])) $ctx[$k] = '[REDACTED]';
    }
    $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $msg;
    if (!empty($ctx)) {
      $line .= ' | ' . json_encode($ctx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    $line .= "\n";
    @file_put_contents('/var/log/poado-drive.log', $line, FILE_APPEND);
  }
}

if (!function_exists('poado_drive_env')) {
  function poado_drive_env(string $k, string $default = ''): string {
    // Prefer poado_env() (from env.php/bootstrap) else getenv()
    if (function_exists('poado_env')) {
      $v = (string)poado_env($k, '');
      $v = trim($v);
      return $v !== '' ? $v : $default;
    }
    $v = getenv($k);
    if ($v === false) return $default;
    $v = trim((string)$v);
    return $v !== '' ? $v : $default;
  }
}

if (!function_exists('poado_drive_must')) {
  function poado_drive_must(string $k): string {
    $v = poado_drive_env($k, '');
    if ($v === '') throw new RuntimeException("Missing required env: {$k}");
    return $v;
  }
}

if (!function_exists('poado_drive_autoload')) {
  function poado_drive_autoload(): void {
    // LOCKED Composer autoload path in dashboard webroot
    if (!class_exists(\Google\Client::class)) {
      $autoload = '/var/www/html/public/rwa/vendor/autoload.php';
      if (!is_file($autoload)) {
        throw new RuntimeException("Composer autoload not found: {$autoload}");
      }
      require_once $autoload;
    }
  }
}

if (!function_exists('poado_drive_cfg')) {
  function poado_drive_cfg(): array {
    $mode = poado_drive_env('GOOGLE_DRIVE_MODE', 'shared_drive');
    $shared = poado_drive_must('GOOGLE_DRIVE_SHARED_ID');
    $root = poado_drive_must('GOOGLE_DRIVE_ROOT_FOLDER_ID');
    $svcJson = poado_drive_must('GOOGLE_DRIVE_SERVICE_JSON');
    $backup = poado_drive_env('GOOGLE_DRIVE_BACKUP_FOLDER_ID', '' );

    if (!$backup || $backup === '.' || $backup === '') {
      $backup = $root;
    }


    if ($mode !== 'shared_drive') {
      // project lock: shared_drive only
      throw new RuntimeException("GOOGLE_DRIVE_MODE must be 'shared_drive' (got: {$mode})");
    }

    return [
      'mode'         => $mode,
      'shared_id'    => $shared,
      'root_id'      => $root,
      'backup_id'    => $backup,
      'service_json' => $svcJson,
    ];
  }
}

if (!function_exists('poado_drive_client')) {
  function poado_drive_client(): \Google\Service\Drive {
    static $svc = null;
    if ($svc instanceof \Google\Service\Drive) return $svc;

    poado_drive_autoload();
    $cfg = poado_drive_cfg();

    if (!is_file($cfg['service_json'])) {
      throw new RuntimeException("Service JSON not found: {$cfg['service_json']}");
    }

    $client = new \Google\Client();
    $client->setAuthConfig($cfg['service_json']);
    $client->setScopes([\Google\Service\Drive::DRIVE]); // full Drive scope for Shared Drive ops
    $client->setAccessType('offline');

    $svc = new \Google\Service\Drive($client);
    return $svc;
  }
}

/**
 * Backward-compat alias: some older code/testers call poado_drive_service()
 */
if (!function_exists('poado_drive_service')) {
  function poado_drive_service(): \Google\Service\Drive {
    return poado_drive_client();
  }
}

if (!function_exists('poado_drive_common_list_args')) {
  function poado_drive_common_list_args(): array {
    $cfg = poado_drive_cfg();
    return [
      'supportsAllDrives' => true,
      'includeItemsFromAllDrives' => true,
      'corpora' => 'drive',
      'driveId' => $cfg['shared_id'],
      'pageSize' => 100,
    ];
  }
}

if (!function_exists('poado_drive_get_file')) {
  function poado_drive_get_file(string $fileId): ?\Google\Service\Drive\DriveFile {
    $drive = poado_drive_client();
    try {
      return $drive->files->get($fileId, [
        'fields' => 'id,name,mimeType,webViewLink,parents,driveId,trashed,size,md5Checksum,createdTime,modifiedTime',
        'supportsAllDrives' => true,
      ]);
    } catch (\Throwable $e) {
      poado_drive_log('drive_get_file failed', ['file_id' => $fileId, 'err' => $e->getMessage()]);
      return null;
    }
  }
}

if (!function_exists('poado_drive_list_children')) {
  function poado_drive_list_children(string $parentId, string $name = '', bool $foldersOnly = false): array {
    $drive = poado_drive_client();
    $q = sprintf("'%s' in parents and trashed=false", addslashes($parentId));
    if ($name !== '') $q .= " and name='" . addslashes($name) . "'";
    if ($foldersOnly) $q .= " and mimeType='application/vnd.google-apps.folder'";

    $out = [];
    $pageToken = null;
    $argsBase = poado_drive_common_list_args();

    do {
      $args = array_merge($argsBase, [
        'q' => $q,
        'fields' => 'nextPageToken,files(id,name,mimeType,webViewLink,parents,driveId,size,md5Checksum,createdTime,modifiedTime)',
        'pageToken' => $pageToken,
      ]);

      $resp = $drive->files->listFiles($args);
      foreach (($resp->getFiles() ?: []) as $f) $out[] = $f;
      $pageToken = $resp->getNextPageToken();
    } while ($pageToken);

    return $out;
  }
}

if (!function_exists('poado_drive_ensure_folder')) {
  function poado_drive_ensure_folder(string $parentId, string $folderName): string {
    $drive = poado_drive_client();

    $existing = poado_drive_list_children($parentId, $folderName, true);
    if (!empty($existing)) return (string)$existing[0]->getId();

    $meta = new \Google\Service\Drive\DriveFile([
      'name' => $folderName,
      'mimeType' => 'application/vnd.google-apps.folder',
      'parents' => [$parentId],
    ]);

    $created = $drive->files->create($meta, [
      'supportsAllDrives' => true,
      'fields' => 'id',
    ]);

    return (string)$created->getId();
  }
}

if (!function_exists('poado_drive_ensure_path')) {
  function poado_drive_ensure_path(string $rootFolderId, string $path): string {
    $path = trim($path);
    $path = trim($path, '/');
    if ($path === '') return $rootFolderId;

    $parts = array_values(array_filter(explode('/', $path), fn($p) => trim($p) !== ''));
    $cur = $rootFolderId;

    foreach ($parts as $seg) {
      $seg = trim($seg);
      if ($seg === '') continue;
      $cur = poado_drive_ensure_folder($cur, $seg);
    }
    return $cur;
  }
}

if (!function_exists('poado_drive_upload_bytes')) {
  function poado_drive_upload_bytes(string $parentId, string $name, string $mime, string $bytes): array {
    $drive = poado_drive_client();

    $meta = new \Google\Service\Drive\DriveFile([
      'name' => $name,
      'parents' => [$parentId],
    ]);

    $created = $drive->files->create($meta, [
      'data' => $bytes,
      'mimeType' => $mime,
      'uploadType' => 'multipart',
      'supportsAllDrives' => true,
      'fields' => 'id,webViewLink,size,md5Checksum,createdTime,modifiedTime',
    ]);

    $id = (string)$created->getId();
    $link = (string)$created->getWebViewLink();
    if ($link === '') $link = "https://drive.google.com/file/d/{$id}/view?usp=drivesdk";

    return [
      'file_id' => $id,
      'webViewLink' => $link,
      'size' => (string)($created->getSize() ?? ''),
      'md5' => (string)($created->getMd5Checksum() ?? ''),
      'createdTime' => (string)($created->getCreatedTime() ?? ''),
      'modifiedTime' => (string)($created->getModifiedTime() ?? ''),
    ];
  }
}

if (!function_exists('poado_drive_upload_stream')) {
  /**
   * Upload from a stream resource (in-memory / php://temp / php://memory)
   */
  function poado_drive_upload_stream(string $parentId, string $name, string $mime, $stream): array {
    if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
      throw new RuntimeException("drive_upload_stream requires a stream resource");
    }
    $bytes = stream_get_contents($stream);
    if ($bytes === false) throw new RuntimeException("Failed reading stream bytes");
    return poado_drive_upload_bytes($parentId, $name, $mime, $bytes);
  }
}

if (!function_exists('poado_drive_delete')) {
  function poado_drive_delete(string $fileId): bool {
    $drive = poado_drive_client();
    try {
      $drive->files->delete($fileId, ['supportsAllDrives' => true]);
      return true;
    } catch (\Throwable $e) {
      poado_drive_log('drive_delete failed', ['file_id' => $fileId, 'err' => $e->getMessage()]);
      return false;
    }
  }
}