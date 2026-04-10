<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/cron/_real-mint-executor.php
 * Version: v1.0.0-20260328-real-ton-mint
 *
 * REAL TON NFT MINT EXECUTOR
 *
 * This file MUST:
 * - mint NFT on TON
 * - return nft_item_address
 * - return tx_hash
 *
 * Required env:
 * - RWA_CERT_MINT_EXECUTOR_URL
 *
 * Expected API response:
 * {
 *   "ok": true,
 *   "nft_item_address": "EQ...",
 *   "collection_address": "EQ...",
 *   "tx_hash": "0x..."
 * }
 */

if (!function_exists('rwa_real_mint_env')) {
    function rwa_real_mint_env(string $key): string
    {
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?? '';
        return is_string($v) ? trim($v) : '';
    }
}

if (!function_exists('rwa_real_mint_http_post')) {
    function rwa_real_mint_http_post(string $url, array $payload): array
    {
        $ch = curl_init($url);

        if ($ch === false) {
            throw new RuntimeException('CURL_INIT_FAILED');
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $res = curl_exec($ch);
        $err = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($res === false) {
            throw new RuntimeException('MINT_HTTP_FAIL: ' . $err);
        }

        $json = json_decode((string)$res, true);

        if (!is_array($json)) {
            throw new RuntimeException('MINT_INVALID_JSON');
        }

        if ($http >= 400) {
            throw new RuntimeException('MINT_HTTP_' . $http);
        }

        return $json;
    }
}

if (!function_exists('rwa_cert_real_mint_execute')) {
    function rwa_cert_real_mint_execute(array $cert, array $payment, array $artifact): array
    {
        $executor = rwa_real_mint_env('RWA_CERT_MINT_EXECUTOR_URL');

        if ($executor === '') {
            return [
                'ok' => false,
                'error' => 'MINT_EXECUTOR_URL_NOT_SET'
            ];
        }

        $payload = [
            'cert_uid' => (string)$cert['cert_uid'],
            'wallet' => (string)$cert['ton_wallet'],
            'metadata_url' => (string)($artifact['metadata_url'] ?? ''),
            'image_url' => (string)($artifact['nft_image_url'] ?? ''),
            'pdf_url' => (string)($artifact['pdf_url'] ?? ''),
            'rwa_code' => (string)($cert['rwa_code'] ?? ''),
            'payment_tx' => (string)($payment['tx_hash'] ?? ''),
        ];

        $res = rwa_real_mint_http_post($executor, $payload);

        if (empty($res['ok'])) {
            return [
                'ok' => false,
                'error' => $res['error'] ?? 'MINT_FAILED'
            ];
        }

        $nft = trim((string)($res['nft_item_address'] ?? ''));

        if ($nft === '') {
            return [
                'ok' => false,
                'error' => 'MINT_NO_NFT_ADDRESS'
            ];
        }

        return [
            'ok' => true,
            'nft_item_address' => $nft,
            'collection_address' => (string)($res['collection_address'] ?? ''),
            'tx_hash' => (string)($res['tx_hash'] ?? ''),
        ];
    }
}
