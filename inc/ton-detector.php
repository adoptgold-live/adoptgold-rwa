<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/ton-address.php';

if (!function_exists('poado_http_json')) {
    function poado_http_json(string $url, array $headers = [], int $timeout = 15): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'error' => $err ?: 'HTTP error'];
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return ['ok' => false, 'error' => 'Invalid JSON response', 'http_code' => $code, 'raw' => $raw];
        }

        return ['ok' => true, 'http_code' => $code, 'json' => $json];
    }
}

if (!function_exists('poado_env')) {
    function poado_env(string $key, ?string $default = null): ?string {
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($v === false || $v === null || $v === '') return $default;
        return (string) $v;
    }
}

if (!function_exists('poado_ton_detect_wallet_assets')) {
    function poado_ton_detect_wallet_assets(string $walletAddress): array {
        $valid = poado_ton_validate_address($walletAddress);
        if (empty($valid['ok'])) {
            return ['ok' => false, 'error' => $valid['error'] ?? 'Invalid TON address'];
        }

        $rawAddr = (string)($valid['raw'] ?? $walletAddress);
        $apiBase = rtrim((string) poado_env('TONCENTER_BASE', 'https://toncenter.com/api/v3'), '/');
        $apiKey  = (string) poado_env('TONCENTER_API_KEY', '');
        $headers = $apiKey !== '' ? ['X-API-Key: ' . $apiKey] : [];

        $result = [
            'ok' => true,
            'wallet' => [
                'input' => $walletAddress,
                'raw' => $rawAddr,
                'format' => (string)($valid['format'] ?? 'unknown'),
            ],
            'native' => [
                'symbol' => 'TON',
                'balance_raw' => '0',
                'balance_display' => 0,
                'decimals' => 9,
            ],
            'jettons' => [],
            'warnings' => [],
        ];

        // 1) Native TON balance
        $u1 = $apiBase . '/account?address=' . rawurlencode($rawAddr);
        $r1 = poado_http_json($u1, $headers);
        if (!empty($r1['ok']) && !empty($r1['json']['balance'])) {
            $raw = (string) $r1['json']['balance'];
            $result['native']['balance_raw'] = $raw;
            $result['native']['balance_display'] = ((float)$raw) / 1000000000;
        } else {
            $result['warnings'][] = 'Unable to fetch native TON balance';
        }

        // 2) Jetton balances
        // Adjust endpoint if your chosen TON API differs
        $u2 = $apiBase . '/jetton/wallets?owner_address=' . rawurlencode($rawAddr) . '&limit=100&offset=0';
        $r2 = poado_http_json($u2, $headers);

        if (!empty($r2['ok']) && !empty($r2['json']['jetton_wallets']) && is_array($r2['json']['jetton_wallets'])) {
            foreach ($r2['json']['jetton_wallets'] as $jw) {
                $meta = $jw['jetton'] ?? [];
                $decimals = isset($meta['decimals']) && is_numeric($meta['decimals']) ? (int)$meta['decimals'] : 9;
                $balRaw = (string)($jw['balance'] ?? '0');
                $display = is_numeric($balRaw) ? ((float)$balRaw / (10 ** $decimals)) : 0;

                $result['jettons'][] = [
                    'asset_type' => 'jetton',
                    'token_symbol' => (string)($meta['symbol'] ?? 'JETTON'),
                    'token_name' => (string)($meta['name'] ?? 'Jetton'),
                    'master_address' => (string)($meta['address'] ?? ''),
                    'jetton_wallet_address' => (string)($jw['address'] ?? ''),
                    'balance_raw' => $balRaw,
                    'balance_display' => $display,
                    'decimals' => $decimals,
                    'metadata_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            }
        } else {
            $result['warnings'][] = 'Unable to fetch jetton balances';
        }

        return $result;
    }
}