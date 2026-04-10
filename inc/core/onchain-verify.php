<?php
declare(strict_types=1);

/**
 * /rwa/inc/core/onchain-verify.php
 * AdoptGold / POAdo — Unified On-chain Jetton Verifier
 * FINAL-LOCK-7
 *
 * LOCKED RULE:
 *   ACCEPT = jetton/token match + exact amount match + ref/payload text match
 *   destination NOT required
 *
 * FEATURES:
 * - multi-token support:
 *     EMA / EMX / EMS / WEMS / USDT_TON
 * - token_key OR jetton_master input
 * - primary path: Toncenter v3 /jetton/transfers
 * - fallback path: Toncenter v3 /transactions full tx scan
 * - shared request-scope runtime cache
 * - safer nested payload extraction
 * - normalized result contract for all modules
 * - decimals-safe amount normalization:
 *     human amount input (e.g. 50000) -> exact base-unit comparison
 */

if (!defined('ONCHAIN_VERIFY_VERSION')) {
    define('ONCHAIN_VERIFY_VERSION', 'FINAL-LOCK-7');
}

/* ==========================================================================
 * Shared request-scope cache
 * ========================================================================== */

if (!function_exists('rwa_onchain_cache_store')) {
    function &rwa_onchain_cache_store(): array
    {
        static $cache = [];
        return $cache;
    }
}

if (!function_exists('rwa_onchain_cache_get')) {
    function rwa_onchain_cache_get(string $bucket, string $key, $default = null)
    {
        $cache = &rwa_onchain_cache_store();
        if (!isset($cache[$bucket]) || !array_key_exists($key, $cache[$bucket])) {
            return $default;
        }
        return $cache[$bucket][$key];
    }
}

if (!function_exists('rwa_onchain_cache_set')) {
    function rwa_onchain_cache_set(string $bucket, string $key, $value)
    {
        $cache = &rwa_onchain_cache_store();
        if (!isset($cache[$bucket]) || !is_array($cache[$bucket])) {
            $cache[$bucket] = [];
        }
        $cache[$bucket][$key] = $value;
        return $value;
    }
}

/* ==========================================================================
 * Config
 * ========================================================================== */

if (!function_exists('rwa_onchain_toncenter_base')) {
    function rwa_onchain_toncenter_base(): string
    {
        $cached = rwa_onchain_cache_get('config', 'toncenter_base');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $v = trim((string)(
            $_ENV['TONCENTER_V3_BASE']
            ?? $_ENV['TONCENTER_BASE']
            ?? $_SERVER['TONCENTER_V3_BASE']
            ?? $_SERVER['TONCENTER_BASE']
            ?? getenv('TONCENTER_V3_BASE')
            ?? getenv('TONCENTER_BASE')
            ?? 'https://toncenter.com/api/v3'
        ));

        return (string)rwa_onchain_cache_set('config', 'toncenter_base', rtrim($v, '/'));
    }
}

if (!function_exists('rwa_onchain_toncenter_api_key')) {
    function rwa_onchain_toncenter_api_key(): string
    {
        $cached = rwa_onchain_cache_get('config', 'toncenter_api_key');
        if (is_string($cached)) {
            return $cached;
        }

        $v = trim((string)(
            $_ENV['TONCENTER_API_KEY']
            ?? $_SERVER['TONCENTER_API_KEY']
            ?? getenv('TONCENTER_API_KEY')
            ?? ''
        ));

        return (string)rwa_onchain_cache_set('config', 'toncenter_api_key', $v);
    }
}

/* ==========================================================================
 * Token registry
 * ========================================================================== */

if (!function_exists('rwa_onchain_registry_all')) {
    function rwa_onchain_registry_all(): array
    {
        $cached = rwa_onchain_cache_get('registry', 'all');
        if (is_array($cached)) {
            return $cached;
        }

        $registry = [
            'EMA' => [
                'token_key'      => 'EMA',
                'jetton_master'  => trim((string)(
                    $_ENV['EMA_JETTON_MASTER_RAW']
                    ?? $_SERVER['EMA_JETTON_MASTER_RAW']
                    ?? getenv('EMA_JETTON_MASTER_RAW')
                    ?? '0:caf9b448ef4e92d5c208a0b853ad37fe7bca4bd93a4e0f9adc3f739ac58cb3b3'
                )),
                'decimals'       => (int)(
                    $_ENV['EMA_DECIMALS']
                    ?? $_SERVER['EMA_DECIMALS']
                    ?? getenv('EMA_DECIMALS')
                    ?? 9
                ),
            ],
            'EMX' => [
                'token_key'      => 'EMX',
                'jetton_master'  => trim((string)(
                    $_ENV['EMX_JETTON_MASTER_RAW']
                    ?? $_SERVER['EMX_JETTON_MASTER_RAW']
                    ?? getenv('EMX_JETTON_MASTER_RAW')
                    ?? '0:63d3319c1cebcde48b013ff040006e4d462b806bf48b06efb18ec267ec078ce2'
                )),
                'decimals'       => (int)(
                    $_ENV['EMX_DECIMALS']
                    ?? $_SERVER['EMX_DECIMALS']
                    ?? getenv('EMX_DECIMALS')
                    ?? 9
                ),
            ],
            'EMS' => [
                'token_key'      => 'EMS',
                'jetton_master'  => trim((string)(
                    $_ENV['EMS_JETTON_MASTER_RAW']
                    ?? $_SERVER['EMS_JETTON_MASTER_RAW']
                    ?? getenv('EMS_JETTON_MASTER_RAW')
                    ?? '0:a92544730780c970bd64792445f2ee49e5299a90cfbf15a7ed4c0c9746b5679c'
                )),
                'decimals'       => (int)(
                    $_ENV['EMS_DECIMALS']
                    ?? $_SERVER['EMS_DECIMALS']
                    ?? getenv('EMS_DECIMALS')
                    ?? 9
                ),
            ],
            'WEMS' => [
                'token_key'      => 'WEMS',
                'jetton_master'  => trim((string)(
                    $_ENV['WEMS_JETTON_MASTER_RAW']
                    ?? $_SERVER['WEMS_JETTON_MASTER_RAW']
                    ?? getenv('WEMS_JETTON_MASTER_RAW')
                    ?? '0:3c74080db67b1f185d0cf8c25f9ea8a2e408717117bbdccf270a4931baaf394e'
                )),
                'decimals'       => (int)(
                    $_ENV['WEMS_DECIMALS']
                    ?? $_SERVER['WEMS_DECIMALS']
                    ?? getenv('WEMS_DECIMALS')
                    ?? 9
                ),
            ],
            'USDT_TON' => [
                'token_key'      => 'USDT_TON',
                'jetton_master'  => trim((string)(
                    $_ENV['USDT_TON_JETTON_MASTER_RAW']
                    ?? $_SERVER['USDT_TON_JETTON_MASTER_RAW']
                    ?? getenv('USDT_TON_JETTON_MASTER_RAW')
                    ?? '0:b113a994b5024a16719f69139328eb759596c38a25f59028b146fecdc3621dfe'
                )),
                'decimals'       => (int)(
                    $_ENV['USDT_TON_DECIMALS']
                    ?? $_SERVER['USDT_TON_DECIMALS']
                    ?? getenv('USDT_TON_DECIMALS')
                    ?? 6
                ),
            ],
        ];

        return (array)rwa_onchain_cache_set('registry', 'all', $registry);
    }
}

/* ==========================================================================
 * Normalizers
 * ========================================================================== */

if (!function_exists('rwa_onchain_addr_canon')) {
    function rwa_onchain_addr_canon(string $v): string
    {
        $v = strtolower(trim($v));
        if ($v === '') {
            return '';
        }

        $v = preg_replace('/\s+/', '', $v);
        $v = preg_replace('/^0:/', '', $v);
        $v = preg_replace('/^eq/', '', $v);
        $v = preg_replace('/^uq/', '', $v);
        $v = preg_replace('/[^a-z0-9_\-]/', '', $v);

        return (string)$v;
    }
}

if (!function_exists('rwa_onchain_hash_norm')) {
    function rwa_onchain_hash_norm(string $v): string
    {
        $v = strtolower(trim($v));
        if ($v === '') {
            return '';
        }
        if (!str_starts_with($v, '0x')) {
            $v = '0x' . $v;
        }
        return $v;
    }
}

if (!function_exists('rwa_onchain_bool')) {
    function rwa_onchain_bool($v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return ((int)$v) !== 0;
        }
        $s = strtolower(trim((string)$v));
        return in_array($s, ['1', 'true', 'yes', 'y', 'on'], true);
    }
}

if (!function_exists('rwa_onchain_tx_time')) {
    function rwa_onchain_tx_time(array $tx): int
    {
        $candidates = [
            $tx['utime'] ?? null,
            $tx['now'] ?? null,
            $tx['timestamp'] ?? null,
            $tx['created_at'] ?? null,
            $tx['time'] ?? null,
        ];

        foreach ($candidates as $v) {
            if (is_numeric($v)) {
                return (int)$v;
            }
            if (is_string($v) && trim($v) !== '') {
                $ts = strtotime($v);
                if ($ts !== false) {
                    return (int)$ts;
                }
            }
        }

        return 0;
    }
}

if (!function_exists('rwa_onchain_is_recent_enough')) {
    function rwa_onchain_is_recent_enough(array $tx, int $lookbackSeconds): bool
    {
        if ($lookbackSeconds <= 0) {
            return true;
        }

        $txTime = rwa_onchain_tx_time($tx);
        if ($txTime <= 0) {
            return true;
        }

        return $txTime >= (time() - $lookbackSeconds);
    }
}

if (!function_exists('rwa_onchain_resolve_token')) {
    function rwa_onchain_resolve_token(array $rules): array
    {
        $cacheKey = md5((string)json_encode([
            'token_key' => $rules['token_key'] ?? '',
            'jetton_master' => $rules['jetton_master'] ?? '',
            'decimals' => $rules['decimals'] ?? null,
        ]));
        $cached = rwa_onchain_cache_get('token_resolve', $cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $tokenKey = strtoupper(trim((string)($rules['token_key'] ?? '')));
        $jettonMaster = trim((string)($rules['jetton_master'] ?? ''));

        if ($jettonMaster !== '') {
            $resolved = [
                'token_key'      => $tokenKey !== '' ? $tokenKey : 'CUSTOM',
                'jetton_master'  => $jettonMaster,
                'decimals'       => (int)($rules['decimals'] ?? 9),
            ];
            return (array)rwa_onchain_cache_set('token_resolve', $cacheKey, $resolved);
        }

        if ($tokenKey === '') {
            throw new RuntimeException('TOKEN_REQUIRED');
        }

        $registry = rwa_onchain_registry_all();
        if (!isset($registry[$tokenKey])) {
            throw new RuntimeException('TOKEN_UNKNOWN');
        }

        return (array)rwa_onchain_cache_set('token_resolve', $cacheKey, $registry[$tokenKey]);
    }
}

/* ==========================================================================
 * Amount helpers
 * ========================================================================== */

if (!function_exists('rwa_onchain_decimals_pow10')) {
    function rwa_onchain_decimals_pow10(int $decimals): string
    {
        $decimals = max(0, min(30, $decimals));
        return '1' . str_repeat('0', $decimals);
    }
}

if (!function_exists('rwa_onchain_amount_to_base_units')) {
    function rwa_onchain_amount_to_base_units(string $humanAmount, int $decimals): string
    {
        $humanAmount = trim($humanAmount);
        if ($humanAmount === '') {
            return '';
        }

        if (!preg_match('/^\d+(?:\.\d+)?$/', $humanAmount)) {
            throw new RuntimeException('INVALID_AMOUNT_FORMAT');
        }

        [$intPart, $fracPart] = array_pad(explode('.', $humanAmount, 2), 2, '');
        $fracPart = preg_replace('/\D+/', '', $fracPart);
        $intPart = preg_replace('/\D+/', '', $intPart);

        $decimals = max(0, min(30, $decimals));
        if (strlen($fracPart) > $decimals) {
            $fracPart = substr($fracPart, 0, $decimals);
        }

        $fracPart = str_pad($fracPart, $decimals, '0', STR_PAD_RIGHT);
        $base = ltrim($intPart . $fracPart, '0');

        return $base === '' ? '0' : $base;
    }
}

if (!function_exists('rwa_onchain_amount_from_base_units')) {
    function rwa_onchain_amount_from_base_units(string $baseUnits, int $decimals): string
    {
        $baseUnits = preg_replace('/\D+/', '', trim($baseUnits));
        if ($baseUnits === '' || $baseUnits === null) {
            return '0';
        }

        $decimals = max(0, min(30, $decimals));
        if ($decimals === 0) {
            return ltrim($baseUnits, '0') ?: '0';
        }

        $baseUnits = str_pad($baseUnits, $decimals + 1, '0', STR_PAD_LEFT);
        $intPart = substr($baseUnits, 0, -$decimals);
        $fracPart = substr($baseUnits, -$decimals);

        $intPart = ltrim($intPart, '0');
        if ($intPart === '') {
            $intPart = '0';
        }

        $fracPart = rtrim($fracPart, '0');
        if ($fracPart === '') {
            return $intPart;
        }

        return $intPart . '.' . $fracPart;
    }
}

if (!function_exists('rwa_onchain_amount_match')) {
    function rwa_onchain_amount_match(string $txAmountUnits, string $requiredHumanAmount, int $decimals): array
    {
        $txAmountUnits = preg_replace('/\D+/', '', trim($txAmountUnits));
        $requiredHumanAmount = trim($requiredHumanAmount);

        if ($txAmountUnits === '' || $requiredHumanAmount === '') {
            return [
                'ok' => false,
                'required_units' => '',
                'required_human' => $requiredHumanAmount,
                'tx_units' => $txAmountUnits,
                'tx_human' => '',
            ];
        }

        $requiredUnits = rwa_onchain_amount_to_base_units($requiredHumanAmount, $decimals);
        $txHuman = rwa_onchain_amount_from_base_units($txAmountUnits, $decimals);

        return [
            'ok' => ($txAmountUnits === $requiredUnits),
            'required_units' => $requiredUnits,
            'required_human' => $requiredHumanAmount,
            'tx_units' => $txAmountUnits,
            'tx_human' => $txHuman,
        ];
    }
}

/* ==========================================================================
 * HTTP
 * ========================================================================== */

if (!function_exists('rwa_onchain_get_json')) {
    function rwa_onchain_get_json(string $url): array
    {
        $cacheKey = md5($url);
        $cached = rwa_onchain_cache_get('http', $cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $headers = ['Accept: application/json'];
        $apiKey = rwa_onchain_toncenter_api_key();
        if ($apiKey !== '') {
            $headers[] = 'X-API-Key: ' . $apiKey;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('TONCENTER_CURL_INIT_FAILED');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $res = curl_exec($ch);
        $err = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($res === false) {
            throw new RuntimeException('TONCENTER_CURL_ERROR: ' . $err);
        }

        $json = json_decode((string)$res, true);
        if (!is_array($json)) {
            throw new RuntimeException('TONCENTER_INVALID_JSON');
        }

        if ($http >= 400) {
            throw new RuntimeException('TONCENTER_HTTP_' . $http);
        }

        rwa_onchain_cache_set('http', $cacheKey, $json);
        return $json;
    }
}

/* ==========================================================================
 * Nested helpers
 * ========================================================================== */

if (!function_exists('rwa_onchain_array_get')) {
    function rwa_onchain_array_get($data, array $path, $default = null)
    {
        $cur = $data;
        foreach ($path as $key) {
            if (!is_array($cur) || !array_key_exists($key, $cur)) {
                return $default;
            }
            $cur = $cur[$key];
        }
        return $cur;
    }
}

if (!function_exists('rwa_onchain_collect_scalar_texts')) {
    function rwa_onchain_collect_scalar_texts($value, array &$out): void
    {
        if ($value === null) {
            return;
        }

        if (is_scalar($value)) {
            $s = trim((string)$value);
            if ($s !== '') {
                $out[] = $s;
            }
            return;
        }

        if (is_array($value)) {
            foreach ($value as $v) {
                if (is_scalar($v)) {
                    $s = trim((string)$v);
                    if ($s !== '') {
                        $out[] = $s;
                    }
                } elseif (is_array($v)) {
                    rwa_onchain_collect_scalar_texts($v, $out);
                }
            }
        }
    }
}

if (!function_exists('rwa_onchain_payload_decode_maybe')) {
    function rwa_onchain_payload_decode_maybe(string $v): string
    {
        $cacheKey = md5($v);
        $cached = rwa_onchain_cache_get('payload_decode', $cacheKey);
        if (is_string($cached)) {
            return $cached;
        }

        $v = trim($v);
        if ($v === '' || !preg_match('/^[A-Za-z0-9+\/=_-]+$/', $v)) {
            return (string)rwa_onchain_cache_set('payload_decode', $cacheKey, '');
        }

        $norm = str_replace(['-', '_'], ['+', '/'], $v);
        $pad = strlen($norm) % 4;
        if ($pad > 0) {
            $norm .= str_repeat('=', 4 - $pad);
        }

        $bin = base64_decode($norm, true);
        if ($bin === false || $bin === '') {
            return (string)rwa_onchain_cache_set('payload_decode', $cacheKey, '');
        }

        $decoded = @mb_convert_encoding($bin, 'UTF-8', 'UTF-8') ?: '';
        return (string)rwa_onchain_cache_set('payload_decode', $cacheKey, $decoded);
    }
}

if (!function_exists('rwa_onchain_extract_payload_texts')) {
    function rwa_onchain_extract_payload_texts(array $tx): array
    {
        $cacheKey = md5((string)json_encode($tx));
        $cached = rwa_onchain_cache_get('payload_extract', $cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $raws = [];

        $candidatePaths = [
            ['decoded_forward_payload'],
            ['forward_payload'],
            ['comment'],
            ['text'],
            ['msg_data_text'],
            ['decoded_comment'],
            ['payload'],

            ['decoded_body', 'forward_payload'],
            ['decoded_body', 'comment'],
            ['decoded_body', 'text'],
            ['decoded_body', 'payload'],
            ['decoded_body', 'value', 'forward_payload'],
            ['decoded_body', 'value', 'comment'],
            ['decoded_body', 'value', 'text'],
            ['decoded_body', 'value', 'payload'],

            ['forward_payload', 'comment'],
            ['forward_payload', 'text'],
            ['forward_payload', 'value'],
            ['forward_payload', 'value', 'text'],
            ['forward_payload', 'value', 'comment'],
            ['forward_payload', 'value', 'value'],
            ['forward_payload', 'value', 'value', 'text'],
            ['forward_payload', 'value', 'value', 'comment'],

            ['custom_payload'],
            ['custom_payload', 'text'],
            ['custom_payload', 'comment'],
            ['custom_payload', 'value'],
            ['custom_payload', 'value', 'text'],
            ['custom_payload', 'value', 'comment'],
        ];

        foreach ($candidatePaths as $path) {
            $v = rwa_onchain_array_get($tx, $path, null);
            if ($v === null) {
                continue;
            }

            if (is_scalar($v)) {
                $s = trim((string)$v);
                if ($s !== '') {
                    $raws[] = $s;
                }
                continue;
            }

            if (is_array($v)) {
                $flat = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (is_string($flat) && trim($flat) !== '') {
                    $raws[] = $flat;
                }
                rwa_onchain_collect_scalar_texts($v, $raws);
            }
        }

        $decoded = [];
        foreach ($raws as $raw) {
            $maybe = rwa_onchain_payload_decode_maybe($raw);
            if ($maybe !== '') {
                $decoded[] = $maybe;
            }
        }

        $out = array_values(array_unique(array_filter(array_merge($raws, $decoded), static function ($v) {
            return is_string($v) && trim($v) !== '';
        })));

        rwa_onchain_cache_set('payload_extract', $cacheKey, $out);
        return $out;
    }
}

if (!function_exists('rwa_onchain_payload_contains_ref')) {
    function rwa_onchain_payload_contains_ref(array $tx, string $ref): bool
    {
        $needle = strtolower(trim($ref));
        if ($needle === '') {
            return false;
        }

        foreach (rwa_onchain_extract_payload_texts($tx) as $txt) {
            if (strpos(strtolower($txt), $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}

/* ==========================================================================
 * Toncenter fetchers
 * ========================================================================== */

if (!function_exists('rwa_onchain_list_jetton_transfers')) {
    function rwa_onchain_list_jetton_transfers(string $ownerAddress, string $jettonMaster, int $limit = 100): array
    {
        $limit = max(1, min(300, $limit));

        $cacheKey = md5((string)json_encode(['jetton_transfers', $ownerAddress, $jettonMaster, $limit]));
        $cached = rwa_onchain_cache_get('jetton_transfers', $cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $url = rwa_onchain_toncenter_base()
            . '/jetton/transfers'
            . '?owner_address=' . rawurlencode($ownerAddress)
            . '&jetton_master=' . rawurlencode($jettonMaster)
            . '&direction=out'
            . '&limit=' . $limit;

        $json = rwa_onchain_get_json($url);
        $list = $json['jetton_transfers'] ?? $json['result'] ?? $json['data'] ?? [];
        if (!is_array($list)) {
            $list = [];
        }

        return (array)rwa_onchain_cache_set('jetton_transfers', $cacheKey, $list);
    }
}

if (!function_exists('rwa_onchain_list_transactions')) {
    function rwa_onchain_list_transactions(string $address, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        $cacheKey = md5((string)json_encode(['transactions', $address, $limit]));
        $cached = rwa_onchain_cache_get('transactions', $cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $url = rwa_onchain_toncenter_base()
            . '/transactions'
            . '?account=' . rawurlencode($address)
            . '&limit=' . $limit
            . '&sort=desc';

        $json = rwa_onchain_get_json($url);
        $list = $json['transactions'] ?? $json['result'] ?? $json['data'] ?? [];
        if (!is_array($list)) {
            $list = [];
        }

        return (array)rwa_onchain_cache_set('transactions', $cacheKey, $list);
    }
}

/* ==========================================================================
 * Match helpers
 * ========================================================================== */

if (!function_exists('rwa_onchain_source_match')) {
    function rwa_onchain_source_match(string $source, string $ownerAddress): bool
    {
        $sourceCanon = rwa_onchain_addr_canon($source);
        $ownerCanon = rwa_onchain_addr_canon($ownerAddress);

        return $sourceCanon !== '' && $ownerCanon !== '' && $sourceCanon === $ownerCanon;
    }
}

if (!function_exists('rwa_onchain_destination_match')) {
    function rwa_onchain_destination_match(string $dest, string $expectedDest): bool
    {
        $destCanon = rwa_onchain_addr_canon($dest);
        $expectedCanon = rwa_onchain_addr_canon($expectedDest);

        return $destCanon !== '' && $expectedCanon !== '' && $destCanon === $expectedCanon;
    }
}

/* ==========================================================================
 * Jetton transfers primary match
 * ========================================================================== */

if (!function_exists('rwa_onchain_match_jetton_transfer')) {
    function rwa_onchain_match_jetton_transfer(array $tx, array $rules): array
    {
        $token = rwa_onchain_resolve_token($rules);

        $txHash = rwa_onchain_hash_norm((string)($tx['transaction_hash'] ?? $tx['hash'] ?? ''));
        $txJetton = trim((string)($tx['jetton_master'] ?? $tx['jetton'] ?? $tx['jetton_address'] ?? ''));
        $txAmount = trim((string)($tx['amount'] ?? ''));
        $txSource = trim((string)($tx['source'] ?? $tx['sender'] ?? $tx['from'] ?? ''));
        $txDest = trim((string)($tx['destination'] ?? $tx['recipient'] ?? $tx['to'] ?? ''));
        $payloadTexts = rwa_onchain_extract_payload_texts($tx);
        $payloadText = implode(' | ', $payloadTexts);

        $requiredMasterCanon = rwa_onchain_addr_canon((string)$token['jetton_master']);
        $requiredHumanAmount = trim((string)($rules['amount_units'] ?? ''));
        $requiredRef = trim((string)($rules['ref'] ?? $rules['reference'] ?? ''));
        $txHint = rwa_onchain_hash_norm(trim((string)($rules['tx_hint'] ?? $rules['tx_hash'] ?? '')));

        $amountCheck = rwa_onchain_amount_match($txAmount, $requiredHumanAmount, (int)$token['decimals']);

        $matchJetton = rwa_onchain_addr_canon($txJetton) === $requiredMasterCanon;
        $matchAmount = $amountCheck['ok'];
        $matchRef = rwa_onchain_payload_contains_ref($tx, $requiredRef);
        $matchTxHint = $txHint === '' ? true : ($txHash === $txHint);

        $sourceChecked = ($txSource !== '' && !empty($rules['owner_address']));
        $sourceMatched = $sourceChecked
            ? rwa_onchain_source_match($txSource, (string)$rules['owner_address'])
            : false;

        $treasuryChecked = !empty($rules['destination']) && $txDest !== '';
        $treasuryMatched = $treasuryChecked
            ? rwa_onchain_destination_match($txDest, (string)$rules['destination'])
            : false;

        return [
            'ok'                => ($matchJetton && $matchAmount && $matchRef && $matchTxHint),
            'tx_hash'           => $txHash,
            'payload_text'      => $payloadText,
            'payload_texts'     => $payloadTexts,
            'source_raw'        => $txSource,
            'destination_raw'   => $txDest,
            'jetton_master_raw' => $txJetton,
            'amount_units'      => $txAmount,
            'amount_human'      => $amountCheck['tx_human'],
            'required_units'    => $amountCheck['required_units'],
            'required_human'    => $amountCheck['required_human'],
            'match_jetton'      => $matchJetton,
            'match_amount'      => $matchAmount,
            'match_ref'         => $matchRef,
            'match_tx_hint'     => $matchTxHint,
            'source_checked'    => $sourceChecked,
            'source_matched'    => $sourceMatched,
            'treasury_checked'  => $treasuryChecked,
            'treasury_matched'  => $treasuryMatched,
            'verify_mode'       => 'jetton_transfers',
            'raw_transfer'      => $tx,
            'confirmations'     => (int)($tx['confirmations'] ?? 0),
            'utime'             => rwa_onchain_tx_time($tx),
        ];
    }
}

if (!function_exists('rwa_onchain_find_matching_jetton_transfer')) {
    function rwa_onchain_find_matching_jetton_transfer(array $transfers, array $rules): ?array
    {
        $lookbackSeconds = (int)($rules['lookback_seconds'] ?? 0);

        foreach ($transfers as $tx) {
            if (!is_array($tx)) {
                continue;
            }

            if (!rwa_onchain_is_recent_enough($tx, $lookbackSeconds)) {
                continue;
            }

            $match = rwa_onchain_match_jetton_transfer($tx, $rules);
            if (!empty($match['ok'])) {
                return $match;
            }
        }
        return null;
    }
}

/* ==========================================================================
 * Transactions fallback
 * ========================================================================== */

if (!function_exists('rwa_onchain_tx_extract_candidates')) {
    function rwa_onchain_tx_extract_candidates(array $tx): array
    {
        $items = [];

        if (!empty($tx['in_msg']) && is_array($tx['in_msg'])) {
            $items[] = $tx['in_msg'];
        }

        if (!empty($tx['out_msgs']) && is_array($tx['out_msgs'])) {
            foreach ($tx['out_msgs'] as $msg) {
                if (is_array($msg)) {
                    $items[] = $msg;
                }
            }
        }

        if (!empty($tx['messages']) && is_array($tx['messages'])) {
            foreach ($tx['messages'] as $msg) {
                if (is_array($msg)) {
                    $items[] = $msg;
                }
            }
        }

        if (!empty($tx['actions']) && is_array($tx['actions'])) {
            foreach ($tx['actions'] as $action) {
                if (is_array($action)) {
                    $items[] = $action;
                }
            }
        }

        $items[] = $tx;

        return $items;
    }
}

if (!function_exists('rwa_onchain_tx_extract_jetton_master')) {
    function rwa_onchain_tx_extract_jetton_master(array $msg): string
    {
        $candidates = [
            $msg['jetton_master'] ?? null,
            $msg['jetton'] ?? null,
            $msg['jetton_address'] ?? null,
            rwa_onchain_array_get($msg, ['decoded_body', 'jetton_master']),
            rwa_onchain_array_get($msg, ['decoded_body', 'value', 'jetton_master']),
            rwa_onchain_array_get($msg, ['body', 'value', 'jetton_master']),
            rwa_onchain_array_get($msg, ['body', 'value', 'value', 'jetton_master']),
        ];

        foreach ($candidates as $v) {
            if (is_scalar($v)) {
                $s = trim((string)$v);
                if ($s !== '') {
                    return $s;
                }
            }
        }

        return '';
    }
}

if (!function_exists('rwa_onchain_tx_extract_amount')) {
    function rwa_onchain_tx_extract_amount(array $msg): string
    {
        $candidates = [
            $msg['amount'] ?? null,
            $msg['jetton_amount'] ?? null,
            rwa_onchain_array_get($msg, ['decoded_body', 'amount']),
            rwa_onchain_array_get($msg, ['decoded_body', 'value', 'amount']),
            rwa_onchain_array_get($msg, ['body', 'value', 'amount']),
            rwa_onchain_array_get($msg, ['body', 'value', 'value', 'amount']),
        ];

        foreach ($candidates as $v) {
            if (is_scalar($v)) {
                $s = trim((string)$v);
                if ($s !== '') {
                    return $s;
                }
            }
        }

        return '';
    }
}

if (!function_exists('rwa_onchain_tx_extract_source')) {
    function rwa_onchain_tx_extract_source(array $msg): string
    {
        $candidates = [
            $msg['source'] ?? null,
            $msg['src'] ?? null,
            $msg['sender'] ?? null,
            $msg['from'] ?? null,
            rwa_onchain_array_get($msg, ['source', 'address']),
            rwa_onchain_array_get($msg, ['from', 'address']),
            rwa_onchain_array_get($msg, ['sender', 'address']),
        ];

        foreach ($candidates as $v) {
            if (is_scalar($v)) {
                $s = trim((string)$v);
                if ($s !== '') {
                    return $s;
                }
            }
        }

        return '';
    }
}

if (!function_exists('rwa_onchain_tx_extract_destination')) {
    function rwa_onchain_tx_extract_destination(array $msg): string
    {
        $candidates = [
            $msg['destination'] ?? null,
            $msg['dest'] ?? null,
            $msg['recipient'] ?? null,
            $msg['to'] ?? null,
            rwa_onchain_array_get($msg, ['destination', 'address']),
            rwa_onchain_array_get($msg, ['to', 'address']),
            rwa_onchain_array_get($msg, ['recipient', 'address']),
        ];

        foreach ($candidates as $v) {
            if (is_scalar($v)) {
                $s = trim((string)$v);
                if ($s !== '') {
                    return $s;
                }
            }
        }

        return '';
    }
}

if (!function_exists('rwa_onchain_match_via_transactions')) {
    function rwa_onchain_match_via_transactions(array $rules): ?array
    {
        $token = rwa_onchain_resolve_token($rules);
        $ownerAddress = trim((string)($rules['owner_address'] ?? ''));
        $requiredRef = trim((string)($rules['ref'] ?? $rules['reference'] ?? ''));
        $requiredHumanAmount = trim((string)($rules['amount_units'] ?? ''));
        $requiredMasterCanon = rwa_onchain_addr_canon((string)$token['jetton_master']);
        $txHint = rwa_onchain_hash_norm(trim((string)($rules['tx_hint'] ?? $rules['tx_hash'] ?? '')));
        $lookbackSeconds = (int)($rules['lookback_seconds'] ?? 0);

        $txs = rwa_onchain_list_transactions($ownerAddress, 80);

        foreach ($txs as $tx) {
            if (!is_array($tx)) {
                continue;
            }

            if (!rwa_onchain_is_recent_enough($tx, $lookbackSeconds)) {
                continue;
            }

            $topTxHash = rwa_onchain_hash_norm((string)($tx['hash'] ?? $tx['transaction_hash'] ?? ''));
            if ($txHint !== '' && $topTxHash !== '' && $topTxHash !== $txHint) {
                continue;
            }

            $items = rwa_onchain_tx_extract_candidates($tx);

            foreach ($items as $msg) {
                if (!is_array($msg)) {
                    continue;
                }

                $jetton = rwa_onchain_tx_extract_jetton_master($msg);
                $amount = rwa_onchain_tx_extract_amount($msg);
                $source = rwa_onchain_tx_extract_source($msg);
                $dest = rwa_onchain_tx_extract_destination($msg);
                $payloadTexts = rwa_onchain_extract_payload_texts($msg);
                $payloadText = implode(' | ', $payloadTexts);

                $amountCheck = rwa_onchain_amount_match($amount, $requiredHumanAmount, (int)$token['decimals']);

                $matchJetton = ($jetton !== '' && rwa_onchain_addr_canon($jetton) === $requiredMasterCanon);
                $matchAmount = $amountCheck['ok'];
                $matchRef = ($requiredRef !== '' && rwa_onchain_payload_contains_ref($msg, $requiredRef));
                $matchTxHint = $txHint === '' ? true : ($topTxHash === $txHint);

                $sourceChecked = ($source !== '' && $ownerAddress !== '');
                $sourceMatched = $sourceChecked
                    ? rwa_onchain_source_match($source, $ownerAddress)
                    : false;

                $treasuryChecked = !empty($rules['destination']) && $dest !== '';
                $treasuryMatched = $treasuryChecked
                    ? rwa_onchain_destination_match($dest, (string)$rules['destination'])
                    : false;

                if ($matchJetton && $matchAmount && $matchRef && $matchTxHint) {
                    return [
                        'ok'                => true,
                        'tx_hash'           => $topTxHash,
                        'payload_text'      => $payloadText,
                        'payload_texts'     => $payloadTexts,
                        'source_raw'        => $source,
                        'destination_raw'   => $dest,
                        'jetton_master_raw' => $jetton,
                        'amount_units'      => $amount,
                        'amount_human'      => $amountCheck['tx_human'],
                        'required_units'    => $amountCheck['required_units'],
                        'required_human'    => $amountCheck['required_human'],
                        'match_jetton'      => true,
                        'match_amount'      => true,
                        'match_ref'         => true,
                        'match_tx_hint'     => $matchTxHint,
                        'source_checked'    => $sourceChecked,
                        'source_matched'    => $sourceMatched,
                        'treasury_checked'  => $treasuryChecked,
                        'treasury_matched'  => $treasuryMatched,
                        'verify_mode'       => 'transactions_fallback',
                        'raw_transfer'      => $tx,
                        'raw_message'       => $msg,
                        'confirmations'     => (int)($tx['confirmations'] ?? 0),
                        'utime'             => rwa_onchain_tx_time($tx),
                    ];
                }
            }
        }

        return null;
    }
}

/* ==========================================================================
 * Result helpers
 * ========================================================================== */

if (!function_exists('rwa_onchain_result_success')) {
    function rwa_onchain_result_success(array $token, array $match, array $rules): array
    {
        $minConfirmations = max(0, (int)($rules['min_confirmations'] ?? 0));
        $confirmations = (int)($match['confirmations'] ?? 0);
        $isConfirmedEnough = ($confirmations >= $minConfirmations);

        return [
            'ok'               => true,
            'status'           => $isConfirmedEnough ? 'CONFIRMED' : 'PENDING_CONFIRMATIONS',
            'verified'         => $isConfirmedEnough,
            'code'             => $isConfirmedEnough ? 'CONFIRMED' : 'PENDING_CONFIRMATIONS',
            'tx_hash'          => (string)($match['tx_hash'] ?? ''),
            'confirmations'    => $confirmations,
            'verify_source'    => 'toncenter_v3_php',
            'verify_mode'      => (string)($match['verify_mode'] ?? 'jetton_transfers'),
            'message'          => $isConfirmedEnough ? 'Matching transfer verified' : 'Matching transfer found but awaiting confirmations',
            'token_key'        => (string)$token['token_key'],
            'jetton_master'    => (string)$token['jetton_master'],
            'decimals'         => (int)$token['decimals'],
            'amount_units'     => (string)($match['required_units'] ?? ''),
            'amount_human'     => (string)($match['required_human'] ?? trim((string)($rules['amount_units'] ?? ''))),
            'payload_text'     => (string)($match['payload_text'] ?? ''),
            'payload_texts'    => $match['payload_texts'] ?? [],
            'match_jetton'     => true,
            'match_amount'     => true,
            'match_ref'        => true,
            'match_tx_hint'    => (bool)($match['match_tx_hint'] ?? true),
            'source_checked'   => (bool)($match['source_checked'] ?? false),
            'source_matched'   => (bool)($match['source_matched'] ?? false),
            'treasury_checked' => (bool)($match['treasury_checked'] ?? false),
            'treasury_matched' => (bool)($match['treasury_matched'] ?? false),
            'source_raw'       => (string)($match['source_raw'] ?? ''),
            'destination_raw'  => (string)($match['destination_raw'] ?? ''),
            'raw_transfer'     => $match['raw_transfer'] ?? null,
            'raw_message'      => $match['raw_message'] ?? null,
            '_version'         => ONCHAIN_VERIFY_VERSION,
            '_file'            => __FILE__,
        ];
    }
}

if (!function_exists('rwa_onchain_result_fail')) {
    function rwa_onchain_result_fail(string $code, string $message = '', array $debug = []): array
    {
        $status = match ($code) {
            'PENDING_CONFIRMATIONS' => 'PENDING_CONFIRMATIONS',
            'INVALID_AMOUNT' => 'INVALID_AMOUNT',
            'INVALID_REF' => 'INVALID_REF',
            'INVALID_SENDER' => 'INVALID_SENDER',
            'TX_NOT_FOUND' => 'TX_NOT_FOUND',
            'TONCENTER_HTTP_ERROR', 'TONCENTER_INVALID_JSON' => 'VERIFY_ERROR',
            default => $code,
        };

        return [
            'ok'            => false,
            'status'        => $status,
            'verified'      => false,
            'code'          => $code,
            'tx_hash'       => '',
            'confirmations' => 0,
            'verify_source' => 'toncenter_v3_php',
            'message'       => $message !== '' ? $message : $code,
            'debug'         => $debug,
            '_version'      => ONCHAIN_VERIFY_VERSION,
            '_file'         => __FILE__,
        ];
    }
}

/* ==========================================================================
 * Main verifier
 * ========================================================================== */

if (!function_exists('rwa_onchain_verify_jetton_transfer')) {
    function rwa_onchain_verify_jetton_transfer(array $rules): array
    {
        $ownerAddress = trim((string)($rules['owner_address'] ?? $rules['wallet_address'] ?? ''));
        $amountHuman  = trim((string)($rules['amount_units'] ?? ''));
        $ref          = trim((string)($rules['ref'] ?? $rules['reference'] ?? ''));
        $limit        = (int)($rules['limit'] ?? 100);
        $lookbackSeconds = max(0, (int)($rules['lookback_seconds'] ?? 0));

        if ($limit <= 0) {
            $limit = 100;
        }

        if ($ownerAddress === '' || $amountHuman === '' || $ref === '') {
            return rwa_onchain_result_fail('INVALID_INPUT', 'owner_address, amount_units, and ref are required');
        }

        try {
            $token = rwa_onchain_resolve_token($rules);
            $requiredUnits = rwa_onchain_amount_to_base_units($amountHuman, (int)$token['decimals']);
        } catch (Throwable $e) {
            return rwa_onchain_result_fail((string)$e->getMessage(), 'Token or amount resolution failed');
        }

        $cacheKey = md5((string)json_encode([
            'owner_address' => $ownerAddress,
            'token_key'     => $token['token_key'],
            'jetton_master' => $token['jetton_master'],
            'amount_human'  => $amountHuman,
            'amount_units'  => $requiredUnits,
            'ref'           => $ref,
            'tx_hint'       => $rules['tx_hint'] ?? ($rules['tx_hash'] ?? ''),
            'limit'         => $limit,
            'lookback'      => $lookbackSeconds,
            'min_conf'      => (int)($rules['min_confirmations'] ?? 0),
        ]));

        $cached = rwa_onchain_cache_get('verify_result', $cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $baseRules = array_merge($rules, [
                'owner_address' => $ownerAddress,
                'amount_units'  => $amountHuman,
                'required_units'=> $requiredUnits,
                'ref'           => $ref,
                'jetton_master' => $token['jetton_master'],
                'token_key'     => $token['token_key'],
                'decimals'      => (int)$token['decimals'],
                'lookback_seconds' => $lookbackSeconds,
            ]);

            $transfers = rwa_onchain_list_jetton_transfers($ownerAddress, (string)$token['jetton_master'], $limit);
            if (!$transfers) {
                $transfers = rwa_onchain_list_jetton_transfers($ownerAddress, (string)$token['jetton_master'], 200);
            }

            $match = rwa_onchain_find_matching_jetton_transfer($transfers, $baseRules);

            if (!$match) {
                $match = rwa_onchain_match_via_transactions($baseRules);
            }

            if ($match) {
                $out = rwa_onchain_result_success($token, $match, $baseRules);
                return (array)rwa_onchain_cache_set('verify_result', $cacheKey, $out);
            }

            $fail = rwa_onchain_result_fail(
                'NO_MATCH',
                'No matching transfer found',
                [
                    'owner_address' => $ownerAddress,
                    'token_key'     => $token['token_key'],
                    'jetton_master' => $token['jetton_master'],
                    'decimals'      => (int)$token['decimals'],
                    'amount_human'  => $amountHuman,
                    'amount_units'  => $requiredUnits,
                    'ref'           => $ref,
                    'tx_hint'       => (string)($rules['tx_hint'] ?? ($rules['tx_hash'] ?? '')),
                    'lookback_seconds' => $lookbackSeconds,
                ]
            );
            return (array)rwa_onchain_cache_set('verify_result', $cacheKey, $fail);

        } catch (Throwable $e) {
            $msg = (string)$e->getMessage();
            $code = 'VERIFY_FAILED';

            if (str_starts_with($msg, 'TONCENTER_HTTP_')) {
                $code = 'TONCENTER_HTTP_ERROR';
            } elseif ($msg === 'TONCENTER_INVALID_JSON') {
                $code = 'TONCENTER_INVALID_JSON';
            }

            $fail = rwa_onchain_result_fail($code, $msg);
            return (array)rwa_onchain_cache_set('verify_result', $cacheKey, $fail);
        }
    }
}
