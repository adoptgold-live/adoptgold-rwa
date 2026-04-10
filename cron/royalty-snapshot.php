<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cron/royalty-snapshot.php
 *
 * Canonical source tables:
 * - poado_rwa_royalty_events_v2
 * - poado_rwa_royalty_snapshots
 * - poado_rwa_royalty_allocations
 * - poado_rwa_royalty_claims
 * - poado_rwa_certs
 * - vw_poado_rwa_cert_weights
 *
 * Locked royalty model:
 * - Seller keeps 75%
 * - Royalty 25%
 * - Treasury 5%
 * - Rewards Pool 15%
 * - Gold Packet 5%
 *
 * Locked weights:
 * - Secondary = 10
 * - Tertiary  = 7
 * - Gold      = 5
 * - Black     = 3
 * - Blue      = 2
 * - Green     = 1
 *
 * Locked rule:
 * - poado_rwa_royalty_events_v2 is the only source of truth
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';

date_default_timezone_set('Asia/Kuala_Lumpur');

function rr_pdo(): PDO
{
    if (function_exists('db')) return db();
    if (function_exists('rwa_db')) return rwa_db();
    if (($GLOBALS['pdo'] ?? null) instanceof PDO) return $GLOBALS['pdo'];
    throw new RuntimeException('PDO_NOT_AVAILABLE');
}

function rr_out(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

function rr_fetch_open_events(PDO $pdo, int $limit = 100): array
{
    $sql = "SELECT *
            FROM poado_rwa_royalty_events_v2
            WHERE snapshot_ref IS NULL OR snapshot_ref = ''
            ORDER BY id ASC
            LIMIT {$limit}";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function rr_fetch_global_counts(PDO $pdo): array
{
    $sql = "SELECT
              COALESCE(SUM(secondary_count),0) AS total_secondary_count,
              COALESCE(SUM(tertiary_count),0)  AS total_tertiary_count,
              COALESCE(SUM(gold_count),0)      AS total_gold_count,
              COALESCE(SUM(black_count),0)     AS total_black_count,
              COALESCE(SUM(blue_count),0)      AS total_blue_count,
              COALESCE(SUM(green_count),0)     AS total_green_count,
              COALESCE(SUM(total_weight),0)    AS total_weight,
              COALESCE(SUM(gold_packet_weight),0) AS gold_packet_total_weight
            FROM vw_poado_rwa_cert_weights";
    $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
    return $row ?: [
        'total_secondary_count' => 0,
        'total_tertiary_count' => 0,
        'total_gold_count' => 0,
        'total_black_count' => 0,
        'total_blue_count' => 0,
        'total_green_count' => 0,
        'total_weight' => 0,
        'gold_packet_total_weight' => 0,
    ];
}

function rr_insert_snapshot(PDO $pdo, array $event, array $totals): array
{
    $snapshotRef = 'SNAP-' . date('YmdHis') . '-' . strtoupper(substr(sha1((string)$event['event_ref']), 0, 8));

    $sql = "INSERT INTO poado_rwa_royalty_snapshots (
                snapshot_ref, snapshot_date, source_event_id, sale_tx_hash, nft_item_address, seller_wallet,
                sale_amount_ton, royalty_amount_ton, treasury_amount_ton, rewards_pool_amount_ton, gold_packet_amount_ton,
                total_secondary_count, total_tertiary_count, total_gold_count, total_black_count, total_blue_count, total_green_count,
                total_weight, gold_packet_total_weight, status, meta_json
            ) VALUES (
                :snapshot_ref, NOW(), :source_event_id, :sale_tx_hash, :nft_item_address, :seller_wallet,
                :sale_amount_ton, :royalty_amount_ton, :treasury_amount_ton, :rewards_pool_amount_ton, :gold_packet_amount_ton,
                :total_secondary_count, :total_tertiary_count, :total_gold_count, :total_black_count, :total_blue_count, :total_green_count,
                :total_weight, :gold_packet_total_weight, 'allocated_pending',
                :meta_json
            )";
    $st = $pdo->prepare($sql);
    $st->execute([
        ':snapshot_ref' => $snapshotRef,
        ':source_event_id' => $event['id'],
        ':sale_tx_hash' => $event['sale_tx_hash'] ?? null,
        ':nft_item_address' => $event['nft_item_address'] ?? null,
        ':seller_wallet' => $event['seller_wallet'] ?? null,
        ':sale_amount_ton' => $event['sale_amount_ton'] ?? 0,
        ':royalty_amount_ton' => $event['royalty_amount_ton'] ?? 0,
        ':treasury_amount_ton' => $event['treasury_ton'] ?? 0,
        ':rewards_pool_amount_ton' => $event['rewards_pool_ton'] ?? 0,
        ':gold_packet_amount_ton' => $event['gold_packet_ton'] ?? 0,
        ':total_secondary_count' => $totals['total_secondary_count'] ?? 0,
        ':total_tertiary_count' => $totals['total_tertiary_count'] ?? 0,
        ':total_gold_count' => $totals['total_gold_count'] ?? 0,
        ':total_black_count' => $totals['total_black_count'] ?? 0,
        ':total_blue_count' => $totals['total_blue_count'] ?? 0,
        ':total_green_count' => $totals['total_green_count'] ?? 0,
        ':total_weight' => $totals['total_weight'] ?? 0,
        ':gold_packet_total_weight' => $totals['gold_packet_total_weight'] ?? 0,
        ':meta_json' => json_encode([
            'event_ref' => $event['event_ref'],
            'collection_address' => $event['collection_address'] ?? null,
            'buyer_wallet' => $event['buyer_wallet'] ?? null,
            'created_by' => 'royalty-snapshot.php',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    $id = (int)$pdo->lastInsertId();
    return ['id' => $id, 'snapshot_ref' => $snapshotRef];
}

function rr_allocate_snapshot(PDO $pdo, int $snapshotId, string $snapshotRef, array $event, array $totals): int
{
    $weights = $pdo->query("SELECT * FROM vw_poado_rwa_cert_weights")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $globalWeight = (float)($totals['total_weight'] ?? 0);
    $goldPacketWeight = (float)($totals['gold_packet_total_weight'] ?? 0);
    $rewardsPool = (float)($event['rewards_pool_ton'] ?? 0);
    $goldPacketPool = (float)($event['gold_packet_ton'] ?? 0);

    $insert = $pdo->prepare("
        INSERT INTO poado_rwa_royalty_allocations (
            snapshot_id, snapshot_ref, owner_user_id, ton_wallet,
            secondary_count, tertiary_count, gold_count, black_count, blue_count, green_count,
            total_weight, rewards_share_ton, gold_packet_share_ton, claimable_ton, claimed_ton,
            kyc_required, status, meta_json
        ) VALUES (
            :snapshot_id, :snapshot_ref, :owner_user_id, :ton_wallet,
            :secondary_count, :tertiary_count, :gold_count, :black_count, :blue_count, :green_count,
            :total_weight, :rewards_share_ton, :gold_packet_share_ton, :claimable_ton, 0,
            1, 'allocated',
            :meta_json
        )
    ");

    $count = 0;

    foreach ($weights as $w) {
        $userWeight = (float)($w['total_weight'] ?? 0);
        $userGoldWeight = (float)($w['gold_packet_weight'] ?? 0);

        $rewardsShare = ($globalWeight > 0 && $userWeight > 0)
            ? round(($userWeight / $globalWeight) * $rewardsPool, 9)
            : 0.0;

        $goldPacketShare = ($goldPacketWeight > 0 && $userGoldWeight > 0)
            ? round(($userGoldWeight / $goldPacketWeight) * $goldPacketPool, 9)
            : 0.0;

        $claimable = round($rewardsShare + $goldPacketShare, 9);
        if ($claimable <= 0) {
            continue;
        }

        $insert->execute([
            ':snapshot_id' => $snapshotId,
            ':snapshot_ref' => $snapshotRef,
            ':owner_user_id' => $w['owner_user_id'],
            ':ton_wallet' => $w['ton_wallet'],
            ':secondary_count' => $w['secondary_count'],
            ':tertiary_count' => $w['tertiary_count'],
            ':gold_count' => $w['gold_count'],
            ':black_count' => $w['black_count'],
            ':blue_count' => $w['blue_count'],
            ':green_count' => $w['green_count'],
            ':total_weight' => $w['total_weight'],
            ':rewards_share_ton' => $rewardsShare,
            ':gold_packet_share_ton' => $goldPacketShare,
            ':claimable_ton' => $claimable,
            ':meta_json' => json_encode([
                'formula' => [
                    'rewards_pool_ton' => $rewardsPool,
                    'gold_packet_pool_ton' => $goldPacketPool,
                    'global_weight' => $globalWeight,
                    'gold_packet_total_weight' => $goldPacketWeight,
                    'user_weight' => $userWeight,
                    'user_gold_packet_weight' => $userGoldWeight,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $count++;
    }

    $st = $pdo->prepare("UPDATE poado_rwa_royalty_snapshots SET status='allocated', updated_at=NOW() WHERE id=:id");
    $st->execute([':id' => $snapshotId]);

    return $count;
}

function rr_mark_event_processed(PDO $pdo, int $eventId, string $snapshotRef): void
{
    $st = $pdo->prepare("UPDATE poado_rwa_royalty_events_v2 SET snapshot_ref=:snapshot_ref WHERE id=:id");
    $st->execute([
        ':snapshot_ref' => $snapshotRef,
        ':id' => $eventId,
    ]);
}

try {
    $pdo = rr_pdo();
    $pdo->beginTransaction();

    $events = rr_fetch_open_events($pdo, 100);
    if (!$events) {
        rr_out('No open royalty events found.');
        $pdo->commit();
        exit(0);
    }

    $processed = 0;

    foreach ($events as $event) {
        $totals = rr_fetch_global_counts($pdo);
        $snapshot = rr_insert_snapshot($pdo, $event, $totals);
        $allocCount = rr_allocate_snapshot($pdo, (int)$snapshot['id'], (string)$snapshot['snapshot_ref'], $event, $totals);
        rr_mark_event_processed($pdo, (int)$event['id'], (string)$snapshot['snapshot_ref']);

        rr_out("Processed event_ref={$event['event_ref']} snapshot_ref={$snapshot['snapshot_ref']} allocations={$allocCount}");
        $processed++;
    }

    $pdo->commit();
    rr_out("Done. processed={$processed}");
    exit(0);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    rr_out('ERROR: ' . $e->getMessage());
    exit(1);
}
