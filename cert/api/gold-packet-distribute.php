<?php
declare(strict_types=1);

/**
 * POAdo RWA Cert Engine
 * File: /rwa/cert/api/gold-packet-distribute.php
 *
 * Purpose:
 * - Build daily Gold Packet distribution rows from the vaulted Gold Packet pool
 * - Uses:
 *     wems_db.poado_rwa_gold_packet_claims
 * - This API does NOT send TON on-chain
 * - It only creates distribution ledger rows for later payout / settlement
 *
 * Locked context:
 * - NFT royalty = 25% to Treasury
 * - Gold Packet Vault share = 5%
 * - Gold Packet pool is first vaulted, then distributed later by engine
 *
 * Distribution model in this baseline version:
 * - take currently available Gold Packet vault balance
 * - distribute equally to eligible active minted-cert holders
 * - write daily distribution rows into poado_rwa_gold_packet_distributions
 *
 * Recommended schedule:
 * - run once daily after royalty pipeline
 */

header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/validators.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/guards.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/json.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/session-user.php';

if (!function_exists('poado_gpd_exit')) {
    function poado_gpd_exit(array $payload, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('poado_gpd_is_admin_like')) {
    function poado_gpd_is_admin_like(array $user): bool
    {
        return !empty($user['is_admin']) || !empty($user['is_senior']);
    }
}

if (!function_exists('poado_gpd_uid')) {
    function poado_gpd_uid(string $prefix): string
    {
        return $prefix . '-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
    }
}

if (!function_exists('poado_gpd_norm_decimal')) {
    function poado_gpd_norm_decimal($value, int $scale = 9): string
    {
        if ($value === null || $value === '') {
            return number_format(0, $scale, '.', '');
        }
        $v = trim((string)$value);
        $v = str_replace(',', '', $v);
        if (!is_numeric($v)) {
            throw new InvalidArgumentException('Invalid decimal value: ' . $v);
        }
        return number_format((float)$v, $scale, '.', '');
    }
}

if (!function_exists('poado_gpd_sub')) {
    function poado_gpd_sub(string $a, string $b, int $scale = 9): string
    {
        if (function_exists('bcsub')) {
            return bcsub($a, $b, $scale);
        }
        return number_format(((float)$a - (float)$b), $scale, '.', '');
    }
}

if (!function_exists('poado_gpd_div')) {
    function poado_gpd_div(string $a, int $b, int $scale = 9): string
    {
        if ($b <= 0) {
            return number_format(0, $scale, '.', '');
        }
        if (function_exists('bcdiv')) {
            return bcdiv($a, (string)$b, $scale);
        }
        return number_format(((float)$a / $b), $scale, '.', '');
    }
}

if (!function_exists('poado_gpd_date_key')) {
    function poado_gpd_date_key(): string
    {
        return gmdate('Y-m-d');
    }
}

try {
    $wallet = get_wallet_session();
    if (!$wallet) {
        poado_gpd_exit([
            'ok' => false,
            'error' => 'not_logged_in',
            'message' => 'Login required.',
        ], 401);
    }

    db_connect();
    /** @var PDO $pdo */
    $pdo = $GLOBALS['pdo'];

    $userStmt = $pdo->prepare("
        SELECT id, wallet, nickname, role, is_active, is_admin, is_senior
        FROM users
        WHERE wallet = :wallet
        LIMIT 1
    ");
    $userStmt->execute([':wallet' => $wallet]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        poado_gpd_exit([
            'ok' => false,
            'error' => 'user_not_found',
            'message' => 'User not found.',
        ], 404);
    }

    if ((int)($user['is_active'] ?? 0) !== 1) {
        poado_gpd_exit([
            'ok' => false,
            'error' => 'user_inactive',
            'message' => 'User inactive.',
        ], 403);
    }

    if (!poado_gpd_is_admin_like($user)) {
        poado_gpd_exit([
            'ok' => false,
            'error' => 'admin_only',
            'message' => 'Gold Packet distribution is restricted to admin/senior operators.',
        ], 403);
    }

    $token = (string)($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '');
    $csrf_ok = true;
    try {
        $r = csrf_check('rwa_gold_packet_distribute', $token);
        if ($r === false) $csrf_ok = false;
    } catch (Throwable $e) {
        $csrf_ok = false;
    }
    if (!$csrf_ok) {
        poado_gpd_exit([
            'ok' => false,
            'error' => 'csrf_failed',
            'message' => 'Security validation failed.',
        ], 419);
    }

    $distributionDate = trim((string)($_POST['distribution_date'] ?? $_GET['distribution_date'] ?? poado_gpd_date_key()));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $distributionDate)) {
        poado_gpd_exit([
            'ok' => false,
            'error' => 'invalid_distribution_date',
            'message' => 'distribution_date must be YYYY-MM-DD.',
        ], 422);
    }

    /**
     * Prevent duplicate daily build
     */
    $dupStmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM poado_rwa_gold_packet_distributions
        WHERE distribution_date = :distribution_date
    ");
    $dupStmt->execute([':distribution_date' => $distributionDate]);
    $alreadyBuilt = (int)$dupStmt->fetchColumn();

    if ($alreadyBuilt > 0) {
        poado_gpd_exit([
            'ok' => false,
            'error' => 'distribution_already_built',
            'message' => 'Gold Packet distribution already built for this date.',
            'distribution_date' => $distributionDate,
            'existing_rows' => $alreadyBuilt,
        ], 409);
    }

    /**
     * Available vault balance:
     * sum(allocated_ton - distributed_ton) for non-void rows
     */
    $balanceStmt = $pdo->query("
        SELECT
            COALESCE(SUM(allocated_ton), 0) AS allocated_total,
            COALESCE(SUM(distributed_ton), 0) AS distributed_total
        FROM poado_rwa_gold_packet_claims
        WHERE status <> 'void'
    ");
    $balanceRow = $balanceStmt->fetch(PDO::FETCH_ASSOC) ?: [
        'allocated_total' => '0',
        'distributed_total' => '0',
    ];

    $allocatedTotal = poado_gpd_norm_decimal($balanceRow['allocated_total'] ?? '0');
    $distributedTotal = poado_gpd_norm_decimal($balanceRow['distributed_total'] ?? '0');
    $vaultBalance = poado_gpd_sub($allocatedTotal, $distributedTotal, 9);

    if ((float)$vaultBalance <= 0) {
        poado_gpd_exit([
            'ok' => false,
            'error' => 'empty_vault_balance',
            'message' => 'No distributable Gold Packet balance available.',
        ], 422);
    }

    /**
     * Eligible recipients:
     * active users owning at least one minted/listed cert
     */
    $holderStmt = $pdo->query("
        SELECT
            c.owner_user_id,
            u.wallet,
            COUNT(*) AS cert_count
        FROM poado_rwa_certs c
        INNER JOIN users u ON u.id = c.owner_user_id
        WHERE c.status IN ('minted','listed')
          AND u.is_active = 1
        GROUP BY c.owner_user_id, u.wallet
        ORDER BY c.owner_user_id ASC
    ");
    $holders = $holderStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $holderCount = count($holders);

    if ($holderCount <= 0) {
        poado_gpd_exit([
            'ok' => false,
            'error' => 'no_eligible_recipients',
            'message' => 'No eligible recipients found for Gold Packet distribution.',
        ], 422);
    }

    /**
     * Baseline equal split
     */
    $perRecipient = poado_gpd_div($vaultBalance, $holderCount, 9);

    $insertStmt = $pdo->prepare("
        INSERT INTO poado_rwa_gold_packet_distributions
        (
            distribution_uid,
            distribution_date,
            owner_user_id,
            owner_wallet,
            cert_count,
            allocated_ton,
            status,
            payout_tx_hash,
            paid_at,
            created_at
        )
        VALUES
        (
            :distribution_uid,
            :distribution_date,
            :owner_user_id,
            :owner_wallet,
            :cert_count,
            :allocated_ton,
            :status,
            :payout_tx_hash,
            :paid_at,
            NOW()
        )
    ");

    $inserted = [];
    $running = 0.0;

    foreach ($holders as $i => $holder) {
        $alloc = $perRecipient;

        if ($i === $holderCount - 1) {
            $alloc = number_format(max(0, (float)$vaultBalance - $running), 9, '.', '');
        }

        $insertStmt->execute([
            ':distribution_uid' => poado_gpd_uid('GPDIST'),
            ':distribution_date' => $distributionDate,
            ':owner_user_id' => (int)$holder['owner_user_id'],
            ':owner_wallet' => (string)$holder['wallet'],
            ':cert_count' => (int)$holder['cert_count'],
            ':allocated_ton' => $alloc,
            ':status' => 'pending',
            ':payout_tx_hash' => null,
            ':paid_at' => null,
        ]);

        $running += (float)$alloc;

        $inserted[] = [
            'owner_user_id' => (int)$holder['owner_user_id'],
            'owner_wallet' => (string)$holder['wallet'],
            'cert_count' => (int)$holder['cert_count'],
            'allocated_ton' => $alloc,
        ];
    }

    poado_gpd_exit([
        'ok' => true,
        'message' => 'Gold Packet daily distribution built.',
        'distribution_date' => $distributionDate,
        'vault_balance_ton' => $vaultBalance,
        'recipient_count' => $holderCount,
        'per_recipient_ton' => $perRecipient,
        'rows_inserted' => count($inserted),
        'items' => $inserted,
    ], 200);

} catch (Throwable $e) {
    poado_gpd_exit([
        'ok' => false,
        'error' => 'server_error',
        'message' => 'Failed to build Gold Packet distribution.',
        'details' => $e->getMessage(),
    ], 500);
}