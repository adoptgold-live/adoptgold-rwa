<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/api/check-sufficient.php
 *
 * Purpose:
 * - Cert-facing sufficiency guard
 * - Reads trusted balance truth from:
 *     /rwa/cert/api/bal-check-helper.php
 * - Evaluates a single rwa_type
 *
 * Inputs:
 * - rwa_type
 * - owner_user_id (optional if wallet provided)
 * - wallet (optional if owner_user_id provided)
 *
 * Output:
 * - ok
 * - rwa_type
 * - token
 * - required
 * - available
 * - sufficient
 * - shortfall
 * - ton_ready
 */

header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';

function suff_json(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function suff_numstr(mixed $v, int $scale = 4): string
{
    if ($v === null || $v === '') {
        return number_format(0, $scale, '.', '');
    }
    return number_format((float)$v, $scale, '.', '');
}

function suff_rule_map(): array
{
    return [
        'green'      => ['token' => 'WEMS', 'required' => 1000.0],
        'blue'       => ['token' => 'WEMS', 'required' => 5000.0],
        'black'      => ['token' => 'WEMS', 'required' => 10000.0],
        'gold'       => ['token' => 'WEMS', 'required' => 50000.0],
        'pink'       => ['token' => 'EMA',  'required' => 100.0],
        'red'        => ['token' => 'EMA',  'required' => 100.0],
        'royal_blue' => ['token' => 'EMA',  'required' => 100.0],
        'yellow'     => ['token' => 'EMA',  'required' => 100.0],
    ];
}

try {
    $rwaType = strtolower(trim((string)($_GET['rwa_type'] ?? $_POST['rwa_type'] ?? '')));
    $ownerUserId = (int)($_GET['owner_user_id'] ?? $_POST['owner_user_id'] ?? 0);
    $wallet = trim((string)($_GET['wallet'] ?? $_POST['wallet'] ?? ''));
    $debug = ((int)($_GET['debug'] ?? $_POST['debug'] ?? 0) === 1);

    if ($rwaType === '') {
        suff_json([
            'ok' => false,
            'error' => 'RWA_TYPE_REQUIRED',
            'ts' => time(),
        ], 400);
    }

    $rules = suff_rule_map();
    if (!isset($rules[$rwaType])) {
        suff_json([
            'ok' => false,
            'error' => 'UNKNOWN_RWA_TYPE',
            'rwa_type' => $rwaType,
            'ts' => time(),
        ], 400);
    }

    if ($ownerUserId <= 0 && $wallet === '') {
        suff_json([
            'ok' => false,
            'error' => 'BALANCE_CONTEXT_REQUIRED',
            'detail' => 'owner_user_id or wallet is required',
            'ts' => time(),
        ], 400);
    }

    $query = [];
    if ($ownerUserId > 0) {
        $query['owner_user_id'] = (string)$ownerUserId;
    }
    if ($wallet !== '') {
        $query['wallet'] = $wallet;
    }

    $helperUrl = 'https://adoptgold.app/rwa/cert/api/bal-check-helper.php?' . http_build_query($query);
    $helperJson = @file_get_contents($helperUrl);
    if ($helperJson === false || trim($helperJson) === '') {
        throw new RuntimeException('BAL_HELPER_FETCH_FAILED');
    }

    $helper = json_decode($helperJson, true);
    if (!is_array($helper)) {
        throw new RuntimeException('BAL_HELPER_JSON_INVALID');
    }

    if (($helper['ok'] ?? false) !== true) {
        suff_json([
            'ok' => false,
            'error' => (string)($helper['error'] ?? 'BAL_HELPER_FAILED'),
            'detail' => (string)($helper['detail'] ?? ''),
            'helper' => $debug ? $helper : null,
            'ts' => time(),
        ], 400);
    }

    $balances = is_array($helper['balances'] ?? null) ? $helper['balances'] : [];
    $wems = (float)($balances['wems'] ?? 0);
    $ema  = (float)($balances['ema'] ?? 0);
    $ton  = (float)($balances['ton'] ?? 0);

    $rule = $rules[$rwaType];
    $token = (string)$rule['token'];
    $required = (float)$rule['required'];
    $available = ($token === 'WEMS') ? $wems : $ema;
    $sufficient = ($available >= $required);
    $shortfall = $sufficient ? 0.0 : ($required - $available);

    $out = [
        'ok' => true,
        'rwa_type' => $rwaType,
        'token' => $token,
        'required' => suff_numstr($required),
        'available' => suff_numstr($available),
        'sufficient' => $sufficient,
        'shortfall' => suff_numstr($shortfall),
        'ton_ready' => ($ton >= 0.5),
        'balances' => [
            'wems' => suff_numstr($wems),
            'ema'  => suff_numstr($ema),
            'ton'  => suff_numstr($ton),
        ],
        'user_id' => (int)($helper['user_id'] ?? 0),
        'resolved_via' => (string)($helper['resolved_via'] ?? ''),
        'user' => is_array($helper['user'] ?? null) ? $helper['user'] : [],
        'source' => [
            'endpoint' => '/rwa/cert/api/bal-check-helper.php',
            'table' => (string)($helper['source']['table'] ?? 'rwa_storage_balances'),
            'updated_at' => $helper['source']['updated_at'] ?? null,
        ],
        'ts' => time(),
    ];

    if ($debug) {
        $out['debug'] = [
            'helper_url' => $helperUrl,
            'helper' => $helper,
        ];
    }

    suff_json($out, 200);

} catch (Throwable $e) {
    suff_json([
        'ok' => false,
        'error' => 'CHECK_SUFFICIENT_FAILED',
        'detail' => $e->getMessage(),
        'ts' => time(),
    ], 500);
}
