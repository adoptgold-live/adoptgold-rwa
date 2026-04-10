<?php
declare(strict_types=1);

/**
 * /rwa/inc/core/claim-liquidity.php
 * Claim liquidity guard
 */

if (!function_exists('claim_token_wallet_env')) {
    function claim_token_wallet_env(string $token): string
    {
        return match (strtoupper(trim($token))) {
            'EMA'  => 'EMA_WALLET',
            'EMX'  => 'EMX_WALLET',
            'EMS'  => 'EMS_WALLET',
            'WEMS' => 'WEMS_WALLET',
            'USDT' => 'USDT_WALLET',
            default => '',
        };
    }
}

if (!function_exists('claim_wallet_address_for_token')) {
    function claim_wallet_address_for_token(string $token): string
    {
        $envName = claim_token_wallet_env($token);
        if ($envName === '') {
            return '';
        }
        return trim((string)($_ENV[$envName] ?? getenv($envName) ?: ''));
    }
}

if (!function_exists('claim_toncenter_api_key')) {
    function claim_toncenter_api_key(): string
    {
        return trim((string)($_ENV['TONCENTER_API_KEY'] ?? getenv('TONCENTER_API_KEY') ?: ''));
    }
}

if (!function_exists('claim_http_get_json')) {
    function claim_http_get_json(string $url, array $headers = []): ?array
    {
        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = $k . ': ' . $v;
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 20,
                'ignore_errors' => true,
                'header' => implode("\r\n", $headerLines),
            ]
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false || $raw === '') {
            return null;
        }

        $json = json_decode($raw, true);
        return is_array($json) ? $json : null;
    }
}

if (!function_exists('claim_fetch_wallet_jetton_balance_units')) {
    function claim_fetch_wallet_jetton_balance_units(string $walletAddress): ?string
    {
        $walletAddress = trim($walletAddress);
        if ($walletAddress === '') {
            return null;
        }

        $url = 'https://toncenter.com/api/v3/jetton/wallets?address=' . rawurlencode($walletAddress);
        $headers = [];

        $apiKey = claim_toncenter_api_key();
        if ($apiKey !== '') {
            $headers['X-API-Key'] = $apiKey;
        }

        $json = claim_http_get_json($url, $headers);
        if (!$json) {
            return null;
        }

        $wallets = $json['jetton_wallets'] ?? $json['wallets'] ?? null;
        if (!is_array($wallets) || empty($wallets[0])) {
            return null;
        }

        $row = $wallets[0];
        $balance = $row['balance'] ?? null;

        if ($balance === null) {
            return null;
        }

        return trim((string)$balance);
    }
}

if (!function_exists('claim_has_sufficient_liquidity')) {
    function claim_has_sufficient_liquidity(string $token, string $amountUnits): array
    {
        $wallet = claim_wallet_address_for_token($token);
        if ($wallet === '') {
            return [
                'ok' => false,
                'error' => 'TOKEN_WALLET_NOT_CONFIGURED',
                'wallet' => '',
                'balance_units' => null,
            ];
        }

        $balanceUnits = claim_fetch_wallet_jetton_balance_units($wallet);
        if ($balanceUnits === null || !preg_match('/^\d+$/', $balanceUnits)) {
            return [
                'ok' => false,
                'error' => 'BALANCE_FETCH_FAILED',
                'wallet' => $wallet,
                'balance_units' => $balanceUnits,
            ];
        }

        $need = trim($amountUnits);
        if (!preg_match('/^\d+$/', $need)) {
            return [
                'ok' => false,
                'error' => 'INVALID_AMOUNT_UNITS',
                'wallet' => $wallet,
                'balance_units' => $balanceUnits,
            ];
        }

        $enough = bccomp($balanceUnits, $need, 0) >= 0;

        return [
            'ok' => $enough,
            'error' => $enough ? '' : 'INSUFFICIENT_LIQUIDITY',
            'wallet' => $wallet,
            'balance_units' => $balanceUnits,
            'required_units' => $need,
        ];
    }
}