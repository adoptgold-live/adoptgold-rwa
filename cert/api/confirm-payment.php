<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/api/confirm-payment.php
 * Version: v3.0.0-20260410-payment-authority-lock
 *
 * PAYMENT AUTHORITY LOCK
 * - confirm-payment.php is the only payment verification authority
 * - accept only when:
 *     1) token_master exact match
 *     2) amount_units exact match
 *     3) payment_ref exact match in decoded memo/comment/payload text
 * - persist tx_hash into poado_rwa_cert_payments.tx_hash
 * - set poado_rwa_cert_payments.status='confirmed'
 * - set poado_rwa_cert_payments.verified=1
 * - set poado_rwa_cert_payments.paid_at=NOW()
 * - set poado_rwa_certs.paid_at=NOW()
 * - DO NOT use poado_rwa_certs.status for payment lifecycle
 * - DO NOT auto-confirm no-memo payments
 */

header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/session-user.php';

function cp_json(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function cp_fail(string $error, int $status = 400, array $extra = []): never
{
    cp_json(array_merge([
        'ok' => false,
        'error' => $error,
        'ts' => time(),
    ], $extra), $status);
}

function cp_ok(array $extra = []): never
{
    cp_json(array_merge([
        'ok' => true,
        'ts' => time(),
    ], $extra));
}

function cp_str(mixed $v): string
{
    return trim((string)($v ?? ''));
}

function cp_env(string $key, string $default = ''): string
{
    $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    $v = is_string($v) ? trim($v) : '';
    return $v !== '' ? $v : $default;
}

function cp_parse_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function cp_meta_decode(?string $json): array
{
    if (!$json) return [];
    try {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : [];
    } catch (Throwable) {
        return [];
    }
}

function cp_meta_get(array $arr, array $path, mixed $default = null): mixed
{
    $cur = $arr;
    foreach ($path as $seg) {
        if (!is_array($cur) || !array_key_exists($seg, $cur)) {
            return $default;
        }
        $cur = $cur[$seg];
    }
    return $cur;
}

function cp_normalize_master(string $s): string
{
    $s = trim($s);
    return $s === '' ? '' : strtolower($s);
}

function cp_extract_text_candidates(array $row): array
{
    $candidates = [];

    foreach ([
        'comment',
        'decoded_forward_payload',
        'decoded_payload',
        'payload_comment',
        'text',
        'message',
        'body',
        'forward_payload_comment',
    ] as $key) {
        $val = cp_str($row[$key] ?? '');
        if ($val !== '') $candidates[] = $val;
    }

    foreach ([
        'forward_payload',
        'payload',
        'body_b64',
        'message_b64',
    ] as $key) {
        $raw = cp_str($row[$key] ?? '');
        if ($raw === '') continue;

        $candidates[] = $raw;

        $decoded = base64_decode(strtr($raw, '-_', '+/'), true);
        if ($decoded !== false) {
            $decoded = trim($decoded);
            if ($decoded !== '') $candidates[] = $decoded;
        }
    }

    $out = [];
    foreach ($candidates as $c) {
        $c = trim($c);
        if ($c !== '') $out[] = $c;
    }

    return array_values(array_unique($out));
}

function cp_find_ref_hit(array $row, string $paymentRef): array
{
    $paymentRef = cp_str($paymentRef);
    if ($paymentRef === '') {
        return ['matched' => false, 'matched_text' => '', 'candidates' => []];
    }

    $candidates = cp_extract_text_candidates($row);
    foreach ($candidates as $text) {
        if (stripos($text, $paymentRef) !== false) {
            return ['matched' => true, 'matched_text' => $text, 'candidates' => $candidates];
        }
    }

    return ['matched' => false, 'matched_text' => '', 'candidates' => $candidates];
}

function cp_http_get_json(string $url, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        throw new RuntimeException('CURL_' . $errno . ':' . $error);
    }

    $json = json_decode((string)$body, true);
    if (!is_array($json)) {
        throw new RuntimeException('INVALID_JSON_RESPONSE');
    }

    if ($code >= 400) {
        $msg = cp_str($json['error'] ?? $json['message'] ?? ('HTTP_' . $code));
        throw new RuntimeException($msg !== '' ? $msg : ('HTTP_' . $code));
    }

    return $json;
}

function cp_toncenter_headers(): array
{
    $headers = ['Accept: application/json'];
    $key = cp_env('TONCENTER_API_KEY', '');
    if ($key !== '') {
        $headers[] = 'X-API-Key: ' . $key;
    }
    return $headers;
}

function cp_url_encode(string $s): string
{
    return rawurlencode($s);
}

function cp_toncenter_fetch_candidates(string $wallet, string $recipient, int $limit = 100): array
{
    $base = rtrim(cp_env('TONCENTER_V3_BASE', 'https://toncenter.com/api/v3'), '/');
    if ($base === '') {
        throw new RuntimeException('TONCENTER_V3_BASE_MISSING');
    }

    $headers = cp_toncenter_headers();
    $queries = [];

    if ($recipient !== '') {
        $queries[] = $base . '/jetton/transfers?limit=' . $limit . '&destination=' . cp_url_encode($recipient);
    }

    if ($wallet !== '') {
        $queries[] = $base . '/jetton/transfers?limit=' . $limit . '&destination=' . cp_url_encode($wallet);
    }

    $all = [];
    foreach ($queries as $url) {
        try {
            $json = cp_http_get_json($url, $headers);
            $rows = $json['jetton_transfers'] ?? $json['transfers'] ?? [];
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    if (is_array($row)) $all[] = $row;
                }
            }
        } catch (Throwable) {
            // Continue to next strategy.
        }
    }

    $dedup = [];
    foreach ($all as $row) {
        $tx = cp_str($row['transaction_hash'] ?? $row['tx_hash'] ?? '');
        $key = $tx !== '' ? $tx : md5(json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $dedup[$key] = $row;
    }

    return array_values($dedup);
}

function cp_compute_read_model(array $certRow, array $paymentRow): array
{
    $meta = cp_meta_decode($certRow['meta_json'] ?? null);

    $artifactReady = cp_str($certRow['metadata_path'] ?? '') !== '' && cp_str($certRow['nft_image_path'] ?? '') !== '';
    $nftHealthy = $artifactReady;
    $nftMinted = (int)($certRow['nft_minted'] ?? 0) === 1;
    $paymentStatus = strtolower(cp_str($paymentRow['status'] ?? ''));
    $paymentVerified = (int)($paymentRow['verified'] ?? 0) === 1;

    if ($nftMinted || strtolower(cp_str($certRow['status'] ?? '')) === 'minted') {
        $bucket = 'issued';
    } elseif ($paymentStatus !== 'confirmed' || !$paymentVerified) {
        $bucket = 'payment_confirmation';
    } elseif (!$artifactReady) {
        $bucket = 'payment_confirmed_pending_artifact';
    } else {
        $bucket = 'mint_ready_queue';
    }

    return [
        'cert_uid' => cp_str($certRow['cert_uid'] ?? ''),
        'rwa_type' => cp_str($certRow['rwa_type'] ?? ''),
        'family' => cp_str($certRow['family'] ?? ''),
        'rwa_code' => cp_str($certRow['rwa_code'] ?? ''),
        'payment_ref' => cp_str($paymentRow['payment_ref'] ?? $certRow['payment_ref'] ?? ''),
        'payment_token' => cp_str($paymentRow['token_symbol'] ?? $certRow['payment_token'] ?? ''),
        'payment_amount' => cp_str($paymentRow['amount'] ?? $certRow['payment_amount'] ?? ''),
        'payment_status' => $paymentStatus,
        'payment_verified' => $paymentVerified ? 1 : 0,
        'payment_ready' => $paymentStatus === 'confirmed' && $paymentVerified,
        'queue_bucket' => $bucket,
        'artifact_ready' => $artifactReady,
        'nft_healthy' => $nftHealthy,
        'nft_minted' => $nftMinted ? 1 : 0,
        'nft_item_address' => cp_str($certRow['nft_item_address'] ?? ''),
        'metadata_path' => cp_str($certRow['metadata_path'] ?? ''),
        'nft_image_path' => cp_str($certRow['nft_image_path'] ?? ''),
        'verify_url' => cp_str($certRow['verify_url'] ?? ''),
        'mint' => [
            'recipient' => cp_str(cp_meta_get($meta, ['mint', 'recipient'], '')),
            'amount_ton' => cp_str(cp_meta_get($meta, ['mint', 'amount_ton'], '')),
            'amount_nano' => cp_str(cp_meta_get($meta, ['mint', 'amount_nano'], '')),
            'item_index' => cp_str(cp_meta_get($meta, ['mint', 'item_index'], '')),
            'payload_b64' => cp_str(cp_meta_get($meta, ['mint', 'payload_b64'], '')),
            'deeplink' => cp_str(cp_meta_get($meta, ['mint', 'deeplink'], cp_meta_get($meta, ['mint', 'wallet_link'], ''))),
        ],
    ];
}

try {
    db_connect();
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) {
        cp_fail('DB_NOT_READY', 500);
    }

    $walletSession = function_exists('get_wallet_session') ? get_wallet_session() : null;
    $sessionWallet = cp_str($walletSession['wallet'] ?? '');
    $sessionUserId = (int)($walletSession['user_id'] ?? 0);

    $body = cp_parse_json_body();
    $certUid = cp_str($body['cert_uid'] ?? $_POST['cert_uid'] ?? $_GET['cert_uid'] ?? '');
    $wallet = cp_str($body['wallet'] ?? $_POST['wallet'] ?? $_GET['wallet'] ?? $sessionWallet);
    $ownerUserId = (int)($body['owner_user_id'] ?? $_POST['owner_user_id'] ?? $_GET['owner_user_id'] ?? $sessionUserId);

    if ($certUid === '') {
        cp_fail('CERT_UID_REQUIRED', 422);
    }

    $sql = "
        SELECT
            c.id AS cert_id,
            c.cert_uid,
            c.rwa_type,
            c.family,
            c.rwa_code,
            c.payment_ref AS cert_payment_ref,
            c.payment_token,
            c.payment_amount,
            c.owner_user_id,
            c.ton_wallet,
            c.nft_image_path,
            c.metadata_path,
            c.verify_url,
            c.meta_json,
            c.nft_item_address,
            c.nft_minted,
            c.status,
            c.paid_at AS cert_paid_at,

            p.id AS payment_id,
            p.payment_ref,
            p.ton_wallet AS payment_wallet,
            p.owner_user_id AS payment_owner_user_id,
            p.token_symbol,
            p.token_master,
            p.decimals,
            p.amount,
            p.amount_units,
            p.status AS payment_status,
            p.tx_hash,
            p.verified,
            p.paid_at AS payment_paid_at,
            p.meta_json AS payment_meta_json
        FROM poado_rwa_certs c
        LEFT JOIN poado_rwa_cert_payments p
          ON p.id = (
              SELECT p2.id
              FROM poado_rwa_cert_payments p2
              WHERE p2.cert_uid = c.cert_uid
              ORDER BY p2.id DESC
              LIMIT 1
          )
        WHERE c.cert_uid = :cert_uid
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':cert_uid' => $certUid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        cp_fail('CERT_NOT_FOUND', 404);
    }

    $certOwnerUserId = (int)($row['owner_user_id'] ?? 0);
    $paymentOwnerUserId = (int)($row['payment_owner_user_id'] ?? 0);
    if ($ownerUserId > 0 && $certOwnerUserId > 0 && $ownerUserId !== $certOwnerUserId && $ownerUserId !== $paymentOwnerUserId) {
        cp_fail('CERT_OWNER_MISMATCH', 403);
    }

    $paymentRef = cp_str($row['payment_ref'] ?? $row['cert_payment_ref'] ?? '');
    $tokenMaster = cp_normalize_master(cp_str($row['token_master'] ?? ''));
    $amountUnits = cp_str($row['amount_units'] ?? '');
    $tokenSymbol = cp_str($row['token_symbol'] ?? $row['payment_token'] ?? '');
    $existingTxHash = cp_str($row['tx_hash'] ?? '');
    $paymentStatus = strtolower(cp_str($row['payment_status'] ?? ''));
    $paymentVerified = (int)($row['verified'] ?? 0) === 1;

    if ($paymentRef === '') {
        cp_fail('MEMO_REQUIRED_FOR_CONFIRMATION', 422, [
            'detail' => 'MISSING_PAYMENT_REF',
            'cert_uid' => $certUid,
        ]);
    }

    if ($tokenMaster === '') {
        cp_fail('TOKEN_MASTER_REQUIRED', 422, [
            'cert_uid' => $certUid,
        ]);
    }

    if ($amountUnits === '') {
        cp_fail('AMOUNT_UNITS_REQUIRED', 422, [
            'cert_uid' => $certUid,
        ]);
    }

    if ($paymentStatus === 'confirmed' && $paymentVerified && $existingTxHash !== '') {
        $readModel = cp_compute_read_model($row, [
            'payment_ref' => $paymentRef,
            'token_symbol' => $tokenSymbol,
            'amount' => cp_str($row['amount'] ?? ''),
            'status' => 'confirmed',
            'verified' => 1,
        ]);

        cp_ok([
            'verified' => true,
            'already_confirmed' => true,
            'cert_uid' => $certUid,
            'payment' => [
                'payment_ref' => $paymentRef,
                'token_symbol' => $tokenSymbol,
                'amount' => cp_str($row['amount'] ?? ''),
                'amount_units' => $amountUnits,
                'status' => 'confirmed',
                'verified' => 1,
                'tx_hash' => $existingTxHash,
            ],
            'read_model' => $readModel,
        ]);
    }

    $certMeta = cp_meta_decode($row['meta_json'] ?? null);
    $paymentMeta = cp_meta_decode($row['payment_meta_json'] ?? null);

    $recipientCandidates = array_values(array_unique(array_filter([
        cp_str(cp_meta_get($paymentMeta, ['recipient'], '')),
        cp_str(cp_meta_get($paymentMeta, ['destination'], '')),
        cp_str(cp_meta_get($paymentMeta, ['to'], '')),
        cp_str(cp_meta_get($certMeta, ['payment', 'recipient'], '')),
        cp_str(cp_meta_get($certMeta, ['payment', 'destination'], '')),
        cp_str(cp_meta_get($certMeta, ['payment', 'to'], '')),
    ], fn ($v) => $v !== '')));

    $recipient = $recipientCandidates[0] ?? '';
    $searchWallet = $wallet !== '' ? $wallet : cp_str($row['payment_wallet'] ?? $row['ton_wallet'] ?? '');

    $candidates = cp_toncenter_fetch_candidates($searchWallet, $recipient, 120);

    $matched = null;
    $auditCandidates = [];

    foreach ($candidates as $cand) {
        $candMaster = cp_normalize_master(cp_str($cand['jetton_master'] ?? $cand['token_master'] ?? ''));
        $candAmount = cp_str($cand['amount'] ?? '');
        $txHash = cp_str($cand['transaction_hash'] ?? $cand['tx_hash'] ?? '');

        $refHit = cp_find_ref_hit($cand, $paymentRef);

        $auditCandidates[] = [
            'tx_hash' => $txHash,
            'jetton_master' => $candMaster,
            'amount' => $candAmount,
            'ref_matched' => $refHit['matched'],
        ];

        if ($candMaster === '') continue;
        if ($candAmount === '') continue;
        if ($txHash === '') continue;

        if ($candMaster !== $tokenMaster) continue;
        if ($candAmount !== $amountUnits) continue;
        if (!$refHit['matched']) continue;

        $matched = [
            'tx_hash' => $txHash,
            'row' => $cand,
            'matched_text' => $refHit['matched_text'],
            'candidates' => $refHit['candidates'],
        ];
        break;
    }

    if (!$matched) {
        $readModel = cp_compute_read_model($row, [
            'payment_ref' => $paymentRef,
            'token_symbol' => $tokenSymbol,
            'amount' => cp_str($row['amount'] ?? ''),
            'status' => cp_str($row['payment_status'] ?? 'pending'),
            'verified' => (int)($row['verified'] ?? 0),
        ]);

        cp_fail('PAYMENT_NOT_CONFIRMED', 200, [
            'cert_uid' => $certUid,
            'verified' => false,
            'payment' => [
                'payment_ref' => $paymentRef,
                'token_symbol' => $tokenSymbol,
                'amount' => cp_str($row['amount'] ?? ''),
                'amount_units' => $amountUnits,
                'status' => cp_str($row['payment_status'] ?? 'pending'),
                'verified' => (int)($row['verified'] ?? 0),
                'tx_hash' => $existingTxHash,
            ],
            'read_model' => $readModel,
            'debug' => [
                'wallet' => $searchWallet,
                'recipient' => $recipient,
                'candidate_count' => count($candidates),
                'audit_candidates' => $auditCandidates,
                'memo_required' => true,
            ],
        ]);
    }

    $now = date('Y-m-d H:i:s');

    $currentPaymentMeta = cp_meta_decode($row['payment_meta_json'] ?? null);
    $currentPaymentMeta['verification'] = [
        'source' => 'confirm-payment.php',
        'mode' => 'strict_memo_match',
        'verified_at' => $now,
        'tx_hash' => $matched['tx_hash'],
        'matched_text' => $matched['matched_text'],
        'wallet' => $searchWallet,
        'recipient' => $recipient,
        'token_master' => $tokenMaster,
        'amount_units' => $amountUnits,
    ];

    $pdo->beginTransaction();

    $up1 = $pdo->prepare("
        UPDATE poado_rwa_cert_payments
        SET
            status = 'confirmed',
            verified = 1,
            tx_hash = :tx_hash,
            paid_at = NOW(),
            meta_json = :meta_json,
            updated_at = NOW()
        WHERE id = :id
        LIMIT 1
    ");
    $up1->execute([
        ':tx_hash' => $matched['tx_hash'],
        ':meta_json' => json_encode($currentPaymentMeta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':id' => (int)$row['payment_id'],
    ]);

    $up2 = $pdo->prepare("
        UPDATE poado_rwa_certs
        SET
            paid_at = NOW(),
            updated_at = NOW()
        WHERE id = :id
        LIMIT 1
    ");
    $up2->execute([
        ':id' => (int)$row['cert_id'],
    ]);

    $pdo->commit();

    $row['payment_status'] = 'confirmed';
    $row['verified'] = 1;
    $row['tx_hash'] = $matched['tx_hash'];
    $row['cert_paid_at'] = $now;

    $readModel = cp_compute_read_model($row, [
        'payment_ref' => $paymentRef,
        'token_symbol' => $tokenSymbol,
        'amount' => cp_str($row['amount'] ?? ''),
        'status' => 'confirmed',
        'verified' => 1,
    ]);

    cp_ok([
        'verified' => true,
        'cert_uid' => $certUid,
        'payment' => [
            'payment_ref' => $paymentRef,
            'token_symbol' => $tokenSymbol,
            'amount' => cp_str($row['amount'] ?? ''),
            'amount_units' => $amountUnits,
            'status' => 'confirmed',
            'verified' => 1,
            'tx_hash' => $matched['tx_hash'],
            'matched_text' => $matched['matched_text'],
        ],
        'read_model' => $readModel,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    cp_fail('CONFIRM_PAYMENT_FAILED', 500, [
        'detail' => $e->getMessage(),
    ]);
}
