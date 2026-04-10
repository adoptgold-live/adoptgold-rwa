<?php
declare(strict_types=1);

/**
 * POAdo RWA Cert Engine
 * File: /rwa/cert/cron/scan-getgems-sales.php
 *
 * Purpose:
 * - Cron-safe royalty scanner for Getgems / TON sale events
 * - Insert canonical royalty ledger rows into:
 *     wems_db.poado_rwa_royalty_events_v2
 */

$root = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '/var/www/html/public', '/');
require_once $root . '/dashboard/inc/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
}

if (!function_exists('poado_cron_out')) {
    function poado_cron_out(array $payload, int $status = 200): void
    {
        if (PHP_SAPI !== 'cli') {
            http_response_code($status);
            echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
        }
        exit;
    }
}

if (!function_exists('poado_cron_norm_decimal')) {
    function poado_cron_norm_decimal($value, int $scale = 9): string
    {
        if ($value === null || $value === '') {
            return number_format(0, $scale, '.', '');
        }
        $v = trim((string)$value);
        $v = str_replace(',', '', $v);
        if (!is_numeric($v)) {
            throw new InvalidArgumentException('Invalid decimal value: ' . $v);
        }
        return number_format((float)$v, $scale, '.', '');
    }
}

if (!function_exists('poado_cron_mul')) {
    function poado_cron_mul(string $amount, string $ratio, int $scale = 9): string
    {
        if (function_exists('bcmul')) {
            return bcadd(bcmul($amount, $ratio, $scale + 4), '0', $scale);
        }
        return number_format(((float)$amount) * ((float)$ratio), $scale, '.', '');
    }
}

if (!function_exists('poado_cron_event_uid')) {
    function poado_cron_event_uid(): string
    {
        return 'ROYALTY-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
    }
}

if (!function_exists('poado_cron_get_payload_file')) {
    function poado_cron_get_payload_file(string $root): string
    {
        global $argv;
        if (isset($argv[1]) && is_string($argv[1]) && trim($argv[1]) !== '') {
            return trim($argv[1]);
        }
        return $root . '/rwa/cert/tmp/logs/getgems-sales-scan.json';
    }
}

try {
    db_connect();
    /** @var PDO $pdo */
    $pdo = $GLOBALS['pdo'];

    $treasuryWallet = 'UQBdYfGArtoCUmBs5TjYQtfPFfQuGC2Ydbj2pQr3zIlNrDta';
    $payloadFile = poado_cron_get_payload_file($root);

    if (!is_file($payloadFile)) {
        poado_cron_out([
            'ok' => true,
            'message' => 'No upstream payload file found. Nothing to scan.',
            'payload_file' => $payloadFile,
            'processed' => 0,
            'inserted_count' => 0,
            'duplicate_count' => 0,
            'error_count' => 0,
        ], 200);
    }

    $raw = file_get_contents($payloadFile);
    $json = json_decode((string)$raw, true);

    if (!is_array($json)) {
        poado_cron_out([
            'ok' => false,
            'error' => 'invalid_payload',
            'message' => 'Payload file is not valid JSON.',
            'payload_file' => $payloadFile,
        ], 500);
    }

    $events = [];
    if (isset($json['events']) && is_array($json['events'])) {
        $events = $json['events'];
    } elseif (array_is_list($json)) {
        $events = $json;
    }

    if (!$events) {
        poado_cron_out([
            'ok' => true,
            'message' => 'Payload contains no sale events.',
            'payload_file' => $payloadFile,
            'processed' => 0,
            'inserted_count' => 0,
            'duplicate_count' => 0,
            'error_count' => 0,
        ], 200);
    }

    $findCertStmt = $pdo->prepare("
        SELECT id, cert_uid, cert_type, owner_user_id
        FROM poado_rwa_certs
        WHERE cert_uid = :cert_uid
        LIMIT 1
    ");

    $findDupStmt = $pdo->prepare("
        SELECT id, event_uid
        FROM poado_rwa_royalty_events_v2
        WHERE treasury_tx_hash = :treasury_tx_hash
          AND cert_uid = :cert_uid
        LIMIT 1
    ");

    $insertStmt = $pdo->prepare("
        INSERT INTO poado_rwa_royalty_events_v2
        (
            event_uid,
            cert_uid,
            nft_item_index,
            marketplace,
            sale_amount_ton,
            royalty_amount_ton,
            treasury_tx_hash,
            block_time,
            holder_pool_ton,
            ace_pool_ton,
            gold_packet_pool_ton,
            treasury_retained_ton
        )
        VALUES
        (
            :event_uid,
            :cert_uid,
            :nft_item_index,
            :marketplace,
            :sale_amount_ton,
            :royalty_amount_ton,
            :treasury_tx_hash,
            :block_time,
            :holder_pool_ton,
            :ace_pool_ton,
            :gold_packet_pool_ton,
            :treasury_retained_ton
        )
    ");

    $processed = 0;
    $inserted = [];
    $duplicates = [];
    $errors = [];

    foreach ($events as $idx => $event) {
        $processed++;
        try {
            $marketplace = strtolower(trim((string)($event['marketplace'] ?? '')));
            $certUid = preg_replace('/[^A-Za-z0-9\-]/', '', (string)($event['cert_uid'] ?? '')) ?: '';
            $treasuryTxHash = trim((string)($event['treasury_tx_hash'] ?? ''));
            $royaltyRecipient = trim((string)($event['royalty_recipient'] ?? $treasuryWallet));
            $blockTime = trim((string)($event['block_time'] ?? ''));
            $nftItemIndex = $event['nft_item_index'] ?? null;

            if ($marketplace === '' || $certUid === '' || $treasuryTxHash === '' || $blockTime === '') {
                throw new RuntimeException('Missing required event fields.');
            }

            if ($marketplace !== 'getgems') {
                throw new RuntimeException('Unsupported marketplace: ' . $marketplace);
            }

            if ($royaltyRecipient !== $treasuryWallet) {
                throw new RuntimeException('Royalty recipient mismatch. Event not paid to locked Treasury wallet.');
            }

            $saleAmountTon = poado_cron_norm_decimal($event['sale_amount_ton'] ?? null);
            $royaltyAmountTon = poado_cron_norm_decimal($event['royalty_amount_ton'] ?? null);

            if ((float)$saleAmountTon <= 0 || (float)$royaltyAmountTon <= 0) {
                throw new RuntimeException('Sale amount and royalty amount must be greater than zero.');
            }

            $expectedRoyalty = poado_cron_mul($saleAmountTon, '0.25');
            if (abs((float)$expectedRoyalty - (float)$royaltyAmountTon) > 0.00001) {
                throw new RuntimeException(
                    'Royalty amount does not match locked 25% model. Expected '
                    . $expectedRoyalty . ' got ' . $royaltyAmountTon
                );
            }

            $findCertStmt->execute([':cert_uid' => $certUid]);
            $cert = $findCertStmt->fetch(PDO::FETCH_ASSOC);
            if (!$cert) {
                throw new RuntimeException('cert_not_found: ' . $certUid);
            }

            $findDupStmt->execute([
                ':treasury_tx_hash' => $treasuryTxHash,
                ':cert_uid' => $certUid,
            ]);
            $dup = $findDupStmt->fetch(PDO::FETCH_ASSOC);
            if ($dup) {
                $duplicates[] = [
                    'cert_uid' => $certUid,
                    'treasury_tx_hash' => $treasuryTxHash,
                    'existing_event_uid' => $dup['event_uid'] ?? null,
                ];
                continue;
            }

            $holderPool = poado_cron_mul($saleAmountTon, '0.10');
            $acePool = poado_cron_mul($saleAmountTon, '0.05');
            $goldPacketPool = poado_cron_mul($saleAmountTon, '0.05');
            $treasuryRetained = poado_cron_mul($saleAmountTon, '0.05');

            $allocTotal = (float)$holderPool + (float)$acePool + (float)$goldPacketPool + (float)$treasuryRetained;
            if (abs($allocTotal - (float)$royaltyAmountTon) > 0.00001) {
                throw new RuntimeException('Internal allocation total does not match royalty amount.');
            }

            $eventUid = poado_cron_event_uid();

            $insertStmt->execute([
                ':event_uid' => $eventUid,
                ':cert_uid' => $certUid,
                ':nft_item_index' => ($nftItemIndex === null || $nftItemIndex === '') ? null : (int)$nftItemIndex,
                ':marketplace' => $marketplace,
                ':sale_amount_ton' => $saleAmountTon,
                ':royalty_amount_ton' => $royaltyAmountTon,
                ':treasury_tx_hash' => $treasuryTxHash,
                ':block_time' => $blockTime,
                ':holder_pool_ton' => $holderPool,
                ':ace_pool_ton' => $acePool,
                ':gold_packet_pool_ton' => $goldPacketPool,
                ':treasury_retained_ton' => $treasuryRetained,
            ]);

            $inserted[] = [
                'event_uid' => $eventUid,
                'cert_uid' => $certUid,
                'marketplace' => $marketplace,
                'sale_amount_ton' => $saleAmountTon,
                'royalty_amount_ton' => $royaltyAmountTon,
                'treasury_tx_hash' => $treasuryTxHash,
                'block_time' => $blockTime,
                'holder_pool_ton' => $holderPool,
                'ace_pool_ton' => $acePool,
                'gold_packet_pool_ton' => $goldPacketPool,
                'treasury_retained_ton' => $treasuryRetained,
            ];
        } catch (Throwable $e) {
            $errors[] = [
                'index' => $idx,
                'cert_uid' => $event['cert_uid'] ?? null,
                'treasury_tx_hash' => $event['treasury_tx_hash'] ?? null,
                'message' => $e->getMessage(),
            ];
        }
    }

    $archivePath = $payloadFile . '.' . gmdate('YmdHis') . '.done';
    @rename($payloadFile, $archivePath);

    poado_cron_out([
        'ok' => true,
        'message' => 'Getgems sales scan completed.',
        'payload_file' => $payloadFile,
        'archived_to' => is_file($archivePath) ? $archivePath : null,
        'processed' => $processed,
        'inserted_count' => count($inserted),
        'duplicate_count' => count($duplicates),
        'error_count' => count($errors),
        'inserted' => $inserted,
        'duplicates' => $duplicates,
        'errors' => $errors,
    ], 200);

} catch (Throwable $e) {
    poado_cron_out([
        'ok' => false,
        'error' => 'server_error',
        'message' => 'Failed to scan Getgems sales.',
        'details' => $e->getMessage(),
    ], 500);
}