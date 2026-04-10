<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/api/issue-pay.php
 * Version: v1.3.0-20260408-unified-payment-model
 *
 * STRICT LOCK
 * - DB schema verified before coding
 * - poado_rwa_certs wallet field = ton_wallet only
 * - poado_rwa_cert_payments wallet field = ton_wallet only
 * - never query wallet or wallet_address
 * - QR payload must equal deeplink
 * - wallet_link = deeplink = qr_text
 * - amount_units must use string-safe decimal scaling
 * - no fallback to stale DB amount_units
 */

header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';

function ip_json(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ip_numstr(mixed $v, int $scale = 4): string
{
    if ($v === null || $v === '') {
        return number_format(0, $scale, '.', '');
    }
    return number_format((float)$v, $scale, '.', '');
}

function ip_parse_input(): array
{
    $raw = file_get_contents('php://input');
    $json = [];
    if (is_string($raw) && trim($raw) !== '') {
        $tmp = json_decode($raw, true);
        if (is_array($tmp)) {
            $json = $tmp;
        }
    }

    return [
        'cert_uid' => trim((string)($_GET['cert_uid'] ?? $_POST['cert_uid'] ?? $json['cert_uid'] ?? $_GET['uid'] ?? $_POST['uid'] ?? $json['uid'] ?? $_GET['cert'] ?? $_POST['cert'] ?? $json['cert'] ?? '')),
        'uid'      => trim((string)($_GET['uid'] ?? $_POST['uid'] ?? $json['uid'] ?? '')),
        'cert'     => trim((string)($_GET['cert'] ?? $_POST['cert'] ?? $json['cert'] ?? '')),
    ];
}

function ip_resolve_pdo(): PDO
{
    $pdo = null;

    if (function_exists('db_connect')) {
        $maybe = db_connect();
        if ($maybe instanceof PDO) {
            $pdo = $maybe;
        }
    }

    if (!$pdo && function_exists('db')) {
        $maybe = db();
        if ($maybe instanceof PDO) {
            $pdo = $maybe;
        }
    }

    if (!$pdo && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        $pdo = $GLOBALS['pdo'];
    }

    if (!$pdo instanceof PDO) {
        throw new RuntimeException('DB_CONNECT_FAILED');
    }

    return $pdo;
}

function ip_get_env(string $key, string $default = ''): string
{
    $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($v === false || $v === null) {
        return $default;
    }
    return trim((string)$v);
}

function ip_token_masters(): array
{
    return [
        'WEMS' => ip_get_env('WEMS_JETTON_MASTER', ip_get_env('WEMS_MASTER', '')),
        'EMA$' => ip_get_env('EMA_JETTON_MASTER', ip_get_env('EMA_MASTER', '')),
        'EMA'  => ip_get_env('EMA_JETTON_MASTER', ip_get_env('EMA_MASTER', '')),
        'EMX'  => ip_get_env('EMX_JETTON_MASTER', ''),
        'EMS'  => ip_get_env('EMS_JETTON_MASTER', ''),
    ];
}

function ip_decimals_for_token(string $token): int
{
    $key = strtoupper(trim($token));
    if ($key === 'USDT' || $key === 'USDT_TON') {
        return 6;
    }
    return 9;
}

function ip_to_units(string $amount, int $decimals): string
{
    $amount = trim($amount);

    if ($amount === '' || !preg_match('/^\d+(?:\.\d+)?$/', $amount)) {
        return '0';
    }

    $decimals = max(0, $decimals);

    $parts = explode('.', $amount, 2);
    $int = $parts[0] ?? '0';
    $frac = $parts[1] ?? '';

    $frac = str_pad($frac, $decimals, '0');
    $frac = substr($frac, 0, $decimals);

    $units = ltrim($int . $frac, '0');
    return $units === '' ? '0' : $units;
}

function ip_qr_image_from_text(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    $qrHelper = $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/qr.php';
    if (is_file($qrHelper)) {
        require_once $qrHelper;

        if (function_exists('poado_qr_png_data_uri')) {
            try {
                return (string)poado_qr_png_data_uri($text, 320, 10);
            } catch (Throwable) {
                return '';
            }
        }

        if (function_exists('poado_qr_svg_data_uri')) {
            try {
                return (string)poado_qr_svg_data_uri($text, 320, 10);
            } catch (Throwable) {
                return '';
            }
        }
    }

    return '';
}

function ip_json_decode_array(mixed $json): array
{
    if (!is_string($json) || trim($json) === '') {
        return [];
    }
    $tmp = json_decode($json, true);
    return is_array($tmp) ? $tmp : [];
}

function ip_default_token_for_rwa_type(string $rwaType): string
{
    $typeKey = strtolower(trim($rwaType));
    return match ($typeKey) {
        'green', 'blue', 'black', 'gold' => 'WEMS',
        'pink', 'red', 'royal_blue', 'yellow' => 'EMA$',
        default => 'WEMS',
    };
}

function ip_default_amount_for_rwa_type(string $rwaType): string
{
    $typeKey = strtolower(trim($rwaType));
    return match ($typeKey) {
        'green' => '1000',
        'blue' => '5000',
        'black' => '10000',
        'gold' => '50000',
        'pink', 'red', 'royal_blue', 'yellow' => '100',
        default => '0',
    };
}

function ip_build_wallet_link(string $recipient, string $jettonMaster, string $amountUnits, string $paymentRef): string
{
    $recipient = trim($recipient);
    $jettonMaster = trim($jettonMaster);
    $amountUnits = trim($amountUnits);
    $paymentRef = trim($paymentRef);

    if ($recipient === '' || $jettonMaster === '' || $amountUnits === '') {
        return '';
    }

    return 'ton://transfer/' . rawurlencode($recipient) . '?' . http_build_query([
        'jetton' => $jettonMaster,
        'amount' => $amountUnits,
        'text'   => $paymentRef,
    ]);
}

try {
    $input = ip_parse_input();
    $certUid = trim((string)($input['cert_uid'] ?: $input['uid'] ?: $input['cert']));

    if ($certUid === '') {
        ip_json([
            'ok' => false,
            'error' => 'CERT_UID_REQUIRED',
            'detail' => 'issue-pay.php requires cert_uid or uid or cert',
            'version' => 'v1.3.0-20260408-unified-payment-model',
            'ts' => time(),
        ], 422);
    }

    $pdo = ip_resolve_pdo();

    $st = $pdo->prepare("
        SELECT
            id,
            cert_uid,
            rwa_type,
            family,
            rwa_code,
            price_wems,
            price_units,
            payment_ref,
            payment_token,
            payment_amount,
            owner_user_id,
            ton_wallet,
            meta_json,
            nft_minted,
            status,
            paid_at,
            minted_at,
            updated_at
        FROM poado_rwa_certs
        WHERE cert_uid = ?
        LIMIT 1
    ");
    $st->execute([$certUid]);
    $cert = $st->fetch(PDO::FETCH_ASSOC);

    if (!$cert) {
        ip_json([
            'ok' => false,
            'error' => 'CERT_NOT_FOUND',
            'cert_uid' => $certUid,
            'version' => 'v1.3.0-20260408-unified-payment-model',
            'ts' => time(),
        ], 404);
    }

    $meta = ip_json_decode_array($cert['meta_json'] ?? '');
    $metaPayment = is_array($meta['payment'] ?? null) ? $meta['payment'] : [];

    $paySt = $pdo->prepare("
        SELECT
            id,
            cert_uid,
            payment_ref,
            owner_user_id,
            ton_wallet,
            token_symbol,
            token_master,
            decimals,
            amount,
            amount_units,
            status,
            tx_hash,
            verified,
            paid_at,
            updated_at,
            meta_json
        FROM poado_rwa_cert_payments
        WHERE cert_uid = ?
        ORDER BY updated_at DESC, id DESC
        LIMIT 1
    ");
    $paySt->execute([$certUid]);
    $pay = $paySt->fetch(PDO::FETCH_ASSOC) ?: [];

    $rwaType = (string)($cert['rwa_type'] ?? '');

    $paymentToken = trim((string)($pay['token_symbol'] ?? $metaPayment['token_symbol'] ?? $metaPayment['token'] ?? $cert['payment_token'] ?? ''));
    if ($paymentToken === '') {
        $paymentToken = ip_default_token_for_rwa_type($rwaType);
    }

    $paymentAmount = trim((string)($pay['amount'] ?? $metaPayment['amount'] ?? $cert['payment_amount'] ?? ''));
    if ($paymentAmount === '' || $paymentAmount === '0' || $paymentAmount === '0.00000000') {
        $paymentAmount = ip_default_amount_for_rwa_type($rwaType);
    }

    $paymentRef = trim((string)($pay['payment_ref'] ?? $metaPayment['payment_ref'] ?? $cert['payment_ref'] ?? ''));
    if ($paymentRef === '') {
        $paymentRef = $certUid;
    }

    $paymentStatus = trim((string)($pay['status'] ?? $metaPayment['status'] ?? 'pending')) ?: 'pending';
    $paymentVerified = (int)($pay['verified'] ?? $metaPayment['verified'] ?? 0);

    $certTonWallet = trim((string)($cert['ton_wallet'] ?? ''));
    $payTonWallet = trim((string)($pay['ton_wallet'] ?? ''));
    $resolvedTonWallet = $payTonWallet !== '' ? $payTonWallet : $certTonWallet;

    $recipient = trim((string)($metaPayment['recipient'] ?? $metaPayment['destination'] ?? ''));
    if ($recipient === '') {
        $recipient = ip_get_env('TON_TREASURY_ADDRESS', ip_get_env('TON_TREASURY', ''));
    }

    $tokenMasters = ip_token_masters();
    $jettonMaster = trim((string)($pay['token_master'] ?? $metaPayment['token_master'] ?? ($tokenMasters[$paymentToken] ?? '')));
    if ($jettonMaster === '' && strtoupper($paymentToken) === 'EMA$') {
        $jettonMaster = trim((string)($tokenMasters['EMA'] ?? ''));
    }

    $decimals = (int)($pay['decimals'] ?? ip_decimals_for_token($paymentToken));
    if ($decimals <= 0) {
        $decimals = ip_decimals_for_token($paymentToken);
    }

    $scaledAmountUnits = ip_to_units((string)$paymentAmount, $decimals);
    if ($scaledAmountUnits === '0' || $scaledAmountUnits === '') {
        throw new RuntimeException('INVALID_AMOUNT_UNITS_COMPUTATION');
    }

    $walletLink = ip_build_wallet_link($recipient, $jettonMaster, $scaledAmountUnits, $paymentRef);
    $qrPayload = $walletLink;
    $qrText = $walletLink;
    $qrImage = ip_qr_image_from_text($qrText);

    $paymentReady = (strtolower($paymentStatus) === 'confirmed' || $paymentVerified === 1);

    $previewRow = [
        'cert_uid' => (string)$cert['cert_uid'],
        'rwa_type' => (string)$cert['rwa_type'],
        'rwa_code' => (string)$cert['rwa_code'],
        'family' => (string)$cert['family'],
        'status' => (string)$cert['status'],
        'owner_user_id' => (int)($cert['owner_user_id'] ?? 0),
        'ton_wallet' => $resolvedTonWallet,
        'payment_token' => $paymentToken,
        'payment_amount' => $paymentAmount,
        'payment_ref' => $paymentRef,
        'payment_status' => $paymentStatus,
        'payment_verified' => $paymentVerified,
        'payment_ready' => $paymentReady,
        'payment_deeplink' => $walletLink,
        'payment_wallet_link' => $walletLink,
        'payment_qr_payload' => $qrPayload,
        'payment_qr_text' => $qrText,
        'payment_qr_image' => $qrImage,
        'payment_recipient' => $recipient,
        'payment_amount_units' => $scaledAmountUnits,
        'queue_bucket' => $paymentReady ? 'mint_ready_queue' : 'rwa_factory',
        'sections' => [
            'rwa_factory' => !$paymentReady,
            'mint_ready_queue' => $paymentReady,
            'minted' => false
        ],
    ];

    ip_json([
        'ok' => true,
        'cert_uid' => (string)$cert['cert_uid'],
        'payment' => [
            'token_symbol' => $paymentToken,
            'jetton_master' => $jettonMaster,
            'decimals' => $decimals,
            'amount' => ip_numstr($paymentAmount, $decimals),
            'amount_units' => $scaledAmountUnits,
            'payment_ref' => $paymentRef,
            'recipient' => $recipient,
            'ton_wallet' => $resolvedTonWallet,
            'wallet_link' => $walletLink,
            'deeplink' => $walletLink,
            'qr_payload' => $qrPayload,
            'qr_text' => $qrText,
            'qr_image' => $qrImage,
            'status' => $paymentStatus,
            'verified' => $paymentVerified,
            'tx_hash' => (string)($pay['tx_hash'] ?? ''),
            'paid_at' => $pay['paid_at'] ?? null,
        ],
        'preview_row' => $previewRow,
        'version' => 'v1.3.0-20260408-unified-payment-model',
        'ts' => time(),
    ], 200);

} catch (Throwable $e) {
    ip_json([
        'ok' => false,
        'error' => 'ISSUE_PAY_FAILED',
        'detail' => $e->getMessage(),
        'version' => 'v1.3.0-20260408-unified-payment-model',
        'ts' => time(),
    ], 500);
}
