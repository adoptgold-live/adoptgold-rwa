<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/swap/stv/api/stv-apply.php
 *
 * STV apply precheck / prepare endpoint
 * - validates selected minted RWA Cert NFTs
 * - validates application ownership
 * - reads locked STV DB tables only
 * - does not upload files
 * - does not finalize submit
 * - returns prepared STV split:
 *     approved_stv = floor(preapproved_total * 0.75)
 *     locked_stv   = preapproved_total - approved_stv
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

header('Content-Type: application/json; charset=utf-8');

function stv_apply_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function stv_apply_pdo(): ?PDO
{
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $GLOBALS['pdo'];
    }

    if (function_exists('db_connect')) {
        try {
            db_connect();
            if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
                return $GLOBALS['pdo'];
            }
        } catch (Throwable $e) {
        }
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

function stv_apply_seed_user(): ?array
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

function stv_apply_hydrate_user(?array $seed, PDO $pdo): ?array
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

function stv_apply_has_column(PDO $pdo, string $table, string $column): bool
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

function stv_apply_require_tables(PDO $pdo): array
{
    $requirements = [
        'poado_stv_applications' => [
            'id',
            'application_uid',
            'user_id',
            'stv_type',
            'subtype',
            'status',
            'preapproved_total',
            'selected_cert_uids_json',
            'created_at',
            'updated_at',
        ],
        'poado_stv_application_files' => [
            'id',
            'application_uid',
            'user_id',
            'doc_key',
            'original_name',
            'mime_type',
            'file_size',
            'google_drive_file_id',
            'google_drive_url',
            'uploaded_at',
        ],
        'poado_rwa_certs' => [
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
            'verify_url',
        ],
    ];

    $missing = [];
    foreach ($requirements as $table => $cols) {
      foreach ($cols as $col) {
        if (!stv_apply_has_column($pdo, $table, $col)) {
            $missing[$table][] = $col;
        }
      }
    }
    return $missing;
}

function stv_apply_cert_value(array $row): int
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

function stv_apply_load_application(PDO $pdo, string $applicationUid, int $userId): ?array
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM poado_stv_applications
        WHERE application_uid = ?
          AND user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$applicationUid, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) && !empty($row) ? $row : null;
}

function stv_apply_uploaded_doc_keys(PDO $pdo, string $applicationUid, int $userId): array
{
    $stmt = $pdo->prepare("
        SELECT DISTINCT doc_key
        FROM poado_stv_application_files
        WHERE application_uid = ?
          AND user_id = ?
        ORDER BY doc_key ASC
    ");
    $stmt->execute([$applicationUid, $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    return array_values(array_map('strval', $rows));
}

function stv_apply_load_valid_certs(PDO $pdo, int $userId, array $certUids): array
{
    if (empty($certUids)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($certUids), '?'));
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
            minted_at,
            verify_url
        FROM poado_rwa_certs
        WHERE owner_user_id = ?
          AND cert_uid IN ($placeholders)
          AND nft_item_address IS NOT NULL
          AND nft_item_address <> ''
          AND revoked_at IS NULL
          AND (
                nft_minted = 1
                OR status = 'minted'
              )
    ";

    $params = array_merge([$userId], array_values($certUids));
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $out = [];
    foreach ($rows as $row) {
        $out[(string)$row['cert_uid']] = $row;
    }
    return $out;
}

$pdo = stv_apply_pdo();
if (!$pdo) {
    stv_apply_json(['ok' => false, 'error' => 'DB_NOT_READY'], 500);
}

$user = stv_apply_hydrate_user(stv_apply_seed_user(), $pdo);
if (!$user || (int)($user['id'] ?? 0) <= 0) {
    stv_apply_json(['ok' => false, 'error' => 'AUTH_REQUIRED'], 401);
}

$schemaMissing = stv_apply_require_tables($pdo);
if (!empty($schemaMissing)) {
    stv_apply_json([
        'ok' => false,
        'error' => 'STV_SCHEMA_MISMATCH',
        'missing' => $schemaMissing,
    ], 500);
}

$raw = file_get_contents('php://input');
$data = json_decode((string)$raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

$applicationUid = trim((string)($data['application_uid'] ?? ''));
$selectedCertUids = $data['selected_cert_uids'] ?? [];

if ($applicationUid === '') {
    stv_apply_json(['ok' => false, 'error' => 'APPLICATION_UID_REQUIRED'], 422);
}
if (!is_array($selectedCertUids)) {
    stv_apply_json(['ok' => false, 'error' => 'SELECTED_CERT_UIDS_INVALID'], 422);
}

$selectedCertUids = array_values(array_unique(array_filter(array_map(static function ($v) {
    return trim((string)$v);
}, $selectedCertUids), static function ($v) {
    return $v !== '';
})));

if (empty($selectedCertUids)) {
    stv_apply_json(['ok' => false, 'error' => 'SELECTED_CERT_UIDS_REQUIRED'], 422);
}

$userId = (int)$user['id'];
$app = stv_apply_load_application($pdo, $applicationUid, $userId);
if (!$app) {
    stv_apply_json(['ok' => false, 'error' => 'APPLICATION_NOT_FOUND'], 404);
}

$validCertMap = stv_apply_load_valid_certs($pdo, $userId, $selectedCertUids);
$invalid = [];
$preparedItems = [];
$preparedTotal = 0;

foreach ($selectedCertUids as $uid) {
    if (!isset($validCertMap[$uid])) {
        $invalid[] = $uid;
        continue;
    }

    $row = $validCertMap[$uid];
    $stvValue = stv_apply_cert_value($row);
    if ($stvValue <= 0) {
        $invalid[] = $uid;
        continue;
    }

    $preparedItems[] = [
        'cert_uid' => (string)$row['cert_uid'],
        'rwa_type' => (string)($row['rwa_type'] ?? ''),
        'family' => (string)($row['family'] ?? ''),
        'rwa_code' => (string)($row['rwa_code'] ?? ''),
        'status' => (string)($row['status'] ?? ''),
        'nft_item_address' => (string)($row['nft_item_address'] ?? ''),
        'minted_at' => (string)($row['minted_at'] ?? ''),
        'verify_url' => (string)($row['verify_url'] ?? ''),
        'stv_value' => $stvValue,
        'stv_value_display' => number_format($stvValue, 0, '.', ','),
    ];
    $preparedTotal += $stvValue;
}

if (!empty($invalid)) {
    stv_apply_json([
        'ok' => false,
        'error' => 'SELECTED_CERT_UIDS_INVALID_OR_NOT_MINTED',
        'invalid_cert_uids' => $invalid,
    ], 422);
}

$approved = (int)floor($preparedTotal * 0.75);
$locked = (int)($preparedTotal - $approved);
$uploadedDocKeys = stv_apply_uploaded_doc_keys($pdo, $applicationUid, $userId);

stv_apply_json([
    'ok' => true,
    'application_uid' => $applicationUid,
    'user_id' => $userId,
    'stv_type' => (string)($app['stv_type'] ?? ''),
    'subtype' => (string)($app['subtype'] ?? ''),
    'status' => (string)($app['status'] ?? 'draft'),
    'selected_cert_uids' => $selectedCertUids,
    'uploaded_doc_keys' => $uploadedDocKeys,
    'prepared' => [
        'total_stv' => $preparedTotal,
        'total_stv_display' => number_format($preparedTotal, 0, '.', ','),
        'approved_stv' => $approved,
        'approved_stv_display' => number_format($approved, 0, '.', ','),
        'locked_stv' => $locked,
        'locked_stv_display' => number_format($locked, 0, '.', ','),
        'items' => $preparedItems,
    ],
    'rule_note' => 'Only Minted RWA Cert NFTs are accepted for STV.',
    'ts' => time(),
]);
