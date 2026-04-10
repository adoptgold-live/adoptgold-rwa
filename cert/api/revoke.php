<?php
declare(strict_types=1);

/**
 * POAdo RWA Cert Engine
 * File: /var/www/html/public/rwa/cert/api/revoke.php
 *
 * Purpose:
 * - Revoke a certificate record
 * - Advance lifecycle into revoked
 * - Keep family/type mapping consistent for:
 *   4 Genesis + 3 Secondary + 1 Tertiary
 * - Tertiary backend key remains: human_rights
 * - Display label remains: Human Resources
 *
 * Notes:
 * - Owner-scoped by default
 * - Safe additive metadata update only
 * - Public validity becomes false after revoke
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/session-user.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function req_json(): array {
    $raw = file_get_contents('php://input');
    if (is_string($raw) && trim($raw) !== '') {
        $j = json_decode($raw, true);
        if (is_array($j)) return $j;
    }
    return $_POST ?: [];
}

function table_exists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :t
        LIMIT 1
    ");
    $st->execute([':t' => $table]);
    return (bool)$st->fetchColumn();
}

function cols(PDO $pdo, string $table): array {
    $sql = "
        SELECT COLUMN_NAME
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :t
        ORDER BY ORDINAL_POSITION
    ";
    $st = $pdo->prepare($sql);
    $st->execute([':t' => $table]);
    return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

function cert_maps(): array {
    return [
        'green' => [
            'prefix' => 'RCO2C-EMA',
            'group' => 'genesis',
            'weight' => 1,
            'label' => 'Genesis Green RWA Certificate',
            'display_name' => 'Green',
        ],
        'gold' => [
            'prefix' => 'RK92-EMA',
            'group' => 'genesis',
            'weight' => 5,
            'label' => 'Genesis Gold RWA Certificate',
            'display_name' => 'Gold',
        ],
        'blue' => [
            'prefix' => 'RH2O-EMA',
            'group' => 'genesis',
            'weight' => 2,
            'label' => 'Genesis Blue RWA Certificate',
            'display_name' => 'Blue',
        ],
        'black' => [
            'prefix' => 'RBLACK-EMA',
            'group' => 'genesis',
            'weight' => 3,
            'label' => 'Genesis Black RWA Certificate',
            'display_name' => 'Black',
        ],
        'health' => [
            'prefix' => 'RLIFE-EMA',
            'group' => 'secondary',
            'weight' => 10,
            'label' => 'Secondary Health RWA Certificate',
            'display_name' => 'Health',
        ],
        'travel' => [
            'prefix' => 'RTRIP-EMA',
            'group' => 'secondary',
            'weight' => 10,
            'label' => 'Secondary Travel RWA Certificate',
            'display_name' => 'Travel',
        ],
        'property' => [
            'prefix' => 'RPROP-EMA',
            'group' => 'secondary',
            'weight' => 10,
            'label' => 'Secondary Property RWA Certificate',
            'display_name' => 'Property',
        ],
        'human_rights' => [
            'prefix' => 'RHRD-EMA',
            'group' => 'tertiary',
            'weight' => 7,
            'label' => 'Tertiary Human Resources RWA Certificate',
            'display_name' => 'Human Resources',
        ],
    ];
}

function detect_type_from_uid(string $uid): string {
    $uid = strtoupper(trim($uid));
    if (str_starts_with($uid, 'RCO2C-EMA-')) return 'green';
    if (str_starts_with($uid, 'RK92-EMA-')) return 'gold';
    if (str_starts_with($uid, 'RH2O-EMA-')) return 'blue';
    if (str_starts_with($uid, 'RBLACK-EMA-')) return 'black';
    if (str_starts_with($uid, 'RLIFE-EMA-')) return 'health';
    if (str_starts_with($uid, 'RTRIP-EMA-')) return 'travel';
    if (str_starts_with($uid, 'RPROP-EMA-')) return 'property';
    if (str_starts_with($uid, 'RHRD-EMA-')) return 'human_rights';

    // legacy fallback
    if (str_starts_with($uid, 'GCN-')) return 'green';
    if (str_starts_with($uid, 'GC-'))  return 'gold';
    if (str_starts_with($uid, 'BC-'))  return 'blue';
    if (str_starts_with($uid, 'BLC-')) return 'black';
    if (str_starts_with($uid, 'HC-'))  return 'health';
    if (str_starts_with($uid, 'TC-'))  return 'travel';
    if (str_starts_with($uid, 'PC-'))  return 'property';
    if (str_starts_with($uid, 'HR-'))  return 'human_rights';

    return 'unknown';
}

function normalize_type(array $row, array $maps): string {
    $raw = strtolower(trim((string)($row['cert_type'] ?? '')));
    if ($raw !== '' && isset($maps[$raw])) {
        return $raw;
    }
    return detect_type_from_uid((string)($row['cert_uid'] ?? ''));
}

function merge_meta(array $base, array $patch): array {
    foreach ($patch as $k => $v) {
        if (is_array($v) && isset($base[$k]) && is_array($base[$k])) {
            $base[$k] = merge_meta($base[$k], $v);
        } else {
            $base[$k] = $v;
        }
    }
    return $base;
}

function update_dynamic(PDO $pdo, string $table, array $data, string $whereSql, array $whereParams): void {
    $available = cols($pdo, $table);
    $filtered = [];
    foreach ($data as $k => $v) {
        if (in_array($k, $available, true)) {
            $filtered[$k] = $v;
        }
    }
    if (!$filtered) {
        throw new RuntimeException('No compatible update columns found for ' . $table);
    }

    $set = [];
    $params = [];
    foreach ($filtered as $k => $v) {
        $set[] = "{$k} = :set_{$k}";
        $params[":set_{$k}"] = $v;
    }
    foreach ($whereParams as $k => $v) {
        $params[$k] = $v;
    }

    $sql = "UPDATE {$table} SET " . implode(', ', $set) . " WHERE {$whereSql}";
    $st = $pdo->prepare($sql);
    $st->execute($params);
}

$csrf_ok = true;
try {
    $token = (string)($_POST['csrf_token'] ?? $_POST['csrf'] ?? '');
    $r = csrf_check('rwa_cert_revoke', $token);
    if ($r === false) $csrf_ok = false;
} catch (Throwable $e) {
    $csrf_ok = false;
}
if (!$csrf_ok) {
    out(['ok' => false, 'error' => 'INVALID_CSRF'], 400);
}

try {
    db_connect();
    /** @var PDO $pdo */
    $pdo = $GLOBALS['pdo'];

    if (!table_exists($pdo, 'poado_rwa_certs')) {
        out(['ok' => false, 'error' => 'CERT_TABLE_MISSING'], 500);
    }

    $user = function_exists('session_user') ? (session_user() ?: []) : [];
    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) {
        out(['ok' => false, 'error' => 'LOGIN_REQUIRED'], 401);
    }

    $in = req_json();
    $certUid = trim((string)($in['cert_uid'] ?? ''));
    $reason = trim((string)($in['reason'] ?? ''));
    $revokedBy = trim((string)($in['revoked_by'] ?? 'owner'));
    $revokeNote = trim((string)($in['revoke_note'] ?? ''));

    if ($certUid === '') {
        out(['ok' => false, 'error' => 'CERT_UID_REQUIRED'], 422);
    }

    $maps = cert_maps();

    $st = $pdo->prepare("
        SELECT *
        FROM poado_rwa_certs
        WHERE cert_uid = :uid
        LIMIT 1
    ");
    $st->execute([':uid' => $certUid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        out(['ok' => false, 'error' => 'CERT_NOT_FOUND'], 404);
    }

    if ((int)($row['owner_user_id'] ?? 0) !== $userId) {
        out(['ok' => false, 'error' => 'FORBIDDEN'], 403);
    }

    $type = normalize_type($row, $maps);
    if (!isset($maps[$type])) {
        out(['ok' => false, 'error' => 'UNKNOWN_CERT_TYPE'], 422);
    }

    $cfg = $maps[$type];
    $currentStatus = strtolower(trim((string)($row['status'] ?? '')));

    if ($currentStatus === 'revoked') {
        out([
            'ok' => true,
            'cert_uid' => $certUid,
            'type' => $type,
            'group' => $cfg['group'],
            'label' => $cfg['label'],
            'display_name' => $cfg['display_name'],
            'status' => 'revoked',
            'message' => 'Certificate already revoked.',
        ]);
    }

    $now = gmdate('Y-m-d H:i:s');
    $existingMeta = json_decode((string)($row['meta'] ?? '{}'), true);
    if (!is_array($existingMeta)) $existingMeta = [];

    $metaPatch = [
        'revoke' => [
            'status' => 'revoked',
            'reason' => $reason,
            'revoked_by' => $revokedBy,
            'revoke_note' => $revokeNote,
            'revoked_at' => $now,
        ],
        'public' => [
            'is_public_valid' => 0,
        ],
    ];

    $newMeta = merge_meta($existingMeta, $metaPatch);

    $update = [
        'cert_type' => $type,
        'status' => 'revoked',
        'weight' => (int)($row['weight'] !== null && $row['weight'] !== '' ? $row['weight'] : $cfg['weight']),
        'meta' => json_encode($newMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'updated_at' => $now,
    ];

    // optional compatible columns if present
    $update['cert_group'] = (string)$cfg['group'];
    $update['cert_label'] = (string)$cfg['label'];

    update_dynamic(
        $pdo,
        'poado_rwa_certs',
        $update,
        'cert_uid = :uid AND owner_user_id = :owner_user_id',
        [
            ':uid' => $certUid,
            ':owner_user_id' => $userId,
        ]
    );

    out([
        'ok' => true,
        'cert_uid' => $certUid,
        'type' => $type,
        'group' => $cfg['group'],
        'prefix' => $cfg['prefix'],
        'label' => $cfg['label'],
        'display_name' => $cfg['display_name'],
        'status' => 'revoked',
        'revoke' => [
            'reason' => $reason,
            'revoked_by' => $revokedBy,
            'revoke_note' => $revokeNote,
            'revoked_at' => $now,
        ],
        'is_public_valid' => false,
        'verify_url' => '/rwa/cert/verify.php?uid=' . rawurlencode($certUid),
        'pdf_url' => '/rwa/cert/pdf.php?uid=' . rawurlencode($certUid),
    ]);
} catch (Throwable $e) {
    out([
        'ok' => false,
        'error' => 'REVOKE_FAILED',
        'message' => $e->getMessage(),
    ], 500);
}
