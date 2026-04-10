<?php
declare(strict_types=1);

require_once '/var/www/html/public/rwa/inc/core/bootstrap.php';
require_once '/var/www/html/public/rwa/cert/api/_cert-cdn.php';

function cdn_sync_db(): PDO
{
    return function_exists('db') ? db() : new PDO(
        "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']};charset=" . ($_ENV['DB_CHARSET'] ?? 'utf8mb4'),
        $_ENV['DB_USER'] ?? '',
        $_ENV['DB_PASS'] ?? '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}

function cdn_sync_meta_decode(string $json): array
{
    $json = trim($json);
    if ($json === '') return [];
    try {
        $d = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return is_array($d) ? $d : [];
    } catch (Throwable) {
        return [];
    }
}

echo "[CDN-SYNC] START " . date('c') . PHP_EOL;

$pdo = cdn_sync_db();
$st = $pdo->query("
    SELECT id, cert_uid, rwa_code, rwa_type, family, owner_user_id, status, issued_at, minted_at,
           meta_json, price_wems, fingerprint_hash, router_tx_hash, nft_item_address, nft_minted
    FROM poado_rwa_certs
    WHERE status IN ('issued','minted')
    ORDER BY id DESC
    LIMIT 100
");

foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $row['meta_json_decoded'] = cdn_sync_meta_decode((string)($row['meta_json'] ?? ''));
    $version = (int)($row['meta_json_decoded']['artifacts']['version'] ?? 1);

    try {
        $r = cert_cdn_publish_all($row, $version);
        echo "[CDN] " . $row['cert_uid'] . " => " . (($r['ok'] ?? false) ? 'OK' : 'FAIL') . PHP_EOL;
    } catch (Throwable $e) {
        error_log('[CDN-SYNC-FAIL] ' . ($row['cert_uid'] ?? '') . ' ' . $e->getMessage());
        echo "[CDN] " . $row['cert_uid'] . " => FAIL " . $e->getMessage() . PHP_EOL;
    }
}

echo "[CDN-SYNC] DONE " . date('c') . PHP_EOL;
