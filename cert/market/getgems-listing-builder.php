<?php
declare(strict_types=1);

/**
 * POAdo RWA Cert Engine
 * File: /var/www/html/public/rwa/cert/market/getgems-listing-builder.php
 *
 * Purpose:
 * - Owner-only marketplace payload builder
 * - Allowed only after cert status = minted
 * - Builds listing payload only
 * - Does not auto-list
 *
 * Locked rules:
 * - Webroot: /var/www/html/public
 * - DB: wems_db only
 * - Absolute includes only
 * - Owner-only access
 * - Status must be exactly minted
 * - Store market trace into meta.market
 */

header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/validators.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/guards.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/json.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/session-user.php';

if (!function_exists('poado_market_exit')) {
    function poado_market_exit(array $payload, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('poado_market_meta_decode')) {
    function poado_market_meta_decode($meta): array
    {
        if (is_array($meta)) {
            return $meta;
        }
        if (!is_string($meta) || trim($meta) === '') {
            return [];
        }
        $decoded = json_decode($meta, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('poado_market_meta_encode')) {
    function poado_market_meta_encode(array $meta): string
    {
        return json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

if (!function_exists('poado_market_detect_type')) {
    function poado_market_detect_type(array $row): string
    {
        $raw = strtolower((string)($row['cert_type'] ?? ''));
        if ($raw !== '') {
            return $raw;
        }

        $uid = strtoupper((string)($row['cert_uid'] ?? ''));
        if (str_starts_with($uid, 'GCN-')) return 'green';
        if (str_starts_with($uid, 'GC-'))  return 'gold';
        if (str_starts_with($uid, 'BC-'))  return 'blue';
        if (str_starts_with($uid, 'BLC-')) return 'black';
        if (str_starts_with($uid, 'HC-'))  return 'health';
        if (str_starts_with($uid, 'TC-'))  return 'travel';
        if (str_starts_with($uid, 'PC-'))  return 'property';
        return 'unknown';
    }
}

if (!function_exists('poado_market_type_label')) {
    function poado_market_type_label(string $type): string
    {
        return match ($type) {
            'green'    => 'Genesis Green RWA Certificate',
            'gold'     => 'Genesis Gold RWA Certificate',
            'blue'     => 'Genesis Blue RWA Certificate',
            'black'    => 'Genesis Black RWA Certificate',
            'health'   => 'Secondary Health RWA Certificate',
            'travel'   => 'Secondary Travel RWA Certificate',
            'property' => 'Secondary Property RWA Certificate',
            default    => 'POAdo RWA Certificate',
        };
    }
}

if (!function_exists('poado_market_group')) {
    function poado_market_group(string $type): string
    {
        if (in_array($type, ['green', 'gold', 'blue', 'black'], true)) {
            return 'genesis';
        }
        if (in_array($type, ['health', 'travel', 'property'], true)) {
            return 'secondary';
        }
        return 'unknown';
    }
}

if (!function_exists('poado_market_weight')) {
    function poado_market_weight(string $type): int
    {
        return match ($type) {
            'green'    => 1,
            'blue'     => 2,
            'black'    => 3,
            'gold'     => 5,
            'health'   => 10,
            'travel'   => 10,
            'property' => 10,
            default    => 0,
        };
    }
}

if (!function_exists('poado_market_price_asset')) {
    function poado_market_price_asset(string $type): string
    {
        return in_array($type, ['green', 'gold', 'blue', 'black'], true) ? 'wEMS' : 'EMA$';
    }
}

if (!function_exists('poado_market_locked_splitter')) {
    function poado_market_locked_splitter(): array
    {
        return [
            'treasury'   => 'UQBdYfGArtoCUmBs5TjYQtfPFfQuGC2Ydbj2pQr3zIlNrDta',
            'holderPool' => 'UQDbVZIewSbi7RM7Xei470WwCfk7-stDox3wXc7RBUh79KaT',
            'issuer'     => 'UQBxc1nE_MGtIQpy1wTzVnoQTPfQmv5st_u2QJWSNNAvbYAv',
        ];
    }
}

try {
    $wallet = get_wallet_session();
    if (!$wallet) {
        poado_market_exit([
            'ok' => false,
            'error' => 'not_logged_in',
            'message' => 'Login required.',
        ], 401);
    }

    $uid = trim((string)($_REQUEST['uid'] ?? ''));
    $uid = preg_replace('/[^A-Za-z0-9\-]/', '', $uid ?? '') ?: '';

    if ($uid === '') {
        poado_market_exit([
            'ok' => false,
            'error' => 'missing_uid',
            'message' => 'Certificate UID is required.',
        ], 422);
    }

    db_connect();
    /** @var PDO $pdo */
    $pdo = $GLOBALS['pdo'];

    $userStmt = $pdo->prepare("
        SELECT id, wallet, nickname, role, is_active
        FROM users
        WHERE wallet = :wallet
        LIMIT 1
    ");
    $userStmt->execute([':wallet' => $wallet]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        poado_market_exit([
            'ok' => false,
            'error' => 'user_not_found',
            'message' => 'User not found.',
        ], 404);
    }

    if ((int)($user['is_active'] ?? 0) !== 1) {
        poado_market_exit([
            'ok' => false,
            'error' => 'user_inactive',
            'message' => 'User inactive.',
        ], 403);
    }

    $certStmt = $pdo->prepare("
        SELECT
            c.*,
            u.nickname AS owner_nickname,
            u.wallet   AS owner_wallet
        FROM poado_rwa_certs c
        LEFT JOIN users u ON u.id = c.owner_user_id
        WHERE c.cert_uid = :uid
        LIMIT 1
    ");
    $certStmt->execute([':uid' => $uid]);
    $cert = $certStmt->fetch(PDO::FETCH_ASSOC);

    if (!$cert) {
        poado_market_exit([
            'ok' => false,
            'error' => 'cert_not_found',
            'message' => 'Certificate not found.',
        ], 404);
    }

    if ((int)($cert['owner_user_id'] ?? 0) !== (int)$user['id']) {
        poado_market_exit([
            'ok' => false,
            'error' => 'owner_only',
            'message' => 'Only the cert owner may build market payload.',
        ], 403);
    }

    $status = strtolower((string)($cert['status'] ?? ''));
    if ($status !== 'minted') {
        poado_market_exit([
            'ok' => false,
            'error' => 'status_not_minted',
            'message' => 'Listing payload is allowed only when status is exactly minted.',
            'status' => $status,
        ], 422);
    }

    $meta = poado_market_meta_decode($cert['meta'] ?? null);
    $vault = is_array($meta['vault'] ?? null) ? $meta['vault'] : [];
    $mint  = is_array($meta['mint'] ?? null) ? $meta['mint'] : [];
    $payment = is_array($meta['payment'] ?? null) ? $meta['payment'] : [];
    $preview = is_array($meta['preview'] ?? null) ? $meta['preview'] : [];

    $type = poado_market_detect_type($cert);
    $group = poado_market_group($type);
    $weight = poado_market_weight($type);
    $label = poado_market_type_label($type);
    $priceAsset = poado_market_price_asset($type);

    $nftItemAddress = trim((string)($cert['nft_item_address'] ?? ($mint['nft_item_address'] ?? '')));
    $txHash = trim((string)($mint['tx_hash'] ?? ''));

    if ($nftItemAddress === '') {
        poado_market_exit([
            'ok' => false,
            'error' => 'missing_nft_item_address',
            'message' => 'Minted cert is missing nft_item_address.',
        ], 422);
    }

    if ($txHash === '') {
        poado_market_exit([
            'ok' => false,
            'error' => 'missing_tx_hash',
            'message' => 'Minted cert is missing tx_hash.',
        ], 422);
    }

    $verifyUrl   = (string)($vault['verify'] ?? ('https://adoptgold.app/rwa/cert/verify.php?uid=' . rawurlencode($uid)));
    $pdfUrl      = (string)($vault['pdf'] ?? ('/rwa/cert/pdf.php?uid=' . rawurlencode($uid)));
    $metadataUrl = (string)($vault['metadata'] ?? '');
    $previewUrl  = (string)($vault['preview'] ?? '');
    $qrUrl       = (string)($vault['qr'] ?? '');

    $splitter = is_array($meta['splitter'] ?? null) ? $meta['splitter'] : [];
    if (!$splitter) {
        $splitter = poado_market_locked_splitter();
    }

    $sellerAskAmount = trim((string)($_REQUEST['ask_amount'] ?? ''));
    $sellerAskAsset  = trim((string)($_REQUEST['ask_asset'] ?? 'TON'));
    if ($sellerAskAsset === '') {
        $sellerAskAsset = 'TON';
    }

    $attributes = [
        ['trait_type' => 'Certificate UID', 'value' => $uid],
        ['trait_type' => 'Certificate Type', 'value' => $type],
        ['trait_type' => 'Certificate Group', 'value' => $group],
        ['trait_type' => 'Weight', 'value' => $weight],
        ['trait_type' => 'Issue Payment Asset', 'value' => $priceAsset],
        ['trait_type' => 'Issue Payment Amount', 'value' => (string)($payment['amount'] ?? $cert['price_units'] ?? '')],
    ];

    if (!empty($preview['industry_key'])) {
        $attributes[] = ['trait_type' => 'Industry Key', 'value' => (string)$preview['industry_key']];
    }

    $title = $label . ' · ' . $uid;
    $description =
        $label . ' issued under the POAdo / AdoptGold RWA certification framework. '
        . 'This marketplace payload is builder-only and does not auto-list.';

    $payload = [
        'market' => 'getgems',
        'mode' => 'payload_only',
        'eligible' => true,

        'nft' => [
            'uid' => $uid,
            'title' => $title,
            'description' => $description,
            'category' => $group,
            'type' => $type,
            'weight' => $weight,
            'nft_item_address' => $nftItemAddress,
            'tx_hash' => $txHash,
            'owner_wallet' => (string)($cert['owner_wallet'] ?? ''),
            'owner_nickname' => (string)($cert['owner_nickname'] ?? ''),
        ],

        'pricing' => [
            'seller_ask_amount' => $sellerAskAmount,
            'seller_ask_asset'  => $sellerAskAsset,
            'original_issue_asset' => $priceAsset,
            'original_issue_amount' => (string)($payment['amount'] ?? $cert['price_units'] ?? ''),
        ],

        'links' => [
            'verify'   => $verifyUrl,
            'pdf'      => $pdfUrl,
            'metadata' => $metadataUrl,
            'preview'  => $previewUrl,
            'qr'       => $qrUrl,
        ],

        'royalty' => [
            'splitter' => [
                'treasury'   => (string)($splitter['treasury'] ?? ''),
                'holderPool' => (string)($splitter['holderPool'] ?? ''),
                'issuer'     => (string)($splitter['issuer'] ?? ''),
            ],
            'note' => 'Royalty routing context only. Real market listing is not executed here.',
        ],

        'attributes' => $attributes,

        'builder_context' => [
            'status_required' => 'minted',
            'built_at' => gmdate('c'),
            'built_by_user_id' => (int)$user['id'],
            'built_by_wallet' => (string)$user['wallet'],
        ],
    ];

    $meta['market'] = [
        'last_builder' => [
            'market' => 'getgems',
            'built_at' => gmdate('c'),
            'built_by_user_id' => (int)$user['id'],
            'built_by_wallet' => (string)$user['wallet'],
            'seller_ask_amount' => $sellerAskAmount,
            'seller_ask_asset' => $sellerAskAsset,
            'nft_item_address' => $nftItemAddress,
            'tx_hash' => $txHash,
        ],
    ];

    $updateStmt = $pdo->prepare("
        UPDATE poado_rwa_certs
        SET meta = :meta, updated_at = NOW()
        WHERE id = :id
        LIMIT 1
    ");
    $updateStmt->execute([
        ':meta' => poado_market_meta_encode($meta),
        ':id'   => (int)$cert['id'],
    ]);

    poado_market_exit([
        'ok' => true,
        'message' => 'Getgems listing payload built successfully.',
        'uid' => $uid,
        'status' => $status,
        'payload' => $payload,
    ], 200);

} catch (Throwable $e) {
    poado_market_exit([
        'ok' => false,
        'error' => 'server_error',
        'message' => 'Failed to build listing payload.',
        'details' => $e->getMessage(),
    ], 500);
}