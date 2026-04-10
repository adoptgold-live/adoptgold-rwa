<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/api/cert/royalty-claim-create.php
 *
 * Creates claim rows from allocations.
 *
 * Locked rules:
 * - system claim only
 * - full KYC required
 * - canonical KYC field = users.is_fully_verified
 * - user pays gas
 * - treasury fee = 0.10 TON
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

function rcc_json(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function rcc_pdo(): PDO
{
    if (function_exists('db')) return db();
    if (function_exists('rwa_db')) return rwa_db();
    if (($GLOBALS['pdo'] ?? null) instanceof PDO) return $GLOBALS['pdo'];
    throw new RuntimeException('PDO_NOT_AVAILABLE');
}

function rcc_input(): array
{
    $raw = file_get_contents('php://input');
    $json = json_decode((string)$raw, true);
    return is_array($json) ? ($json + $_POST + $_GET) : ($_POST + $_GET);
}

function rcc_user_is_fully_verified(PDO $pdo, int $userId): bool
{
    if ($userId <= 0) return false;
    $st = $pdo->prepare("SELECT COALESCE(is_fully_verified, 0) FROM users WHERE id = :id LIMIT 1");
    $st->execute([':id' => $userId]);
    return (int)$st->fetchColumn() === 1;
}

try {
    $user = rwa_require_login();
    $pdo = rcc_pdo();
    $input = rcc_input();

    $userId = (int)($user['id'] ?? $user['user_id'] ?? 0);
    $wallet = (string)($user['wallet_address'] ?? $user['wallet'] ?? $user['ton_wallet'] ?? '');

    if ($userId <= 0 && $wallet === '') {
        rcc_json(['ok' => false, 'error' => 'USER_IDENTITY_MISSING'], 400);
    }

    if (!rcc_user_is_fully_verified($pdo, $userId)) {
        rcc_json(['ok' => false, 'error' => 'FULL_KYC_REQUIRED'], 403);
    }

    $pdo->beginTransaction();

    $sql = "SELECT *
            FROM poado_rwa_royalty_allocations
            WHERE status IN ('allocated','claimable','partial')
              AND claimable_ton > claimed_ton";
    $params = [];

    if ($userId > 0) {
        $sql .= " AND owner_user_id = :user_id";
        $params[':user_id'] = $userId;
    } else {
        $sql .= " AND ton_wallet = :ton_wallet";
        $params[':ton_wallet'] = $wallet;
    }

    if (!empty($input['allocation_id'])) {
        $sql .= " AND id = :allocation_id";
        $params[':allocation_id'] = (int)$input['allocation_id'];
    }

    $sql .= " ORDER BY id ASC LIMIT 100";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $allocs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (!$allocs) {
        $pdo->commit();
        rcc_json(['ok' => true, 'created' => 0, 'claims' => []]);
    }

    $insert = $pdo->prepare("
        INSERT INTO poado_rwa_royalty_claims (
            claim_ref, allocation_id, snapshot_id, owner_user_id, ton_wallet,
            claim_type, token_symbol, token_master, decimals, amount_ton, amount_units,
            kyc_verified, treasury_fee_ton, gas_paid_by_user, status, meta_json
        ) VALUES (
            :claim_ref, :allocation_id, :snapshot_id, :owner_user_id, :ton_wallet,
            :claim_type, 'TON', NULL, 9, :amount_ton, NULL,
            1, 0.100000000, 1, 'pending',
            :meta_json
        )
    ");

    $mark = $pdo->prepare("
        UPDATE poado_rwa_royalty_allocations
        SET status = 'claim_pending', updated_at = NOW()
        WHERE id = :id
    ");

    $claims = [];

    foreach ($allocs as $a) {
        $claimable = round((float)$a['claimable_ton'] - (float)$a['claimed_ton'], 9);
        if ($claimable <= 0) {
            continue;
        }

        $claimType = ((float)$a['gold_packet_share_ton'] > 0 && (float)$a['rewards_share_ton'] <= 0)
            ? 'gold_packet'
            : 'rewards_pool';

        $claimRef = 'RCLM-' . date('YmdHis') . '-' . strtoupper(substr(sha1($a['id'] . '|' . microtime(true)), 0, 8));

        $insert->execute([
            ':claim_ref' => $claimRef,
            ':allocation_id' => (int)$a['id'],
            ':snapshot_id' => (int)$a['snapshot_id'],
            ':owner_user_id' => $a['owner_user_id'],
            ':ton_wallet' => $a['ton_wallet'],
            ':claim_type' => $claimType,
            ':amount_ton' => $claimable,
            ':meta_json' => json_encode([
                'allocation_id' => (int)$a['id'],
                'snapshot_ref' => $a['snapshot_ref'],
                'created_by' => 'royalty-claim-create.php',
                'kyc_rule' => 'users.is_fully_verified = 1',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $mark->execute([':id' => (int)$a['id']]);

        $claims[] = [
            'claim_ref' => $claimRef,
            'allocation_id' => (int)$a['id'],
            'amount_ton' => $claimable,
            'claim_type' => $claimType,
        ];
    }

    $pdo->commit();

    rcc_json([
        'ok' => true,
        'created' => count($claims),
        'claims' => $claims,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    rcc_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
