<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

header('Content-Type: application/json');

try {
    $user = rwa_require_login();
    $wallet = trim((string)$user['wallet_address']);

    if ($wallet === '') {
        echo json_encode([
            'ok' => false,
            'error' => 'WALLET_NOT_BOUND'
        ]);
        exit;
    }

    $base = rtrim(getenv('TON_API_BASE') ?: 'https://tonapi.io/v2', '/');
    $apiKey = getenv('TON_API_KEY');

    $url = $base . '/accounts/' . urlencode($wallet) . '/jettons';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_TIMEOUT => 10
    ]);

    $resp = curl_exec($ch);
    if ($resp === false) {
        throw new Exception('TON_API_ERROR');
    }

    $data = json_decode($resp, true);

    $balances = [
        'EMX' => '0',
        'EMA' => '0',
        'EMS' => '0',
        'WEMS' => '0',
        'USDT' => '0'
    ];

    foreach (($data['balances'] ?? []) as $jetton) {
        $master = $jetton['jetton']['address'] ?? '';
        $amount = $jetton['balance'] ?? '0';
        $decimals = (int)($jetton['jetton']['decimals'] ?? 9);

        $normalized = bcdiv($amount, bcpow('10', (string)$decimals), 6);

        switch ($master) {
            case JETTON_EMX:
                $balances['EMX'] = $normalized;
                break;
            case JETTON_EMA:
                $balances['EMA'] = $normalized;
                break;
            case JETTON_EMS:
                $balances['EMS'] = $normalized;
                break;
            case JETTON_WEMS:
                $balances['WEMS'] = $normalized;
                break;
            case JETTON_USDT:
                $balances['USDT'] = $normalized;
                break;
        }
    }

    echo json_encode([
        'ok' => true,
        'wallet' => $wallet,
        'balances' => $balances,
        'ts' => time()
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}