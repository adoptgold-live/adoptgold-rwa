<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cron/normalize-cert-ton-wallets.php
 * Version: v1.1.0-20260330-raw-safe-friendly-unify
 *
 * Purpose:
 * - normalize poado_rwa_certs.ton_wallet toward friendly TON format when available
 * - tolerate raw TON format when friendly conversion library is unavailable
 * - preserve raw form in meta_json.owner_wallet_raw
 * - preserve friendly form in meta_json.owner_wallet_friendly when available
 *
 * Safe usage:
 * php /var/www/html/public/rwa/cron/normalize-cert-ton-wallets.php
 *
 * Locked behavior:
 * - do NOT hard-fail raw 0:... wallets only because friendly conversion is unavailable
 * - prefer friendly when available
 * - otherwise keep/use raw as the usable target wallet
 */

require_once __DIR__ . '/../inc/core/bootstrap.php';
require_once __DIR__ . '/../inc/core/ton-address.php';

$pdo = function_exists('rwa_db') ? rwa_db() : ($GLOBALS['pdo'] ?? null);
if (!$pdo instanceof PDO) {
    fwrite(STDERR, "DB not ready\n");
    exit(1);
}

function n_json_decode(?string $json): array
{
    $json = trim((string)$json);
    if ($json === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function n_json_encode(array $data): string
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('JSON_ENCODE_FAILED');
    }
    return $json;
}

$rows = $pdo->query("
    SELECT id, cert_uid, ton_wallet, meta_json
    FROM poado_rwa_certs
    WHERE ton_wallet IS NOT NULL
      AND TRIM(ton_wallet) <> ''
    ORDER BY id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$updated = 0;
$skipped = 0;
$failed = 0;

$up = $pdo->prepare("
    UPDATE poado_rwa_certs
    SET ton_wallet = :ton_wallet,
        meta_json = :meta_json,
        updated_at = NOW()
    WHERE id = :id
    LIMIT 1
");

foreach ($rows as $row) {
    $certUid = trim((string)($row['cert_uid'] ?? ''));
    $input = trim((string)($row['ton_wallet'] ?? ''));

    if ($input === '') {
        $failed++;
        echo "[FAIL] {$certUid} :: empty ton_wallet" . PHP_EOL;
        continue;
    }

    $norm = poado_ton_address_normalize($input);

    if (empty($norm['ok'])) {
        $failed++;
        echo "[FAIL] {$certUid} :: {$input} :: " . trim((string)($norm['error'] ?? 'UNKNOWN')) . PHP_EOL;
        continue;
    }

    $friendly = trim((string)($norm['friendly'] ?? ''));
    $raw = trim((string)($norm['raw'] ?? ''));
    $targetWallet = $friendly !== '' ? $friendly : ($raw !== '' ? $raw : '');

    if ($targetWallet === '') {
        $failed++;
        echo "[FAIL] {$certUid} :: no usable normalized wallet" . PHP_EOL;
        continue;
    }

    $meta = n_json_decode((string)($row['meta_json'] ?? ''));
    $currentFriendlyMeta = trim((string)($meta['owner_wallet_friendly'] ?? ''));
    $currentRawMeta = trim((string)($meta['owner_wallet_raw'] ?? ''));

    if ($friendly !== '') {
        $meta['owner_wallet_friendly'] = $friendly;
    }
    if ($raw !== '') {
        $meta['owner_wallet_raw'] = $raw;
    }

    $alreadySynced =
        $input === $targetWallet
        && (($friendly === '') || ($currentFriendlyMeta === $friendly))
        && (($raw === '') || ($currentRawMeta === $raw));

    if ($alreadySynced) {
        $skipped++;
        echo "[SKIP] {$certUid} :: already normalized/synced" . PHP_EOL;
        continue;
    }

    try {
        $up->execute([
            ':ton_wallet' => $targetWallet,
            ':meta_json' => n_json_encode($meta),
            ':id' => (int)$row['id'],
        ]);

        $updated++;

        $mode = $friendly !== '' ? 'friendly' : 'raw-fallback';
        echo "[OK] {$certUid} :: {$input} -> {$targetWallet} :: {$mode}" . PHP_EOL;
    } catch (Throwable $e) {
        $failed++;
        echo "[FAIL] {$certUid} :: DB update failed :: " . $e->getMessage() . PHP_EOL;
    }
}

echo PHP_EOL;
echo "Updated: {$updated}" . PHP_EOL;
echo "Skipped: {$skipped}" . PHP_EOL;
echo "Failed : {$failed}" . PHP_EOL;