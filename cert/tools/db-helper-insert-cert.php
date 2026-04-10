<?php
declare(strict_types=1);

/**
 * FINAL — DB Helper (REAL0002)
 * Safe for live schema
 */

require_once '/var/www/html/public/rwa/inc/core/bootstrap.php';

function dbh(): PDO {
    if (function_exists('rwa_db')) {
        $pdo = rwa_db();
    } elseif (function_exists('db')) {
        $pdo = db();
    } elseif (($GLOBALS['pdo'] ?? null) instanceof PDO) {
        $pdo = $GLOBALS['pdo'];
    } else {
        throw new RuntimeException('DB_NOT_READY');
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function q(PDO $pdo, string $sql, array $b=[]): void {
    $pdo->prepare($sql)->execute($b);
}

function one(PDO $pdo, string $sql, array $b=[]): ?array {
    $st=$pdo->prepare($sql);
    $st->execute($b);
    $r=$st->fetch();
    return is_array($r)?$r:null;
}

function now(): string { return gmdate('Y-m-d H:i:s'); }

/* ============================
   CONFIG (READY)
============================ */

$cert_uid      = 'RK92-EMA-20260406-REAL0002';
$payment_ref   = 'PAY-RK92EMA20260406REAL0002';

$owner_user_id = 13;
$wallet        = 'UQBxc1nE_MGtIQpy1wTzVnoQTPfQmv5st_u2QJWSNNAvbYAv';

$rwa_type      = 'gold';
$family        = 'GENESIS';
$rwa_code      = 'RK92-EMA';

$price_units   = '50000';
$price_wems    = 50000;

$payment_token = 'WEMS';
$payment_amt   = '50000.00000000';

$token_master  = 'EQA8dAgNtnsfGF0M-MJfnqii5AhxcRe73M8nCkkxuq85Tr-Q';

/* ============================
   EXECUTION
============================ */

$pdo = dbh();

/* --- safety --- */

if (one($pdo,"SELECT id FROM poado_rwa_certs WHERE cert_uid=?",[$cert_uid])) {
    echo "SKIP cert exists\n";
    exit;
}
if (one($pdo,"SELECT id FROM poado_rwa_cert_payments WHERE payment_ref=?",[$payment_ref])) {
    echo "SKIP payment exists\n";
    exit;
}

/* --- meta json --- */

$meta = json_encode([
    'helper' => true,
    'uid' => $cert_uid,
    'rwa_code' => $rwa_code,
    'created_at' => now()
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

/* --- insert cert --- */

q($pdo,"
INSERT INTO poado_rwa_certs (
    cert_uid,
    rwa_type,
    family,
    rwa_code,
    price_wems,
    price_units,
    payment_ref,
    payment_token,
    payment_amount,
    owner_user_id,
    ton_wallet,
    pdf_path,
    nft_image_path,
    metadata_path,
    verify_url,
    meta_json,
    nft_item_address,
    nft_minted,
    status,
    issued_at,
    paid_at,
    updated_at
) VALUES (
    :cert_uid,
    :rwa_type,
    :family,
    :rwa_code,
    :price_wems,
    :price_units,
    :payment_ref,
    :payment_token,
    :payment_amount,
    :owner_user_id,
    :ton_wallet,
    :pdf_path,
    :nft_image_path,
    :metadata_path,
    :verify_url,
    :meta_json,
    NULL,
    0,
    'issued',
    UTC_TIMESTAMP(),
    UTC_TIMESTAMP(),
    UTC_TIMESTAMP()
)
",[
    ':cert_uid'=>$cert_uid,
    ':rwa_type'=>$rwa_type,
    ':family'=>$family,
    ':rwa_code'=>$rwa_code,
    ':price_wems'=>$price_wems,
    ':price_units'=>$price_units,
    ':payment_ref'=>$payment_ref,
    ':payment_token'=>$payment_token,
    ':payment_amount'=>$payment_amt,
    ':owner_user_id'=>$owner_user_id,
    ':ton_wallet'=>$wallet,
    ':pdf_path'=>"/rwa/metadata/cert/bootstrap/$cert_uid/pdf/cert.pdf",
    ':nft_image_path'=>"/rwa/metadata/cert/bootstrap/$cert_uid/nft/image.png",
    ':metadata_path'=>"/rwa/metadata/cert/bootstrap/$cert_uid/meta/metadata.json",
    ':verify_url'=>"https://adoptgold.app/rwa/cert/verify.php?uid=$cert_uid",
    ':meta_json'=>$meta
]);

/* --- insert payment --- */

q($pdo,"
INSERT INTO poado_rwa_cert_payments (
    cert_uid,
    payment_ref,
    owner_user_id,
    ton_wallet,
    token_symbol,
    token_master,
    decimals,
    amount,
    amount_units,
    status,
    tx_hash,
    verified,
    paid_at,
    meta_json
) VALUES (
    :cert_uid,
    :payment_ref,
    :owner_user_id,
    :ton_wallet,
    :token_symbol,
    :token_master,
    9,
    :amount,
    :amount_units,
    'confirmed',
    :tx_hash,
    1,
    UTC_TIMESTAMP(),
    :meta_json
)
",[
    ':cert_uid'=>$cert_uid,
    ':payment_ref'=>$payment_ref,
    ':owner_user_id'=>$owner_user_id,
    ':ton_wallet'=>$wallet,
    ':token_symbol'=>$payment_token,
    ':token_master'=>$token_master,
    ':amount'=>$payment_amt,
    ':amount_units'=>$price_units,
    ':tx_hash'=>"HELPER_TX_REAL0002",
    ':meta_json'=>$meta
]);

echo "OK\n";
echo "cert_uid=$cert_uid\n";
echo "payment_ref=$payment_ref\n";
