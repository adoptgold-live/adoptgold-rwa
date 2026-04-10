<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| POAdo RWA Royalty Pipeline Cron
|--------------------------------------------------------------------------
| File:
| /var/www/html/public/rwa/cert/cron/run-royalty-pipeline-cron.php
|
| Purpose:
| Convert scanned GetGems sales into royalty ledger records.
|
| Input:
| /rwa/cert/tmp/logs/getgems-sales-scan.json
|
| Output:
| Insert into poado_rwa_royalty_events_v2
|
| Locked Royalty Distribution:
| 25% royalty total
| 10% Holder pool
| 5% ACE pool
| 5% Gold Packet pool
| 5% Treasury retained
|
|--------------------------------------------------------------------------
*/

$root = dirname(__DIR__, 3);
require_once $root.'/dashboard/inc/bootstrap.php';

header('Content-Type: application/json');

try {

    $scanFile = $root.'/rwa/cert/tmp/logs/getgems-sales-scan.json';

    if (!file_exists($scanFile)) {
        echo json_encode([
            "ok" => true,
            "message" => "No upstream payload file found. Nothing to process."
        ], JSON_PRETTY_PRINT);
        exit;
    }

    $payload = json_decode(file_get_contents($scanFile), true);

    if (!$payload || empty($payload['sales'])) {
        echo json_encode([
            "ok" => true,
            "message" => "No sales rows detected."
        ], JSON_PRETTY_PRINT);
        exit;
    }

    $pdo = $GLOBALS['pdo'];

    $inserted = 0;
    $duplicates = 0;
    $errors = 0;

    $stmt = $pdo->prepare("
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
            treasury_retained_ton
        )
        VALUES
        (?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    foreach ($payload['sales'] as $sale) {

        if (empty($sale['event_uid'])) {
            continue;
        }

        $event_uid = $sale['event_uid'];

        $check = $pdo->prepare("
            SELECT id FROM poado_rwa_royalty_events_v2
            WHERE event_uid = ?
            LIMIT 1
        ");
        $check->execute([$event_uid]);

        if ($check->fetch()) {
            $duplicates++;
            continue;
        }

        $saleAmount = (float)$sale['sale_amount_ton'];

        if ($saleAmount <= 0) {
            continue;
        }

        $royalty = $saleAmount * 0.25;

        $holderPool = $saleAmount * 0.10;
        $acePool = $saleAmount * 0.05;
        $goldPacket = $saleAmount * 0.05;
        $treasury = $saleAmount * 0.05;

        try {

            $stmt->execute([
                $event_uid,
                $sale['cert_uid'] ?? '',
                $sale['nft_item_index'] ?? 0,
                $sale['marketplace'] ?? 'getgems',
                $saleAmount,
                $royalty,
                $sale['tx_hash'] ?? '',
                $sale['block_time'] ?? date('Y-m-d H:i:s'),
                $holderPool,
                $acePool,
                $goldPacket,
                $treasury
            ]);

            $inserted++;

        } catch (Throwable $e) {
            $errors++;
        }

    }

    echo json_encode([
        "ok" => true,
        "pipeline" => "rwa-royalty",
        "inserted_count" => $inserted,
        "duplicate_count" => $duplicates,
        "error_count" => $errors
    ], JSON_PRETTY_PRINT);

} catch (Throwable $e) {

    echo json_encode([
        "ok" => false,
        "error" => "server_error",
        "message" => "Failed to run royalty pipeline cron.",
        "details" => $e->getMessage()
    ], JSON_PRETTY_PRINT);

}