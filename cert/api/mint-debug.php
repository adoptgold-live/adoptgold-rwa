<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once __DIR__ . '/_mint-onchain-verify.php';

function md_out(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function md_fail(string $error, string $detail = '', int $status = 400, array $extra = []): never
{
    $out = ['ok' => false, 'error' => $error];
    if ($detail !== '') $out['detail'] = $detail;
    if ($extra) $out += $extra;
    md_out($out, $status);
}

function md_db(): PDO
{
    if (function_exists('rwa_db')) {
        $pdo = rwa_db();
        if ($pdo instanceof PDO) return $pdo;
    }
    if (($GLOBALS['pdo'] ?? null) instanceof PDO) return $GLOBALS['pdo'];
    throw new RuntimeException('DB_UNAVAILABLE');
}

function md_req(array $in, string $key, string $default = ''): string
{
    $v = $in[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function md_json_decode(?string $json): array
{
    $json = trim((string)$json);
    if ($json === '') return [];
    $x = json_decode($json, true);
    return is_array($x) ? $x : [];
}

function md_fetch_cert(PDO $pdo, string $certUid): ?array
{
    $st = $pdo->prepare("SELECT * FROM poado_rwa_certs WHERE cert_uid = :uid LIMIT 1");
    $st->execute([':uid' => $certUid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function md_fetch_payment(PDO $pdo, string $certUid): ?array
{
    $st = $pdo->prepare("
        SELECT *
        FROM poado_rwa_cert_payments
        WHERE cert_uid = :uid
        ORDER BY id DESC
        LIMIT 1
    ");
    $st->execute([':uid' => $certUid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

try {
    $in = $_GET ?: $_POST ?: [];
    $certUid = md_req($in, 'cert_uid');
    if ($certUid === '') md_fail('CERT_UID_REQUIRED');

    $pdo = md_db();
    $cert = md_fetch_cert($pdo, $certUid);
    if (!$cert) md_fail('CERT_NOT_FOUND', $certUid, 404);

    $payment = md_fetch_payment($pdo, $certUid);
    $meta = md_json_decode((string)($cert['meta_json'] ?? ''));

    $mint = is_array($meta['mint'] ?? null) ? $meta['mint'] : [];
    $mintReq = is_array($mint['mint_request'] ?? null) ? $mint['mint_request'] : [];
    if (!$mintReq && is_array($meta['mint_request'] ?? null)) {
        $mintReq = $meta['mint_request'];
    }

    $paymentMeta = is_array($meta['payment'] ?? null) ? $meta['payment'] : [];

    $collectionAddress = trim((string)($mintReq['collection_address'] ?? $mint['collection_address'] ?? ''));
    $itemIndex = trim((string)($mintReq['item_index'] ?? $mint['item_index'] ?? ''));
    $txHint = trim((string)($cert['router_tx_hash'] ?? ''));

    $onchain = [];
    if ($collectionAddress !== '' && $itemIndex !== '') {
        $onchain = cert_mint_verify_onchain([
            'collection_address' => $collectionAddress,
            'item_index' => $itemIndex,
            'tx_hint' => $txHint,
            'lookback_seconds' => 86400,
        ]);
    }

    $dbMinted = ((string)($cert['status'] ?? '') === 'minted' || (int)($cert['nft_minted'] ?? 0) === 1);
    $canMarkMinted = !empty($onchain['ok']) && !empty($onchain['verified']);

    md_out([
        'ok' => true,
        'cert_uid' => $certUid,
        'summary' => [
            'db_status' => (string)($cert['status'] ?? ''),
            'db_nft_minted' => (int)($cert['nft_minted'] ?? 0),
            'db_minted_at' => (string)($cert['minted_at'] ?? ''),
            'dbMinted' => $dbMinted,
            'payment_status' => (string)($payment['status'] ?? $paymentMeta['status'] ?? ''),
            'payment_ref' => (string)($payment['payment_ref'] ?? $paymentMeta['payment_ref'] ?? ''),
            'mint_prepared' => ($collectionAddress !== '' && $itemIndex !== ''),
            'canMarkMintedNow' => $canMarkMinted,
        ],
        'cert_row' => [
            'id' => $cert['id'] ?? null,
            'cert_uid' => $cert['cert_uid'] ?? '',
            'rwa_type' => $cert['rwa_type'] ?? '',
            'family' => $cert['family'] ?? '',
            'rwa_code' => $cert['rwa_code'] ?? '',
            'status' => $cert['status'] ?? '',
            'owner_user_id' => $cert['owner_user_id'] ?? null,
            'ton_wallet' => $cert['ton_wallet'] ?? '',
            'nft_minted' => $cert['nft_minted'] ?? 0,
            'minted_at' => $cert['minted_at'] ?? '',
            'nft_item_address' => $cert['nft_item_address'] ?? '',
            'router_tx_hash' => $cert['router_tx_hash'] ?? '',
            'updated_at' => $cert['updated_at'] ?? '',
        ],
        'payment_row' => $payment ?: null,
        'mint_request' => [
            'collection_address' => $collectionAddress,
            'item_index' => $itemIndex,
            'tx_hint' => $txHint,
        ],
        'meta_extract' => [
            'payment' => $paymentMeta,
            'mint' => $mint,
            'mint_request' => $mintReq,
        ],
        'onchain_verify' => $onchain,
    ]);
} catch (Throwable $e) {
    md_fail('MINT_DEBUG_FAILED', $e->getMessage(), 500, [
        'trace_head' => explode("\n", $e->getTraceAsString())[0] ?? ''
    ]);
}
