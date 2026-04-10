<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/cron/royalty-scan.php
 * Version: v1.0.0-20260402-events-v2-self-contained
 *
 * Notes:
 * - CLI-safe absolute paths only
 * - Uses poado_rwa_royalty_events_v2
 * - Self-contained royalty split helper (no missing lib dependency)
 * - Current TON tx scan treats inbound value as observed royalty amount
 * - sale_amount_ton is stored equal to observed royalty amount unless a full sale amount source is added later
 */

require_once '/var/www/html/public/rwa/inc/core/bootstrap.php';
require_once '/var/www/html/public/rwa/inc/core/toncenter.php';

$pdo = (($GLOBALS['pdo'] ?? null) instanceof PDO) ? $GLOBALS['pdo'] : (function_exists('rwa_db') ? rwa_db() : (function_exists('db_connect') ? db_connect() : (function_exists('db') ? db() : null)));
if (!($pdo instanceof PDO)) {
    fwrite(STDERR, "DB_UNAVAILABLE\n");
    exit(1);
}

function table_exists(PDO $pdo, string $table): bool
{
    $table = str_replace(['%', '_'], ['\\%', '\\_'], $table);
    $sql = "SHOW TABLES LIKE " . $pdo->quote($table);
    $st = $pdo->query($sql);
    return (bool)$st->fetchColumn();
}

if (!table_exists($pdo, 'poado_rwa_royalty_events_v2')) {
    fwrite(STDERR, "ROYALTY_EVENTS_V2_TABLE_MISSING\n");
    exit(1);
}

function tx_exists(PDO $pdo, string $hash): bool
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM poado_rwa_royalty_events_v2 WHERE treasury_tx_hash = ?");
    $st->execute([$hash]);
    return ((int)$st->fetchColumn()) > 0;
}

function ton_to_float(string $nano): float
{
    return ((float)$nano) / 1e9;
}

function cert_type_from_uid(string $certUid): string
{
    $u = strtoupper($certUid);
    if (str_starts_with($u, 'RK92-EMA-')) return 'gold';
    if (str_starts_with($u, 'RBLACK-EMA-')) return 'black';
    if (str_starts_with($u, 'RH2O-EMA-')) return 'blue';
    if (str_starts_with($u, 'RCO2C-EMA-')) return 'green';
    if (str_starts_with($u, 'RLIFE-EMA-')) return 'secondary';
    if (str_starts_with($u, 'RTRIP-EMA-')) return 'secondary';
    if (str_starts_with($u, 'RPROP-EMA-')) return 'secondary';
    if (str_starts_with($u, 'RHRD-EMA-')) return 'secondary';
    return 'unknown';
}

/**
 * Mapping into existing v2 columns:
 * - holder_pool_ton      = Snapshot pool (25%)
 * - ace_pool_ton         = Rewards pool (15%)
 * - gold_packet_pool_ton = Gold packet pool (5% only for gold certs)
 * - treasury_retained_ton= Treasury pool (5%)
 *
 * The seller/minter share is not stored in this ledger row because the table does not have a dedicated column for it.
 */
function rwa_split_royalty_v2(float $royaltyTon, string $certType): array
{
    $snapshot = round($royaltyTon * 0.25, 9);
    $treasury = round($royaltyTon * 0.05, 9);
    $rewards  = round($royaltyTon * 0.15, 9);
    $goldPack = ($certType === 'gold') ? round($royaltyTon * 0.05, 9) : 0.0;

    return [
        'snapshot_pool'     => $snapshot,
        'rewards_pool'      => $rewards,
        'gold_packet_pool'  => $goldPack,
        'treasury_pool'     => $treasury,
    ];
}

// FETCH MINTED NFTS
$rows = $pdo->query("
    SELECT cert_uid, nft_item_address
    FROM poado_rwa_certs
    WHERE status = 'minted'
      AND nft_item_address IS NOT NULL
      AND nft_item_address <> ''
    ORDER BY id DESC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $r) {
    $certUid = trim((string)($r['cert_uid'] ?? ''));
    $nft = trim((string)($r['nft_item_address'] ?? ''));
    if ($certUid === '' || $nft === '') {
        continue;
    }

    echo "SCAN {$certUid}\n";

    try {
        $txs = poado_toncenter_get_transactions($nft, 20);

        if (!is_array($txs)) {
            echo "ERROR {$certUid} poado_toncenter_get_transactions returned non-array\n";
            continue;
        }

        foreach ($txs as $tx) {
            $hash  = trim((string)($tx['transaction_id']['hash'] ?? ''));
            $value = trim((string)($tx['in_msg']['value'] ?? '0'));

            if ($hash === '' || $value === '0') {
                continue;
            }

            if (tx_exists($pdo, $hash)) {
                continue;
            }

            $royaltyAmount = ton_to_float($value);

            // ignore dust
            if ($royaltyAmount < 0.05) {
                continue;
            }

            $certType = cert_type_from_uid($certUid);
            $split = rwa_split_royalty_v2($royaltyAmount, $certType);

            $st = $pdo->prepare("
                INSERT INTO poado_rwa_royalty_events_v2
                (
                    event_uid,
                    cert_uid,
                    nft_item_index,
                    marketplace,
                    sale_amount_ton,
                    royalty_amount_ton,
                    treasury_tx_hash,
                    block_time,
                    holder_pool_ton,
                    ace_pool_ton,
                    gold_packet_pool_ton,
                    treasury_retained_ton,
                    created_at
                )
                VALUES
                (
                    UUID(),
                    ?,
                    0,
                    'TON',
                    ?,
                    ?,
                    ?,
                    NOW(),
                    ?,
                    ?,
                    ?,
                    ?,
                    NOW()
                )
            ");

            $st->execute([
                $certUid,
                $royaltyAmount,               // fallback until full sale amount source exists
                $royaltyAmount,
                $hash,
                $split['snapshot_pool'],
                $split['rewards_pool'],
                $split['gold_packet_pool'],
                $split['treasury_pool'],
            ]);

            echo "ROYALTY DETECTED {$certUid} {$royaltyAmount} TON\n";
        }
    } catch (Throwable $e) {
        echo "ERROR {$certUid} " . $e->getMessage() . "\n";
    }
}
