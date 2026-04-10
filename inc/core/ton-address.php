<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/inc/core/ton-address.php
 * Version: v1.1.0-20260330-raw-friendly-tolerant
 *
 * Purpose:
 * - accept TON raw or friendly address input
 * - normalize locally when a TON PHP library is available
 * - gracefully tolerate raw addresses when no TON class/autoload exists
 *
 * Global lock:
 * - this helper is the canonical TON address normalizer across modules and ADG chats
 * - do not call Toncenter just for address conversion
 * - do not reject raw 0:... input merely because conversion class is missing
 */

if (function_exists('poado_ton_address_normalize')) {
    return;
}

function poado_ton_address_autoload_paths(): array
{
    return [
        '/var/www/html/public/rwa/vendor/autoload.php',
        '/var/www/html/public/dashboard/vendor/autoload.php',
        '/var/www/html/public/vendor/autoload.php',
    ];
}

function poado_ton_address_require_autoload(): bool
{
    static $loaded = null;
    if ($loaded !== null) {
        return $loaded;
    }

    foreach (poado_ton_address_autoload_paths() as $path) {
        if (is_file($path)) {
            require_once $path;
            $loaded = true;
            return true;
        }
    }

    $loaded = false;
    return false;
}

function poado_ton_is_raw_address(string $value): bool
{
    $value = trim($value);
    return (bool)preg_match('/^[+-]?\d+:[0-9a-fA-F]{64}$/', $value);
}

function poado_ton_is_friendly_address(string $value): bool
{
    $value = trim($value);
    return (bool)preg_match('/^[A-Za-z0-9\-_]{36,128}$/', $value);
}

function poado_ton_address_find_php_class(): string
{
    poado_ton_address_require_autoload();

    foreach ([
        '\\Ton\\Core\\Address',
        '\\ton\\core\\Address',
        '\\TonCore\\Address',
    ] as $cls) {
        if (class_exists($cls)) {
            return $cls;
        }
    }

    return '';
}

function poado_ton_address_normalize(string $input): array
{
    $input = trim($input);

    $result = [
        'ok' => false,
        'input' => $input,
        'input_type' => 'unknown',
        'raw' => '',
        'friendly' => '',
        'bounceable' => '',
        'non_bounceable' => '',
        'error' => '',
        'normalized' => false,
        'library_available' => false,
    ];

    if ($input === '') {
        $result['error'] = 'EMPTY_TON_ADDRESS';
        return $result;
    }

    if (poado_ton_is_raw_address($input)) {
        $result['input_type'] = 'raw';
        $result['raw'] = $input;
    } elseif (poado_ton_is_friendly_address($input)) {
        $result['input_type'] = 'friendly';
        $result['friendly'] = $input;
        $result['bounceable'] = $input;
    } else {
        $result['error'] = 'INVALID_TON_ADDRESS_FORMAT';
        return $result;
    }

    $cls = poado_ton_address_find_php_class();
    $result['library_available'] = ($cls !== '');

    /**
     * Graceful fallback:
     * - friendly input is always acceptable as friendly even if no library exists
     * - raw input is acceptable as raw even if no library exists
     *   (normalized=false tells callers conversion was not available)
     */
    if ($cls === '') {
        if ($result['input_type'] === 'friendly') {
            $result['ok'] = true;
            $result['normalized'] = false;
            $result['non_bounceable'] = $input;
            return $result;
        }

        if ($result['input_type'] === 'raw') {
            $result['ok'] = true;
            $result['normalized'] = false;
            $result['error'] = 'TON_ADDRESS_CLASS_NOT_FOUND';
            return $result;
        }

        $result['error'] = 'TON_ADDRESS_CLASS_NOT_FOUND';
        return $result;
    }

    try {
        $addr = $cls::parse($input);

        $raw = method_exists($addr, 'toRawString') ? (string)$addr->toRawString() : ($result['raw'] !== '' ? $result['raw'] : '');
        $bounceable = '';
        $nonBounceable = '';

        if (method_exists($addr, 'toString')) {
            try {
                $bounceable = (string)$addr->toString(true, true, true, false);
            } catch (Throwable) {
                try {
                    $bounceable = (string)$addr->toString();
                } catch (Throwable) {
                    $bounceable = '';
                }
            }

            try {
                $nonBounceable = (string)$addr->toString(true, true, false, false);
            } catch (Throwable) {
                $nonBounceable = '';
            }
        }

        $friendly = $bounceable !== '' ? $bounceable : ($result['friendly'] !== '' ? $result['friendly'] : '');

        if ($result['input_type'] === 'raw' && $friendly === '') {
            $result['error'] = 'TON_FRIENDLY_CONVERSION_FAILED';
            return $result;
        }

        $result['ok'] = true;
        $result['normalized'] = true;
        $result['raw'] = $raw !== '' ? $raw : $result['raw'];
        $result['friendly'] = $friendly;
        $result['bounceable'] = $bounceable !== '' ? $bounceable : $friendly;
        $result['non_bounceable'] = $nonBounceable !== '' ? $nonBounceable : ($friendly !== '' ? $friendly : $result['non_bounceable']);

        return $result;
    } catch (Throwable $e) {
        /**
         * Last-resort tolerance:
         * - keep raw accepted if parse class exists but parse failed unexpectedly
         * - keep friendly accepted as-is if parse failed unexpectedly
         */
        if ($result['input_type'] === 'raw') {
            $result['ok'] = true;
            $result['normalized'] = false;
            $result['error'] = 'TON_ADDRESS_PARSE_FAILED: ' . $e->getMessage();
            return $result;
        }

        if ($result['input_type'] === 'friendly') {
            $result['ok'] = true;
            $result['normalized'] = false;
            $result['error'] = 'TON_ADDRESS_PARSE_FAILED: ' . $e->getMessage();
            return $result;
        }

        $result['error'] = 'TON_ADDRESS_PARSE_FAILED: ' . $e->getMessage();
        return $result;
    }
}
