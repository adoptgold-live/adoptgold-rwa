<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/api/bal-check-helper.php
 *
 * Purpose:
 * - Trusted balance adapter for Cert module
 * - Reads DB directly by owner_user_id and/or TON wallet_address
 * - Returns normalized balances for:
 *     wems, ema, ton
 *
 * Locked mapping:
 * - TON address maps through users.wallet_address
 * - user_id is authoritative once resolved
 */

header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';

function bal_json(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function bal_numstr(mixed $v, int $scale = 4): string
{
    if ($v === null || $v === '') {
        return number_format(0, $scale, '.', '');
    }
    return number_format((float)$v, $scale, '.', '');
}

try {
    $pdo = null;

    if (function_exists('db_connect')) {
        $maybe = db_connect();
        if ($maybe instanceof PDO) {
            $pdo = $maybe;
        }
    }

    if (!$pdo && function_exists('db')) {
        $maybe = db();
        if ($maybe instanceof PDO) {
            $pdo = $maybe;
        }
    }

    if (!$pdo && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        $pdo = $GLOBALS['pdo'];
    }

    if (!$pdo instanceof PDO) {
        throw new RuntimeException('DB_CONNECT_FAILED');
    }

    $ownerUserId = (int)($_GET['owner_user_id'] ?? 0);
    $wallet      = trim((string)($_GET['wallet'] ?? ''));
    $debug       = ((int)($_GET['debug'] ?? 0) === 1);

    if ($ownerUserId <= 0 && $wallet === '') {
        bal_json([
            'ok' => false,
            'error' => 'BALANCE_CONTEXT_REQUIRED',
            'detail' => 'owner_user_id or wallet is required',
            'ts' => time(),
        ], 400);
    }

    $resolvedUserId = 0;
    $resolvedVia = '';
    $resolvedUser = null;

    if ($ownerUserId > 0) {
        $st = $pdo->prepare("
            SELECT id, wallet, wallet_address, nickname
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $st->execute([$ownerUserId]);
        $u = $st->fetch(PDO::FETCH_ASSOC);

        if ($u) {
            $resolvedUserId = (int)$u['id'];
            $resolvedVia = 'owner_user_id';
            $resolvedUser = $u;
        }
    }

    if ($resolvedUserId <= 0 && $wallet !== '') {
        $st = $pdo->prepare("
            SELECT id, wallet, wallet_address, nickname
            FROM users
            WHERE wallet_address = ?
               OR wallet = ?
            LIMIT 1
        ");
        $st->execute([$wallet, $wallet]);
        $u = $st->fetch(PDO::FETCH_ASSOC);

        if ($u) {
            $resolvedUserId = (int)$u['id'];
            $resolvedVia = ((string)$u['wallet_address'] === $wallet) ? 'wallet_address' : 'wallet';
            $resolvedUser = $u;
        }
    }

    if ($resolvedUserId <= 0) {
        bal_json([
            'ok' => false,
            'error' => 'USER_NOT_FOUND',
            'detail' => 'No matching user for provided context',
            'ts' => time(),
        ], 404);
    }

    $st = $pdo->prepare("
        SELECT
            user_id,
            card_balance_rwa,
            onchain_emx,
            onchain_ema,
            onchain_wems,
            unclaim_ema,
            unclaim_wems,
            unclaim_gold_packet_usdt,
            unclaim_tips_emx,
            fuel_usdt_ton,
            fuel_ems,
            fuel_ton_gas,
            created_at,
            updated_at
        FROM rwa_storage_balances
        WHERE user_id = ?
        LIMIT 1
    ");
    $st->execute([$resolvedUserId]);
    $bal = $st->fetch(PDO::FETCH_ASSOC);

    if (!$bal) {
        bal_json([
            'ok' => false,
            'error' => 'BALANCE_ROW_NOT_FOUND',
            'detail' => 'No rwa_storage_balances row for resolved user',
            'user_id' => $resolvedUserId,
            'ts' => time(),
        ], 404);
    }

    $wems = (float)($bal['onchain_wems'] ?? 0);
    $ema  = (float)($bal['onchain_ema'] ?? 0);
    $ton  = (float)($bal['fuel_ton_gas'] ?? 0);

    $checks = [
        'green_1000_wems' => ($wems >= 1000.0),
        'blue_5000_wems'  => ($wems >= 5000.0),
        'gold_50000_wems' => ($wems >= 50000.0),
        'ema_100'         => ($ema >= 100.0),
        'ton_ready_0_5'   => ($ton >= 0.5),
    ];

    $out = [
        'ok' => true,
        'user_id' => $resolvedUserId,
        'resolved_via' => $resolvedVia,
        'user' => [
            'wallet' => (string)($resolvedUser['wallet'] ?? ''),
            'wallet_address' => (string)($resolvedUser['wallet_address'] ?? ''),
            'nickname' => (string)($resolvedUser['nickname'] ?? ''),
        ],
        'balances' => [
            'wems' => bal_numstr($bal['onchain_wems']),
            'ema'  => bal_numstr($bal['onchain_ema']),
            'ton'  => bal_numstr($bal['fuel_ton_gas']),
        ],
        'ton_ready' => $checks['ton_ready_0_5'],
        'sufficient' => [
            'green'  => $checks['green_1000_wems'],
            'blue'   => $checks['blue_5000_wems'],
            'gold'   => $checks['gold_50000_wems'],
            'ema100' => $checks['ema_100'],
        ],
        'source' => [
            'table' => 'rwa_storage_balances',
            'updated_at' => $bal['updated_at'] ?? null,
        ],
        'ts' => time(),
    ];

    if ($debug) {
        $out['debug'] = [
            'input' => [
                'owner_user_id' => $ownerUserId,
                'wallet' => $wallet,
            ],
            'raw' => $bal,
        ];
    }

    bal_json($out, 200);

} catch (Throwable $e) {
    bal_json([
        'ok' => false,
        'error' => 'BAL_CHECK_FAILED',
        'detail' => $e->getMessage(),
        'ts' => time(),
    ], 500);
}
