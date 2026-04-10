<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/bootstrap.php';

db_connect();
$pdo = $GLOBALS['pdo'] ?? null;

if (!$pdo instanceof PDO) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'DB_NOT_READY']);
    exit;
}

function s($v){ return trim((string)($v ?? '')); }

function hasArtifacts($r){
    return s($r['metadata_path']) !== '' && s($r['nft_image_path']) !== '';
}

/**
 * FINAL RULE:
 * minting_process = ONLY active (<5 min) session
 */
function isActiveMinting(array $meta): bool
{
    $handoff = is_array($meta['mint_handoff_v9'] ?? null)
        ? $meta['mint_handoff_v9']
        : [];

    $flow = strtolower(s($handoff['flow_state'] ?? ''));
    $step = strtolower(s($handoff['handoff']['step'] ?? ''));
    $at   = s($handoff['handoff']['at'] ?? '');

    $txHash = s(
        $meta['mint']['tx_hash']
        ?? ($meta['mint']['final']['tx_hash'] ?? '')
    );

    $mintedAt = s(
        $meta['mint']['minted_at']
        ?? ($meta['minted_at'] ?? '')
    );

    // already done → NOT minting
    if ($txHash !== '' || $mintedAt !== '') return false;

    // must be minting + wallet step
    if ($flow !== 'minting') return false;
    if (!in_array($step, ['wallet_sign','submitted'], true)) return false;

    // must have timestamp
    if ($at === '') return false;

    $ts = strtotime($at);
    if (!$ts) return false;

    // expire after 5 min
    if ((time() - $ts) > 300) return false;

    return true;
}

$sql = "
SELECT
    c.cert_uid,
    c.rwa_code,
    c.payment_ref,
    c.metadata_path,
    c.nft_image_path,
    c.nft_minted,
    c.status AS cert_status,
    c.meta_json,
    p.status AS payment_status,
    p.verified AS payment_verified
FROM poado_rwa_certs c
LEFT JOIN poado_rwa_cert_payments p
ON p.id = (
    SELECT id FROM poado_rwa_cert_payments
    WHERE cert_uid = c.cert_uid
    ORDER BY id DESC LIMIT 1
)
ORDER BY c.id DESC
";

$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$buckets = [
    'issuance_factory'=>[],
    'payment_confirmation'=>[],
    'payment_confirmed_pending_artifact'=>[],
    'mint_ready_queue'=>[],
    'minting_process'=>[],
    'issued'=>[],
    'blocked'=>[],
];

foreach($rows as $r){

    $meta = json_decode($r['meta_json'] ?? '{}', true) ?: [];

    $paymentStatus = strtolower(s($r['payment_status']));
    $verified = (int)$r['payment_verified'] === 1;
    $ref = s($r['payment_ref']);
    $minted = (int)$r['nft_minted'] === 1;

    if ($minted || $r['cert_status']==='minted'){
        $bucket = 'issued';
    }
    elseif ($r['cert_status']==='revoked'){
        $bucket = 'blocked';
    }
    elseif ($ref===''){
        $bucket = 'issuance_factory';
    }
    elseif ($paymentStatus!=='confirmed' || !$verified){
        $bucket = 'payment_confirmation';
    }
    elseif (!hasArtifacts($r)){
        $bucket = 'payment_confirmed_pending_artifact';
    }
    elseif (isActiveMinting($meta)){
        $bucket = 'minting_process';
    }
    else{
        $bucket = 'mint_ready_queue';
    }

    $buckets[$bucket][] = [
        'cert_uid'=>$r['cert_uid'],
        'rwa_code'=>$r['rwa_code'],
        'queue_bucket'=>$bucket,
        'payment_status'=>$paymentStatus,
        'payment_verified'=>$verified?1:0,
        'nft_minted'=>$minted?1:0
    ];
}

$counts = [];
foreach($buckets as $k=>$v){
    $counts[$k] = count($v);
}

echo json_encode([
    'ok'=>true,
    'counts'=>$counts,
    'buckets'=>$buckets
], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
