<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/bootstrap.php';

$uid = $_GET['uid'] ?? '';

$pdo = $GLOBALS['pdo'];

$st = $pdo->prepare("SELECT nft_item_address FROM poado_rwa_certs WHERE cert_uid=? LIMIT 1");
$st->execute([$uid]);
$c = $st->fetch();

if (!$c || !$c['nft_item_address']) {
    die('NFT_NOT_FOUND');
}

$nft = trim($c['nft_item_address']);

// GetGems collection UI
$link = "https://getgems.io/collection/" . urlencode($nft);

header("Location: $link");
exit;
