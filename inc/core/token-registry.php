<?php
declare(strict_types=1);

/**
 * /rwa/inc/core/token-registry.php
 * AdoptGold / POAdo — Canonical TON Jetton Token Registry
 * Version: v1.0.0-20260319
 *
 * Rules:
 * - standalone RWA core helper
 * - raw master addresses only
 * - no friendly EQ... addresses as canonical source
 * - env-backed
 * - safe for repeated include/require
 */

if (!function_exists('poado_token_env')) {
    function poado_token_env(string $key, string $default = ''): string
    {
        $v = getenv($key);
        if (is_string($v) && $v !== '') {
            return $v;
        }

        if (isset($_ENV[$key]) && is_scalar($_ENV[$key])) {
            $tmp = (string)$_ENV[$key];
            if ($tmp !== '') {
                return $tmp;
            }
        }

        if (isset($_SERVER[$key]) && is_scalar($_SERVER[$key])) {
            $tmp = (string)$_SERVER[$key];
            if ($tmp !== '') {
                return $tmp;
            }
        }

        return $default;
    }
}

if (!function_exists('poado_token_registry')) {
    function poado_token_registry(): array
    {
        static $registry = null;
        if (is_array($registry)) {
            return $registry;
        }

        $registry = [
            'EMA' => [
                'key' => 'EMA',
                'symbol' => 'EMA$',
                'name' => 'eMoney RWA Adoption Token',
                'master_raw' => trim(poado_token_env('EMA_JETTON_MASTER_RAW', '0:caf9b448ef4e92d5c208a0b853ad37fe7bca4bd93a4e0f9adc3f739ac58cb3b3')),
                'decimals' => (int)poado_token_env('EMA_DECIMALS', '9'),
                'aliases' => ['EMA', 'EMA$'],
                'ui_label' => 'EMA$',
                'icon' => '/metadata/ema.png',
            ],
            'EMX' => [
                'key' => 'EMX',
                'symbol' => 'EMX',
                'name' => 'eMoney XAU Gold RWA Stable Token',
                'master_raw' => trim(poado_token_env('EMX_JETTON_MASTER_RAW', '0:63d3319c1cebcde48b013ff040006e4d462b806bf48b06efb18ec267ec078ce2')),
                'decimals' => (int)poado_token_env('EMX_DECIMALS', '9'),
                'aliases' => ['EMX'],
                'ui_label' => 'EMX',
                'icon' => '/metadata/emx.png',
            ],
            'EMS' => [
                'key' => 'EMS',
                'symbol' => 'EMS',
                'name' => 'eMoney Solvency RWA Fuel Token',
                'master_raw' => trim(poado_token_env('EMS_JETTON_MASTER_RAW', '0:a92544730780c970bd64792445f2ee49e5299a90cfbf15a7ed4c0c9746b5679c')),
                'decimals' => (int)poado_token_env('EMS_DECIMALS', '9'),
                'aliases' => ['EMS'],
                'ui_label' => 'EMS',
                'icon' => '/metadata/ems.png',
            ],
            'WEMS' => [
                'key' => 'WEMS',
                'symbol' => 'WEMS',
                'name' => 'WEB3 Gold Mining Reward Token',
                'master_raw' => trim(poado_token_env('WEMS_JETTON_MASTER_RAW', '0:3c74080db67b1f185d0cf8c25f9ea8a2e408717117bbdccf270a4931baaf394e')),
                'decimals' => (int)poado_token_env('WEMS_DECIMALS', '9'),
                'aliases' => ['WEMS', 'wEMS'],
                'ui_label' => 'wEMS',
                'icon' => '/metadata/wems.png',
            ],
            'USDT_TON' => [
                'key' => 'USDT_TON',
                'symbol' => 'USDT-TON',
                'name' => 'Tether USD',
                'master_raw' => trim(poado_token_env('USDT_TON_JETTON_MASTER_RAW', '0:b113a994b5024a16719f69139328eb759596c38a25f59028b146fecdc3621dfe')),
                'decimals' => (int)poado_token_env('USDT_TON_DECIMALS', '6'),
                'aliases' => ['USDT', 'USDT-TON', 'USDTTON', 'USD₮'],
                'ui_label' => 'USDT-TON',
                'icon' => '/metadata/usdt_ton.png',
            ],
        ];

        return $registry;
    }
}

if (!function_exists('poado_token_registry_get')) {
    function poado_token_registry_get(string $key): ?array
    {
        $key = strtoupper(trim($key));
        $all = poado_token_registry();
        return $all[$key] ?? null;
    }
}

if (!function_exists('poado_token_registry_all')) {
    function poado_token_registry_all(): array
    {
        return poado_token_registry();
    }
}

if (!function_exists('poado_token_registry_master_map')) {
    function poado_token_registry_master_map(): array
    {
        $out = [];
        foreach (poado_token_registry() as $key => $cfg) {
            $master = trim((string)($cfg['master_raw'] ?? ''));
            if ($master !== '') {
                $out[$key] = $master;
            }
        }
        return $out;
    }
}

if (!function_exists('poado_token_registry_alias_map')) {
    function poado_token_registry_alias_map(): array
    {
        $out = [];
        foreach (poado_token_registry() as $key => $cfg) {
            $out[$key] = $key;

            $symbol = strtoupper(trim((string)($cfg['symbol'] ?? '')));
            if ($symbol !== '') {
                $out[$symbol] = $key;
            }

            foreach ((array)($cfg['aliases'] ?? []) as $alias) {
                $a = strtoupper(trim((string)$alias));
                if ($a !== '') {
                    $out[$a] = $key;
                }
            }
        }
        return $out;
    }
}

if (!function_exists('poado_token_registry_resolve_key')) {
    function poado_token_registry_resolve_key(string $value): ?string
    {
        $v = strtoupper(trim($value));
        if ($v === '') {
            return null;
        }

        $map = poado_token_registry_alias_map();
        return $map[$v] ?? null;
    }
}

if (!function_exists('poado_token_registry_find_by_master')) {
    function poado_token_registry_find_by_master(string $masterRaw): ?array
    {
        $needle = trim($masterRaw);
        if ($needle === '') {
            return null;
        }

        foreach (poado_token_registry() as $cfg) {
            if (trim((string)($cfg['master_raw'] ?? '')) === $needle) {
                return $cfg;
            }
        }

        return null;
    }
}

if (!function_exists('poado_token_decimals')) {
    function poado_token_decimals(string $key): int
    {
        $resolved = poado_token_registry_resolve_key($key);
        if ($resolved === null) {
            return 9;
        }

        $cfg = poado_token_registry_get($resolved);
        return (int)($cfg['decimals'] ?? 9);
    }
}

if (!function_exists('poado_token_master_raw')) {
    function poado_token_master_raw(string $key): string
    {
        $resolved = poado_token_registry_resolve_key($key);
        if ($resolved === null) {
            return '';
        }

        $cfg = poado_token_registry_get($resolved);
        return trim((string)($cfg['master_raw'] ?? ''));
    }
}

if (!function_exists('poado_token_label')) {
    function poado_token_label(string $key): string
    {
        $resolved = poado_token_registry_resolve_key($key);
        if ($resolved === null) {
            return trim($key);
        }

        $cfg = poado_token_registry_get($resolved);
        return trim((string)($cfg['ui_label'] ?? $resolved));
    }
}

if (!function_exists('poado_token_icon')) {
    function poado_token_icon(string $key): string
    {
        $resolved = poado_token_registry_resolve_key($key);
        if ($resolved === null) {
            return '';
        }

        $cfg = poado_token_registry_get($resolved);
        return trim((string)($cfg['icon'] ?? ''));
    }
}

if (!function_exists('poado_token_format_units')) {
    function poado_token_format_units(string $rawAmount, int $decimals, int $scale = 6): string
    {
        $rawAmount = trim($rawAmount);
        if ($rawAmount === '' || !preg_match('/^-?\d+$/', $rawAmount)) {
            return number_format(0, $scale, '.', '');
        }

        $neg = false;
        if ($rawAmount[0] === '-') {
            $neg = true;
            $rawAmount = substr($rawAmount, 1);
        }

        $rawAmount = ltrim($rawAmount, '0');
        if ($rawAmount === '') {
            $rawAmount = '0';
        }

        $decimals = max(0, $decimals);
        $scale = max(0, $scale);

        if ($decimals === 0) {
            $out = $rawAmount;
        } else {
            $rawAmount = str_pad($rawAmount, $decimals + 1, '0', STR_PAD_LEFT);
            $intPart = substr($rawAmount, 0, -$decimals);
            $fracPart = substr($rawAmount, -$decimals);
            $fracPart = substr($fracPart, 0, $scale);
            $fracPart = str_pad($fracPart, $scale, '0');
            $out = $intPart . ($scale > 0 ? '.' . $fracPart : '');
        }

        if ($neg && $out !== '0' && $out !== '0.' . str_repeat('0', $scale)) {
            $out = '-' . $out;
        }

        return $out;
    }
}