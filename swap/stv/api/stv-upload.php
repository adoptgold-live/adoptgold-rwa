<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/swap/stv/api/stv-upload.php
 *
 * STV upload endpoint
 * - accepts Personal / Business document uploads
 * - uploads file to Google Drive under STV/{type}/{application_uid}/{doc_key}/
 * - stores metadata only in DB
 * - schema-safe; returns clear mismatch errors if required tables/columns are missing
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

$dashboardEnv = $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/env.php';
$dashboardDrive = $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/drive.php';
$dashboardVault = $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/vault.php';

if (is_file($dashboardEnv)) {
    require_once $dashboardEnv;
}
if (is_file($dashboardDrive)) {
    require_once $dashboardDrive;
}
if (is_file($dashboardVault)) {
    require_once $dashboardVault;
}

header('Content-Type: application/json; charset=utf-8');

function stv_upload_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function stv_upload_pdo(): ?PDO
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

function stv_upload_seed_user(): ?array
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

function stv_upload_hydrate_user(?array $seed, PDO $pdo): ?array
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

function stv_upload_has_column(PDO $pdo, string $table, string $column): bool
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

function stv_upload_require_tables(PDO $pdo): array
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
    ];

    $missing = [];
    foreach ($requirements as $table => $cols) {
        foreach ($cols as $col) {
            if (!stv_upload_has_column($pdo, $table, $col)) {
                $missing[$table][] = $col;
            }
        }
    }
    return $missing;
}

function stv_upload_allowed_doc_keys(): array
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

function stv_upload_allowed_mime_types(): array
{
    return [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];
}

function stv_upload_normalize_type(string $type): string
{
    $t = strtolower(trim($type));
    return in_array($t, ['personal', 'business'], true) ? $t : '';
}

function stv_upload_uuid_fragment(int $len = 8): string
{
    return substr(bin2hex(random_bytes(16)), 0, $len);
}

function stv_upload_make_application_uid(string $type): string
{
    return strtoupper($type) . '-STV-' . gmdate('YmdHis') . '-' . stv_upload_uuid_fragment(8);
}

function stv_upload_find_or_create_application(PDO $pdo, int $userId, string $type, string $subtype, int $preapprovedTotal, string $applicationUid = ''): string
{
    if ($applicationUid !== '') {
        $stmt = $pdo->prepare("
            SELECT application_uid
            FROM poado_stv_applications
            WHERE application_uid = ?
              AND user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$applicationUid, $userId]);
        $existing = $stmt->fetchColumn();
        if ($existing) {
            return (string)$existing;
        }
    }

    $newUid = stv_upload_make_application_uid($type);

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
            '[]',
            NOW(),
            NOW()
        )
    ");
    $stmt->execute([
        ':application_uid' => $newUid,
        ':user_id' => $userId,
        ':stv_type' => $type,
        ':subtype' => $subtype,
        ':preapproved_total' => $preapprovedTotal,
    ]);

    return $newUid;
}

function stv_upload_drive_put(string $drivePath, array $file): array
{
    $tmp = $file['tmp_name'] ?? '';
    $name = $file['name'] ?? 'upload.bin';
    $mime = $file['type'] ?? 'application/octet-stream';

    if (!is_uploaded_file($tmp)) {
        throw new RuntimeException('UPLOAD_TEMP_FILE_INVALID');
    }

    $content = file_get_contents($tmp);
    if ($content === false) {
        throw new RuntimeException('UPLOAD_READ_FAILED');
    }

    if (function_exists('vault_upload_bytes')) {
        $res = vault_upload_bytes($drivePath, $content, $mime);
        if (is_array($res) && !empty($res['id'])) {
            return [
                'id' => (string)$res['id'],
                'url' => (string)($res['webViewLink'] ?? $res['url'] ?? ''),
                'name' => $name,
            ];
        }
    }

    if (function_exists('gdrive_upload_bytes')) {
        $res = gdrive_upload_bytes($drivePath, $content, $mime);
        if (is_array($res) && !empty($res['id'])) {
            return [
                'id' => (string)$res['id'],
                'url' => (string)($res['webViewLink'] ?? $res['url'] ?? ''),
                'name' => $name,
            ];
        }
    }

    if (function_exists('drive_upload_bytes')) {
        $res = drive_upload_bytes($drivePath, $content, $mime);
        if (is_array($res) && !empty($res['id'])) {
            return [
                'id' => (string)$res['id'],
                'url' => (string)($res['webViewLink'] ?? $res['url'] ?? ''),
                'name' => $name,
            ];
        }
    }

    throw new RuntimeException('GOOGLE_DRIVE_HELPER_NOT_AVAILABLE');
}

$pdo = stv_upload_pdo();
if (!$pdo) {
    stv_upload_json(['ok' => false, 'error' => 'DB_NOT_READY'], 500);
}

$user = stv_upload_hydrate_user(stv_upload_seed_user(), $pdo);
if (!$user || (int)($user['id'] ?? 0) <= 0) {
    stv_upload_json(['ok' => false, 'error' => 'AUTH_REQUIRED'], 401);
}

$schemaMissing = stv_upload_require_tables($pdo);
if (!empty($schemaMissing)) {
    stv_upload_json([
        'ok' => false,
        'error' => 'STV_SCHEMA_MISMATCH',
        'missing' => $schemaMissing,
    ], 500);
}

$type = stv_upload_normalize_type((string)($_POST['stv_type'] ?? ''));
$subtype = trim((string)($_POST['subtype'] ?? ''));
$docKey = trim((string)($_POST['doc_key'] ?? ''));
$applicationUid = trim((string)($_POST['application_uid'] ?? ''));
$preapprovedTotal = (int)preg_replace('/[^0-9\-]/', '', (string)($_POST['preapproved_total'] ?? '0'));

if ($type === '') {
    stv_upload_json(['ok' => false, 'error' => 'STV_TYPE_REQUIRED'], 422);
}

$allowedKeys = stv_upload_allowed_doc_keys();
if (!isset($allowedKeys[$type]) || !in_array($docKey, $allowedKeys[$type], true)) {
    stv_upload_json(['ok' => false, 'error' => 'DOC_KEY_INVALID'], 422);
}

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    stv_upload_json(['ok' => false, 'error' => 'FILE_REQUIRED'], 422);
}

$file = $_FILES['file'];
if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    stv_upload_json([
        'ok' => false,
        'error' => 'UPLOAD_ERROR',
        'code' => (int)($file['error'] ?? UPLOAD_ERR_NO_FILE),
    ], 422);
}

$mime = (string)($file['type'] ?? '');
if (!in_array($mime, stv_upload_allowed_mime_types(), true)) {
    stv_upload_json([
        'ok' => false,
        'error' => 'MIME_NOT_ALLOWED',
        'mime' => $mime,
    ], 422);
}

$fileSize = (int)($file['size'] ?? 0);
if ($fileSize <= 0) {
    stv_upload_json(['ok' => false, 'error' => 'FILE_EMPTY'], 422);
}

if ($fileSize > (20 * 1024 * 1024)) {
    stv_upload_json(['ok' => false, 'error' => 'FILE_TOO_LARGE', 'max_mb' => 20], 422);
}

$userId = (int)$user['id'];

try {
    $pdo->beginTransaction();

    $applicationUid = stv_upload_find_or_create_application(
        $pdo,
        $userId,
        $type,
        $subtype,
        $preapprovedTotal,
        $applicationUid
    );

    $safeOriginalName = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)($file['name'] ?? 'upload.bin'));
    $drivePath = 'STV/' . $type . '/' . $applicationUid . '/' . $docKey . '/' . $safeOriginalName;

    $uploaded = stv_upload_drive_put($drivePath, $file);

    $stmt = $pdo->prepare("
        INSERT INTO poado_stv_application_files
        (
            application_uid,
            user_id,
            doc_key,
            original_name,
            mime_type,
            file_size,
            google_drive_file_id,
            google_drive_url,
            uploaded_at
        )
        VALUES
        (
            :application_uid,
            :user_id,
            :doc_key,
            :original_name,
            :mime_type,
            :file_size,
            :google_drive_file_id,
            :google_drive_url,
            NOW()
        )
    ");
    $stmt->execute([
        ':application_uid' => $applicationUid,
        ':user_id' => $userId,
        ':doc_key' => $docKey,
        ':original_name' => (string)($file['name'] ?? ''),
        ':mime_type' => $mime,
        ':file_size' => $fileSize,
        ':google_drive_file_id' => (string)$uploaded['id'],
        ':google_drive_url' => (string)$uploaded['url'],
    ]);

    $pdo->commit();

    stv_upload_json([
        'ok' => true,
        'application_uid' => $applicationUid,
        'stv_type' => $type,
        'subtype' => $subtype,
        'doc_key' => $docKey,
        'file' => [
            'original_name' => (string)($file['name'] ?? ''),
            'mime_type' => $mime,
            'file_size' => $fileSize,
            'google_drive_file_id' => (string)$uploaded['id'],
            'google_drive_url' => (string)$uploaded['url'],
            'drive_path' => $drivePath,
        ],
        'ts' => time(),
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    stv_upload_json([
        'ok' => false,
        'error' => 'UPLOAD_SAVE_FAILED',
        'message' => $e->getMessage(),
    ], 500);
}
