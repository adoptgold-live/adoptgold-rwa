<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/cron/drive-sync.php
 * Version: v1.0.0-20260404-drive-sync-worker
 *
 * LOCK
 * - retries Drive replication for certs with local artifacts
 * - does not generate artifacts
 * - does not change issuance truth
 */

require_once '/var/www/html/public/rwa/inc/core/bootstrap.php';
require_once '/var/www/html/public/rwa/cert/api/_drive-upload.php';

const DRIVE_SYNC_VERSION = 'v1.0.0-20260404-drive-sync-worker';

function drive_sync_db(): PDO
{
    if (function_exists('db')) {
        $pdo = db();
        if ($pdo instanceof PDO) {
            return $pdo;
        }
    }

    $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
    $name = $_ENV['DB_NAME'] ?? 'wems_db';
    $user = $_ENV['DB_USER'] ?? '';
    $pass = $_ENV['DB_PASS'] ?? '';
    $charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';

    $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function drive_sync_meta_decode(string $json): array
{
    $json = trim($json);
    if ($json === '') {
        return [];
    }
    try {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    } catch (Throwable) {
        return [];
    }
}

function drive_sync_detect_version(array $row): int
{
    $meta = $row['meta_json_decoded'] ?? [];
    $candidates = [
        (int)($meta['artifacts']['version'] ?? 0),
        (int)($meta['version'] ?? 0),
        1,
    ];
    foreach ($candidates as $v) {
        if ($v > 0) {
            return $v;
        }
    }
    return 1;
}

function drive_sync_rows(PDO $pdo): array
{
    $sql = "
        SELECT
            id,
            cert_uid,
            rwa_code,
            family,
            owner_user_id,
            status,
            meta_json
        FROM poado_rwa_certs
        WHERE status IN ('issued', 'minted')
        ORDER BY id DESC
        LIMIT 100
    ";

    $st = $pdo->query($sql);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$row) {
        $row['meta_json_decoded'] = drive_sync_meta_decode((string)($row['meta_json'] ?? ''));
    }
    unset($row);

    return $rows;
}

echo "[DRIVE-SYNC] START " . date('c') . PHP_EOL;

try {
    $pdo = drive_sync_db();
    $rows = drive_sync_rows($pdo);

    foreach ($rows as $row) {
        $uid = (string)($row['cert_uid'] ?? '');
        if ($uid === '') {
            echo "[SYNC] SKIP EMPTY_UID" . PHP_EOL;
            continue;
        }

        $version = drive_sync_detect_version($row);
        $res = cert_drive_upload_all($row, $version);

        echo "[SYNC] {$uid} => " . (($res['ok'] ?? false) ? 'OK' : 'FAIL') . PHP_EOL;

        if (!($res['ok'] ?? false)) {
            error_log('[DRIVE-SYNC-FAIL] ' . $uid . ' ' . json_encode($res));
        }
    }

    echo "[DRIVE-SYNC] DONE " . date('c') . PHP_EOL;
} catch (Throwable $e) {
    error_log('[DRIVE-SYNC-FATAL] ' . $e->getMessage());
    echo "[DRIVE-SYNC] FATAL " . $e->getMessage() . PHP_EOL;
    exit(1);
}
