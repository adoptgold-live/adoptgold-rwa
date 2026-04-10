<?php
declare(strict_types=1);

/**
 * POAdo RWA Cert Engine
 * File: /rwa/cert/api/run-royalty-pipeline.php
 *
 * Purpose
 * -------
 * Execute the complete royalty accounting pipeline:
 *
 * 1. scan royalties
 * 2. build holder claims
 * 3. build ACE claims
 * 4. build Gold Packet claims
 * 5. build treasury retained ledger
 *
 * Admin / Senior only.
 */

header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'].'/dashboard/inc/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/dashboard/inc/guards.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/dashboard/inc/json.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/dashboard/inc/session-user.php';

function exit_json($data,$status=200){
    http_response_code($status);
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

try {

    $wallet = get_wallet_session();
    if(!$wallet){
        exit_json(["ok"=>false,"error"=>"not_logged_in"],401);
    }

    db_connect();
    $pdo = $GLOBALS['pdo'];

    $u=$pdo->prepare("SELECT id,wallet,is_admin,is_senior,is_active FROM users WHERE wallet=? LIMIT 1");
    $u->execute([$wallet]);
    $user=$u->fetch(PDO::FETCH_ASSOC);

    if(!$user){
        exit_json(["ok"=>false,"error"=>"user_not_found"],404);
    }

    if(!$user['is_active']){
        exit_json(["ok"=>false,"error"=>"user_inactive"],403);
    }

    if(empty($user['is_admin']) && empty($user['is_senior'])){
        exit_json(["ok"=>false,"error"=>"admin_only"],403);
    }

    /* CSRF */
    $token=$_POST['csrf_token']??$_GET['csrf_token']??'';
    $csrf_ok=true;
    try{
        $r=csrf_check('run_royalty_pipeline',$token);
        if($r===false)$csrf_ok=false;
    }catch(Throwable $e){
        $csrf_ok=false;
    }

    if(!$csrf_ok){
        exit_json(["ok"=>false,"error"=>"csrf_failed"],419);
    }

    $base=$_SERVER['DOCUMENT_ROOT'].'/rwa/cert/api/';

    $steps=[
        "scan_royalties"           => "scan-royalties.php",
        "build_holder_claims"      => "build-holder-claims.php",
        "build_ace_claims"         => "build-ace-claims.php",
        "build_gold_packet_claims" => "build-gold-packet-claims.php",
        "build_treasury_retained"  => "build-treasury-retained.php"
    ];

    $results=[];
    $startTime=gmdate('Y-m-d H:i:s');

    foreach($steps as $name=>$file){

        $path=$base.$file;

        if(!file_exists($path)){
            $results[$name]=[
                "ok"=>false,
                "error"=>"missing_file",
                "file"=>$file
            ];
            continue;
        }

        try{

            ob_start();
            include $path;
            $raw=ob_get_clean();

            $json=json_decode($raw,true);

            if(is_array($json)){
                $results[$name]=$json;
            }else{
                $results[$name]=[
                    "ok"=>false,
                    "error"=>"invalid_json_response",
                    "raw"=>$raw
                ];
            }

        }catch(Throwable $e){

            $results[$name]=[
                "ok"=>false,
                "error"=>"execution_failed",
                "message"=>$e->getMessage()
            ];
        }
    }

    exit_json([
        "ok"=>true,
        "message"=>"Royalty pipeline executed",
        "started_at"=>$startTime,
        "finished_at"=>gmdate('Y-m-d H:i:s'),
        "executed_by_wallet"=>$wallet,
        "steps"=>$results
    ]);

}catch(Throwable $e){

    exit_json([
        "ok"=>false,
        "error"=>"server_error",
        "message"=>$e->getMessage()
    ],500);
}