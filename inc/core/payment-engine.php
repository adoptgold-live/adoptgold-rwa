<?php
// /dashboard/inc/payment-engine.php
// POAdo Global Payment Engine (Popup QR Salt + RPC Verify)
// - NO Web3 / MetaMask connect flows
// - On-chain verification via BSC RPC eth_getLogs (Transfer to Treasury)
// - Supports selectable BSC tokens: EMX (9d), EMA (9d), EMS (18d)

if (defined('POADO_PAYMENT_ENGINE_LOADED')) {
  return;
}
define('POADO_PAYMENT_ENGINE_LOADED', true);

/**
 * Supported on-chain tokens (BSC)
 * NOTE: Contracts are locked in env/memory for this project.
 */
function poado_payment_supported_tokens(): array {
  return [
    'EMX' => [
      'symbol' => 'EMX',
      'contract' => strtolower('0x29FABe2c7cCd54ecdA0b02DEAD68Ba7b183Ebe84'),
      'decimals' => 9,
    ],
    'EMA' => [
      'symbol' => 'EMA',
      'contract' => strtolower('0xf92D8CF31F89C5246Ce0Ac1f3D96F03E1F902Cdb'),
      'decimals' => 9,
    ],
    'EMS' => [
      'symbol' => 'EMS',
      'contract' => strtolower('0xb5080C0BD7DAeB5e74001d49c34617Db9f0791d7'),
      'decimals' => 18,
    ],
  ];
}

/**
 * Simple DB column existence check (MariaDB)
 */
function poado_col_exists(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE()
                         AND TABLE_NAME = ?
                         AND COLUMN_NAME = ?");
  $st->execute([$table, $col]);
  return ((int)$st->fetchColumn()) > 0;
}

function poado_norm_addr(string $addr): string {
  $a = strtolower(trim($addr));
  if ($a === '') return '';
  if (!str_starts_with($a, '0x')) $a = '0x' . $a;
  return $a;
}

function poado_topic_addr(string $addr): string {
  $a = poado_norm_addr($addr);
  $a = ltrim($a, '0x');
  return '0x' . str_pad($a, 64, '0', STR_PAD_LEFT);
}

function poado_rpc_call(string $rpc, string $method, array $params = []) {
  $payload = json_encode([
    'jsonrpc' => '2.0',
    'id' => 1,
    'method' => $method,
    'params' => $params
  ], JSON_UNESCAPED_SLASHES);

  $ch = curl_init($rpc);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 25,
  ]);
  $raw = curl_exec($ch);
  $err = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($raw === false || $code >= 400) {
    throw new RuntimeException("RPC http=$code err=$err");
  }

  $j = json_decode($raw, true);
  if (!is_array($j)) throw new RuntimeException("RPC invalid JSON");
  if (isset($j['error'])) {
    $msg = is_array($j['error']) ? ($j['error']['message'] ?? 'rpc error') : 'rpc error';
    throw new RuntimeException("RPC error: ".$msg);
  }
  return $j['result'] ?? null;
}

function poado_hex_to_int(string $hex): int {
  $h = strtolower(trim($hex));
  if (str_starts_with($h, '0x')) $h = substr($h, 2);
  if ($h === '') return 0;
  return (int)hexdec($h);
}

/**
 * 32-byte hex -> decimal string (bigint)
 * Prefers GMP, else BCMath. If neither available -> throws (safer than wrong matching).
 */
function poado_hex_256_to_dec_string(string $hex): string {
  $h = strtolower(trim($hex));
  if (str_starts_with($h, '0x')) $h = substr($h, 2);
  if ($h === '' || preg_match('/^0+$/', $h)) return '0';

  if (function_exists('gmp_init')) {
    return gmp_strval(gmp_init($h, 16), 10);
  }

  if (function_exists('bcadd')) {
    $dec = '0';
    for ($i=0; $i<strlen($h); $i++) {
      $dec = bcmul($dec, '16', 0);
      $dec = bcadd($dec, (string)hexdec($h[$i]), 0);
    }
    return $dec;
  }

  throw new RuntimeException("Need GMP or BCMath for bigint conversion");
}

/**
 * decimal string -> integer units string (as decimal, no exponent)
 * amount like "10.100006705" with decimals=9 -> "10100006705"
 */
function poado_dec_to_units(string $amount, int $decimals): string {
  $s = trim($amount);
  if ($s === '') return '0';
  if (!preg_match('/^[0-9]+(\.[0-9]+)?$/', $s)) {
    throw new InvalidArgumentException("Invalid decimal amount");
  }

  if (!function_exists('bcadd')) {
    // Use BCMath for exactness; without it we may mis-round
    throw new RuntimeException("BCMath required for exact units conversion");
  }

  if (!str_contains($s, '.')) {
    return bcmul($s, bcpow('10', (string)$decimals, 0), 0);
  }

  [$w, $f] = explode('.', $s, 2);
  $w = $w === '' ? '0' : $w;
  $f = substr($f . str_repeat('0', $decimals), 0, $decimals);
  $base = bcmul($w, bcpow('10', (string)$decimals, 0), 0);
  return bcadd($base, $f === '' ? '0' : $f, 0);
}

function poado_units_to_dec(string $units, int $decimals): string {
  $u = ltrim(trim($units), '+');
  if ($u === '' || $u === '0') return '0';
  $u = preg_replace('/\D/', '', $u);
  if ($decimals <= 0) return $u;

  $len = strlen($u);
  if ($len <= $decimals) {
    return '0.' . str_pad($u, $decimals, '0', STR_PAD_LEFT);
  }
  $w = substr($u, 0, $len - $decimals);
  $f = substr($u, $len - $decimals);
  $f = rtrim($f, '0');
  return $f === '' ? $w : ($w . '.' . $f);
}

/**
 * Generate salted required amount and persist into meta.payment.*
 *
 * $context is a small struct describing which DB row to update.
 * Example for Deal module:
 *   ['table'=>'poado_deals','uid_col'=>'deal_uid','uid'=>'DL-...']
 */
function poado_payment_generate(PDO $pdo, array $context, string $tokenSymbol, string $amount_main, string $amount_tips = '0', ?int $expiry_minutes = 10): array {
  $table = (string)($context['table'] ?? '');
  $uidCol = (string)($context['uid_col'] ?? '');
  $uid = (string)($context['uid'] ?? '');

  if ($table === '' || $uidCol === '' || $uid === '') {
    throw new InvalidArgumentException("Bad context");
  }

  $tokens = poado_payment_supported_tokens();
  if (!isset($tokens[$tokenSymbol])) {
    throw new InvalidArgumentException("Unsupported token");
  }
  $tok = $tokens[$tokenSymbol];

  $treasury = poado_norm_addr((string)(getenv('TREASURY_ADDRESS') ?: ''));
  if ($treasury === '' || !preg_match('/^0x[a-f0-9]{40}$/', $treasury)) {
    throw new RuntimeException("TREASURY_ADDRESS invalid");
  }

  $chainId = 56; // BSC
  $decimals = (int)$tok['decimals'];
  $contract = poado_norm_addr((string)$tok['contract']);

  // Compute total = main + tips + salt
  if (!function_exists('bcadd')) {
    throw new RuntimeException("BCMath required");
  }
  $main = trim($amount_main);
  $tips = trim($amount_tips);
  if ($tips === '') $tips = '0';
  if (!preg_match('/^[0-9]+(\.[0-9]+)?$/', $main)) throw new InvalidArgumentException("Bad amount_main");
  if (!preg_match('/^[0-9]+(\.[0-9]+)?$/', $tips)) throw new InvalidArgumentException("Bad amount_tips");

  // Salt: 1..9999 units (small) => collision resistant, still exact
  $saltUnits = (string)random_int(1, 9999);
  $saltDec = poado_units_to_dec($saltUnits, $decimals);

  $total = bcadd(bcadd($main, $tips, $decimals), $saltDec, $decimals);
  $requiredUnits = poado_dec_to_units($total, $decimals);

  $nowUtc = gmdate('c');
  $expiresUtc = null;
  if ($expiry_minutes !== null && $expiry_minutes > 0) {
    $expiresUtc = gmdate('c', time() + ($expiry_minutes * 60));
  }

  // Load existing meta (keep module-specific content)
  $st = $pdo->prepare("SELECT meta FROM {$table} WHERE {$uidCol}=? LIMIT 1");
  $st->execute([$uid]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  $meta = [];
  if ($row && isset($row['meta'])) {
    $tmp = json_decode((string)$row['meta'], true);
    if (is_array($tmp)) $meta = $tmp;
  }

  $meta['payment'] = [
    'method' => 'popup_qr_salt_rpc',
    'token_symbol' => $tokenSymbol,
    'token_contract' => strtolower($contract),
    'decimals' => $decimals,
    'treasury' => strtolower($treasury),
    'chain_id' => $chainId,
    'required_amount' => $total,          // decimal string
    'required_units' => $requiredUnits,   // integer string
    'main_amount' => $main,
    'tips_amount' => $tips,
    'salt_units' => $saltUnits,
    'created_at_utc' => $nowUtc,
    'expires_at_utc' => $expiresUtc,
    'lookback_blocks' => 8000,
    'min_confirmations' => 3,
  ];

  // Update row: set awaiting_payment if status column exists
  $hasStatus = poado_col_exists($pdo, $table, 'status');
  $sql = "UPDATE {$table} SET meta=?".($hasStatus ? ", status='awaiting_payment'" : "")." WHERE {$uidCol}=? LIMIT 1";
  $u = $pdo->prepare($sql);
  $u->execute([json_encode($meta, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), $uid]);

  return $meta['payment'];
}

/**
 * Check via RPC logs and, if matched, mark row paid (status/tx_hash/paid_at if columns exist).
 * Returns: ['ok'=>true,'status'=>'paid'|'pending'|'expired', 'tx_hash' => '0x..'|null]
 */
function poado_payment_check_and_mark(PDO $pdo, array $context): array {
  $table = (string)($context['table'] ?? '');
  $uidCol = (string)($context['uid_col'] ?? '');
  $uid = (string)($context['uid'] ?? '');

  if ($table === '' || $uidCol === '' || $uid === '') {
    return ['ok'=>false,'msg'=>'Bad context'];
  }

  $st = $pdo->prepare("SELECT * FROM {$table} WHERE {$uidCol}=? LIMIT 1");
  $st->execute([$uid]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) return ['ok'=>false,'msg'=>'Not found'];

  $meta = [];
  $tmp = json_decode((string)($row['meta'] ?? ''), true);
  if (is_array($tmp)) $meta = $tmp;

  $p = $meta['payment'] ?? null;
  if (!is_array($p)) return ['ok'=>false,'msg'=>'No payment meta'];

  // expiry check
  $exp = (string)($p['expires_at_utc'] ?? '');
  if ($exp !== '') {
    $t = strtotime($exp);
    if ($t && time() > $t) {
      return ['ok'=>true,'status'=>'expired','tx_hash'=>null];
    }
  }

  $rpc = (string)(getenv('BSC_RPC') ?: 'https://bsc-dataseed.binance.org');
  $contract = poado_norm_addr((string)($p['token_contract'] ?? ''));
  $treasury = poado_norm_addr((string)($p['treasury'] ?? ''));
  $requiredUnits = (string)($p['required_units'] ?? '');
  $minConfs = (int)($p['min_confirmations'] ?? 3);
  $lookback = (int)($p['lookback_blocks'] ?? 8000);

  if ($contract === '' || $treasury === '' || $requiredUnits === '') {
    return ['ok'=>false,'msg'=>'Bad payment meta'];
  }

  // Latest block and scan window
  $latestHex = poado_rpc_call($rpc, 'eth_blockNumber', []);
  $latest = poado_hex_to_int((string)$latestHex);
  $toBlock = max(0, $latest - max(0, ($minConfs - 1)));
  $fromBlock = max(0, $toBlock - max(1000, $lookback));

  // ERC20 Transfer topic0 (full 32 bytes)
  $TRANSFER_TOPIC0 = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

  $params = [[
    'fromBlock' => '0x' . dechex($fromBlock),
    'toBlock'   => '0x' . dechex($toBlock),
    'address'   => strtolower($contract),
    'topics'    => [
      $TRANSFER_TOPIC0,
      null,
      poado_topic_addr($treasury),
    ]
  ]];

  try {
    $logs = poado_rpc_call($rpc, 'eth_getLogs', $params);
  } catch (Throwable $e) {
    return ['ok'=>false,'msg'=>'RPC scan failed'];
  }

  if (!is_array($logs) || count($logs) === 0) {
    return ['ok'=>true,'status'=>'pending','tx_hash'=>null];
  }

  foreach ($logs as $log) {
    if (!is_array($log)) continue;
    $dataHex = (string)($log['data'] ?? '0x0');
    $txHash = (string)($log['transactionHash'] ?? '');
    $blkHex = (string)($log['blockNumber'] ?? '0x0');

    // compare transfer value exactly in units
    try {
      $valDecStr = poado_hex_256_to_dec_string($dataHex); // units decimal string
    } catch (Throwable $e) {
      continue;
    }

    if ($valDecStr !== $requiredUnits) continue;

    // Mark paid (if columns exist)
    $hasStatus = poado_col_exists($pdo, $table, 'status');
    $hasTx = poado_col_exists($pdo, $table, 'tx_hash');
    $hasPaidAt = poado_col_exists($pdo, $table, 'paid_at');

    $sets = [];
    $args = [];

    if ($hasStatus) $sets[] = "status='paid'";
    if ($hasTx) { $sets[] = "tx_hash=?"; $args[] = $txHash; }
    if ($hasPaidAt) $sets[] = "paid_at=NOW()";

    // Also stamp meta
    $meta['payment']['paid_tx_hash'] = $txHash;
    $meta['payment']['paid_block'] = poado_hex_to_int($blkHex);
    $meta['payment']['paid_detected_at_utc'] = gmdate('c');

    $sets[] = "meta=?";
    $args[] = json_encode($meta, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

    $args[] = $uid;

    $sql = "UPDATE {$table} SET ".implode(',', $sets)." WHERE {$uidCol}=? LIMIT 1";
    $u = $pdo->prepare($sql);
    $u->execute($args);

    return ['ok'=>true,'status'=>'paid','tx_hash'=>$txHash];
  }

  return ['ok'=>true,'status'=>'pending','tx_hash'=>null];
}
