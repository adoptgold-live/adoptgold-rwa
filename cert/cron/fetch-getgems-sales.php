<?php
declare(strict_types=1);

$root = dirname(__DIR__,3);
require_once $root.'/dashboard/inc/bootstrap.php';

header('Content-Type: application/json');

try {

    $outputFile = $root.'/rwa/cert/tmp/logs/getgems-sales-source.json';

    $collectionAddress = getenv('RWA_NFT_COLLECTION') ?: '';

    if (!$collectionAddress) {
        echo json_encode([
            "ok"=>false,
            "error"=>"missing_collection",
            "message"=>"NFT collection address not configured"
        ],JSON_PRETTY_PRINT);
        exit;
    }

    $url = "https://api.getgems.io/public/api/collection/".$collectionAddress."/events";

    $ch = curl_init($url);

    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_TIMEOUT=>10
    ]);

    $response = curl_exec($ch);

    if(!$response){
        throw new Exception("GetGems API request failed");
    }

    $data=json_decode($response,true);

    if(empty($data['events'])){
        echo json_encode([
            "ok"=>true,
            "message"=>"No sales events returned",
            "prepared_count"=>0
        ],JSON_PRETTY_PRINT);
        exit;
    }

    $sales=[];

    foreach($data['events'] as $event){

        if(($event['type'] ?? '') !== 'sale'){
            continue;
        }

        $sales[]=[

            "event_uid"=>$event['id'],

            "cert_uid"=>$event['nft']['name'] ?? '',

            "nft_item_index"=>$event['nft']['index'] ?? 0,

            "marketplace"=>"getgems",

            "sale_amount_ton"=>($event['price'] ?? 0)/1e9,

            "tx_hash"=>$event['transaction']['hash'] ?? '',

            "block_time"=>date(
                "Y-m-d H:i:s",
                strtotime($event['timestamp'] ?? 'now')
            )

        ];

    }

    file_put_contents($outputFile,json_encode([
        "sales"=>$sales
    ],JSON_PRETTY_PRINT));

    echo json_encode([
        "ok"=>true,
        "message"=>"GetGems sales fetched",
        "output_file"=>$outputFile,
        "prepared_count"=>count($sales)
    ],JSON_PRETTY_PRINT);

}
catch(Throwable $e){

    echo json_encode([
        "ok"=>false,
        "error"=>"fetch_failed",
        "message"=>$e->getMessage()
    ],JSON_PRETTY_PRINT);

}