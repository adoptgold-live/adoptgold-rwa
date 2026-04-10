<?php
declare(strict_types=1);

/**
 * POAdo RWA Cert Engine
 * File: /var/www/html/public/rwa/cert/api/get.php
 *
 * Purpose:
 * - Return one certificate detail row by cert_uid
 * - Owner scope by default
 * - Optional public mode for minted / listed verification use
 * - Supports 4 Genesis + 3 Secondary + 1 Tertiary
 * - Tertiary backend key remains: human_rights
 * - Display label remains: Human Resources
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

function public_valid_status(string $status): bool {
    return in_array(strtolower(trim($status)), ['minted', 'listed'], true);
}

try {
    db_connect();
    /** @var PDO $pdo */
    $pdo = $GLOBALS['pdo'];

    if (!table_exists($pdo, 'poado_rwa_certs')) {
        out(['ok' => false, 'error' => 'CERT_TABLE_MISSING'], 500);
    }

    $maps = cert_maps();
    $user = function_exists('session_user') ? (session_user() ?: []) : [];
    $sessionUserId = (int)($user['id'] ?? 0);

    $certUid = trim((string)($_GET['cert_uid'] ?? $_GET['uid'] ?? ''));
    $publicOnly = (int)($_GET['public_only'] ?? 0) === 1;
    $ownerOnly = (int)($_GET['owner_only'] ?? 1) === 1;

    if ($certUid === '') {
        out(['ok' => false, 'error' => 'CERT_UID_REQUIRED'], 422);
    }

    if ($ownerOnly && $sessionUserId <= 0) {
        out(['ok' => false, 'error' => 'LOGIN_REQUIRED'], 401);
    }

    $sql = "
        SELECT
            c.*,
            u.nickname AS owner_nickname,
            u.wallet   AS owner_wallet
        FROM poado_rwa_certs c
        LEFT JOIN users u ON u.id = c.owner_user_id
        WHERE c.cert_uid = :uid
    ";
    $params = [':uid' => $certUid];

    if ($ownerOnly) {
        $sql .= " AND c.owner_user_id = :owner_uid";
        $params[':owner_uid'] = $sessionUserId;
    }

    $sql .= " LIMIT 1";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        out(['ok' => false, 'error' => 'CERT_NOT_FOUND'], 404);
    }

    $type = normalize_type($row, $maps);
    $cfg = $maps[$type] ?? null;
    if (!$cfg) {
        out(['ok' => false, 'error' => 'UNKNOWN_CERT_TYPE'], 422);
    }

    $status = strtolower((string)($row['status'] ?? ''));
    $isPublicValid = public_valid_status($status);

    if ($publicOnly && !$isPublicValid) {
        out([
            'ok' => false,
            'error' => 'CERT_NOT_PUBLIC_VALID',
            'status' => $status,
        ], 403);
    }

    $meta = json_decode((string)($row['meta'] ?? '{}'), true);
    if (!is_array($meta)) $meta = [];

    $effectiveWeight = isset($row['weight']) && $row['weight'] !== null && $row['weight'] !== ''
        ? (int)$row['weight']
        : (int)$cfg['weight'];

    out([
        'ok' => true,
        'item' => [
            'id' => (int)($row['id'] ?? 0),
            'cert_uid' => (string)($row['cert_uid'] ?? ''),
            'type' => $type,
            'group' => (string)$cfg['group'],
            'prefix' => (string)$cfg['prefix'],
            'label' => (string)$cfg['label'],
            'display_name' => (string)$cfg['display_name'],
            'status' => $status,
            'weight' => $effectiveWeight,
            'price_asset' => (string)($row['price_asset'] ?? ''),
            'price_amount' => (string)($row['price_amount'] ?? ''),
            'owner_user_id' => (int)($row['owner_user_id'] ?? 0),
            'owner_nickname' => (string)($row['owner_nickname'] ?? ''),
            'owner_wallet' => (string)($row['owner_wallet'] ?? ''),
            'ton_wallet' => (string)($row['ton_wallet'] ?? ''),
            'nft_item_address' => (string)($row['nft_item_address'] ?? ''),
            'router_tx_hash' => (string)($row['router_tx_hash'] ?? ''),
            'issued_at' => (string)($row['issued_at'] ?? ''),
            'minted_at' => (string)($row['minted_at'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
            'is_public_valid' => $isPublicValid,
            'verify_url' => '/rwa/cert/verify.php?uid=' . rawurlencode((string)($row['cert_uid'] ?? '')),
            'pdf_url' => '/rwa/cert/pdf.php?uid=' . rawurlencode((string)($row['cert_uid'] ?? '')),
            'meta' => $meta,
        ],
    ]);
} catch (Throwable $e) {
    out([
        'ok' => false,
        'error' => 'GET_FAILED',
        'message' => $e->getMessage(),
    ], 500);
}
