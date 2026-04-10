<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cron/royalty-claim-payout.php
 *
 * Purpose:
 * - moves queued claims to approved / paid based on manual or adapter payout
 *
 * Locked rules:
 * - payout source is system-side
 * - claim rows are canonical
 * - user pays gas and treasury fee separately
 *
 * Notes:
 * - if no payout adapter exists, this cron marks queued claims as 'approved'
 * - if payout adapter exists, it can mark claims directly as 'paid'
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';

date_default_timezone_set('Asia/Kuala_Lumpur');

function rcp_pdo(): PDO
{
    if (function_exists('db')) return db();
    if (function_exists('rwa_db')) return rwa_db();
    if (($GLOBALS['pdo'] ?? null) instanceof PDO) return $GLOBALS['pdo'];
    throw new RuntimeException('PDO_NOT_AVAILABLE');
}

function rcp_log(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

function rcp_dispatch_claim(array $claim): array
{
    $adapters = [
        'poado_royalty_claim_dispatch',
        'poado_claim_vault_dispatch',
        'rwa_claim_vault_dispatch',
    ];

    foreach ($adapters as $fn) {
        if (function_exists($fn)) {
            $res = $fn($claim);
            if (is_array($res)) {
                return [
                    'mode' => 'adapter',
                    'status' => (string)($res['status'] ?? 'approved'),
                    'claim_tx_hash' => (string)($res['claim_tx_hash'] ?? ''),
                    'raw' => $res,
                ];
            }
        }
    }

    return [
        'mode' => 'manual',
        'status' => 'approved',
        'claim_tx_hash' => '',
        'raw' => ['message' => 'No payout adapter found; moved to approved for manual payout.'],
    ];
}

try {
    $pdo = rcp_pdo();

    $claims = $pdo->query("
        SELECT *
        FROM poado_rwa_royalty_claims
        WHERE status = 'queued'
        ORDER BY id ASC
        LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (!$claims) {
        rcp_log('No queued claims.');
        exit(0);
    }

    $pdo->beginTransaction();

    $updClaim = $pdo->prepare("
        UPDATE poado_rwa_royalty_claims
        SET status = :status,
            claim_tx_hash = :claim_tx_hash,
            approved_at = CASE WHEN :status IN ('approved','paid') THEN NOW() ELSE approved_at END,
            paid_at = CASE WHEN :status = 'paid' THEN NOW() ELSE paid_at END,
            updated_at = NOW(),
            meta_json = :meta_json
        WHERE id = :id
    ");

    $updAlloc = $pdo->prepare("
        UPDATE poado_rwa_royalty_allocations
        SET claimed_ton = claimed_ton + :claimed_ton,
            status = CASE
                        WHEN (claimed_ton + :claimed_ton) >= claimable_ton THEN 'claimed'
                        ELSE 'partial'
                     END,
            updated_at = NOW()
        WHERE id = :allocation_id
    ");

    $done = 0;

    foreach ($claims as $claim) {
        $dispatch = rcp_dispatch_claim($claim);
        $status = $dispatch['status'];

        $meta = json_decode((string)($claim['meta_json'] ?? '{}'), true);
        if (!is_array($meta)) $meta = [];
        $meta['dispatch'] = [
            'mode' => $dispatch['mode'],
            'at' => date('c'),
            'raw' => $dispatch['raw'],
        ];

        $updClaim->execute([
            ':status' => $status,
            ':claim_tx_hash' => $dispatch['claim_tx_hash'],
            ':meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':id' => (int)$claim['id'],
        ]);

        if ($status === 'paid') {
            $updAlloc->execute([
                ':claimed_ton' => (float)$claim['amount_ton'],
                ':allocation_id' => (int)$claim['allocation_id'],
            ]);
        }

        rcp_log("Processed claim_ref={$claim['claim_ref']} status={$status}");
        $done++;
    }

    $pdo->commit();
    rcp_log("Done. processed={$done}");
    exit(0);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    rcp_log('ERROR: ' . $e->getMessage());
    exit(1);
}
