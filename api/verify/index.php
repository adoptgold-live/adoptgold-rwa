<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$input = verify_api_payload_input();

if ($input['query'] === '') {
    verify_api_json([
        'ok' => false,
        'verified' => false,
        'status' => 'invalid',
        'message' => 'Empty query.',
        'usage' => [
            'GET /rwa/api/verify/index.php?q=WEMS',
            'GET /rwa/api/verify/index.php?q=EQBj0zGcHOvN5IsBP_BAAG5NRiuAa_SLBu-xjsJn7AeM4nQy',
            'GET /rwa/api/verify/index.php?q=RK92-EMA-20260310-1A2B3C4D',
            'POST JSON {"query":"WEMS"}',
        ],
        'ts' => time(),
        'at' => verify_api_now(),
    ], 400);
}

$type = verify_api_guess_type($input['query'], $input['mode']);

if ($type === 'token') {
    $token = verify_api_find_token($input['query']);
    if ($token === null) {
        verify_api_json(verify_api_not_found($input['query'], 'token'), 404);
    }
    verify_api_json(verify_api_build_token_result($token, $input['query'], 'token'));
}

if ($type === 'cert') {
    verify_api_json(verify_api_build_cert_result($input['query']));
}

verify_api_json(verify_api_not_found($input['query'], 'unknown'), 404);
