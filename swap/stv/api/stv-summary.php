<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/swap/stv/api/stv-summary.php
 * STV Summary API
 *
 * Rule:
 * - STV accepts ONLY minted RWA Cert NFTs
 * - user-scoped by owner_user_id
 * - summary is dashboard-ready
 * - approved_stv = floor(total_stv * 0.75)
 * - locked_stv   = total_stv - approved_stv
 * - used_stv defaults to 0 until a dedicated usage ledger is introduced
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

header('Content-Type: application/json; charset=utf-8');

function stv_summary_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function stv_summary_pdo(): ?PDO
{
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $GLOBALS['pdo'];
    }

    if (function_exists('db')) {
        try {
            $pdo = db();
            if ($pdo instanceof PDO) {
                $GLOBALS['pdo'] = $pdo;
                return $pdo;
            }
        } catch (Throwable $e) {
        }
    }

    if (function_exists('rwa_db')) {
        try {
            $pdo = rwa_db();
            if ($pdo instanceof PDO) {
                $GLOBALS['pdo'] = $pdo;
                return $pdo;
            }
        } catch (Throwable $e) {
        }
    }

    return null;
}

function stv_summary_seed_user(): ?array
{
    if (function_exists('rwa_current_user')) {
        try {
            $u = rwa_current_user();
            if (is_array($u) && !empty($u)) {
                return $u;
            }
        } catch (Throwable $e) {
        }
    }

    if (function_exists('rwa_session_user')) {
        try {
            $u = rwa_session_user();
            if (is_array($u) && !empty($u)) {
                return $u;
            }
        } catch (Throwable $e) {
        }
    }

    if (function_exists('get_wallet_session')) {
        try {
            $u = get_wallet_session();
            if (is_array($u) && !empty($u)) {
                return $u;
            }
            if (is_string($u) && trim($u) !== '') {
                return ['wallet' => trim($u)];
            }
        } catch (Throwable $e) {
        }
    }

    return null;
}

function stv_summary_hydrate_user(?array $seed, PDO $pdo): ?array
{
    if (!$seed || !is_array($seed)) {
        return null;
    }

    $userId = (int)($seed['id'] ?? 0);
    $wallet = trim((string)($seed['wallet'] ?? ''));
    $walletAddress = trim((string)($seed['wallet_address'] ?? ''));

    $sql = "
        SELECT
            id,
            wallet,
            wallet_address,
            nickname,
            email,
            role,
            is_active,
            is_fully_verified
        FROM users
    ";

    try {
        if ($userId > 0) {
            $stmt = $pdo->prepare($sql . " WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && !empty($row)) {
                return $row;
            }
        }

        if ($walletAddress !== '') {
            $stmt = $pdo->prepare($sql . " WHERE wallet_address = ? LIMIT 1");
            $stmt->execute([$walletAddress]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && !empty($row)) {
                return $row;
            }
        }

        if ($wallet !== '') {
            $stmt = $pdo->prepare($sql . " WHERE wallet = ? LIMIT 1");
            $stmt->execute([$wallet]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && !empty($row)) {
                return $row;
            }
        }
    } catch (Throwable $e) {
    }

    return null;
}

function stv_summary_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS c
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);
        $cache[$key] = ((int)$stmt->fetchColumn() > 0);
        return $cache[$key];
    } catch (Throwable $e) {
        $cache[$key] = false;
        return false;
    }
}

function stv_summary_rwae(int $n): string
{
    return 'RWA€ ' . number_format($n, 0, '.', ',');
}

function stv_summary_cert_value(array $row): int
{
    $rwaCode = strtoupper(trim((string)($row['rwa_code'] ?? '')));
    $priceUnitsRaw = $row['price_units'] ?? null;

    if ($priceUnitsRaw !== null && $priceUnitsRaw !== '') {
        $units = (float)preg_replace('/[^0-9.\-]/', '', (string)$priceUnitsRaw);
        if ($units > 0) {
            return (int)floor($units);
        }
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

$pdo = stv_summary_pdo();
if (!$pdo) {
    stv_summary_json([
        'ok' => false,
        'error' => 'DB_NOT_READY',
    ], 500);
}

$user = stv_summary_hydrate_user(stv_summary_seed_user(), $pdo);
if (!$user || (int)($user['id'] ?? 0) <= 0) {
    stv_summary_json([
        'ok' => false,
        'error' => 'AUTH_REQUIRED',
    ], 401);
}

$userId = (int)$user['id'];

$requiredColumns = [
    'cert_uid',
    'rwa_type',
    'family',
    'rwa_code',
    'price_units',
    'owner_user_id',
    'nft_item_address',
    'nft_minted',
    'status',
    'revoked_at',
    'minted_at',
];

$missing = [];
foreach ($requiredColumns as $col) {
    if (!stv_summary_has_column($pdo, 'poado_rwa_certs', $col)) {
        $missing[] = $col;
    }
}

if (!empty($missing)) {
    stv_summary_json([
        'ok' => false,
        'error' => 'CERT_SCHEMA_MISMATCH',
        'missing_columns' => $missing,
    ], 500);
}

$sql = "
    SELECT
        cert_uid,
        rwa_type,
        family,
        rwa_code,
        price_units,
        owner_user_id,
        nft_item_address,
        nft_minted,
        status,
        revoked_at,
        minted_at
    FROM poado_rwa_certs
    WHERE owner_user_id = :owner_user_id
      AND nft_item_address IS NOT NULL
      AND nft_item_address <> ''
      AND revoked_at IS NULL
      AND (
            nft_minted = 1
            OR status = 'minted'
          )
    ORDER BY minted_at DESC, id DESC
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':owner_user_id' => $userId,
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    stv_summary_json([
        'ok' => false,
        'error' => 'CERT_QUERY_FAILED',
        'message' => $e->getMessage(),
    ], 500);
}

$eligibleItems = [];
$totalStv = 0;

foreach ($rows as $row) {
    $stvValue = stv_summary_cert_value($row);
    if ($stvValue <= 0) {
        continue;
    }

    $eligibleItems[] = [
        'cert_uid' => (string)($row['cert_uid'] ?? ''),
        'rwa_type' => (string)($row['rwa_type'] ?? ''),
        'family' => (string)($row['family'] ?? ''),
        'rwa_code' => (string)($row['rwa_code'] ?? ''),
        'nft_item_address' => (string)($row['nft_item_address'] ?? ''),
        'status' => (string)($row['status'] ?? ''),
        'nft_minted' => (int)($row['nft_minted'] ?? 0),
        'minted_at' => (string)($row['minted_at'] ?? ''),
        'stv_value' => $stvValue,
        'stv_rwae_display' => stv_summary_rwae($stvValue),
    ];

    $totalStv += $stvValue;
}

$approvedStv = (int)floor($totalStv * 0.75);
$lockedStv = (int)($totalStv - $approvedStv);

/**
 * Future usage ledger hook:
 * Replace this 0 with real used capacity when a canonical STV usage table is introduced.
 */
$usedStv = 0;

stv_summary_json([
    'ok' => true,
    'scope' => 'minted_rwa_cert_nft_only',
    'user_id' => $userId,

    'eligible_count' => count($eligibleItems),
    'eligible_items' => $eligibleItems,

    'total_stv' => $totalStv,
    'approved_stv' => $approvedStv,
    'locked_stv' => $lockedStv,
    'used_stv' => $usedStv,

    'total_display' => number_format($totalStv, 0, '.', ','),
    'approved_total_display' => number_format($approvedStv, 0, '.', ','),
    'locked_display' => number_format($lockedStv, 0, '.', ','),
    'approved_display' => number_format($usedStv, 0, '.', ',') . ' / ' . number_format($approvedStv, 0, '.', ','),
    'total_rwae_display' => stv_summary_rwae($totalStv),
    'locked_rwae_display' => stv_summary_rwae($lockedStv),

    'status' => 'draft',
    'status_label' => 'Draft',
    'rule_note' => 'Only Minted RWA Cert NFTs are accepted for STV.',
    'ts' => time(),
]);
