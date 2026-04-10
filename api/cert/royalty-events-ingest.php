<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/api/cert/royalty-events-ingest.php
 *
 * Purpose:
 * - ingest marketplace royalty sale events into poado_rwa_royalty_events_v2
 *
 * Locked rules:
 * - poado_rwa_royalty_events_v2 is the only source of truth
 * - royalty split:
 *   treasury      = 20% of royalty pot
 *   rewards_pool  = 60% of royalty pot
 *   gold_packet   = 20% of royalty pot
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';

function rei_json(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function rei_pdo(): PDO
{
    if (function_exists('db')) return db();
    if (function_exists('rwa_db')) return rwa_db();
    if (($GLOBALS['pdo'] ?? null) instanceof PDO) return $GLOBALS['pdo'];
    throw new RuntimeException('PDO_NOT_AVAILABLE');
}

function rei_input(): array
{
    $raw = file_get_contents('php://input');
    $json = json_decode((string)$raw, true);
    return is_array($json) ? ($json + $_POST + $_GET) : ($_POST + $_GET);
}

try {
    $pdo = rei_pdo();
    $in = rei_input();

    $eventRef = trim((string)($in['event_ref'] ?? ''));
    if ($eventRef === '') {
        $seed = implode('|', [
            (string)($in['sale_tx_hash'] ?? ''),
            (string)($in['nft_item_address'] ?? ''),
            (string)($in['sale_amount_ton'] ?? ''),
            microtime(true),
        ]);
        $eventRef = 'REV-' . date('YmdHis') . '-' . strtoupper(substr(sha1($seed), 0, 10));
    }

    $saleAmount = round((float)($in['sale_amount_ton'] ?? 0), 9);
    $royaltyAmount = round((float)($in['royalty_amount_ton'] ?? 0), 9);

    if ($saleAmount <= 0 || $royaltyAmount <= 0) {
        rei_json(['ok' => false, 'error' => 'SALE_AND_ROYALTY_REQUIRED'], 422);
    }

    $treasury = round($royaltyAmount * 0.20, 9);
    $rewards = round($royaltyAmount * 0.60, 9);
    $goldPacket = round($royaltyAmount * 0.20, 9);

    $sql = "
        INSERT INTO poado_rwa_royalty_events_v2 (
            event_ref, collection_address, nft_item_address, sale_tx_hash, seller_wallet, buyer_wallet,
            sale_amount_ton, royalty_amount_ton, treasury_ton, rewards_pool_ton, gold_packet_ton, snapshot_ref, created_at
        ) VALUES (
            :event_ref, :collection_address, :nft_item_address, :sale_tx_hash, :seller_wallet, :buyer_wallet,
            :sale_amount_ton, :royalty_amount_ton, :treasury_ton, :rewards_pool_ton, :gold_packet_ton, NULL, NOW()
        )
    ";
    $st = $pdo->prepare($sql);
    $st->execute([
        ':event_ref' => $eventRef,
        ':collection_address' => $in['collection_address'] ?? null,
        ':nft_item_address' => $in['nft_item_address'] ?? null,
        ':sale_tx_hash' => $in['sale_tx_hash'] ?? null,
        ':seller_wallet' => $in['seller_wallet'] ?? null,
        ':buyer_wallet' => $in['buyer_wallet'] ?? null,
        ':sale_amount_ton' => $saleAmount,
        ':royalty_amount_ton' => $royaltyAmount,
        ':treasury_ton' => $treasury,
        ':rewards_pool_ton' => $rewards,
        ':gold_packet_ton' => $goldPacket,
    ]);

    rei_json([
        'ok' => true,
        'event_ref' => $eventRef,
        'split' => [
            'treasury_ton' => $treasury,
            'rewards_pool_ton' => $rewards,
            'gold_packet_ton' => $goldPacket,
        ],
    ]);
} catch (Throwable $e) {
    rei_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
