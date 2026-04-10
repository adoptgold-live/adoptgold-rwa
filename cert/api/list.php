<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/api/list.php
 * Cert registry list + summary + lifecycle stats
 *
 * Supports:
 * - owner-only registry search
 * - Genesis / Secondary / Tertiary summary
 * - public valid count + weight
 * - lifecycle monitor counts
 * - q search by UID / type / status / prefix-like code
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

if (!function_exists('json_ok')) {
    function json_ok(array $payload = []): void {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(['ok' => true], $payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
if (!function_exists('json_fail')) {
    function json_fail(string $message, int $code = 400, array $extra = []): void {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(['ok' => false, 'error' => $message], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('rwa_db')) {
    json_fail('DB helper not available', 500);
}

$user = function_exists('session_user') ? (session_user() ?: []) : [];
$userId = (int)($user['id'] ?? 0);

if ($userId <= 0) {
    json_fail('Authentication required', 401);
}

$pdo = rwa_db();
$q = trim((string)($_GET['q'] ?? ''));
$family = strtolower(trim((string)($_GET['family'] ?? '')));

$typeMeta = [
    'green' => [
        'group' => 'genesis',
        'label' => 'Green',
        'code'  => 'RCO2C-EMA',
        'weight'=> 1,
    ],
    'gold' => [
        'group' => 'genesis',
        'label' => 'Gold',
        'code'  => 'RK92-EMA',
        'weight'=> 5,
    ],
    'blue' => [
        'group' => 'genesis',
        'label' => 'Blue',
        'code'  => 'RH2O-EMA',
        'weight'=> 2,
    ],
    'black' => [
        'group' => 'genesis',
        'label' => 'Black',
        'code'  => 'RBLACK-EMA',
        'weight'=> 3,
    ],
    'health' => [
        'group' => 'secondary',
        'label' => 'Health',
        'code'  => 'RLIFE-EMA',
        'weight'=> 10,
    ],
    'travel' => [
        'group' => 'secondary',
        'label' => 'Travel',
        'code'  => 'RTRIP-EMA',
        'weight'=> 10,
    ],
    'property' => [
        'group' => 'secondary',
        'label' => 'Property',
        'code'  => 'RPROP-EMA',
        'weight'=> 10,
    ],
    'human_rights' => [
        'group' => 'tertiary',
        'label' => 'Human Rights',
        'code'  => 'RHRD-EMA',
        'weight'=> 7,
    ],
];

$allowedFamilies = ['genesis','secondary','tertiary'];

$where = ['owner_user_id = :uid'];
$params = [':uid' => $userId];

if ($family !== '' && in_array($family, $allowedFamilies, true)) {
    $typeKeys = [];
    foreach ($typeMeta as $typeKey => $meta) {
        if ($meta['group'] === $family) {
            $typeKeys[] = $typeKey;
        }
    }
    if ($typeKeys) {
        $holders = [];
        foreach ($typeKeys as $i => $typeKey) {
            $ph = ':tf' . $i;
            $holders[] = $ph;
            $params[$ph] = $typeKey;
        }
        $where[] = 'rwa_type IN (' . implode(',', $holders) . ')';
    }
}

if ($q !== '') {
    $where[] = '('
        . 'cert_uid LIKE :q '
        . 'OR rwa_type LIKE :q '
        . 'OR status LIKE :q '
        . 'OR nft_item_address LIKE :q '
        . 'OR fingerprint_hash LIKE :q '
        . 'OR router_tx_hash LIKE :q'
        . ')';
    $params[':q'] = '%' . $q . '%';
}

$sql = "
    SELECT
        id,
        cert_uid,
        rwa_type,
        status,
        owner_user_id,
        nft_item_address,
        nft_minted,
        minted_at,
        issued_at,
        paid_at,
        updated_at,
        price_wems,
        price_units,
        fingerprint_hash,
        router_tx_hash
    FROM poado_rwa_certs
    WHERE " . implode(' AND ', $where) . "
    ORDER BY id DESC
    LIMIT 200
";

try {
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    json_fail('Failed to load cert registry', 500, ['detail' => $e->getMessage()]);
}

$items = [];
$summary = [
    'genesis_count' => 0,
    'genesis_weight' => 0,
    'secondary_count' => 0,
    'secondary_weight' => 0,
    'tertiary_count' => 0,
    'tertiary_weight' => 0,
    'public_valid_count' => 0,
    'public_valid_weight' => 0,
];

$statuses = [
    'initiated' => 0,
    'payment_pending' => 0,
    'paid' => 0,
    'mint_pending' => 0,
    'minted' => 0,
    'listed' => 0,
    'revoked' => 0,
];

foreach ($rows as $row) {
    $type = (string)($row['rwa_type'] ?? '');
    $meta = $typeMeta[$type] ?? [
        'group' => 'unknown',
        'label' => ucfirst(str_replace('_', ' ', $type)),
        'code'  => strtoupper($type),
        'weight'=> 0,
    ];

    $group = (string)$meta['group'];
    $weight = (int)$meta['weight'];
    $status = strtolower((string)($row['status'] ?? ''));

    if ($group === 'genesis') {
        $summary['genesis_count']++;
        $summary['genesis_weight'] += $weight;
    } elseif ($group === 'secondary') {
        $summary['secondary_count']++;
        $summary['secondary_weight'] += $weight;
    } elseif ($group === 'tertiary') {
        $summary['tertiary_count']++;
        $summary['tertiary_weight'] += $weight;
    }

    if (array_key_exists($status, $statuses)) {
        $statuses[$status]++;
    }

    if ($status !== 'revoked') {
        $summary['public_valid_count']++;
        $summary['public_valid_weight'] += $weight;
    }

    $items[] = [
        'id' => (int)($row['id'] ?? 0),
        'cert_uid' => (string)($row['cert_uid'] ?? ''),
        'uid' => (string)($row['cert_uid'] ?? ''),
        'type' => $type,
        'family' => ucfirst($group),
        'group' => $group,
        'cert_group' => ucfirst($group),
        'label' => (string)$meta['label'],
        'rwa_code' => (string)$meta['code'],
        'code' => (string)$meta['code'],
        'weight' => $weight,
        'status' => (string)($row['status'] ?? ''),
        'nft_item_address' => (string)($row['nft_item_address'] ?? ''),
        'nft_minted' => (int)($row['nft_minted'] ?? 0),
        'issued_at' => (string)($row['issued_at'] ?? ''),
        'paid_at' => (string)($row['paid_at'] ?? ''),
        'minted_at' => (string)($row['minted_at'] ?? ''),
        'updated_at' => (string)($row['updated_at'] ?? ''),
        'price_wems' => $row['price_wems'],
        'price_units' => (string)($row['price_units'] ?? ''),
        'fingerprint_hash' => (string)($row['fingerprint_hash'] ?? ''),
        'router_tx_hash' => (string)($row['router_tx_hash'] ?? ''),
    ];
}

json_ok([
    'items' => $items,
    'rows' => $items,
    'summary' => $summary,
    'statuses' => $statuses,
    'filters' => [
        'q' => $q,
        'family' => $family,
    ],
    'count' => count($items),
    'ts' => gmdate('c'),
]);
