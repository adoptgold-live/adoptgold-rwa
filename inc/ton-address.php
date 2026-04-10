<?php
declare(strict_types=1);

/**
 * TON address helper
 * Supports:
 * - raw: workchain:64hex
 * - user-friendly: base64/base64url TON address
 */

if (!function_exists('poado_ton_normalize_b64')) {
    function poado_ton_normalize_b64(string $s): string {
        $s = trim($s);
        $s = strtr($s, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad > 0) {
            $s .= str_repeat('=', 4 - $pad);
        }
        return $s;
    }
}

if (!function_exists('poado_ton_crc16_xmodem')) {
    function poado_ton_crc16_xmodem(string $data): int {
        $crc = 0x0000;
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $crc ^= (ord($data[$i]) << 8);
            for ($j = 0; $j < 8; $j++) {
                if (($crc & 0x8000) !== 0) {
                    $crc = (($crc << 1) ^ 0x1021) & 0xFFFF;
                } else {
                    $crc = ($crc << 1) & 0xFFFF;
                }
            }
        }
        return $crc & 0xFFFF;
    }
}

if (!function_exists('poado_ton_is_valid_raw_address')) {
    function poado_ton_is_valid_raw_address(string $addr): bool {
        $addr = trim($addr);
        return (bool) preg_match('/^-?\d+:[a-fA-F0-9]{64}$/', $addr);
    }
}

if (!function_exists('poado_ton_parse_friendly_address')) {
    function poado_ton_parse_friendly_address(string $addr): array {
        $addr = trim($addr);
        $norm = poado_ton_normalize_b64($addr);
        $bin = base64_decode($norm, true);

        if ($bin === false || strlen($bin) !== 36) {
            return ['ok' => false, 'error' => 'Invalid friendly address length'];
        }

        $body = substr($bin, 0, 34);
        $crcBytes = substr($bin, 34, 2);
        $crcActual = unpack('n', $crcBytes)[1];
        $crcExpect = poado_ton_crc16_xmodem($body);

        if ($crcActual !== $crcExpect) {
            return ['ok' => false, 'error' => 'Invalid friendly address checksum'];
        }

        $tag = ord($body[0]);
        $wcByte = ord($body[1]);

        $isTestOnly = (bool) ($tag & 0x80);
        $tagBase = $tag & 0x7F;

        // Known main tags in friendly format
        $isBounceable = ($tagBase === 0x11);
        $isNonBounceable = ($tagBase === 0x51);

        if (!$isBounceable && !$isNonBounceable) {
            return ['ok' => false, 'error' => 'Invalid friendly address tag'];
        }

        $workchain = ($wcByte === 0xFF) ? -1 : $wcByte;
        $hashPart = substr($body, 2, 32);

        return [
            'ok' => true,
            'format' => 'friendly',
            'is_test_only' => $isTestOnly,
            'is_bounceable' => $isBounceable,
            'workchain' => $workchain,
            'hash_hex' => bin2hex($hashPart),
            'raw' => $workchain . ':' . bin2hex($hashPart),
        ];
    }
}

if (!function_exists('poado_ton_validate_address')) {
    function poado_ton_validate_address(string $addr): array {
        $addr = trim($addr);

        if ($addr === '') {
            return ['ok' => false, 'error' => 'Empty address'];
        }

        if (poado_ton_is_valid_raw_address($addr)) {
            [$wc, $hex] = explode(':', $addr, 2);
            return [
                'ok' => true,
                'format' => 'raw',
                'workchain' => (int) $wc,
                'hash_hex' => strtolower($hex),
                'raw' => ((int)$wc) . ':' . strtolower($hex),
            ];
        }

        return poado_ton_parse_friendly_address($addr);
    }
}