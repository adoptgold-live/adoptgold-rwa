<?php
/**
 * /dashboard/inc/gdrive-user.php
 * v1.0.20260226
 * Google Drive user token storage + helpers (Option A, Composer google/apiclient)
 *
 * Requires: /dashboard/inc/env.php + DB (via bootstrap or db_connect)
 * Stores refresh/access tokens encrypted with APP_SECRET.
 */

declare(strict_types=1);

if (!function_exists('poado_env')) {
  // env.php must be loaded by caller; fail loudly if not.
  throw new RuntimeException('env.php not loaded: poado_env() missing');
}

if (!function_exists('db_connect')) {
  throw new RuntimeException('DB not available: db_connect() missing');
}

function poado_gdrive_app_secret(): string {
  $s = (string)poado_env('APP_SECRET', '');
  if (strlen($s) < 16) throw new RuntimeException('APP_SECRET missing/too short');
  return $s;
}

function poado_gdrive_encrypt(string $plain): string {
  if ($plain === '') return '';
  $key = hash('sha256', poado_gdrive_app_secret(), true);
  $iv = random_bytes(12);
  $tag = '';
  $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
  if ($ct === false) throw new RuntimeException('encrypt failed');
  return rtrim(strtr(base64_encode($iv . $tag . $ct), '+/', '-_'), '=');
}

function poado_gdrive_decrypt(string $enc): string {
  if ($enc === '') return '';
  $raw = base64_decode(strtr($enc, '-_', '+/'), true);
  if ($raw === false || strlen($raw) < 12 + 16 + 1) return '';
  $iv = substr($raw, 0, 12);
  $tag = substr($raw, 12, 16);
  $ct = substr($raw, 28);
  $key = hash('sha256', poado_gdrive_app_secret(), true);
  $pt = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
  return ($pt === false) ? '' : $pt;
}

/**
 * Table contract (locked names used by tester):
 * - poado_user_gdrive
 * - poado_rwa_cert_drive_copies
 * - poado_drive_logs
 */

function poado_gdrive_get_user(int $userId): ?array {
  $pdo = db_connect();
  $st = $pdo->prepare("SELECT * FROM poado_user_gdrive WHERE user_id=? LIMIT 1");
  $st->execute([$userId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) return null;

  // decrypt on-demand fields (do NOT expose refresh token to browser)
  $row['_access_token'] = poado_gdrive_decrypt((string)($row['access_token_enc'] ?? ''));
  $row['_refresh_token'] = poado_gdrive_decrypt((string)($row['refresh_token_enc'] ?? ''));
  return $row;
}

function poado_gdrive_upsert_user(int $userId, array $data): void {
  $pdo = db_connect();

  $email = (string)($data['email'] ?? '');
  $googleUserId = (string)($data['google_user_id'] ?? '');
  $scope = (string)($data['scope'] ?? '');
  $expiresAt = (int)($data['expires_at'] ?? 0);

  $access = (string)($data['access_token'] ?? '');
  $refresh = (string)($data['refresh_token'] ?? '');

  $accessEnc = poado_gdrive_encrypt($access);
  $refreshEnc = $refresh !== '' ? poado_gdrive_encrypt($refresh) : '';

  // keep old refresh if not provided
  $existing = poado_gdrive_get_user($userId);
  if ($existing && $refreshEnc === '') {
    $refreshEnc = (string)($existing['refresh_token_enc'] ?? '');
  }

  $sql = "
    INSERT INTO poado_user_gdrive
      (user_id, google_user_id, email, scope, access_token_enc, refresh_token_enc, expires_at, is_active, updated_at, created_at)
    VALUES
      (:user_id, :google_user_id, :email, :scope, :access_token_enc, :refresh_token_enc, FROM_UNIXTIME(:expires_at), 1, NOW(), NOW())
    ON DUPLICATE KEY UPDATE
      google_user_id=VALUES(google_user_id),
      email=VALUES(email),
      scope=VALUES(scope),
      access_token_enc=VALUES(access_token_enc),
      refresh_token_enc=VALUES(refresh_token_enc),
      expires_at=VALUES(expires_at),
      is_active=1,
      updated_at=NOW()
  ";
  $st = $pdo->prepare($sql);
  $st->execute([
    ':user_id' => $userId,
    ':google_user_id' => $googleUserId,
    ':email' => $email,
    ':scope' => $scope,
    ':access_token_enc' => $accessEnc,
    ':refresh_token_enc' => $refreshEnc,
    ':expires_at' => $expiresAt,
  ]);
}

function poado_gdrive_set_folder(int $userId, string $folderId, string $folderName): void {
  $pdo = db_connect();
  $st = $pdo->prepare("UPDATE poado_user_gdrive SET folder_id=?, folder_name=?, updated_at=NOW() WHERE user_id=?");
  $st->execute([$folderId, $folderName, $userId]);
}

function poado_gdrive_log(int $userId, string $action, bool $ok, string $message = '', array $meta = []): void {
  $pdo = db_connect();
  $st = $pdo->prepare("INSERT INTO poado_drive_logs (user_id, action, ok, message, meta, created_at) VALUES (?,?,?,?,?,NOW())");
  $st->execute([$userId, $action, $ok ? 1 : 0, $message, json_encode($meta, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
}

function poado_gdrive_require_connected(int $userId): array {
  $u = poado_gdrive_get_user($userId);
  if (!$u || (int)($u['is_active'] ?? 0) !== 1) {
    throw new RuntimeException('Drive not connected');
  }
  return $u;
}