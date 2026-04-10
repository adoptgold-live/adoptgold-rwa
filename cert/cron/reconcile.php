<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/cron/reconcile.php
 * Version: v4.0.0-20260328-realdb-auto-mint
 *
 * REAL DB LOCKED FLOW
 * - poado_rwa_certs.status enum = issued / minted / revoked
 * - poado_rwa_cert_payments.status = pending / confirmed / expired (varchar)
 * - payment truth lives in poado_rwa_cert_payments
 * - cert truth lives in poado_rwa_certs
 * - ledger is anti-replay only
 *
 * Auto mint integration:
 * - after payment confirmation, cron marks cert "auto_mint_ready" in meta_json
 * - cron attempts to run auto mint only when:
 *     1) payment is confirmed
 *     2) cert is still issued
 *     3) nft_item_address is empty
 * - safe hook order:
 *     A. callable function rwa_cert_auto_mint_cron($certRow, $paymentRow)
 *     B. callable function rwa_cert_auto_mint($certRow, $paymentRow)
 *     C. include optional hook file:
 *        /var/www/html/public/rwa/cert/cron/_auto-mint-hook.php
 *        and call rwa_cert_auto_mint_hook($certRow, $paymentRow)
 *
 * Required hook return shape for success:
 * [
 *   'ok' => true,
 *   'nft_item_address' => 'EQ...',
 *   'collection_address' => 'EQ...',   // optional
 *   'tx_hash' => '0x...',              // optional
 * ]
 *
 * If no hook exists, cron remains payment-confirming only and logs AUTO_MINT_SKIPPED.
 */

$ROOT = realpath(__DIR__ . '/../../..');
if ($ROOT === false) {
    throw new RuntimeException('APP_ROOT_RESOLVE_FAILED');
}

require_once $ROOT . '/rwa/inc/core/bootstrap.php';
require_once $ROOT . '/rwa/inc/core/db.php';
require_once $ROOT . '/rwa/inc/core/onchain-verify.php';

date_default_timezone_set('UTC');

function logx(string $msg, array $ctx = []): void
{
    echo '[' . date('c') . '] ' . $msg . ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

function pdo_db(): PDO
{
    if (function_exists('rwa_db')) {
        $pdo = rwa_db();
        if ($pdo instanceof PDO) {
            return $pdo;
        }
    }
    if (($GLOBALS['pdo'] ?? null) instanceof PDO) {
        return $GLOBALS['pdo'];
    }
    throw new RuntimeException('NO_DB');
}

function norm_amount($v): string
{
    if ($v === null || $v === '') {
        return '';
    }
    $n = (float)$v;
    if (floor($n) === $n) {
        return number_format($n, 0, '.', '');
    }
    return rtrim(rtrim(number_format($n, 8, '.', ''), '0'), '.');
}

function envv(string $key): string
{
    $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?? '';
    return is_string($v) ? trim($v) : '';
}

function token_key(string $symbol): string
{
    return match (strtoupper(trim($symbol))) {
        'WEMS' => 'WEMS',
        'EMA', 'EMA$' => 'EMA',
        'EMX' => 'EMX',
        'EMS' => 'EMS',
        'USDT', 'USDT_TON' => 'USDT_TON',
        default => throw new RuntimeException('UNSUPPORTED_TOKEN_SYMBOL_' . $symbol),
    };
}

function treasury_for_token(string $symbol): string
{
    return match (strtoupper(trim($symbol))) {
        'WEMS' => envv('WEMS_TREASURY') !== '' ? envv('WEMS_TREASURY') : envv('TON_TREASURY'),
        'EMA', 'EMA$' => envv('EMA_TREASURY') !== '' ? envv('EMA_TREASURY') : envv('TON_TREASURY'),
        'EMX' => envv('EMX_TREASURY') !== '' ? envv('EMX_TREASURY') : envv('TON_TREASURY'),
        'EMS' => envv('EMS_TREASURY') !== '' ? envv('EMS_TREASURY') : envv('TON_TREASURY'),
        'USDT', 'USDT_TON' => envv('USDT_TREASURY') !== '' ? envv('USDT_TREASURY') : envv('TON_TREASURY'),
        default => '',
    };
}

function decode_meta(?string $json): array
{
    if (!$json) {
        return [];
    }
    $arr = json_decode($json, true);
    return is_array($arr) ? $arr : [];
}

function save_cert_meta(PDO $pdo, string $certUid, array $meta): void
{
    $st = $pdo->prepare("
        UPDATE poado_rwa_certs
        SET meta_json = :meta,
            updated_at = NOW()
        WHERE cert_uid = :uid
        LIMIT 1
    ");
    $st->execute([
        ':uid' => $certUid,
        ':meta' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
    ]);
}

function append_cert_history(PDO $pdo, array $cert, array $event, array $patch = []): void
{
    $meta = decode_meta($cert['meta_json'] ?? null);

    if (!isset($meta['lifecycle']) || !is_array($meta['lifecycle'])) {
        $meta['lifecycle'] = [];
    }
    if (!isset($meta['lifecycle']['history']) || !is_array($meta['lifecycle']['history'])) {
        $meta['lifecycle']['history'] = [];
    }

    $meta['lifecycle']['history'][] = $event;
    if (isset($event['status'])) {
        $meta['lifecycle']['current'] = $event['status'];
    }

    foreach ($patch as $k => $v) {
        if (is_array($v) && isset($meta[$k]) && is_array($meta[$k])) {
            $meta[$k] = array_replace_recursive($meta[$k], $v);
        } else {
            $meta[$k] = $v;
        }
    }

    save_cert_meta($pdo, (string)$cert['cert_uid'], $meta);
}

function update_payment_meta(PDO $pdo, int $paymentId, array $meta): void
{
    $st = $pdo->prepare("
        UPDATE poado_rwa_cert_payments
        SET meta_json = :meta,
            updated_at = NOW()
        WHERE id = :id
        LIMIT 1
    ");
    $st->execute([
        ':id' => $paymentId,
        ':meta' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
    ]);
}

function cert_verify_url(string $uid): string
{
    return 'https://adoptgold.app/rwa/cert/verify.php?uid=' . rawurlencode($uid);
}

function try_auto_mint(array $certRow, array $paymentRow): array
{
    if (function_exists('rwa_cert_auto_mint_cron')) {
        $res = rwa_cert_auto_mint_cron($certRow, $paymentRow);
        return is_array($res) ? $res : ['ok' => false, 'error' => 'AUTO_MINT_BAD_RETURN'];
    }

    if (function_exists('rwa_cert_auto_mint')) {
        $res = rwa_cert_auto_mint($certRow, $paymentRow);
        return is_array($res) ? $res : ['ok' => false, 'error' => 'AUTO_MINT_BAD_RETURN'];
    }

    $hookFile = __DIR__ . '/_auto-mint-hook.php';
    if (is_file($hookFile)) {
        require_once $hookFile;
        if (function_exists('rwa_cert_auto_mint_hook')) {
            $res = rwa_cert_auto_mint_hook($certRow, $paymentRow);
            return is_array($res) ? $res : ['ok' => false, 'error' => 'AUTO_MINT_BAD_RETURN'];
        }
    }

    return [
        'ok' => false,
        'error' => 'AUTO_MINT_HOOK_NOT_FOUND',
    ];
}

$pdo = pdo_db();
logx('CRON START');

// ---------------------------------------------------------------------
// 0. PAYMENT LEDGER FOR DUPLICATE/REPLAY PROTECTION
// REAL LIVE SCHEMA:
//   id, tx_hash, cert_uid, token, amount, created_at
// ---------------------------------------------------------------------
$pdo->exec("
CREATE TABLE IF NOT EXISTS poado_rwa_payment_ledger (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tx_hash VARCHAR(128) NOT NULL,
    cert_uid VARCHAR(128) NOT NULL,
    token VARCHAR(16) NOT NULL,
    amount VARCHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY tx_hash (tx_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ---------------------------------------------------------------------
// 1. PAYMENT RECONCILE
//    Source of truth = poado_rwa_cert_payments
// ---------------------------------------------------------------------
$pendingPayments = $pdo->query("
    SELECT
        p.*,
        c.id AS cert_id,
        c.rwa_type,
        c.family,
        c.rwa_code,
        c.payment_token AS cert_payment_token,
        c.payment_amount AS cert_payment_amount,
        c.payment_ref AS cert_payment_ref,
        c.owner_user_id AS cert_owner_user_id,
        c.ton_wallet AS cert_ton_wallet,
        c.status AS cert_status,
        c.verify_url AS cert_verify_url,
        c.meta_json AS cert_meta_json,
        c.nft_item_address,
        c.router_tx_hash
    FROM poado_rwa_cert_payments p
    INNER JOIN poado_rwa_certs c
        ON c.cert_uid = p.cert_uid
    WHERE p.status = 'pending'
      AND c.status = 'issued'
    ORDER BY p.id ASC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($pendingPayments as $row) {
    try {
        $certUid = (string)$row['cert_uid'];
        $paymentRef = trim((string)$row['payment_ref']);
        $tokenSymbol = trim((string)$row['token_symbol']);
        $amountHuman = norm_amount($row['amount']);
        $ownerWallet = trim((string)($row['ton_wallet'] ?: $row['cert_ton_wallet']));
        $token = $tokenSymbol !== '' ? $tokenSymbol : (string)($row['cert_payment_token'] ?? '');
        $treasury = treasury_for_token($token);

        if ($paymentRef === '' || $amountHuman === '' || $ownerWallet === '' || $token === '') {
            logx('PAYMENT_SKIP_MISSING_FIELDS', [
                'cert_uid' => $certUid,
                'payment_ref' => $paymentRef,
                'amount' => $amountHuman,
                'owner_wallet' => $ownerWallet,
                'token' => $token,
            ]);
            continue;
        }

        if (!function_exists('rwa_onchain_verify_jetton_transfer')) {
            throw new RuntimeException('ONCHAIN_VERIFY_ENGINE_NOT_AVAILABLE');
        }

        $verifyArgs = [
            'owner_address' => $ownerWallet,
            'token_key' => token_key($token),
            'amount_units' => $amountHuman,
            'ref' => $paymentRef,
            'min_confirmations' => 0,
            'limit' => 100,
            'lookback_seconds' => 86400 * 7,
        ];
        if ($treasury !== '') {
            $verifyArgs['destination'] = $treasury;
        }

        $verify = rwa_onchain_verify_jetton_transfer($verifyArgs);

        if (empty($verify['verified'])) {
            logx('PAYMENT_PENDING_NO_MATCH', [
                'cert_uid' => $certUid,
                'status' => $verify['status'] ?? '',
                'code' => $verify['code'] ?? '',
            ]);
            continue;
        }

        $txHash = trim((string)($verify['tx_hash'] ?? ''));
        if ($txHash === '') {
            logx('PAYMENT_SKIP_NO_TX', ['cert_uid' => $certUid]);
            continue;
        }

        $chk = $pdo->prepare("
            SELECT id
            FROM poado_rwa_payment_ledger
            WHERE tx_hash = :tx
            LIMIT 1
        ");
        $chk->execute([':tx' => $txHash]);

        if ($chk->fetch(PDO::FETCH_ASSOC)) {
            logx('DUPLICATE_TX_SKIPPED', [
                'cert_uid' => $certUid,
                'tx_hash' => $txHash,
            ]);
            continue;
        }

        $ins = $pdo->prepare("
            INSERT INTO poado_rwa_payment_ledger
            (tx_hash, cert_uid, token, amount)
            VALUES (:tx, :uid, :token, :amount)
        ");
        $ins->execute([
            ':tx' => $txHash,
            ':uid' => $certUid,
            ':token' => strtoupper($token),
            ':amount' => $amountHuman,
        ]);

        $paymentMeta = decode_meta($row['meta_json'] ?? null);
        $paymentMeta = array_replace_recursive($paymentMeta, [
            'verified' => true,
            'status' => 'confirmed',
            'payment_ref' => $paymentRef,
            'token' => strtoupper($token),
            'amount' => $amountHuman,
            'tx_hash' => $txHash,
            'paid_at' => gmdate('Y-m-d H:i:s'),
            'confirmed_at' => gmdate('c'),
            'confirmations' => (int)($verify['confirmations'] ?? 0),
            'verify_source' => (string)($verify['verify_source'] ?? ''),
            'verify_mode' => (string)($verify['verify_mode'] ?? ''),
            'token_key' => (string)($verify['token_key'] ?? ''),
            'amount_units' => (string)($verify['amount_units'] ?? ''),
            'amount_human' => (string)($verify['amount_human'] ?? $amountHuman),
            'payload_text' => (string)($verify['payload_text'] ?? ''),
            'source_checked' => (bool)($verify['source_checked'] ?? false),
            'source_matched' => (bool)($verify['source_matched'] ?? false),
            'treasury_checked' => (bool)($verify['treasury_checked'] ?? false),
            'treasury_matched' => (bool)($verify['treasury_matched'] ?? false),
            'raw_verify' => $verify,
        ]);

        $updPayment = $pdo->prepare("
            UPDATE poado_rwa_cert_payments
            SET status = 'confirmed',
                verified = 1,
                tx_hash = :tx,
                paid_at = NOW(),
                updated_at = NOW(),
                meta_json = :meta
            WHERE id = :id
            LIMIT 1
        ");
        $updPayment->execute([
            ':id' => (int)$row['id'],
            ':tx' => $txHash,
            ':meta' => json_encode($paymentMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        ]);

        $updCert = $pdo->prepare("
            UPDATE poado_rwa_certs
            SET router_tx_hash = :tx,
                paid_at = NOW(),
                updated_at = NOW(),
                verify_url = COALESCE(NULLIF(verify_url, ''), :verify_url)
            WHERE cert_uid = :uid
            LIMIT 1
        ");
        $updCert->execute([
            ':uid' => $certUid,
            ':tx' => $txHash,
            ':verify_url' => cert_verify_url($certUid),
        ]);

        append_cert_history($pdo, [
            'cert_uid' => $certUid,
            'meta_json' => $row['cert_meta_json'] ?? null,
        ], [
            'event' => 'cron_payment_reconciled',
            'status' => 'issued',
            'at' => gmdate('c'),
        ], [
            'payment' => [
                'verified' => true,
                'status' => 'confirmed',
                'payment_ref' => $paymentRef,
                'token' => strtoupper($token),
                'amount' => $amountHuman,
                'tx_hash' => $txHash,
                'paid_at' => gmdate('Y-m-d H:i:s'),
                'confirmed_at' => gmdate('c'),
            ],
            'auto_mint' => [
                'ready' => true,
                'last_event' => 'payment_confirmed',
                'updated_at' => gmdate('c'),
            ],
        ]);

        logx('PAYMENT_CONFIRMED', [
            'cert_uid' => $certUid,
            'payment_ref' => $paymentRef,
            'tx_hash' => $txHash,
            'token' => strtoupper($token),
            'amount' => $amountHuman,
        ]);
    } catch (Throwable $e) {
        logx('PAYMENT_ERR', [
            'cert_uid' => $row['cert_uid'] ?? '',
            'error' => $e->getMessage(),
        ]);
    }
}

// ---------------------------------------------------------------------
// 2. AUTO MINT INTEGRATION
//    Only for confirmed payments on certs still in "issued"
// ---------------------------------------------------------------------
$mintCandidates = $pdo->query("
    SELECT
        c.*,
        p.id AS payment_id,
        p.payment_ref,
        p.token_symbol,
        p.amount,
        p.amount_units,
        p.tx_hash AS payment_tx_hash,
        p.meta_json AS payment_meta_json
    FROM poado_rwa_certs c
    INNER JOIN poado_rwa_cert_payments p
        ON p.cert_uid = c.cert_uid
    WHERE c.status = 'issued'
      AND (c.nft_item_address IS NULL OR c.nft_item_address = '')
      AND p.status = 'confirmed'
      AND p.verified = 1
    ORDER BY p.paid_at ASC, p.id ASC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($mintCandidates as $row) {
    try {
        $certUid = (string)$row['cert_uid'];

        $res = try_auto_mint($row, $row);

        if (empty($res['ok'])) {
            append_cert_history($pdo, $row, [
                'event' => 'cron_auto_mint_skipped',
                'status' => 'issued',
                'at' => gmdate('c'),
            ], [
                'auto_mint' => [
                    'ready' => true,
                    'last_event' => 'skipped',
                    'last_error' => (string)($res['error'] ?? 'AUTO_MINT_UNKNOWN'),
                    'updated_at' => gmdate('c'),
                ],
            ]);

            logx('AUTO_MINT_SKIPPED', [
                'cert_uid' => $certUid,
                'reason' => $res['error'] ?? 'AUTO_MINT_UNKNOWN',
            ]);
            continue;
        }

        $nftItemAddress = trim((string)($res['nft_item_address'] ?? ''));
        if ($nftItemAddress === '') {
            throw new RuntimeException('AUTO_MINT_NO_NFT_ITEM_ADDRESS');
        }

        $txHash = trim((string)($res['tx_hash'] ?? $row['payment_tx_hash'] ?? $row['router_tx_hash'] ?? ''));
        $collectionAddress = trim((string)($res['collection_address'] ?? ''));

        $fields = [
            'nft_item_address' => $nftItemAddress,
            'nft_minted' => 1,
            'status' => 'minted',
            'minted_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($txHash !== '') {
            $fields['router_tx_hash'] = $txHash;
        }

        $set = [];
        $params = [':uid' => $certUid];
        foreach ($fields as $k => $v) {
            $set[] = "{$k} = :{$k}";
            $params[":{$k}"] = $v;
        }

        $sql = "UPDATE poado_rwa_certs SET " . implode(', ', $set) . " WHERE cert_uid = :uid LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute($params);

        append_cert_history($pdo, $row, [
            'event' => 'cron_auto_mint_success',
            'status' => 'minted',
            'at' => gmdate('c'),
        ], [
            'mint' => [
                'nft_item_address' => $nftItemAddress,
                'collection_address' => $collectionAddress,
                'tx_hash' => $txHash,
                'minted_at' => gmdate('c'),
            ],
            'auto_mint' => [
                'ready' => false,
                'last_event' => 'success',
                'updated_at' => gmdate('c'),
            ],
        ]);

        logx('AUTO_MINT_SUCCESS', [
            'cert_uid' => $certUid,
            'nft_item_address' => $nftItemAddress,
            'collection_address' => $collectionAddress,
            'tx_hash' => $txHash,
        ]);
    } catch (Throwable $e) {
        append_cert_history($pdo, $row, [
            'event' => 'cron_auto_mint_error',
            'status' => 'issued',
            'at' => gmdate('c'),
        ], [
            'auto_mint' => [
                'ready' => true,
                'last_event' => 'error',
                'last_error' => $e->getMessage(),
                'updated_at' => gmdate('c'),
            ],
        ]);

        logx('AUTO_MINT_ERR', [
            'cert_uid' => $row['cert_uid'] ?? '',
            'error' => $e->getMessage(),
        ]);
    }
}

// ---------------------------------------------------------------------
// 3. MINT REPAIR
//    If nft_item_address already exists but status drifted
// ---------------------------------------------------------------------
$mintRows = $pdo->query("
    SELECT *
    FROM poado_rwa_certs
    WHERE nft_item_address IS NOT NULL
      AND nft_item_address <> ''
      AND (status <> 'minted' OR nft_minted = 0 OR nft_minted IS NULL)
    ORDER BY id ASC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($mintRows as $row) {
    try {
        $uid = (string)$row['cert_uid'];
        $nftItem = trim((string)$row['nft_item_address']);

        $st = $pdo->prepare("
            UPDATE poado_rwa_certs
            SET status = 'minted',
                nft_minted = 1,
                minted_at = COALESCE(minted_at, NOW()),
                updated_at = NOW()
            WHERE cert_uid = :uid
            LIMIT 1
        ");
        $st->execute([':uid' => $uid]);

        append_cert_history($pdo, $row, [
            'event' => 'cron_mint_repaired',
            'status' => 'minted',
            'at' => gmdate('c'),
        ], [
            'mint' => [
                'nft_item_address' => $nftItem,
                'minted_at' => gmdate('c'),
            ],
        ]);

        logx('CERT_MINTED', [
            'cert_uid' => $uid,
            'nft_item_address' => $nftItem,
        ]);
    } catch (Throwable $e) {
        logx('MINT_ERR', [
            'cert_uid' => $row['cert_uid'] ?? '',
            'error' => $e->getMessage(),
        ]);
    }
}

// ---------------------------------------------------------------------
// 4. STATUS REPAIR
// ---------------------------------------------------------------------

// 4a. payment confirmed but router_tx_hash missing on cert
$repairPayments = $pdo->query("
    SELECT p.cert_uid, p.tx_hash
    FROM poado_rwa_cert_payments p
    INNER JOIN poado_rwa_certs c ON c.cert_uid = p.cert_uid
    WHERE p.status = 'confirmed'
      AND p.verified = 1
      AND (c.router_tx_hash IS NULL OR c.router_tx_hash = '')
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($repairPayments as $row) {
    try {
        $st = $pdo->prepare("
            UPDATE poado_rwa_certs
            SET router_tx_hash = :tx,
                paid_at = COALESCE(paid_at, NOW()),
                updated_at = NOW()
            WHERE cert_uid = :uid
            LIMIT 1
        ");
        $st->execute([
            ':uid' => (string)$row['cert_uid'],
            ':tx' => (string)$row['tx_hash'],
        ]);

        logx('REPAIR_CERT_TX', [
            'cert_uid' => $row['cert_uid'],
            'tx_hash' => $row['tx_hash'],
        ]);
    } catch (Throwable $e) {
        logx('REPAIR_CERT_TX_ERR', [
            'cert_uid' => $row['cert_uid'] ?? '',
            'error' => $e->getMessage(),
        ]);
    }
}

// ---------------------------------------------------------------------
// 5. EXPIRE STALE PENDING PAYMENTS
// ---------------------------------------------------------------------
try {
    $stale = $pdo->query("
        SELECT p.id, p.cert_uid
        FROM poado_rwa_cert_payments p
        INNER JOIN poado_rwa_certs c ON c.cert_uid = p.cert_uid
        WHERE p.status = 'pending'
          AND c.status = 'issued'
          AND p.created_at < (NOW() - INTERVAL 24 HOUR)
        LIMIT 200
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($stale as $row) {
        $updP = $pdo->prepare("
            UPDATE poado_rwa_cert_payments
            SET status = 'expired',
                updated_at = NOW()
            WHERE id = :id
            LIMIT 1
        ");
        $updP->execute([':id' => (int)$row['id']]);

        $updC = $pdo->prepare("
            UPDATE poado_rwa_certs
            SET status = 'revoked',
                revoked_at = COALESCE(revoked_at, NOW()),
                updated_at = NOW()
            WHERE cert_uid = :uid
            LIMIT 1
        ");
        $updC->execute([':uid' => (string)$row['cert_uid']]);

        logx('PAYMENT_EXPIRED_CERT_REVOKED', [
            'cert_uid' => $row['cert_uid'],
        ]);
    }

    logx('EXPIRE_DONE', ['count' => count($stale)]);
} catch (Throwable $e) {
    logx('EXPIRE_ERR', ['error' => $e->getMessage()]);
}

logx('CRON DONE');
