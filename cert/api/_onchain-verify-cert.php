<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/api/_onchain-verify-cert.php
 * Version: v2.0.0-20260403-auto-payment-truth-3criteria-lock
 *
 * Cert-module local payment verifier
 *
 * HARD LOCK
 * - verify by 3 criteria only:
 *   1) token master
 *   2) exact amount_units
 *   3) payment_ref
 * - no destination requirement
 * - no owner_address requirement
 * - confirm-payment.php must normalize canonical inputs before calling this file
 *
 * INPUT CONTRACT
 * - token_master : raw TON form preferred (0:...)
 * - amount_units : exact on-chain jetton units
 * - ref          : payment_ref text to match in payload/comment/body
 *
 * OUTPUT CONTRACT
 * - stable MATCHED / NOT_FOUND / INVALID_INPUT / HTTP_ERROR format
 */

const CERT_VERIFY_VERSION = 'v2.0.0-20260403-auto-payment-truth-3criteria-lock';
const CERT_VERIFY_SOURCE  = 'cert_local_toncenter_v3';

if (!function_exists('cert_env')) {
    function cert_env(string $key, string $default = ''): string
    {
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        return is_string($v) && trim($v) !== '' ? trim($v) : $default;
    }
}

if (!function_exists('cert_http_get_json')) {
    function cert_http_get_json(string $url, array $headers = []): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('CURL_INIT_FAILED');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw = curl_exec($ch);
        if (!is_string($raw)) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException($err !== '' ? $err : 'HTTP_REQUEST_FAILED');
        }

        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new RuntimeException('HTTP_JSON_DECODE_FAILED');
        }

        if ($code >= 400) {
            throw new RuntimeException((string)($json['error'] ?? ('HTTP_' . $code)));
        }

        return $json;
    }
}

if (!function_exists('cert_verify_result')) {
    function cert_verify_result(
        bool $ok,
        string $status,
        bool $verified,
        string $message,
        string $txHash = '',
        int $confirmations = 0,
        array $debug = []
    ): array {
        return [
            'ok' => $ok,
            'status' => $status,
            'verified' => $verified,
            'code' => $status,
            'tx_hash' => $txHash,
            'confirmations' => $confirmations,
            'verify_source' => CERT_VERIFY_SOURCE,
            'message' => $message,
            'debug' => $debug,
            '_version' => CERT_VERIFY_VERSION,
            '_file' => __FILE__,
        ];
    }
}

if (!function_exists('cert_normalize_addr_token')) {
    function cert_normalize_addr_token(string $value): string
    {
        $value = strtoupper(trim($value));
        return preg_replace('/[^A-Z0-9:]/', '', $value) ?? '';
    }
}

if (!function_exists('cert_token_master_aliases')) {
    function cert_token_master_aliases(string $tokenMaster): array
    {
        $tokenMaster = trim($tokenMaster);
        if ($tokenMaster === '') {
            return [];
        }

        $known = [
            'EQA8dAgNtnsfGF0M-MJfnqii5AhxcRe73M8nCkkxuq85Tr-Q' => '0:3C74080DB67B1F185D0CF8C25F9EA8A2E408717117BBDCCF270A4931BAAF394E',
            'EQDK-bRI706S1cIIoLhTrTf-e8pL2TpOD5rcP3OaxYyzs74b' => '0:CAF9B448EF4E92D5C208A0B853AD37FE7BCA4BD93A4E0F9ADC3F739AC58CB3B3',
            'EQBj0zGcHOvN5IsBP_BAAG5NRiuAa_SLBu-xjsJn7AeM4nQy' => '0:63D3319C1CEBCDE48B013FF040006E4D462B806BF48B06EFB18EC267EC078CE2',
            'EQCpJURzB4DJcL1keSRF8u5J5SmakM-_FaftTAyXRrVnnNmD' => '0:A92544730780C970BD64792445F2EE49E5299A90CFBF15A7ED4C0C9746B5679C',
            'EQCxE6mUtQJKFnGfaROTKOt1lZbDiiX1kCixRv7Nw2Id_sDs' => '0:B113A994B5024A16719F69139328EB759596C38A25F59028B146FECDC3621DFE',
        ];

        $aliases = [$tokenMaster];

        if (isset($known[$tokenMaster])) {
            $aliases[] = $known[$tokenMaster];
        } else {
            $flip = array_flip($known);
            if (isset($flip[strtoupper($tokenMaster)])) {
                $aliases[] = $flip[strtoupper($tokenMaster)];
            }
        }

        $norm = [];
        foreach ($aliases as $a) {
            $a = trim((string)$a);
            if ($a !== '') {
                $norm[$a] = true;
                $n = cert_normalize_addr_token($a);
                if ($n !== '') {
                    $norm[$n] = true;
                }
            }
        }

        return array_keys($norm);
    }
}

if (!function_exists('cert_extract_transfer_ref')) {
    function cert_extract_transfer_ref(array $row): string
    {
        $candidates = [
            $row['comment'] ?? null,
            $row['memo'] ?? null,
            $row['message'] ?? null,
            $row['payload_text'] ?? null,
            $row['forward_payload_text'] ?? null,
            $row['decoded_forward_payload'] ?? null,
            $row['forward_comment'] ?? null,
            $row['payload'] ?? null,
            $row['body'] ?? null,
            $row['msg_data'] ?? null,
            $row['decoded_body'] ?? null,
            $row['in_msg'] ?? null,
            $row['out_msgs'] ?? null,
            $row['custom_payload'] ?? null,
            $row['decoded_custom_payload'] ?? null,
            $row['forward_payload'] ?? null,
        ];

        foreach ($candidates as $v) {
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
            if (is_array($v)) {
                $flat = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (is_string($flat) && trim($flat) !== '') {
                    return trim($flat);
                }
            }
        }

        $flatRow = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($flatRow) && trim($flatRow) !== '') {
            return trim($flatRow);
        }

        return '';
    }
}

if (!function_exists('cert_extract_tx_hash')) {
    function cert_extract_tx_hash(array $row): string
    {
        $candidates = [
            $row['hash'] ?? null,
            $row['tx_hash'] ?? null,
            $row['transaction_id']['hash'] ?? null,
            $row['transaction_id'] ?? null,
        ];

        foreach ($candidates as $v) {
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        return '';
    }
}

if (!function_exists('cert_extract_confirmations')) {
    function cert_extract_confirmations(array $row): int
    {
        foreach (['confirmations', 'confirmation_count'] as $k) {
            $v = $row[$k] ?? null;
            if (is_numeric($v)) {
                return (int)$v;
            }
        }
        return 0;
    }
}

if (!function_exists('cert_extract_amount_from_tx')) {
    function cert_extract_amount_from_tx(array $tx): string
    {
        $candidates = [
            $tx['amount'] ?? null,
            $tx['amount_units'] ?? null,
            $tx['jetton_amount'] ?? null,
            $tx['value'] ?? null,
            $tx['in_msg']['decoded']['amount']['value'] ?? null,
            $tx['in_msg']['decoded']['amount'] ?? null,
            $tx['in_msg']['message_content']['decoded']['amount']['value'] ?? null,
            $tx['in_msg']['message_content']['decoded']['amount'] ?? null,
        ];

        foreach ($candidates as $v) {
            if (is_scalar($v) && (string)$v !== '') {
                return trim((string)$v);
            }
        }

        return '';
    }
}

if (!function_exists('cert_tx_matches_ref')) {
    function cert_tx_matches_ref(array $tx, string $ref): bool
    {
        $ref = trim($ref);
        if ($ref === '') {
            return false;
        }

        $flat = json_encode($tx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($flat) && str_contains($flat, $ref);
    }
}

if (!function_exists('cert_tx_matches_token_master')) {
    function cert_tx_matches_token_master(array $tx, string $tokenMaster): bool
    {
        $aliases = cert_token_master_aliases($tokenMaster);
        if ($aliases === []) {
            return false;
        }

        $flat = json_encode($tx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($flat) || $flat === '') {
            return false;
        }

        $flatNorm = cert_normalize_addr_token($flat);

        foreach ($aliases as $alias) {
            if ($alias === '') {
                continue;
            }
            if (str_contains($flat, $alias)) {
                return true;
            }
            $aliasNorm = cert_normalize_addr_token($alias);
            if ($aliasNorm !== '' && $flatNorm !== '' && str_contains($flatNorm, $aliasNorm)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('cert_match_token_master')) {
    function cert_match_token_master(array $row, string $tokenMaster): bool
    {
        $aliases = cert_token_master_aliases($tokenMaster);
        if ($aliases === []) {
            return false;
        }

        $candidates = [
            $row['jetton_master'] ?? null,
            $row['token_master'] ?? null,
            $row['jetton_address'] ?? null,
            $row['master_address'] ?? null,
        ];

        foreach ($candidates as $v) {
            if (!is_string($v) || trim($v) === '') {
                continue;
            }

            $raw = trim($v);
            $norm = cert_normalize_addr_token($raw);

            foreach ($aliases as $alias) {
                if ($raw === $alias) {
                    return true;
                }
                $aliasNorm = cert_normalize_addr_token($alias);
                if ($aliasNorm !== '' && $norm !== '' && $aliasNorm === $norm) {
                    return true;
                }
            }
        }

        return cert_tx_matches_token_master($row, $tokenMaster);
    }
}

if (!function_exists('cert_match_amount_units')) {
    function cert_match_amount_units(array $row, string $amountUnits): bool
    {
        $amountUnits = trim($amountUnits);
        if ($amountUnits === '') {
            return false;
        }

        $candidates = [
            $row['amount'] ?? null,
            $row['amount_units'] ?? null,
            $row['jetton_amount'] ?? null,
            $row['value'] ?? null,
            $row['in_msg']['decoded']['amount']['value'] ?? null,
            $row['in_msg']['decoded']['amount'] ?? null,
            $row['in_msg']['message_content']['decoded']['amount']['value'] ?? null,
            $row['in_msg']['message_content']['decoded']['amount'] ?? null,
        ];

        foreach ($candidates as $v) {
            if ((string)$v === $amountUnits) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('cert_match_ref')) {
    function cert_match_ref(array $row, string $ref): bool
    {
        $ref = trim($ref);
        if ($ref === '') {
            return false;
        }

        $hay = cert_extract_transfer_ref($row);
        if ($hay === '') {
            return false;
        }

        return str_contains($hay, $ref);
    }
}

if (!function_exists('cert_candidate_rows_from_tx')) {
    function cert_candidate_rows_from_tx(array $tx): array
    {
        $rows = [$tx];

        $extra = [
            $tx['in_msg'] ?? null,
            $tx['out_msgs'] ?? null,
            $tx['actions'] ?? null,
            $tx['children'] ?? null,
            $tx['messages'] ?? null,
            $tx['events'] ?? null,
        ];

        foreach ($extra as $block) {
            if (is_array($block)) {
                $isList = array_keys($block) === range(0, count($block) - 1);
                if ($isList) {
                    foreach ($block as $row) {
                        if (is_array($row)) {
                            $rows[] = $row;
                        }
                    }
                } else {
                    $rows[] = $block;
                }
            }
        }

        return $rows;
    }
}

if (!function_exists('cert_onchain_verify_jetton_transfer')) {
    function cert_onchain_verify_jetton_transfer(array $args): array
    {
        $tokenMaster = trim((string)($args['token_master'] ?? ''));
        $amountUnits = trim((string)($args['amount_units'] ?? ''));
        $ref = trim((string)($args['ref'] ?? ''));
        $lookback = (int)($args['lookback_seconds'] ?? 86400);
        $treasuryOwner = trim((string)($args['treasury_owner'] ?? cert_env('TON_TREASURY_ADDRESS', 'UQBdYfGArtoCUmBs5TjYQtfPFfQuGC2Ydbj2pQr3zIlNrDta')));
        $limit = (int)($args['limit'] ?? 50);

        if ($tokenMaster === '' || $amountUnits === '' || $ref === '') {
            return cert_verify_result(
                false,
                'INVALID_INPUT',
                false,
                'token_master, amount_units, and ref are required'
            );
        }

        if ($limit < 10) {
            $limit = 10;
        } elseif ($limit > 200) {
            $limit = 200;
        }

        $base = rtrim(cert_env('TONCENTER_BASE', 'https://toncenter.com/api/v3'), '/');
        $apiKey = cert_env('TONCENTER_API_KEY', '');
        $headers = ['Accept: application/json'];
        if ($apiKey !== '') {
            $headers[] = 'X-API-Key: ' . $apiKey;
        }

        $now = time();
        $start = max(0, $now - max(300, $lookback));

        $url = $base . '/transactions?' . http_build_query([
            'account' => $treasuryOwner,
            'limit' => $limit,
            'sort' => 'desc',
        ]);

        try {
            $json = cert_http_get_json($url, $headers);
        } catch (Throwable $e) {
            return cert_verify_result(
                false,
                'HTTP_ERROR',
                false,
                $e->getMessage()
            );
        }

        $rows = $json['transactions'] ?? $json['result'] ?? [];
        if (!is_array($rows)) {
            $rows = [];
        }

        $searched = 0;
        $recent = 0;

        foreach ($rows as $tx) {
            if (!is_array($tx)) {
                continue;
            }

            $txTime = (int)($tx['now'] ?? 0);
            if ($txTime > 0 && $txTime < $start) {
                continue;
            }

            $recent++;
            $searched++;

            foreach (cert_candidate_rows_from_tx($tx) as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }

                if (!cert_match_token_master($candidate, $tokenMaster)) {
                    continue;
                }

                if (!cert_match_amount_units($candidate, $amountUnits)) {
                    continue;
                }

                if (!cert_match_ref($candidate, $ref)) {
                    continue;
                }

                return cert_verify_result(
                    true,
                    'MATCHED',
                    true,
                    'Matched by treasury transaction: token_master + amount_units + ref',
                    cert_extract_tx_hash($tx),
                    cert_extract_confirmations($tx),
                    [
                        'searched_rows' => $searched,
                        'recent_rows' => $recent,
                        'lookback_seconds' => $lookback,
                        'token_master' => $tokenMaster,
                        'amount_units' => $amountUnits,
                        'ref' => $ref,
                    ]
                );
            }
        }

        return cert_verify_result(
            true,
            'NOT_FOUND',
            false,
            'No matching treasury transaction found',
            '',
            0,
            [
                'searched_rows' => $searched,
                'recent_rows' => $recent,
                'lookback_seconds' => $lookback,
                'token_master' => $tokenMaster,
                'amount_units' => $amountUnits,
                'ref' => $ref,
            ]
        );
    }
}
