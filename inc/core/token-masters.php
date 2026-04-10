<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/inc/core/token-masters.php
 * Version: v1.0.0-20260407-global-jetton-registry-lock
 *
 * GLOBAL LOCK
 * Canonical token master registry for all standalone RWA / ADG modules.
 */

if (!function_exists('tm_env')) {
    function tm_env(string $key, string $default = ''): string
    {
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        return is_string($v) && trim($v) !== '' ? trim($v) : $default;
    }
}

if (!function_exists('token_master_address')) {
    function token_master_address(string $token): string
    {
        return match (strtoupper(trim($token))) {
            'EMA$', 'EMA'      => tm_env('EMA_MASTER_ADDRESS', 'EQDK-bRI706S1cIIoLhTrTf-e8pL2TpOD5rcP3OaxYyzs74b'),
            'EMX'              => tm_env('EMX_MASTER_ADDRESS', 'EQBj0zGcHOvN5IsBP_BAAG5NRiuAa_SLBu-xjsJn7AeM4nQy'),
            'EMS'              => tm_env('EMS_MASTER_ADDRESS', 'EQCpJURzB4DJcL1keSRF8u5J5SmakM-_FaftTAyXRrVnnNmD'),
            'WEMS'             => tm_env('WEMS_MASTER_ADDRESS', 'EQA8dAgNtnsfGF0M-MJfnqii5AhxcRe73M8nCkkxuq85Tr-Q'),
            'USDT', 'USDT_TON' => tm_env('USDT_MASTER_ADDRESS', 'EQCxE6mUtQJKFnGfaROTKOt1lZbDiiX1kCixRv7Nw2Id_sDs'),
            default            => '',
        };
    }
}

if (!function_exists('token_decimals')) {
    function token_decimals(string $token): int
    {
        return match (strtoupper(trim($token))) {
            'USDT', 'USDT_TON' => 6,
            'EMA$', 'EMA', 'EMX', 'EMS', 'WEMS' => 9,
            default => 9,
        };
    }
}

if (!function_exists('token_units_from_display')) {
    function token_units_from_display(string $displayAmount, string $token): string
    {
        $displayAmount = trim($displayAmount);
        if ($displayAmount === '' || !preg_match('/^\d+(?:\.\d+)?$/', $displayAmount)) {
            return '0';
        }

        $decimals = token_decimals($token);
        $parts = explode('.', $displayAmount, 2);
        $whole = $parts[0];
        $frac = $parts[1] ?? '';
        $frac = substr($frac . str_repeat('0', $decimals), 0, $decimals);

        $units = ltrim($whole . $frac, '0');
        return $units === '' ? '0' : $units;
    }
}

if (!function_exists('build_jetton_deeplink')) {
    function build_jetton_deeplink(string $recipient, string $token, string $amountUnits, string $paymentRef = ''): string
    {
        $recipient = trim($recipient);
        $amountUnits = trim($amountUnits);
        $paymentRef = trim($paymentRef);
        $master = token_master_address($token);

        if ($recipient === '' || $master === '' || $amountUnits === '') {
            return '';
        }

        $qs = [
            'jetton=' . rawurlencode($master),
            'amount=' . rawurlencode($amountUnits),
        ];

        if ($paymentRef !== '') {
            $qs[] = 'text=' . rawurlencode($paymentRef);
        }

        return 'ton://transfer/' . rawurlencode($recipient) . '?' . implode('&', $qs);
    }
}

if (!function_exists('assert_token_master_address')) {
    function assert_token_master_address(string $token): string
    {
        $master = token_master_address($token);
        if ($master === '') {
            throw new RuntimeException('JETTON_MASTER_REQUIRED:' . strtoupper(trim($token)));
        }
        return $master;
    }
}
