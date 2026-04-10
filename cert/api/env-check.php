<?php
declare(strict_types=1);
require_once __DIR__ . '/../../inc/core/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
  'RWA_CERT_COLLECTION_ADDRESS' => getenv('RWA_CERT_COLLECTION_ADDRESS') ?: null,
  'RWA_CERT_COMMON_CONTENT_PREFIX' => getenv('RWA_CERT_COMMON_CONTENT_PREFIX') ?: null,
  'RWA_CERT_MINT_CMD' => getenv('RWA_CERT_MINT_CMD') ?: null,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
