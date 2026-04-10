<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$uid = trim((string)($_GET['cert_uid'] ?? $_GET['uid'] ?? ''));
if ($uid === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'CERT_UID_REQUIRED'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

$verifyUrl = 'https://adoptgold.app/rwa/cert/api/mint-verify.php?cert_uid=' . rawurlencode($uid);
$verifyStatusUrl = 'https://adoptgold.app/rwa/cert/api/verify-status.php?cert_uid=' . rawurlencode($uid);

function fetch_json(string $url): array {
    $ctx = stream_context_create([
        'http' => ['timeout' => 20, 'ignore_errors' => true],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    $json = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($json) ? $json : ['ok' => false, 'raw' => $raw];
}

$mv = fetch_json($verifyUrl);
$vs = fetch_json($verifyStatusUrl);

$row = null;
if (!empty($vs['rows']) && is_array($vs['rows'])) {
    $row = $vs['rows'][0] ?? null;
} elseif (!empty($vs['row']) && is_array($vs['row'])) {
    $row = $vs['row'];
}

$issued_truth = (
    (($mv['minted'] ?? false) === true || (int)($mv['nft_minted'] ?? 0) === 1) &&
    !empty($mv['nft_item_address']) &&
    !empty($mv['minted_at']) &&
    (($mv['queue_bucket'] ?? '') === 'issued')
);

echo json_encode([
    'ok' => true,
    'cert_uid' => $uid,
    'mint_verify' => $mv,
    'verify_status_row' => $row,
    'issued_truth' => $issued_truth,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
