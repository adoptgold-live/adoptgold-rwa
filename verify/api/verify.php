<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    verify_json([
        'ok' => false,
        'status' => 'invalid',
        'message' => 'Method not allowed.',
        'ts' => time(),
    ], 405);
}

$input = $method === 'POST'
    ? (string)($_POST['q'] ?? '')
    : (string)($_GET['q'] ?? '');

$q = verify_clean_query($input);
if ($q === '') {
    verify_json([
        'ok' => false,
        'status' => 'invalid',
        'message' => 'Empty query.',
        'ts' => time(),
    ], 400);
}

$type = verify_guess_query_type($q);

if ($type === 'token_symbol' || $type === 'master_address') {
    $token = verify_token_by_any($q);
    if ($token === null) {
        verify_json(verify_unknown_payload($q), 404);
    }
    verify_json(verify_token_payload($token, $q, $type));
}

if ($type === 'ton_address') {
    $token = verify_token_by_any($q);
    if ($token !== null) {
        verify_json(verify_token_payload($token, $q, 'master_address'));
    }

    verify_json([
        'ok' => true,
        'verified' => false,
        'status' => 'not_found',
        'query' => $q,
        'query_type' => 'ton_address',
        'message' => 'TON address detected, but it does not match any locked jetton master in this verifier.',
        'explorer' => [
            'tonviewer' => verify_tonviewer_link($q),
            'tonscan' => verify_tonscan_link($q),
            'internal_address_page' => '/address.html?address=' . rawurlencode($q),
        ],
        'ts' => time(),
    ]);
}

if ($type === 'cert_uid') {
    verify_json(verify_cert_uid_payload(strtoupper($q)));
}

verify_json(verify_unknown_payload($q), 404);
