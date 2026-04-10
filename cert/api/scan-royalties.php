<?php
declare(strict_types=1);

/**
 * POAdo RWA Cert Engine
 * File: /rwa/cert/api/scan-royalties.php
 *
 * Purpose:
 * - Scan TON treasury wallet transactions
 * - Detect NFT royalty income
 * - Insert royalty ledger rows
 *
 * Source:
 * poado_rwa_royalty_events_v2
 *
 * This is the main feed for the entire
 * RWA financial ecosystem.
 */

header('Content-Type: application/json');

require_once $_SERVER['DOCUMENT_ROOT'].'/dashboard/inc/bootstrap.php';

$TREASURY = 'UQBdYfGArtoCUmBs5TjYQtfPFfQuGC2Ydbj2pQr3zIlNrDta';

try {

    $pdo = db_connect();

    /*
    ---------------------------------------------------
    Toncenter API
    ---------------------------------------------------
    */

    $url = "https://toncenter.com/api/v3/transactions?account=".$TREASURY."&limit=50";

    $opts = [
        "http" => [
            "method" => "GET",
            "header" => "X-API-Key: YOUR_TONCENTER_API_KEY"
        ]
    ];

    $context = stream_context_create($opts);

    $json = file_get_contents($url,false,$context);

    if(!$json){
        echo json_encode([
            "ok"=>false,
            "error"=>"ton_api_error"
        ]);
        exit;
    }

    $data = json_decode($json,true);

    if(empty($data["transactions"])){
        echo json_encode([
            "ok"=>true,
            "message"=>"no transactions"
        ]);
        exit;
    }

    $inserted = 0;

    foreach($data["transactions"] as $tx){

        $hash = $tx["hash"];

        /*
        ---------------------------------------------------
        Prevent duplicate
        ---------------------------------------------------
        */

        $chk = $pdo->prepare("
        SELECT id
        FROM poado_rwa_royalty_events_v2
        WHERE treasury_tx_hash = ?
        LIMIT 1
        ");

        $chk->execute([$hash]);

        if($chk->fetch()){
            continue;
        }

        /*
        ---------------------------------------------------
        Detect TON value
        ---------------------------------------------------
        */

        $value = (float)$tx["in_msg"]["value"] / 1000000000;

        if($value <= 0){
            continue;
        }

        /*
        ---------------------------------------------------
        Generate UID
        ---------------------------------------------------
        */

        $uid = "ROY-".date("Ymd")."-".strtoupper(bin2hex(random_bytes(4)));

        /*
        ---------------------------------------------------
        Insert royalty event
        ---------------------------------------------------
        */

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
            treasury_retained_ton,
            created_at
        )
        VALUES
        (?, '',0,'TON', ?, ?, ?, NOW(),0,0,0,0,NOW())
        ");

        $stmt->execute([
            $uid,
            $value,
            $value,
            $hash
        ]);

        $inserted++;

    }

    echo json_encode([
        "ok"=>true,
        "inserted"=>$inserted
    ]);

} catch(Throwable $e){

    echo json_encode([
        "ok"=>false,
        "error"=>$e->getMessage()
    ]);

}