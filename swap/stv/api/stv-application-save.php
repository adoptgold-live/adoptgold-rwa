<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/swap/stv/api/stv-application-save.php
 *
 * STV draft save endpoint
 * - saves Personal / Business draft choice
 * - saves selected eligible minted cert_uids
 * - updates preapproved_total from selected NFTs
 * - creates draft application if needed
 * - uses locked STV DB schema only
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

header('Content-Type: application/json; charset=utf-8');

function stv_save_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function stv_save_pdo(): ?PDO
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
        } catch (Throwable $e) {}
    }

    if (function_exists('db')) {
        try {
            $pdo = db();
            if ($pdo instanceof PDO) {
                $GLOBALS['pdo'] = $pdo;
                return $pdo;
            }
        } catch (Throwable $e) {}
    }

    if (function_exists('rwa_db')) {
        try {
            $pdo = rwa_db();
            if ($pdo instanceof PDO) {
                $GLOBALS['pdo'] = $pdo;
                return $pdo;
            }
        } catch (Throwable $e) {}
    }

    return null;
}

function stv_save_seed_user(): ?array
{
    if (function_exists('rwa_current_user')) {
        try {
            $u = rwa_current_user();
            if (is_array($u) && !empty($u)) return $u;
        } catch (Throwable $e) {}
    }

    if (function_exists('rwa_session_user')) {
        try {
            $u = rwa_session_user();
            if (is_array($u) && !empty($u)) return $u;
        } catch (Throwable $e) {}
    }

    if (function_exists('get_wallet_session')) {
        try {
            $u = get_wallet_session();
            if (is_array($u) && !empty($u)) return $u;
            if (is_string($u) && trim($u) !== '') return ['wallet' => trim($u)];
        } catch (Throwable $e) {}
    }

    return null;
}

function stv_save_hydrate_user(?array $seed, PDO $pdo): ?array
{
    if (!$seed || !is_array($seed)) return null;

    $userId = (int)($seed['id'] ?? 0);
    $wallet = trim((string)($seed['wallet'] ?? ''));
    $walletAddress = trim((string)($seed['wallet_address'] ?? ''));

    $sql = "
        SELECT id, wallet, wallet_address, nickname, email, role, is_active, is_fully_verified
        FROM users
    ";

    try {
        if ($userId > 0) {
            $stmt = $pdo->prepare($sql . " WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && !empty($row)) return $row;
        }

        if ($walletAddress !== '') {
            $stmt = $pdo->prepare($sql . " WHERE wallet_address = ? LIMIT 1");
            $stmt->execute([$walletAddress]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && !empty($row)) return $row;
        }

        if ($wallet !== '') {
            $stmt = $pdo->prepare($sql . " WHERE wallet = ? LIMIT 1");
            $stmt->execute([$wallet]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && !empty($row)) return $row;
        }
    } catch (Throwable $e) {}

    return null;
}

function stv_save_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];

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

function stv_save_require_tables(PDO $pdo): array
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
        'poado_rwa_certs' => [
            'cert_uid',
            'owner_user_id',
            'rwa_code',
            'price_units',
            'nft_item_address',
            'nft_minted',
            'status',
            'revoked_at',
        ],
    ];

    $missing = [];
    foreach ($requirements as $table => $cols) {
        foreach ($cols as $col) {
            if (!stv_save_has_column($pdo, $table, $col)) {
                $missing[$table][] = $col;
            }
        }
    }
    return $missing;
}

function stv_save_normalize_type(string $type): string
{
    $type = strtolower(trim($type));
    return in_array($type, ['personal', 'business'], true) ? $type : '';
}

function stv_save_normalize_subtype(string $type, string $subtype): string
{
    $subtype = strtolower(trim($subtype));

    if ($type === 'personal' && in_array($subtype, ['salary_earner', 'self_employed'], true)) {
        return $subtype;
    }

    if ($type === 'business') {
        return 'company';
    }

    return '';
}

function stv_save_make_uid(string $type): string
{
    return strtoupper($type) . '-STV-' . gmdate('YmdHis') . '-' . substr(bin2hex(random_bytes(16)), 0, 8);
}

function stv_save_cert_value(array $row): int
{
    $rwaCode = strtoupper(trim((string)($row['rwa_code'] ?? '')));
    $priceUnitsRaw = $row['price_units'] ?? null;

    if ($priceUnitsRaw !== null && $priceUnitsRaw !== '') {
        $units = (float)preg_replace('/[^0-9.\-]/', '', (string)$priceUnitsRaw);
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

function stv_save_load_valid_certs(PDO $pdo, int $userId, array $certUids): array
{
    if (empty($certUids)) return [];

    $placeholders = implode(',', array_fill(0, count($certUids), '?'));
    $sql = "
        SELECT cert_uid, rwa_code, price_units, nft_item_address, nft_minted, status, revoked_at
        FROM poado_rwa_certs
        WHERE owner_user_id = ?
          AND cert_uid IN ($placeholders)
          AND nft_item_address IS NOT NULL
          AND nft_item_address <> ''
          AND revoked_at IS NULL
          AND (nft_minted = 1 OR status = 'minted')
    ";

    $params = array_merge([$userId], array_values($certUids));
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $map = [];
    foreach ($rows as $row) {
        $map[(string)$row['cert_uid']] = $row;
    }
    return $map;
}

function stv_save_load_application(PDO $pdo, string $applicationUid, int $userId): ?array
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

$pdo = stv_save_pdo();
if (!$pdo) {
    stv_save_json(['ok' => false, 'error' => 'DB_NOT_READY'], 500);
}

$user = stv_save_hydrate_user(stv_save_seed_user(), $pdo);
if (!$user || (int)($user['id'] ?? 0) <= 0) {
    stv_save_json(['ok' => false, 'error' => 'AUTH_REQUIRED'], 401);
}

$schemaMissing = stv_save_require_tables($pdo);
if (!empty($schemaMissing)) {
    stv_save_json([
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
$stvType = stv_save_normalize_type((string)($data['stv_type'] ?? ''));
$subtype = stv_save_normalize_subtype($stvType, (string)($data['subtype'] ?? ''));
$selectedCertUids = $data['selected_cert_uids'] ?? [];

if ($stvType === '') {
    stv_save_json(['ok' => false, 'error' => 'STV_TYPE_REQUIRED'], 422);
}
if ($subtype === '') {
    stv_save_json(['ok' => false, 'error' => 'SUBTYPE_REQUIRED'], 422);
}
if (!is_array($selectedCertUids)) {
    stv_save_json(['ok' => false, 'error' => 'SELECTED_CERT_UIDS_INVALID'], 422);
}

$selectedCertUids = array_values(array_unique(array_filter(array_map(static function ($v) {
    return trim((string)$v);
}, $selectedCertUids), static function ($v) {
    return $v !== '';
})));

if (empty($selectedCertUids)) {
    stv_save_json(['ok' => false, 'error' => 'SELECTED_CERT_UIDS_REQUIRED'], 422);
}

$userId = (int)$user['id'];
$validCertMap = stv_save_load_valid_certs($pdo, $userId, $selectedCertUids);

$invalid = [];
$selectedItems = [];
$total = 0;

foreach ($selectedCertUids as $uid) {
    if (!isset($validCertMap[$uid])) {
        $invalid[] = $uid;
        continue;
    }
    $row = $validCertMap[$uid];
    $value = stv_save_cert_value($row);
    if ($value <= 0) {
        $invalid[] = $uid;
        continue;
    }

    $selectedItems[] = [
        'cert_uid' => (string)$uid,
        'rwa_code' => (string)($row['rwa_code'] ?? ''),
        'stv_value' => $value,
        'stv_rwae_display' => 'RWA€ ' . number_format($value, 0, '.', ','),
    ];
    $total += $value;
}

if (!empty($invalid)) {
    stv_save_json([
        'ok' => false,
        'error' => 'SELECTED_CERT_UIDS_INVALID_OR_NOT_MINTED',
        'invalid_cert_uids' => $invalid,
    ], 422);
}

$approved = (int)floor($total * 0.75);
$locked = (int)($total - $approved);

try {
    $pdo->beginTransaction();

    if ($applicationUid !== '') {
        $existing = stv_save_load_application($pdo, $applicationUid, $userId);
        if (!$existing) {
            $pdo->rollBack();
            stv_save_json(['ok' => false, 'error' => 'APPLICATION_NOT_FOUND'], 404);
        }

        $stmt = $pdo->prepare("
            UPDATE poado_stv_applications
            SET
                stv_type = :stv_type,
                subtype = :subtype,
                status = 'draft',
                preapproved_total = :preapproved_total,
                selected_cert_uids_json = :selected_cert_uids_json,
                updated_at = NOW()
            WHERE application_uid = :application_uid
              AND user_id = :user_id
            LIMIT 1
        ");
        $stmt->execute([
            ':stv_type' => $stvType,
            ':subtype' => $subtype,
            ':preapproved_total' => $total,
            ':selected_cert_uids_json' => json_encode($selectedCertUids, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':application_uid' => $applicationUid,
            ':user_id' => $userId,
        ]);
    } else {
        $applicationUid = stv_save_make_uid($stvType);

        $stmt = $pdo->prepare("
            INSERT INTO poado_stv_applications
            (
                application_uid,
                user_id,
                stv_type,
                subtype,
                status,
                preapproved_total,
                selected_cert_uids_json,
                created_at,
                updated_at
            )
            VALUES
            (
                :application_uid,
                :user_id,
                :stv_type,
                :subtype,
                'draft',
                :preapproved_total,
                :selected_cert_uids_json,
                NOW(),
                NOW()
            )
        ");
        $stmt->execute([
            ':application_uid' => $applicationUid,
            ':user_id' => $userId,
            ':stv_type' => $stvType,
            ':subtype' => $subtype,
            ':preapproved_total' => $total,
            ':selected_cert_uids_json' => json_encode($selectedCertUids, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    $pdo->commit();

    stv_save_json([
        'ok' => true,
        'application_uid' => $applicationUid,
        'status' => 'draft',
        'stv_type' => $stvType,
        'subtype' => $subtype,
        'selected_cert_uids' => $selectedCertUids,
        'selected_items' => $selectedItems,
        'total_stv' => $total,
        'approved_stv' => $approved,
        'locked_stv' => $locked,
        'total_rwae_display' => 'RWA€ ' . number_format($total, 0, '.', ','),
        'approved_display' => '0 / ' . number_format($approved, 0, '.', ','),
        'locked_rwae_display' => 'RWA€ ' . number_format($locked, 0, '.', ','),
        'ts' => time(),
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    stv_save_json([
        'ok' => false,
        'error' => 'STV_DRAFT_SAVE_FAILED',
        'message' => $e->getMessage(),
    ], 500);
}
