<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';

function mf_out(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function mf_fail(string $error, string $detail = '', int $status = 400): never
{
    $out = ['ok' => false, 'error' => $error];
    if ($detail !== '') $out['detail'] = $detail;
    mf_out($out, $status);
}

function mf_db(): PDO
{
    if (function_exists('rwa_db')) {
        $pdo = rwa_db();
        if ($pdo instanceof PDO) return $pdo;
    }
    if (($GLOBALS['pdo'] ?? null) instanceof PDO) return $GLOBALS['pdo'];
    throw new RuntimeException('DB_UNAVAILABLE');
}

try {
    $certUid = trim((string)($_GET['cert_uid'] ?? ''));
    $itemAddress = trim((string)($_GET['item_address'] ?? ''));
    $txHash = trim((string)($_GET['tx_hash'] ?? ''));

    if ($certUid === '') mf_fail('CERT_UID_REQUIRED');

    $pdo = mf_db();
    $sql = "
        UPDATE poado_rwa_certs
        SET
            status = 'minted',
            nft_minted = 1,
            minted_at = COALESCE(minted_at, UTC_TIMESTAMP()),
            updated_at = UTC_TIMESTAMP(),
            nft_item_address = CASE WHEN :item_address <> '' THEN :item_address ELSE nft_item_address END,
            router_tx_hash = CASE WHEN :tx_hash <> '' THEN :tx_hash ELSE router_tx_hash END
        WHERE cert_uid = :cert_uid
        LIMIT 1
    ";
    $st = $pdo->prepare($sql);
    $st->execute([
        ':item_address' => $itemAddress,
        ':tx_hash' => $txHash,
        ':cert_uid' => $certUid,
    ]);

    mf_out([
        'ok' => true,
        'cert_uid' => $certUid,
        'status' => 'minted',
        'item_address' => $itemAddress,
        'tx_hash' => $txHash,
    ]);
} catch (Throwable $e) {
    mf_fail('FORCE_FINALIZE_FAILED', $e->getMessage(), 500);
}
