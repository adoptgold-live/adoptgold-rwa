<?php
declare(strict_types=1);

/**
 * SAFE MATCH ENGINE (NO ARRAY→STRING WARNINGS)
 */

if (!function_exists('cert_mint_verify_tx_matches_index')) {
    function cert_mint_verify_tx_matches_index(array $tx, string $itemIndex): bool
    {
        $need = strtolower(trim($itemIndex));
        if ($need === '') return false;

        $parts = [];

        $push = function ($v) use (&$parts) {
            if (is_string($v)) {
                $v = trim($v);
                if ($v !== '') $parts[] = $v;
                return;
            }

            if (is_int($v) || is_float($v) || is_bool($v)) {
                $parts[] = (string)$v;
                return;
            }

            if (is_array($v)) {
                foreach ($v as $vv) {
                    if (is_string($vv)) {
                        $vv = trim($vv);
                        if ($vv !== '') $parts[] = $vv;
                    } elseif (is_int($vv) || is_float($vv) || is_bool($vv)) {
                        $parts[] = (string)$vv;
                    }
                }
            }
        };

        $push($tx['description'] ?? null);
        $push($tx['hash'] ?? null);

        foreach ($tx as $k => $v) {
            $push($v);
        }

        $haystack = strtolower(implode("\n", $parts));

        if ($haystack === '') return false;

        return strpos($haystack, $need) !== false;
    }
}


if (!function_exists('cert_mint_verify_onchain')) {
    function cert_mint_verify_onchain(array $rules): array
    {
        $collection = (string)($rules['collection_address'] ?? '');
        $itemIndex  = (string)($rules['item_index'] ?? '');

        if ($collection === '' || $itemIndex === '') {
            return [
                'ok' => false,
                'verified' => false,
                'reason' => 'INVALID_RULE_INPUT'
            ];
        }

        // SIMPLE MATCH MODE (SAFE BASELINE)
        // NOTE: real TON scan logic can be plugged here later

        return [
            'ok' => true,
            'verified' => false,
            'minted' => false,
            'match' => [
                'collection' => $collection,
                'item_index' => $itemIndex,
                'mode' => 'no_match'
            ]
        ];
    }
}
