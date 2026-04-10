<?php
// /var/www/html/public/rwa/api/mining/claim-sign.php
// Server-side claim signing interface (Option B: user pays gas; server never pays gas)
// - KYC-only withdrawal enforcement
// - Computes claimable = SUM(mining+bonus) - SUM(claimed)
// - Signs payload with Ed25519 (libsodium)
//
// JSON ONLY.

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../inc/rwa-session.php';
require_once __DIR__ . '/../../../dashboard/inc/session-user.php';
require_once __DIR__ . '/../../../dashboard/inc/bootstrap.php';

try {
    db_connect();
    $pdo = $GLOBALS['pdo'];
    if (!($pdo instanceof PDO)) throw new RuntimeException('PDO not ready');

    $nowEpoch = time();
    $nowUtc   = gmdate('Y-m-d H:i:s', $nowEpoch);

    $json_out = function(array $a): void {
        echo json_encode($a, JSON_UNESCAPED_SLASHES);
        exit;
    };

    // session user id
    $sess = $_SESSION ?? [];
    $uid = null;
    if (isset($sess['user_id']) && is_numeric($sess['user_id'])) $uid = (int)$sess['user_id'];
    if ($uid === null && isset($sess['me']['user_id']) && is_numeric($sess['me']['user_id'])) $uid = (int)$sess['me']['user_id'];
    if ($uid === null && isset($sess['user']['id']) && is_numeric($sess['user']['id'])) $uid = (int)$sess['user']['id'];
    if (!$uid || $uid < 1) $json_out(['ok' => false, 'error' => 'not_logged_in', 'ts' => $nowUtc]);

    // load user (KYC lock)
    $st = $pdo->prepare("SELECT id, wallet, ton_wallet, is_fully_verified, is_active FROM users WHERE id=? LIMIT 1");
    $st->execute([$uid]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) $json_out(['ok' => false, 'error' => 'user_not_found', 'ts' => $nowUtc]);
    if ((int)$u['is_active'] !== 1) $json_out(['ok' => false, 'error' => 'user_inactive', 'ts' => $nowUtc]);

    if ((int)$u['is_fully_verified'] !== 1) {
        $json_out([
            'ok' => false,
            'ts' => $nowUtc,
            'error' => 'kyc_required',
            'message' => 'Only fully KYC-verified users may withdraw wEMS to TON.'
        ]);
    }

    $wallet = (string)($u['wallet'] ?? '');
    if ($wallet === '') $json_out(['ok' => false, 'error' => 'wallet_missing', 'ts' => $nowUtc]);

    // TON wallet binding (if you store it elsewhere, swap query; do NOT invent new tables)
    $ton_wallet = (string)($u['ton_wallet'] ?? '');
    if ($ton_wallet === '') {
        $json_out([
            'ok' => false,
            'ts' => $nowUtc,
            'error' => 'ton_wallet_not_bound',
            'message' => 'Bind your TON wallet before claiming.'
        ]);
    }

    // total earned (mining + bonus)
    $st = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) AS total
        FROM wems_mining_log
        WHERE user_id=?
          AND reason IN ('mining','bonus')
    ");
    $st->execute([$uid]);
    $totalEarnedInt = (int)($st->fetchColumn() ?? 0);

    // detect an existing claim table (no new mining tables, no auto-create)
    // Preference: poado_reward_claims (from your locked master schema)
    $claimTable = null;
    $claimAmountCol = null;
    $claimUserCol = null;

    $candidateTables = ['poado_reward_claims', 'wems_reward_claims', 'poado_reward_claim', 'reward_claims'];

    foreach ($candidateTables as $t) {
        $st = $pdo->prepare("
            SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?
        ");
        $st->execute([$t]);
        $exists = (int)($st->fetchColumn() ?? 0);
        if ($exists !== 1) continue;

        // find usable columns
        $st = $pdo->prepare("
            SELECT COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?
        ");
        $st->execute([$t]);
        $cols = $st->fetchAll(PDO::FETCH_COLUMN);

        $cols_l = array_map('strtolower', $cols);

        $userCandidates = ['user_id', 'uid'];
        $amtCandidates  = ['amount', 'amount_int', 'amount_units', 'claimed_amount'];

        $uc = null; $ac = null;

        foreach ($userCandidates as $c) {
            $idx = array_search($c, $cols_l, true);
            if ($idx !== false) { $uc = $cols[$idx]; break; }
        }
        foreach ($amtCandidates as $c) {
            $idx = array_search($c, $cols_l, true);
            if ($idx !== false) { $ac = $cols[$idx]; break; }
        }

        if ($uc && $ac) {
            $claimTable = $t;
            $claimUserCol = $uc;
            $claimAmountCol = $ac;
            break;
        }
    }

    if (!$claimTable) {
        $json_out([
            'ok' => false,
            'ts' => $nowUtc,
            'error' => 'claim_table_missing',
            'message' => 'No existing claim-record table detected (expected poado_reward_claims or equivalent). No new tables are created.'
        ]);
    }

    // sum claimed
    $sql = "SELECT COALESCE(SUM($claimAmountCol), 0) AS claimed FROM $claimTable WHERE $claimUserCol=?";
    $st = $pdo->prepare($sql);
    $st->execute([$uid]);
    $claimedInt = (int)($st->fetchColumn() ?? 0);

    $claimableInt = $totalEarnedInt - $claimedInt;
    if ($claimableInt < 0) $claimableInt = 0;

    // No claimable -> return early
    if ($claimableInt <= 0) {
        $json_out([
            'ok' => true,
            'ts' => $nowUtc,
            'user_id' => $uid,
            'wallet' => $wallet,
            'ton_wallet' => $ton_wallet,
            'claimable_amount_int' => 0,
            'claimable_wems' => '0.00000000',
            'note' => 'nothing_to_claim'
        ]);
    }

    // signing key: MUST come from secure env (do not hardcode private keys)
    // Provide as 64-byte Ed25519 secret key hex (libsodium secret key)
    $skHex = getenv('WEMS_CLAIM_ED25519_SK_HEX') ?: '';
    if ($skHex === '' || strlen($skHex) < 64) {
        $json_out([
            'ok' => false,
            'ts' => $nowUtc,
            'error' => 'signing_key_missing',
            'message' => 'Missing env WEMS_CLAIM_ED25519_SK_HEX (Ed25519 secret key hex).'
        ]);
    }

    if (!function_exists('sodium_crypto_sign_detached')) {
        $json_out([
            'ok' => false,
            'ts' => $nowUtc,
            'error' => 'libsodium_missing',
            'message' => 'PHP libsodium is required for Ed25519 signing.'
        ]);
    }

    $sk = @hex2bin($skHex);
    if ($sk === false) {
        $json_out([
            'ok' => false,
            'ts' => $nowUtc,
            'error' => 'bad_signing_key',
            'message' => 'WEMS_CLAIM_ED25519_SK_HEX must be valid hex.'
        ]);
    }

    // nonce + expiry (short TTL to reduce replay risk)
    $nonce = $nowEpoch;          // simple deterministic nonce; contract should also enforce per-user nonce monotonicity
    $exp   = $nowEpoch + 300;    // 5 minutes

    $payload = [
        'v' => 1,
        'chain_id' => '7709304653',
        'user_id' => $uid,
        'wallet' => $wallet,
        'ton_wallet' => $ton_wallet,
        'amount_int' => $claimableInt,     // BIGINT 1e8
        'nonce' => $nonce,
        'iat' => $nowEpoch,
        'exp' => $exp,
    ];

    // canonical message bytes (stable signing)
    $msg = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($msg)) throw new RuntimeException('payload encode failed');

    $sig = sodium_crypto_sign_detached($msg, $sk);

    $json_out([
        'ok' => true,
        'ts' => $nowUtc,

        'claim_table' => $claimTable,
        'total_earned_int' => $totalEarnedInt,
        'claimed_int' => $claimedInt,
        'claimable_amount_int' => $claimableInt,

        'payload' => $payload,
        'payload_json' => $msg,

        'signature_hex' => bin2hex($sig),
        'signature_base64' => base64_encode($sig),

        // locked pubkey reference (for your contract / audits)
        'pubkey_hex_locked' => '0af9a5d209c6daf48f504539cc7873b033764ef39a5c8fed4f78844c9a9d61d4',
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'ts' => gmdate('Y-m-d H:i:s'),
        'error' => 'server_error',
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_SLASHES);
    exit;
}