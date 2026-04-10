<?php
declare(strict_types=1);

/**
 * STV Eligible NFTs API
 * - Only Minted RWA Cert NFTs
 * - Returns UI-ready card data
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

header('Content-Type: application/json; charset=utf-8');

function stv_json($payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function db_pdo(): ?PDO {
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $GLOBALS['pdo'];
    }
    if (function_exists('db')) return db();
    if (function_exists('rwa_db')) return rwa_db();
    return null;
}

function current_user(PDO $pdo): ?array {
    if (function_exists('rwa_current_user')) {
        $u = rwa_current_user();
        if (!empty($u['id'])) return $u;
    }

    if (function_exists('get_wallet_session')) {
        $wallet = get_wallet_session();
        if (is_string($wallet) && $wallet !== '') {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE wallet = ? LIMIT 1");
            $stmt->execute([$wallet]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }

    return null;
}

function rwae(int $n): string {
    return 'RWA€ ' . number_format($n, 0, '.', ',');
}

function cert_value(array $row): int {
    $rwaCode = strtoupper(trim((string)($row['rwa_code'] ?? '')));
    $priceUnits = $row['price_units'] ?? null;

    if ($priceUnits !== null && $priceUnits !== '') {
        $units = (float)preg_replace('/[^0-9.\-]/', '', (string)$priceUnits);
        if ($units > 0) return (int)floor($units);
    }

    $map = [
        'RCO2C-EMA'  => 1000,
        'RH2O-EMA'   => 5000,
        'RBLACK-EMA' => 10000,
        'RK92-EMA'   => 50000,
        'RLIFE-EMA'  => 100,
        'RTRIP-EMA'  => 100,
        'RPROP-EMA'  => 100,
        'RHRD-EMA'   => 100,
    ];

    return (int)($map[$rwaCode] ?? 0);
}

$pdo = db_pdo();
if (!$pdo) {
    stv_json(['ok'=>false,'error'=>'DB_NOT_READY'],500);
}

$user = current_user($pdo);
if (!$user) {
    stv_json(['ok'=>false,'error'=>'AUTH_REQUIRED'],401);
}

$userId = (int)$user['id'];

$sql = "
SELECT
    cert_uid,
    rwa_type,
    family,
    rwa_code,
    price_units,
    nft_item_address,
    nft_minted,
    status,
    minted_at
FROM poado_rwa_certs
WHERE owner_user_id = :uid
  AND nft_item_address IS NOT NULL
  AND nft_item_address <> ''
  AND (
        nft_minted = 1
        OR status = 'minted'
      )
ORDER BY minted_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute([':uid'=>$userId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$list = [];

foreach ($rows as $row) {
    $value = cert_value($row);
    if ($value <= 0) continue;

    $certUid = (string)$row['cert_uid'];

    $list[] = [
        'cert_uid' => $certUid,
        'rwa_code' => (string)$row['rwa_code'],
        'rwa_type' => (string)$row['rwa_type'],
        'family' => (string)$row['family'],
        'nft_item_address' => (string)$row['nft_item_address'],

        'stv_value' => $value,
        'stv_rwae_display' => rwae($value),

        'minted_at' => (string)$row['minted_at'],
        'status' => (string)$row['status'],

        // UI helpers
        'title' => $certUid,
        'subtitle' => (string)$row['rwa_code'],
        'badge' => 'Minted',
        'action_label' => 'Select',
    ];
}

stv_json([
    'ok' => true,
    'count' => count($list),
    'items' => $list,
]);
