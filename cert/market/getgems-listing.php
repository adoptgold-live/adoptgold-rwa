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

$priceTon = 1; // adjustable

$data = [
    "marketplace" => "getgems",
    "nft_address" => $c['nft_item_address'],
    "price_ton" => $priceTon,
    "royalty_percent" => 25,
    "metadata" => $meta['vault']['metadata'] ?? ''
];

header('Content-Type: application/json');
echo json_encode($data, JSON_UNESCAPED_SLASHES);
