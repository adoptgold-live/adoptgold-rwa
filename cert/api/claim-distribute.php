<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| POAdo RWA Cert Engine
| Royalty Distribution Engine
|--------------------------------------------------------------------------
|
| File:
| /rwa/cert/api/claim-distribute.php
|
| Purpose
| Allocate royalty income into ecosystem pools
|
| Source table
| wems_db.poado_rwa_royalty_events_v2
|
| Distribution (locked globally)
|
| 25% Royalty → Treasury Wallet
|
| Treasury Ledger Allocation
| 10% Holder Pool
| 5%  ACE Pool (RK92-EMA weighted)
| 5%  Gold Packet Vault
| 5%  Treasury Retained
|
| This API only writes ledger allocation
| Actual claims handled separately
|
*/

require_once $_SERVER['DOCUMENT_ROOT'].'/dashboard/inc/bootstrap.php';

header('Content-Type: application/json');

try {

    $pdo = db_connect();

    /*
    -------------------------------------------------------
    Fetch unprocessed royalty events
    -------------------------------------------------------
    */

    $sql = "
    SELECT *
    FROM poado_rwa_royalty_events_v2
    WHERE holder_pool_ton IS NULL
    ORDER BY block_time ASC
    LIMIT 100
    ";

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        echo json_encode([
            'ok' => true,
            'message' => 'no new royalty events'
        ]);
        exit;
    }

    $updated = 0;

    foreach ($rows as $row) {

        $royalty = (float)$row['royalty_amount_ton'];

        /*
        ---------------------------------------
        Distribution model
        ---------------------------------------
        */

        $holder  = $royalty * 0.10;
        $ace     = $royalty * 0.05;
        $gold    = $royalty * 0.05;
        $treasury= $royalty * 0.05;

        $update = $pdo->prepare("
        UPDATE poado_rwa_royalty_events_v2
        SET
            holder_pool_ton = ?,
            ace_pool_ton = ?,
            gold_packet_pool_ton = ?,
            treasury_retained_ton = ?
        WHERE id = ?
        ");

        $update->execute([
            $holder,
            $ace,
            $gold,
            $treasury,
            $row['id']
        ]);

        $updated++;

    }

    echo json_encode([
        'ok' => true,
        'processed' => $updated
    ]);

} catch (Throwable $e) {

    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}