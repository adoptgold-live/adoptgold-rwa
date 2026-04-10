<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/api/storage/store-action.php
 * AdoptGold / POAdo — Storage Store Action API
 * Version: v3.1.0-exact-schema-20260317
 *
 * POST only
 *
 * Exact confirmed schema:
 * - rwa_storage_balances:
 *   user_id, card_balance_rwa, onchain_emx, onchain_ema, onchain_wems,
 *   unclaim_ema, unclaim_wems, unclaim_gold_packet_usdt, unclaim_tips_emx,
 *   fuel_usdt_ton, fuel_ems, fuel_ton_gas, created_at, updated_at
 *
 * Locked rules:
 * - STORE IN allowed: EMX, EMA$, wEMS, EMS, USDT-TON
 * - STORE OUT allowed: EMX, EMA$, wEMS
 * - require TON bind
 * - require CSRF
 *
 * Current production interpretation:
 * - STORE IN / STORE OUT are offchain balance moves against confirmed storage balances
 * - onchain columns are used as source/sink mirrors for allowed supported tokens
 * - no guessed columns
 */

require_once __DIR__ . '/_bootstrap.php';

storage_assert_ready();
storage_require_post();
storage_require_user();
storage_require_csrf_any('storage_store_action');
storage_assert_ton_bound();

$userId = storage_user_id();
$mode = strtoupper(trim((string)(storage_input('mode', '') ?: storage_input('action', ''))));
$tokenInput = (string)storage_input('token', '');
$token = storage_normalize_token($tokenInput);
$amount = storage_require_positive_amount(storage_input('amount', ''), 4);

if (!in_array($mode, ['STORE_IN', 'STORE_OUT'], true)) {
    storage_abort('INVALID_STORE_MODE', 422, ['mode' => $mode]);
}

if ($mode === 'STORE_IN') {
    storage_require_enum($token, ['EMX', 'EMA', 'WEMS', 'EMS', 'USDT-TON'], 'INVALID_STORE_IN_TOKEN');
} else {
    storage_require_enum($token, ['EMX', 'EMA', 'WEMS'], 'INVALID_STORE_OUT_TOKEN');
}

storage_tx_begin();

try {
    $balances = storage_balance_ensure_row($userId);

    if ($mode === 'STORE_IN') {
        $result = storage_apply_store_in($userId, $balances, $token, $amount);
    } else {
        $result = storage_apply_store_out($userId, $balances, $token, $amount);
    }

    storage_tx_commit();

    storage_json_ok([
        'mode' => $mode,
        'token' => $token,
        'amount' => $amount,
        'message' => $result['message'],
        'balances' => $result['balances'],
    ]);
} catch (Throwable $e) {
    storage_tx_rollback();
    throw $e;
}

function storage_apply_store_in(int $userId, array $balances, string $token, string $amount): array
{
    return match ($token) {
        'EMX' => storage_move_between_balance_columns(
            $userId,
            $balances,
            'onchain_emx',
            'unclaim_tips_emx',
            $amount,
            'store_in',
            'STORE_IN_SUCCESS'
        ),
        'EMA' => storage_move_between_balance_columns(
            $userId,
            $balances,
            'onchain_ema',
            'unclaim_ema',
            $amount,
            'store_in',
            'STORE_IN_SUCCESS'
        ),
        'WEMS' => storage_move_between_balance_columns(
            $userId,
            $balances,
            'onchain_wems',
            'unclaim_wems',
            $amount,
            'store_in',
            'STORE_IN_SUCCESS'
        ),
        'EMS' => storage_move_between_balance_columns(
            $userId,
            $balances,
            'fuel_ems',
            'fuel_ems',
            $amount,
            'store_in',
            'STORE_IN_SUCCESS',
            true
        ),
        'USDT-TON' => storage_move_between_balance_columns(
            $userId,
            $balances,
            'fuel_usdt_ton',
            'unclaim_gold_packet_usdt',
            $amount,
            'store_in',
            'STORE_IN_SUCCESS'
        ),
        default => storage_abort('INVALID_STORE_IN_TOKEN', 422, ['token' => $token]),
    };
}

function storage_apply_store_out(int $userId, array $balances, string $token, string $amount): array
{
    return match ($token) {
        'EMX' => storage_move_between_balance_columns(
            $userId,
            $balances,
            'unclaim_tips_emx',
            'onchain_emx',
            $amount,
            'store_out',
            'STORE_OUT_SUCCESS'
        ),
        'EMA' => storage_move_between_balance_columns(
            $userId,
            $balances,
            'unclaim_ema',
            'onchain_ema',
            $amount,
            'store_out',
            'STORE_OUT_SUCCESS'
        ),
        'WEMS' => storage_move_between_balance_columns(
            $userId,
            $balances,
            'unclaim_wems',
            'onchain_wems',
            $amount,
            'store_out',
            'STORE_OUT_SUCCESS'
        ),
        default => storage_abort('INVALID_STORE_OUT_TOKEN', 422, ['token' => $token]),
    };
}

function storage_move_between_balance_columns(
    int $userId,
    array $balances,
    string $fromCol,
    string $toCol,
    string $amount,
    string $historyType,
    string $message,
    bool $sameColumnNoop = false
): array {
    $currentFrom = storage_decimal4((string)($balances[$fromCol] ?? '0.0000'));
    $currentTo = storage_decimal4((string)($balances[$toCol] ?? '0.0000'));
    $amount = storage_decimal4($amount);

    if (!storage_decimal4_gte($currentFrom, $amount) && !$sameColumnNoop) {
        storage_abort('INSUFFICIENT_BALANCE', 422, [
            'column' => $fromCol,
            'available' => $currentFrom,
            'required' => $amount,
        ]);
    }

    if ($sameColumnNoop) {
        storage_history_add(
            $userId,
            $historyType,
            storage_token_for_column($toCol),
            $amount,
            [
                'source_column' => $fromCol,
                'target_column' => $toCol,
                'mode' => 'noop_same_column',
            ]
        );

        return [
            'message' => $message,
            'balances' => storage_balance_ensure_row($userId),
        ];
    }

    $newFrom = storage_decimal4_sub($currentFrom, $amount);
    $newTo = storage_decimal4_add($currentTo, $amount);

    storage_exec(
        "UPDATE rwa_storage_balances
         SET `{$fromCol}` = :from_val,
             `{$toCol}` = :to_val,
             `updated_at` = :updated_at
         WHERE user_id = :user_id
         LIMIT 1",
        [
            ':from_val' => $newFrom,
            ':to_val' => $newTo,
            ':updated_at' => storage_now(),
            ':user_id' => $userId,
        ]
    );

    storage_history_add(
        $userId,
        $historyType,
        storage_token_for_column($toCol),
        $amount,
        [
            'source_column' => $fromCol,
            'target_column' => $toCol,
            'before_source' => $currentFrom,
            'after_source' => $newFrom,
            'before_target' => $currentTo,
            'after_target' => $newTo,
        ]
    );

    return [
        'message' => $message,
        'balances' => storage_balance_ensure_row($userId),
    ];
}

function storage_token_for_column(string $column): string
{
    return match ($column) {
        'onchain_emx', 'unclaim_tips_emx' => 'EMX',
        'onchain_ema', 'unclaim_ema' => 'EMA',
        'onchain_wems', 'unclaim_wems' => 'WEMS',
        'fuel_ems' => 'EMS',
        'fuel_usdt_ton', 'unclaim_gold_packet_usdt' => 'USDT-TON',
        default => 'TOKEN',
    };
}

function storage_decimal4(string $value): string
{
    $raw = trim($value);
    if ($raw === '' || !preg_match('/^-?\d+(\.\d+)?$/', $raw)) {
        $raw = '0';
    }

    $neg = '';
    if (str_starts_with($raw, '-')) {
        $neg = '-';
        $raw = substr($raw, 1);
    }

    [$int, $frac] = array_pad(explode('.', $raw, 2), 2, '');
    $int = ltrim($int, '0');
    $int = ($int === '') ? '0' : $int;
    $frac = preg_replace('/\D+/', '', $frac) ?? '';
    $frac = substr(str_pad($frac, 4, '0'), 0, 4);

    return $neg . $int . '.' . $frac;
}

function storage_decimal4_add(string $a, string $b): string
{
    [$ai, $af] = storage_decimal4_parts($a);
    [$bi, $bf] = storage_decimal4_parts($b);

    $frac = $af + $bf;
    $carry = intdiv($frac, 10000);
    $frac = $frac % 10000;
    $int = $ai + $bi + $carry;

    return $int . '.' . str_pad((string)$frac, 4, '0', STR_PAD_LEFT);
}

function storage_decimal4_sub(string $a, string $b): string
{
    [$ai, $af] = storage_decimal4_parts($a);
    [$bi, $bf] = storage_decimal4_parts($b);

    if ($ai < $bi || ($ai === $bi && $af < $bf)) {
        storage_abort('NEGATIVE_BALANCE_RESULT', 422, [
            'left' => $a,
            'right' => $b,
        ]);
    }

    if ($af < $bf) {
        $ai -= 1;
        $af += 10000;
    }

    $int = $ai - $bi;
    $frac = $af - $bf;

    return $int . '.' . str_pad((string)$frac, 4, '0', STR_PAD_LEFT);
}

function storage_decimal4_gte(string $a, string $b): bool
{
    [$ai, $af] = storage_decimal4_parts($a);
    [$bi, $bf] = storage_decimal4_parts($b);

    return ($ai > $bi) || ($ai === $bi && $af >= $bf);
}

function storage_decimal4_parts(string $value): array
{
    $v = storage_decimal4($value);

    if (str_starts_with($v, '-')) {
        storage_abort('NEGATIVE_DECIMAL_NOT_SUPPORTED', 422, ['value' => $value]);
    }

    [$int, $frac] = explode('.', $v, 2);
    return [(int)$int, (int)$frac];
}