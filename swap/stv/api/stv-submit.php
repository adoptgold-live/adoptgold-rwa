<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/swap/stv/api/stv-submit.php
 *
 * STV submit endpoint
 * - validates Personal / Business checklist completion
 * - validates selected cert_uids still belong to user and are minted NFTs
 * - updates draft application to submitted
 * - Google Drive file refs already stored by stv-upload.php
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

header('Content-Type: application/json; charset=utf-8');

function stv_submit_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function stv_submit_pdo(): ?PDO
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

function stv_submit_seed_user(): ?array
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

function stv_submit_hydrate_user(?array $seed, PDO $pdo): ?array
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

function stv_submit_has_column(PDO $pdo, string $table, string $column): bool
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

function stv_submit_require_tables(PDO $pdo): array
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
            'google_drive_file_id',
            'google_drive_url',
            'uploaded_at',
        ],
        'poado_rwa_certs' => [
            'cert_uid',
            'owner_user_id',
            'nft_item_address',
            'nft_minted',
            'status',
            'revoked_at',
        ],
    ];

    $missing = [];
    foreach ($requirements as $table => $cols) {
        foreach ($cols as $col) {
            if (!stv_submit_has_column($pdo, $table, $col)) {
                $missing[$table][] = $col;
            }
        }
    }
    return $missing;
}

function stv_submit_allowed_doc_keys(): array
{
    return [
        'personal' => [
            'payslips',
            'epf_recent_year',
            'salary_bank_statements',
            'company_bank_statements_6m',
            'form_b_recent_year',
            'ssm_doc',
        ],
        'business' => [
            'consent_letter',
            'directors_ic',
            'ctos_report',
            'ssm_company_profile',
            'audited_annual_report',
            'management_account',
            'ageing_report',
            'company_bank_statements_6m',
        ],
    ];
}

function stv_submit_normalize_type(string $type): string
{
    $t = strtolower(trim($type));
    return in_array($t, ['personal', 'business'], true) ? $t : '';
}

function stv_submit_required_doc_keys(string $type, string $subtype): array
{
    $type = stv_submit_normalize_type($type);
    $subtype = strtolower(trim($subtype));

    if ($type === 'personal') {
        if ($subtype === 'salary_earner') {
            return [
                'payslips',
                // OR alternatives handled later:
                // epf_recent_year OR salary_bank_statements
            ];
        }

        if ($subtype === 'self_employed') {
            return [
                'ssm_doc',
                // OR alternatives handled later:
                // company_bank_statements_6m OR form_b_recent_year
            ];
        }

        return [];
    }

    if ($type === 'business') {
        return [
            'consent_letter',
            'directors_ic',
            'ctos_report',
            'ssm_company_profile',
            'audited_annual_report',
            'management_account',
            'ageing_report',
            'company_bank_statements_6m',
        ];
    }

    return [];
}

function stv_submit_application(PDO $pdo, string $applicationUid, int $userId): ?array
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

function stv_submit_uploaded_doc_keys(PDO $pdo, string $applicationUid, int $userId): array
{
    $stmt = $pdo->prepare("
        SELECT DISTINCT doc_key
        FROM poado_stv_application_files
        WHERE application_uid = ?
          AND user_id = ?
    ");
    $stmt->execute([$applicationUid, $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    return array_values(array_unique(array_map('strval', $rows)));
}

function stv_submit_validate_docs(string $type, string $subtype, array $uploadedDocKeys): array
{
    $missing = [];
    $uploadedMap = array_fill_keys($uploadedDocKeys, true);

    if ($type === 'personal' && $subtype === 'salary_earner') {
        if (empty($uploadedMap['payslips'])) {
            $missing[] = 'payslips';
        }
        if (empty($uploadedMap['epf_recent_year']) && empty($uploadedMap['salary_bank_statements'])) {
            $missing[] = 'epf_recent_year_or_salary_bank_statements';
        }
        return $missing;
    }

    if ($type === 'personal' && $subtype === 'self_employed') {
        if (empty($uploadedMap['ssm_doc'])) {
            $missing[] = 'ssm_doc';
        }
        if (empty($uploadedMap['company_bank_statements_6m']) && empty($uploadedMap['form_b_recent_year'])) {
            $missing[] = 'company_bank_statements_6m_or_form_b_recent_year';
        }
        return $missing;
    }

    $required = stv_submit_required_doc_keys($type, $subtype);
    foreach ($required as $docKey) {
        if (empty($uploadedMap[$docKey])) {
            $missing[] = $docKey;
        }
    }

    return $missing;
}

function stv_submit_validate_selected_certs(PDO $pdo, int $userId, array $certUids): array
{
    if (empty($certUids)) {
        return ['SELECTED_CERT_UIDS_REQUIRED'];
    }

    $placeholders = implode(',', array_fill(0, count($certUids), '?'));
    $sql = "
        SELECT cert_uid
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
    $valid = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $validMap = array_fill_keys(array_map('strval', $valid), true);
    $invalid = [];
    foreach ($certUids as $uid) {
        $uid = (string)$uid;
        if (!isset($validMap[$uid])) {
            $invalid[] = $uid;
        }
    }

    return $invalid;
}

$pdo = stv_submit_pdo();
if (!$pdo) {
    stv_submit_json(['ok' => false, 'error' => 'DB_NOT_READY'], 500);
}

$user = stv_submit_hydrate_user(stv_submit_seed_user(), $pdo);
if (!$user || (int)($user['id'] ?? 0) <= 0) {
    stv_submit_json(['ok' => false, 'error' => 'AUTH_REQUIRED'], 401);
}

$schemaMissing = stv_submit_require_tables($pdo);
if (!empty($schemaMissing)) {
    stv_submit_json([
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
$type = stv_submit_normalize_type((string)($data['stv_type'] ?? ''));
$subtype = trim((string)($data['subtype'] ?? ''));
$selectedCertUids = $data['selected_cert_uids'] ?? [];

if ($applicationUid === '') {
    stv_submit_json(['ok' => false, 'error' => 'APPLICATION_UID_REQUIRED'], 422);
}
if ($type === '') {
    stv_submit_json(['ok' => false, 'error' => 'STV_TYPE_REQUIRED'], 422);
}
if ($subtype === '') {
    stv_submit_json(['ok' => false, 'error' => 'SUBTYPE_REQUIRED'], 422);
}
if (!is_array($selectedCertUids)) {
    stv_submit_json(['ok' => false, 'error' => 'SELECTED_CERT_UIDS_INVALID'], 422);
}

$selectedCertUids = array_values(array_unique(array_filter(array_map(static function ($v) {
    return trim((string)$v);
}, $selectedCertUids), static function ($v) {
    return $v !== '';
})));

$userId = (int)$user['id'];
$app = stv_submit_application($pdo, $applicationUid, $userId);
if (!$app) {
    stv_submit_json(['ok' => false, 'error' => 'APPLICATION_NOT_FOUND'], 404);
}

$uploadedDocKeys = stv_submit_uploaded_doc_keys($pdo, $applicationUid, $userId);
$missingDocs = stv_submit_validate_docs($type, $subtype, $uploadedDocKeys);
if (!empty($missingDocs)) {
    stv_submit_json([
        'ok' => false,
        'error' => 'REQUIRED_DOCUMENTS_MISSING',
        'missing_doc_keys' => $missingDocs,
    ], 422);
}

$invalidCerts = stv_submit_validate_selected_certs($pdo, $userId, $selectedCertUids);
if (!empty($invalidCerts)) {
    stv_submit_json([
        'ok' => false,
        'error' => 'SELECTED_CERT_UIDS_INVALID_OR_NOT_MINTED',
        'invalid_cert_uids' => $invalidCerts,
    ], 422);
}

try {
    $stmt = $pdo->prepare("
        UPDATE poado_stv_applications
        SET
            stv_type = :stv_type,
            subtype = :subtype,
            status = 'submitted',
            selected_cert_uids_json = :selected_cert_uids_json,
            updated_at = NOW()
        WHERE application_uid = :application_uid
          AND user_id = :user_id
        LIMIT 1
    ");
    $stmt->execute([
        ':stv_type' => $type,
        ':subtype' => $subtype,
        ':selected_cert_uids_json' => json_encode($selectedCertUids, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':application_uid' => $applicationUid,
        ':user_id' => $userId,
    ]);

    stv_submit_json([
        'ok' => true,
        'application_uid' => $applicationUid,
        'status' => 'submitted',
        'stv_type' => $type,
        'subtype' => $subtype,
        'uploaded_doc_keys' => $uploadedDocKeys,
        'selected_cert_uids' => $selectedCertUids,
        'ts' => time(),
    ]);
} catch (Throwable $e) {
    stv_submit_json([
        'ok' => false,
        'error' => 'STV_SUBMIT_FAILED',
        'message' => $e->getMessage(),
    ], 500);
}
