<?php
/**
 * /rwa/inc/core/toncenter.php
 * POAdo / AdoptGold — Global TON Center Helper
 * Version: v1.0.0-20260318
 *
 * Locked rules:
 * - canonical standalone path: /rwa/inc/core/toncenter.php
 * - global helper for all standalone RWA modules
 * - no "use" statements
 * - safe for repeated include/require
 * - reads env from current runtime/bootstrap
 * - supports TON Center v2 and v3 style usage
 */

declare(strict_types=1);

if (!defined('POADO_RWA_TONCENTER_VERSION')) {
    define('POADO_RWA_TONCENTER_VERSION', 'v1.0.0-20260318');
}

if (!function_exists('poado_toncenter_env')) {
    function poado_toncenter_env(string $key, string $default = ''): string
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

if (!function_exists('poado_toncenter_base_url')) {
    /**
     * Canonical default remains v2 unless explicitly overridden by env.
     */
    function poado_toncenter_base_url(): string
    {
        return rtrim(poado_toncenter_env('TONCENTER_BASE_URL', 'https://toncenter.com/api/v2'), '/');
    }
}

if (!function_exists('poado_toncenter_api_key')) {
    function poado_toncenter_api_key(): string
    {
        return poado_toncenter_env('TONCENTER_API_KEY', '');
    }
}

if (!function_exists('poado_toncenter_timeout')) {
    function poado_toncenter_timeout(): int
    {
        $n = (int)poado_toncenter_env('TONCENTER_TIMEOUT', '8');
        return $n > 0 ? $n : 8;
    }
}

if (!function_exists('poado_toncenter_http_headers')) {
    function poado_toncenter_http_headers(?string $apiKey = null): string
    {
        $key = $apiKey ?? poado_toncenter_api_key();
        $headers = "Accept: application/json\r\n";
        if ($key !== '') {
            $headers .= "X-API-Key: {$key}\r\n";
        }
        return $headers;
    }
}

if (!function_exists('poado_toncenter_build_url')) {
    function poado_toncenter_build_url(string $path, array $params = [], ?string $baseUrl = null): string
    {
        $base = rtrim((string)($baseUrl ?: poado_toncenter_base_url()), '/');
        $path = '/' . ltrim($path, '/');
        $qs = $params ? ('?' . http_build_query($params)) : '';
        return $base . $path . $qs;
    }
}

if (!function_exists('poado_toncenter_request')) {
    /**
     * Low-level GET request helper.
     *
     * Returns:
     * [
     *   'ok' => bool,
     *   'http_code' => int,
     *   'url' => string,
     *   'json' => array|null,
     *   'raw' => string,
     *   'error' => string|null,
     * ]
     */
    function poado_toncenter_request(string $path, array $params = [], array $opts = []): array
    {
        $baseUrl = (string)($opts['base_url'] ?? poado_toncenter_base_url());
        $apiKey = (string)($opts['api_key'] ?? poado_toncenter_api_key());
        $timeout = (int)($opts['timeout'] ?? poado_toncenter_timeout());

        $url = poado_toncenter_build_url($path, $params, $baseUrl);
        $headers = poado_toncenter_http_headers($apiKey);

        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => max(1, $timeout),
                'ignore_errors' => true,
                'header' => $headers,
            ]
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        $httpCode = 0;

        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('~^HTTP/\S+\s+(\d{3})~i', (string)$line, $m)) {
                    $httpCode = (int)$m[1];
                    break;
                }
            }
        }

        if (!is_string($raw) || $raw === '') {
            return [
                'ok' => false,
                'http_code' => $httpCode,
                'url' => $url,
                'json' => null,
                'raw' => '',
                'error' => 'network',
            ];
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return [
                'ok' => false,
                'http_code' => $httpCode,
                'url' => $url,
                'json' => null,
                'raw' => $raw,
                'error' => 'json',
            ];
        }

        return [
            'ok' => true,
            'http_code' => $httpCode,
            'url' => $url,
            'json' => $json,
            'raw' => $raw,
            'error' => null,
        ];
    }
}

if (!function_exists('poado_toncenter_api')) {
    /**
     * Backward-compatible high-level helper.
     * Returns decoded Toncenter JSON or standard error array.
     */
    function poado_toncenter_api(string $path, array $params = [], array $opts = []): array
    {
        $res = poado_toncenter_request($path, $params, $opts);
        if (($res['ok'] ?? false) !== true || !is_array($res['json'] ?? null)) {
            return [
                'ok' => false,
                'error' => (string)($res['error'] ?? 'request_failed'),
                'http_code' => (int)($res['http_code'] ?? 0),
                'url' => (string)($res['url'] ?? ''),
                'raw' => $res['raw'] ?? '',
            ];
        }

        return $res['json'];
    }
}

if (!function_exists('poado_toncenter_is_ok')) {
    /**
     * Toncenter commonly returns {"ok":true,"result":...}
     */
    function poado_toncenter_is_ok(array $response): bool
    {
        return (($response['ok'] ?? false) === true);
    }
}

if (!function_exists('poado_toncenter_get_transactions')) {
    /**
     * Canonical wrapper for /getTransactions on v2 endpoints.
     */
    function poado_toncenter_get_transactions(string $address, int $limit = 50, array $extra = [], array $opts = []): array
    {
        $address = trim($address);
        if ($address === '') {
            return ['ok' => false, 'error' => 'args'];
        }

        $params = array_merge([
            'address' => $address,
            'limit' => max(1, $limit),
        ], $extra);

        return poado_toncenter_api('/getTransactions', $params, $opts);
    }
}

if (!function_exists('poado_toncenter_find_tx_by_comment')) {
    /**
     * Best-effort auto-detect payment by scanning recent Treasury tx.
     *
     * Strongest signal:
     * - in_msg.message contains reference string
     *
     * Also checks:
     * - in_msg.comment
     * - top-level/comment-adjacent decoded shapes when available
     */
    function poado_toncenter_find_tx_by_comment(string $treasury, string $reference, int $lookback = 50, array $opts = []): array
    {
        $treasury = trim($treasury);
        $reference = trim($reference);

        if ($treasury === '' || $reference === '') {
            return ['ok' => false, 'error' => 'args'];
        }

        $res = poado_toncenter_get_transactions($treasury, $lookback, [], $opts);
        if (!poado_toncenter_is_ok($res)) {
            return [
                'ok' => false,
                'error' => 'toncenter',
                'raw' => $res,
            ];
        }

        $list = $res['result'] ?? [];
        if (!is_array($list)) {
            return ['ok' => false, 'error' => 'format'];
        }

        foreach ($list as $tx) {
            if (!is_array($tx)) {
                continue;
            }

            $hash = (string)($tx['transaction_id']['hash'] ?? $tx['hash'] ?? '');
            $inmsg = isset($tx['in_msg']) && is_array($tx['in_msg']) ? $tx['in_msg'] : [];

            $candidates = [
                (string)($inmsg['message'] ?? ''),
                (string)($inmsg['comment'] ?? ''),
                (string)($inmsg['msg_data']['text'] ?? ''),
                (string)($tx['comment'] ?? ''),
            ];

            foreach ($candidates as $idx => $candidate) {
                if ($candidate !== '' && strpos($candidate, $reference) !== false) {
                    $matched = 'unknown';
                    if ($idx === 0) $matched = 'in_msg.message';
                    elseif ($idx === 1) $matched = 'in_msg.comment';
                    elseif ($idx === 2) $matched = 'in_msg.msg_data.text';
                    elseif ($idx === 3) $matched = 'tx.comment';

                    return [
                        'ok' => true,
                        'tx_hash' => $hash,
                        'matched' => $matched,
                        'reference' => $reference,
                    ];
                }
            }
        }

        return ['ok' => false, 'error' => 'not_found'];
    }
}

if (!function_exists('poado_toncenter_get_address_balance')) {
    /**
     * Native TON balance wrapper for v2 getAddressBalance.
     */
    function poado_toncenter_get_address_balance(string $address, array $opts = []): array
    {
        $address = trim($address);
        if ($address === '') {
            return ['ok' => false, 'error' => 'args'];
        }

        return poado_toncenter_api('/getAddressBalance', ['address' => $address], $opts);
    }
}

if (!function_exists('poado_toncenter_to_decimal')) {
    /**
     * Convert chain integer units into decimal string.
     */
    function poado_toncenter_to_decimal($amount, int $decimals = 9, int $scale = 6): string
    {
        $raw = trim((string)$amount);
        $decimals = max(0, $decimals);
        $scale = max(0, $scale);

        if ($raw === '' || !preg_match('/^-?\d+$/', $raw)) {
            return number_format(0, $scale, '.', '');
        }

        $neg = false;
        if ($raw[0] === '-') {
            $neg = true;
            $raw = substr($raw, 1);
        }

        $raw = ltrim($raw, '0');
        if ($raw === '') {
            $raw = '0';
        }

        if ($decimals === 0) {
            $out = $raw;
        } else {
            $raw = str_pad($raw, $decimals + 1, '0', STR_PAD_LEFT);
            $intPart = substr($raw, 0, -$decimals);
            $fracPart = substr($raw, -$decimals);
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