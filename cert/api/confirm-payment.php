<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/api/confirm-payment.php
 * Version: v6.0.0-20260410-final-payment-authority
 *
 * FINAL LOCK
 * - Business payment authority for cert flow
 * - Match rule = token master + exact amount_units + payment_ref/comment
 * - Destination is NOT required
 * - DB truth first, UI never authoritative
 * - Success writes:
 *     poado_rwa_cert_payments.status   = confirmed
 *     poado_rwa_cert_payments.verified = 1
 *     poado_rwa_certs.status           = paid
 * - Replay protection on tx_hash
 * - Safe unified JSON response
 */

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once __DIR__ . '/_helpers.php';

if (!function_exists('db_connect')) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'BOOTSTRAP_DB_CONNECT_MISSING',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db_connect();

function cp_json(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function cp_now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function cp_read_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
}

function cp_get_cert_uid(): string
{
    $in = cp_read_input();

    $candidates = [
        $in['cert_uid'] ?? null,
        $in['uid'] ?? null,
        $in['cert'] ?? null,
        $_POST['cert_uid'] ?? null,
        $_POST['uid'] ?? null,
        $_POST['cert'] ?? null,
        $_GET['cert_uid'] ?? null,
        $_GET['uid'] ?? null,
        $_GET['cert'] ?? null,
    ];

    foreach ($candidates as $v) {
        $s = trim((string)$v);
        if ($s !== '') {
            return $s;
        }
    }
    return '';
}

function cp_norm_addr_raw(string $addr): string
{
    $addr = trim($addr);
    if ($addr === '') {
        return '';
    }

    if (strpos($addr, ':') !== false) {
        [$wc, $hex] = array_pad(explode(':', $addr, 2), 2, '');
        $hex = strtolower(preg_replace('/[^0-9a-f]/i', '', $hex));
        if ($hex !== '') {
            return ((string)(int)$wc) . ':' . $hex;
        }
    }

    return $addr;
}

function cp_eq_to_raw(string $friendly): string
{
    $friendly = trim($friendly);
    if ($friendly === '') {
        return '';
    }

    if (strpos($friendly, ':') !== false) {
        return cp_norm_addr_raw($friendly);
    }

    if (!class_exists('\\Tonkeeper\\Tongo\\Address')) {
        return $friendly;
    }

    try {
        /** @var \Tonkeeper\Tongo\Address $parsed */
        $parsed = \Tonkeeper\Tongo\Address::fromString($friendly);
        return cp_norm_addr_raw($parsed->toRaw());
    } catch (\Throwable $e) {
        return $friendly;
    }
}

function cp_norm_any_addr(string $addr): string
{
    $addr = trim($addr);
    if ($addr === '') {
        return '';
    }

    if (strpos($addr, ':') !== false) {
        return cp_norm_addr_raw($addr);
    }

    $raw = cp_eq_to_raw($addr);
    return cp_norm_addr_raw($raw);
}

function cp_norm_master_compare(string $addr): string
{
    return cp_norm_any_addr($addr);
}

function cp_norm_ref(string $s): string
{
    $s = trim($s);
    if ($s === '') {
        return '';
    }
    return preg_replace('/\s+/', ' ', $s);
}

function cp_extract_comment_from_transfer(array $row): string
{
    $candidates = [
        $row['comment'] ?? null,
        $row['payload'] ?? null,
        $row['text'] ?? null,
        $row['memo'] ?? null,
        $row['ref'] ?? null,
        $row['decoded_forward_payload']['comment'] ?? null,
        $row['forward_payload_comment'] ?? null,
        $row['decoded_payload']['comment'] ?? null,
    ];

    foreach ($candidates as $v) {
        $s = trim((string)$v);
        if ($s !== '') {
            return cp_norm_ref($s);
        }
    }

    $fp = trim((string)($row['forward_payload'] ?? ''));
    if ($fp !== '') {
        $decoded = base64_decode(strtr($fp, '-_', '+/'), true);
        if ($decoded !== false && preg_match('/PAY-[A-Z0-9]+/i', $decoded, $m)) {
            return cp_norm_ref($m[0]);
        }
    }

    return '';
}

function cp_build_payment_from_rows(array $certRow, ?array $paymentRow): array
{
    $meta = [];
    if (!empty($certRow['meta_json'])) {
        $decoded = json_decode((string)$certRow['meta_json'], true);
        if (is_array($decoded)) {
            $meta = $decoded;
        }
    }

    $metaPayment = is_array($meta['payment'] ?? null) ? $meta['payment'] : [];

    $paymentRef = trim((string)(
        $paymentRow['payment_ref'] ??
        $certRow['payment_ref'] ??
        $metaPayment['payment_ref'] ??
        ''
    ));

    $tokenSymbol = trim((string)(
        $paymentRow['token_symbol'] ??
        $paymentRow['payment_token'] ??
        $certRow['payment_token'] ??
        $metaPayment['token_symbol'] ??
        $metaPayment['payment_token'] ??
        'EMA$'
    ));

    $tokenMaster = trim((string)(
        $paymentRow['token_master'] ??
        $metaPayment['token_master'] ??
        ''
    ));

    $amountUnits = trim((string)(
        $paymentRow['amount_units'] ??
        $paymentRow['payment_amount_units'] ??
        $certRow['payment_amount_units'] ??
        $metaPayment['amount_units'] ??
        ''
    ));

    $amount = trim((string)(
        $paymentRow['amount'] ??
        $paymentRow['payment_amount'] ??
        $certRow['payment_amount'] ??
        $metaPayment['amount'] ??
        ''
    ));

    $destination = trim((string)(
        $paymentRow['destination'] ??
        $metaPayment['destination'] ??
        ''
    ));

    $decimals = (int)($paymentRow['decimals'] ?? $metaPayment['decimals'] ?? 9);
    $status = trim((string)($paymentRow['status'] ?? 'pending'));
    $verified = (int)($paymentRow['verified'] ?? 0);
    $txHash = trim((string)($paymentRow['tx_hash'] ?? ''));
    $paidAt = trim((string)($paymentRow['paid_at'] ?? ''));

    $deeplink = trim((string)(
        $paymentRow['deeplink'] ??
        $paymentRow['wallet_link'] ??
        $metaPayment['deeplink'] ??
        $metaPayment['wallet_link'] ??
        $metaPayment['wallet_url'] ??
        ''
    ));

    $walletLink = trim((string)(
        $paymentRow['wallet_link'] ??
        $paymentRow['wallet_url'] ??
        $metaPayment['wallet_link'] ??
        $metaPayment['wallet_url'] ??
        $deeplink
    ));

    $qrPayload = trim((string)(
        $paymentRow['qr_payload'] ??
        $metaPayment['qr_payload'] ??
        $deeplink
    ));

    $qrText = trim((string)(
        $paymentRow['qr_text'] ??
        $metaPayment['qr_text'] ??
        $qrPayload
    ));

    $qrImage = trim((string)(
        $paymentRow['qr_image'] ??
        $paymentRow['qr_url'] ??
        $metaPayment['qr_image'] ??
        $metaPayment['qr_url'] ??
        ''
    ));

    return [
        'status'       => $status !== '' ? $status : 'pending',
        'verified'     => $verified,
        'token_symbol' => $tokenSymbol,
        'token_master' => $tokenMaster,
        'amount'       => $amount,
        'amount_units' => $amountUnits,
        'payment_ref'  => $paymentRef,
        'destination'  => $destination,
        'deeplink'     => $deeplink,
        'wallet_link'  => $walletLink !== '' ? $walletLink : $deeplink,
        'qr_payload'   => $qrPayload !== '' ? $qrPayload : $deeplink,
        'qr_image'     => $qrImage,
        'qr_text'      => $qrText !== '' ? $qrText : $deeplink,
        'tx_hash'      => $txHash,
        'paid_at'      => $paidAt,
        'decimals'     => $decimals,
        'ton_wallet'   => trim((string)($certRow['ton_wallet'] ?? '')),
    ];
}

function cp_find_cert(PDO $pdo, string $certUid): ?array
{
    $sql = "SELECT *
            FROM poado_rwa_certs
            WHERE cert_uid = :uid
            LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([':uid' => $certUid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function cp_find_payment_row(PDO $pdo, string $certUid): ?array
{
    $tables = ['poado_rwa_cert_payments'];

    foreach ($tables as $table) {
        try {
            $sql = "SELECT *
                    FROM {$table}
                    WHERE cert_uid = :uid
                    ORDER BY id DESC
                    LIMIT 1";
            $st = $pdo->prepare($sql);
            $st->execute([':uid' => $certUid]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                return $row;
            }
        } catch (\Throwable $e) {
            continue;
        }
    }

    return null;
}

function cp_get_toncenter_base(): string
{
    $candidates = [
        getenv('TONCENTER_V3_BASE') ?: '',
        getenv('TONCENTER_BASE') ?: '',
        'https://toncenter.com/api/v3',
    ];

    foreach ($candidates as $base) {
        $base = rtrim(trim($base), '/');
        if ($base !== '') {
            return $base;
        }
    }

    return 'https://toncenter.com/api/v3';
}

function cp_get_toncenter_key(): string
{
    $keys = [
        getenv('TONCENTER_API_KEY') ?: '',
        getenv('TON_API_KEY') ?: '',
    ];

    foreach ($keys as $k) {
        $k = trim($k);
        if ($k !== '') {
            return $k;
        }
    }

    return '';
}

function cp_http_get_json(string $url): array
{
    $headers = ['Accept: application/json'];
    $apiKey = cp_get_toncenter_key();
    if ($apiKey !== '') {
        $headers[] = 'X-API-Key: ' . $apiKey;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        throw new RuntimeException('CURL_ERROR: ' . $error);
    }
    if ($http < 200 || $http >= 300) {
        throw new RuntimeException('HTTP_' . $http);
    }

    $json = json_decode((string)$body, true);
    if (!is_array($json)) {
        throw new RuntimeException('INVALID_JSON');
    }

    return $json;
}

function cp_query_recent_transfers(string $destination, int $limit = 200): array
{
    $base = cp_get_toncenter_base();
    $destinationRaw = cp_norm_any_addr($destination);

    $queries = [];

    if ($destinationRaw !== '') {
        $queries[] = $base . '/jetton/transfers?limit=' . $limit . '&destination=' . rawurlencode($destinationRaw);
    }
    $queries[] = $base . '/jetton/transfers?limit=' . $limit . '&destination=' . rawurlencode(trim($destination));

    $seen = [];
    foreach ($queries as $url) {
        if (isset($seen[$url])) {
            continue;
        }
        $seen[$url] = true;

        try {
            $json = cp_http_get_json($url);
            $rows = $json['jetton_transfers'] ?? [];
            if (is_array($rows)) {
                return $rows;
            }
        } catch (\Throwable $e) {
            continue;
        }
    }

    return [];
}

function cp_match_transfer(array $payment, array $rows): array
{
    $expectedMaster = cp_norm_master_compare((string)$payment['token_master']);
    $expectedAmount = trim((string)$payment['amount_units']);
    $expectedRef = cp_norm_ref((string)$payment['payment_ref']);

    $searchedRows = count($rows);
    $recentRows = 0;

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $recentRows++;

        $rowMaster = cp_norm_master_compare((string)($row['jetton_master'] ?? ''));
        $rowAmount = trim((string)($row['amount'] ?? ''));
        $rowRef = cp_extract_comment_from_transfer($row);

        $masterOk = ($expectedMaster !== '' && $rowMaster !== '' && $rowMaster === $expectedMaster);
        $amountOk = ($expectedAmount !== '' && $rowAmount === $expectedAmount);
        $refOk = ($expectedRef !== '' && $rowRef !== '' && $rowRef === $expectedRef);

        if ($masterOk && $amountOk && $refOk) {
            return [
                'ok'            => true,
                'status'        => 'MATCHED',
                'verified'      => true,
                'code'          => 'MATCHED',
                'tx_hash'       => trim((string)($row['transaction_hash'] ?? '')),
                'confirmations' => 0,
                'verify_source' => 'cert_local_toncenter_v3',
                'message'       => 'Matched treasury transaction by token master + amount + ref',
                'matched_row'   => $row,
                'debug'         => [
                    'searched_rows'    => $searchedRows,
                    'recent_rows'      => $recentRows,
                    'lookback_seconds' => 86400,
                    'token_master'     => $expectedMaster,
                    'amount_units'     => $expectedAmount,
                    'ref'              => $expectedRef,
                ],
            ];
        }
    }

    return [
        'ok'            => true,
        'status'        => 'NOT_FOUND',
        'verified'      => false,
        'code'          => 'NOT_FOUND',
        'tx_hash'       => '',
        'confirmations' => 0,
        'verify_source' => 'cert_local_toncenter_v3',
        'message'       => 'No matching treasury transaction found',
        'matched_row'   => null,
        'debug'         => [
            'searched_rows'    => $searchedRows,
            'recent_rows'      => $recentRows,
            'lookback_seconds' => 86400,
            'token_master'     => $expectedMaster,
            'amount_units'     => $expectedAmount,
            'ref'              => $expectedRef,
        ],
    ];
}

function cp_replay_exists(PDO $pdo, string $txHash, string $certUid): bool
{
    if ($txHash === '') {
        return false;
    }

    try {
        $sql = "SELECT cert_uid
                FROM poado_rwa_cert_payments
                WHERE tx_hash = :tx
                  AND cert_uid <> :uid
                LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([
            ':tx'  => $txHash,
            ':uid' => $certUid,
        ]);
        return (bool)$st->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return false;
    }
}

function cp_update_payment_row(PDO $pdo, string $certUid, array $payment, array $verify): bool
{
    try {
        $sql = "UPDATE poado_rwa_cert_payments
                SET status = :status,
                    verified = :verified,
                    tx_hash = :tx_hash,
                    verify_source = :verify_source,
                    confirmations = :confirmations,
                    paid_at = :paid_at,
                    updated_at = NOW()
                WHERE cert_uid = :uid";
        $st = $pdo->prepare($sql);
        $st->execute([
            ':status'        => 'confirmed',
            ':verified'      => 1,
            ':tx_hash'       => (string)($verify['tx_hash'] ?? ''),
            ':verify_source' => (string)($verify['verify_source'] ?? 'cert_local_toncenter_v3'),
            ':confirmations' => (int)($verify['confirmations'] ?? 0),
            ':paid_at'       => cp_now_utc(),
            ':uid'           => $certUid,
        ]);
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

function cp_update_cert_row(PDO $pdo, array $certRow, array $payment, array $verify): array
{
    $meta = [];
    if (!empty($certRow['meta_json'])) {
        $decoded = json_decode((string)$certRow['meta_json'], true);
        if (is_array($decoded)) {
            $meta = $decoded;
        }
    }

    if (!is_array($meta['payment'] ?? null)) {
        $meta['payment'] = [];
    }

    $meta['payment']['status'] = 'confirmed';
    $meta['payment']['verified'] = true;
    $meta['payment']['tx_hash'] = (string)($verify['tx_hash'] ?? '');
    $meta['payment']['paid_at'] = cp_now_utc();
    $meta['payment']['verify_source'] = (string)($verify['verify_source'] ?? 'cert_local_toncenter_v3');
    $meta['queue_bucket'] = 'mint_ready_queue';
    $meta['flow_state'] = 'payment_confirmed';

    $ok = false;
    try {
        $sql = "UPDATE poado_rwa_certs
                SET status = :status,
                    paid_at = :paid_at,
                    updated_at = NOW(),
                    meta_json = :meta_json
                WHERE cert_uid = :uid";
        $st = $pdo->prepare($sql);
        $st->execute([
            ':status'   => 'paid',
            ':paid_at'  => cp_now_utc(),
            ':meta_json'=> json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':uid'      => (string)$certRow['cert_uid'],
        ]);
        $ok = true;
    } catch (\Throwable $e) {
        $ok = false;
    }

    return [
        'ok' => $ok,
        'meta_json' => $meta,
    ];
}

function cp_try_repair(string $certUid): array
{
    $script = dirname(__DIR__) . '/cron/repair-nft.php';
    if (!is_file($script)) {
        return [
            'ok'     => false,
            'status' => 'SKIPPED_REPAIR_SCRIPT_MISSING',
            'output' => [],
        ];
    }

    $cmd = 'php ' . escapeshellarg($script) . ' --uid=' . escapeshellarg($certUid) . ' 2>&1';
    $out = [];
    $code = 0;
    @exec($cmd, $out, $code);

    return [
        'ok'     => ($code === 0),
        'status' => ($code === 0 ? 'DONE' : 'FAILED'),
        'output' => $out,
    ];
}

function cp_verify_status_payload(string $certUid): ?array
{
    $url = 'https://adoptgold.app/rwa/cert/api/verify-status.php?cert_uid=' . rawurlencode($certUid);
    try {
        return cp_http_get_json($url);
    } catch (\Throwable $e) {
        return null;
    }
}

$certUid = cp_get_cert_uid();
if ($certUid === '') {
    cp_json([
        'ok'    => false,
        'error' => 'CERT_UID_REQUIRED',
    ], 422);
}

$certRow = cp_find_cert($pdo, $certUid);
if (!$certRow) {
    cp_json([
        'ok'       => false,
        'error'    => 'CERT_NOT_FOUND',
        'cert_uid' => $certUid,
    ], 404);
}

$paymentRow = cp_find_payment_row($pdo, $certUid);
$payment = cp_build_payment_from_rows($certRow, $paymentRow);

if (
    trim((string)$payment['status']) === 'confirmed'
    && (int)$payment['verified'] === 1
) {
    cp_json([
        'ok'                => true,
        'verified'          => true,
        'already_confirmed' => true,
        'confirmed_now'     => false,
        'replay_detected'   => false,
        'cert_uid'          => $certUid,
        'payment'           => $payment,
        'debug'             => [
            'ok'            => true,
            'status'        => 'ALREADY_CONFIRMED',
            'verified'      => true,
            'code'          => 'ALREADY_CONFIRMED',
            'tx_hash'       => trim((string)($payment['tx_hash'] ?? '')),
            'confirmations' => 0,
            'verify_source' => 'db_truth',
            'message'       => 'Payment already confirmed in DB',
            '_version'      => 'v6.0.0-20260410-final-payment-authority',
            '_file'         => __FILE__,
            'healed'        => [
                'payment_row'  => false,
                'meta_payment' => false,
            ],
        ],
        'repair'        => [
            'ok'     => false,
            'status' => 'SKIPPED_ALREADY_CONFIRMED',
            'output' => [],
        ],
        'verify_status' => cp_verify_status_payload($certUid),
        'read_model'    => null,
        'healed'        => [
            'payment_row'  => false,
            'meta_payment' => false,
        ],
        'ts'            => gmdate('c'),
    ]);
}

$rows = cp_query_recent_transfers((string)$payment['destination'], 200);
$verify = cp_match_transfer($payment, $rows);

$replayDetected = false;
if (!empty($verify['tx_hash'])) {
    $replayDetected = cp_replay_exists($pdo, (string)$verify['tx_hash'], $certUid);
}

$confirmedNow = false;
$repair = [
    'ok'     => false,
    'status' => 'SKIPPED_NOT_VERIFIED',
    'output' => [],
];
$healed = [
    'payment_row'  => false,
    'meta_payment' => false,
];

if (($verify['verified'] ?? false) === true && !$replayDetected) {
    $healed['payment_row'] = cp_update_payment_row($pdo, $certUid, $payment, $verify);
    $certUpdate = cp_update_cert_row($pdo, $certRow, $payment, $verify);
    $healed['meta_payment'] = (bool)$certUpdate['ok'];
    $confirmedNow = ($healed['payment_row'] || $healed['meta_payment']);

    $payment['status'] = 'confirmed';
    $payment['verified'] = 1;
    $payment['tx_hash'] = (string)($verify['tx_hash'] ?? '');
    $payment['paid_at'] = cp_now_utc();

    $repair = cp_try_repair($certUid);
}

cp_json([
    'ok'                => true,
    'verified'          => (bool)($verify['verified'] ?? false),
    'already_confirmed' => false,
    'confirmed_now'     => $confirmedNow,
    'replay_detected'   => $replayDetected,
    'cert_uid'          => $certUid,
    'payment'           => $payment,
    'debug'             => [
        'ok'            => true,
        'status'        => (string)($verify['status'] ?? 'UNKNOWN'),
        'verified'      => (bool)($verify['verified'] ?? false),
        'code'          => (string)($verify['code'] ?? ''),
        'tx_hash'       => (string)($verify['tx_hash'] ?? ''),
        'confirmations' => (int)($verify['confirmations'] ?? 0),
        'verify_source' => (string)($verify['verify_source'] ?? 'cert_local_toncenter_v3'),
        'message'       => (string)($verify['message'] ?? ''),
        'debug'         => $verify['debug'] ?? [],
        '_version'      => 'v6.0.0-20260410-final-payment-authority',
        '_file'         => __FILE__,
        'healed'        => $healed,
    ],
    'repair'        => $repair,
    'verify_status' => cp_verify_status_payload($certUid),
    'read_model'    => null,
    'healed'        => $healed,
    'ts'            => gmdate('c'),
]);
