<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/bootstrap.php';

$uid = $_GET['uid'] ?? '';

$pdo = $GLOBALS['pdo'];

$st = $pdo->prepare("SELECT * FROM poado_rwa_certs WHERE cert_uid=? LIMIT 1");
$st->execute([$uid]);
$c = $st->fetch();

if (!$c || $c['status'] !== 'minted') {
    die('NOT_MINTED');
}

$meta = json_decode($c['meta_json'], true);

$nft = trim($c['nft_item_address']);
$priceTon = 1.0;

// TON nano
$priceNano = (string)intval($priceTon * 1e9);

$data = [
    "nft_address" => $nft,
    "price_ton" => $priceTon,
    "price_nano" => $priceNano,
    "metadata" => $meta['vault']['metadata'] ?? ''
];

header('Content-Type: application/json');
echo json_encode($data, JSON_UNESCAPED_SLASHES);
