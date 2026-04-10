<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/api/search.php
 *
 * RWA Cert Dashboard v3
 * Search endpoint for current real poado_rwa_certs schema.
 *
 * Supports:
 * - cert UID search
 * - owner search
 * - RWA type search
 * - status search
 *
 * Notes:
 * - current lean DB status enum remains: issued / minted / revoked
 * - paid business state is inferred via paid_at
 * - cert type uses core color only
 */

require_once '/var/www/html/public/rwa/inc/core/bootstrap.php';
require_once '/var/www/html/public/rwa/inc/core/json.php';
require_once '/var/www/html/public/rwa/inc/core/error.php';
require_once '/var/www/html/public/rwa/cert/api/_meta-image-map.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_fail('Method not allowed.', 405);
}

function poado_cert_search_db(): PDO
{
    if (function_exists('rwa_db')) {
        $pdo = rwa_db();
        if ($pdo instanceof PDO) {
            return $pdo;
        }
    }

    foreach (['pdo', 'db', 'dbh'] as $k) {
        if (isset($GLOBALS[$k]) && $GLOBALS[$k] instanceof PDO) {
            return $GLOBALS[$k];
        }
    }

    throw new RuntimeException('PDO connection not available.');
}

function poado_cert_search_normalize_limit($value): int
{
    $limit = (int)$value;
    if ($limit <= 0) {
        return 20;
    }
    return min($limit, 100);
}

function poado_cert_search_normalize_offset($value): int
{
    $offset = (int)$value;
    return max(0, $offset);
}

function poado_cert_search_map_rwa_key_to_type(string $rwaKey): ?string
{
    $rwaKey = strtoupper(trim($rwaKey));
    if ($rwaKey === '') {
        return null;
    }

    foreach (poado_cert_meta_image_map() as $type => $cfg) {
        if (strtoupper((string)$cfg['rwa_key']) === $rwaKey) {
            return (string)$type;
        }
    }

    return null;
}

function poado_cert_search_enrich_row(array $row): array
{
    $type = strtolower(trim((string)($row['rwa_type'] ?? '')));
    $cfg = null;

    try {
        if ($type !== '') {
            $cfg = poado_cert_meta_image_config($type);
        }
    } catch (Throwable $e) {
        $cfg = null;
    }

    $paidAt = trim((string)($row['paid_at'] ?? ''));
    $mintedAt = trim((string)($row['minted_at'] ?? ''));
    $isMinted = !empty($row['nft_minted']) || $mintedAt !== '';

    $businessState = 'issued';
    if ($isMinted) {
        $businessState = 'minted';
    } elseif ($paidAt !== '') {
        $businessState = 'paid';
    }

    return [
        'id' => (int)($row['id'] ?? 0),
        'cert_uid' => (string)($row['cert_uid'] ?? ''),
        'cert_type' => $type,
        'rwa_key' => $cfg['rwa_key'] ?? null,
        'rwa_code' => $cfg['rwa_code'] ?? null,
        'family' => $cfg['family'] ?? null,
        'label' => $cfg['label'] ?? null,
        'weight' => $cfg['weight'] ?? null,
        'price' => [
            'amount' => (string)($row['price_wems'] ?? ''),
            'units' => (string)($row['price_units'] ?? ''),
        ],
        'owner_user_id' => (int)($row['owner_user_id'] ?? 0),
        'ton_wallet' => (string)($row['ton_wallet'] ?? ''),
        'status' => (string)($row['status'] ?? ''),
        'business_state' => $businessState,
        'paid_at' => (string)($row['paid_at'] ?? ''),
        'issued_at' => (string)($row['issued_at'] ?? ''),
        'minted_at' => (string)($row['minted_at'] ?? ''),
        'nft_minted' => (int)($row['nft_minted'] ?? 0),
        'nft_item_address' => (string)($row['nft_item_address'] ?? ''),
        'pdf_path' => (string)($row['pdf_path'] ?? ''),
        'nft_image_path' => (string)($row['nft_image_path'] ?? ''),
        'metadata_path' => (string)($row['metadata_path'] ?? ''),
        'verify_url' => (string)($row['cert_uid'] ?? '') !== ''
            ? poado_cert_verify_url((string)$row['cert_uid'])
            : null,
        'updated_at' => (string)($row['updated_at'] ?? ''),
    ];
}

try {
    $pdo = poado_cert_search_db();

    $q = trim((string)($_GET['q'] ?? ''));
    $certUid = strtoupper(trim((string)($_GET['cert_uid'] ?? '')));
    $ownerUserId = trim((string)($_GET['owner_user_id'] ?? ''));
    $wallet = trim((string)($_GET['wallet'] ?? ''));
    $certType = strtolower(trim((string)($_GET['cert_type'] ?? $_GET['rwa_type'] ?? '')));
    $rwaKey = strtoupper(trim((string)($_GET['rwa_key'] ?? '')));
    $status = strtolower(trim((string)($_GET['status'] ?? '')));
    $businessState = strtolower(trim((string)($_GET['business_state'] ?? '')));

    if ($certType === '' && $rwaKey !== '') {
        $mappedType = poado_cert_search_map_rwa_key_to_type($rwaKey);
        if ($mappedType !== null) {
            $certType = $mappedType;
        }
    }

    $limit = poado_cert_search_normalize_limit($_GET['limit'] ?? 20);
    $offset = poado_cert_search_normalize_offset($_GET['offset'] ?? 0);

    $where = [];
    $params = [];

    if ($certUid !== '') {
        $where[] = 'cert_uid = :cert_uid';
        $params[':cert_uid'] = $certUid;
    }

    if ($ownerUserId !== '') {
        $where[] = 'owner_user_id = :owner_user_id';
        $params[':owner_user_id'] = (int)$ownerUserId;
    }

    if ($wallet !== '') {
        $where[] = 'ton_wallet = :wallet';
        $params[':wallet'] = $wallet;
    }

    if ($certType !== '') {
        $where[] = 'rwa_type = :rwa_type';
        $params[':rwa_type'] = $certType;
    }

    if ($status !== '') {
        $allowed = ['issued', 'minted', 'revoked'];
        if (!in_array($status, $allowed, true)) {
            json_fail('Invalid status filter.', 422);
        }
        $where[] = 'status = :status';
        $params[':status'] = $status;
    }

    if ($businessState !== '') {
        if ($businessState === 'paid') {
            $where[] = "(paid_at IS NOT NULL AND paid_at <> '' AND (nft_minted = 0 OR nft_minted IS NULL) AND minted_at IS NULL)";
        } elseif ($businessState === 'minted') {
            $where[] = "(nft_minted = 1 OR minted_at IS NOT NULL)";
        } elseif ($businessState === 'issued') {
            $where[] = "((paid_at IS NULL OR paid_at = '') AND (nft_minted = 0 OR nft_minted IS NULL) AND minted_at IS NULL)";
        } else {
            json_fail('Invalid business_state filter.', 422);
        }
    }

    if ($q !== '') {
        $where[] = '(
            cert_uid LIKE :q
            OR rwa_type LIKE :q
            OR ton_wallet LIKE :q
            OR price_units LIKE :q
            OR nft_item_address LIKE :q
        )';
        $params[':q'] = '%' . $q . '%';
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $countSql = "SELECT COUNT(*) FROM poado_rwa_certs {$whereSql}";
    $countSt = $pdo->prepare($countSql);
    foreach ($params as $k => $v) {
        $countSt->bindValue($k, $v);
    }
    $countSt->execute();
    $total = (int)$countSt->fetchColumn();

    $sql = "
        SELECT
            id,
            cert_uid,
            rwa_type,
            price_wems,
            price_units,
            fingerprint_hash,
            router_tx_hash,
            owner_user_id,
            ton_wallet,
            pdf_path,
            nft_image_path,
            metadata_path,
            nft_item_address,
            nft_minted,
            status,
            issued_at,
            paid_at,
            minted_at,
            updated_at
        FROM poado_rwa_certs
        {$whereSql}
        ORDER BY id DESC
        LIMIT :limit OFFSET :offset
    ";

    $st = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        if (is_int($v)) {
            $st->bindValue($k, $v, PDO::PARAM_INT);
        } else {
            $st->bindValue($k, $v, PDO::PARAM_STR);
        }
    }
    $st->bindValue(':limit', $limit, PDO::PARAM_INT);
    $st->bindValue(':offset', $offset, PDO::PARAM_INT);
    $st->execute();

    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $items = array_map('poado_cert_search_enrich_row', $rows);

    json_ok([
        'ok' => true,
        'filters' => [
            'q' => $q,
            'cert_uid' => $certUid,
            'owner_user_id' => $ownerUserId !== '' ? (int)$ownerUserId : null,
            'wallet' => $wallet !== '' ? $wallet : null,
            'cert_type' => $certType !== '' ? $certType : null,
            'rwa_key' => $rwaKey !== '' ? $rwaKey : null,
            'status' => $status !== '' ? $status : null,
            'business_state' => $businessState !== '' ? $businessState : null,
            'limit' => $limit,
            'offset' => $offset,
        ],
        'pagination' => [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'count' => count($items),
            'has_more' => ($offset + count($items)) < $total,
        ],
        'items' => $items,
    ]);
} catch (Throwable $e) {
    if (function_exists('poado_error')) {
        poado_error('rwa_cert', '/rwa/cert/api/search.php', 'CERT_SEARCH_FAILED', $e->getMessage(), [
            'query' => $_GET ?? [],
        ]);
    }

    json_fail($e->getMessage(), 500);
}
