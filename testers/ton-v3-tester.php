<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/testers/ton-v3-tester.php
 * AdoptGold / POAdo — TON v3 Token Tester
 * Version: v1.0.0-20260318
 *
 * Purpose:
 * - key in any TON wallet address
 * - fetch native TON balance
 * - fetch all jetton balances from TON Center v3
 * - show token list, masters, decimals, raw balance, decimal balance
 * - safe standalone tester for Storage/on-chain balance debugging
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/toncenter.php';

function tester_env(string $key, string $default = ''): string
{
    $v = getenv($key);
    if (is_string($v) && $v !== '') return $v;
    if (isset($_ENV[$key]) && is_scalar($_ENV[$key]) && (string)$_ENV[$key] !== '') return (string)$_ENV[$key];
    if (isset($_SERVER[$key]) && is_scalar($_SERVER[$key]) && (string)$_SERVER[$key] !== '') return (string)$_SERVER[$key];
    return $default;
}

function tester_h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function tester_v3_base(): string
{
    return rtrim(
        tester_env('TONCENTER_V3_BASE_URL', tester_env('TONCENTER_BASE', 'https://toncenter.com/api/v3')),
        '/'
    );
}

function tester_fetch_ton(string $address): array
{
    $res = poado_toncenter_get_address_balance($address);
    if (!poado_toncenter_is_ok($res)) {
        return [
            'ok' => false,
            'raw' => $res,
            'balance_raw' => '0',
            'balance' => '0.000000',
        ];
    }

    $raw = (string)($res['result'] ?? '0');

    return [
        'ok' => true,
        'raw' => $res,
        'balance_raw' => $raw,
        'balance' => poado_toncenter_to_decimal($raw, 9, 6),
    ];
}

function tester_fetch_jettons(string $address): array
{
    $baseV3 = tester_v3_base();

    $res = poado_toncenter_api('/jetton/wallets', ['owner' => $address], [
        'base_url' => $baseV3,
    ]);

    if (!is_array($res)) {
        return [
            'ok' => false,
            'error' => 'INVALID_RESPONSE',
            'items' => [],
            'raw' => $res,
            'base_url' => $baseV3,
        ];
    }

    if (($res['ok'] ?? false) !== true) {
        return [
            'ok' => false,
            'error' => (string)($res['error'] ?? 'TONCENTER_V3_FAILED'),
            'items' => [],
            'raw' => $res,
            'base_url' => $baseV3,
        ];
    }

    $items = [];
    if (isset($res['result']) && is_array($res['result'])) {
        $items = $res['result'];
    } elseif (isset($res['jetton_wallets']) && is_array($res['jetton_wallets'])) {
        $items = $res['jetton_wallets'];
    }

    return [
        'ok' => true,
        'error' => '',
        'items' => $items,
        'raw' => $res,
        'base_url' => $baseV3,
    ];
}

function tester_normalize_jettons(array $items): array
{
    $out = [];

    foreach ($items as $item) {
        if (!is_array($item)) continue;

        $jetton = isset($item['jetton']) && is_array($item['jetton']) ? $item['jetton'] : [];
        $metadata = isset($jetton['metadata']) && is_array($jetton['metadata']) ? $jetton['metadata'] : [];

        $decimals = (int)($jetton['decimals'] ?? $item['decimals'] ?? 9);
        $balanceRaw = (string)($item['balance'] ?? '0');

        $symbol = trim((string)(
            $metadata['symbol']
            ?? $jetton['symbol']
            ?? $item['symbol']
            ?? ''
        ));

        $name = trim((string)(
            $metadata['name']
            ?? $jetton['name']
            ?? $item['name']
            ?? ''
        ));

        $master = trim((string)(
            $jetton['address']
            ?? $item['jetton_master']
            ?? $jetton['master']
            ?? $item['master']
            ?? ''
        ));

        $wallet = trim((string)(
            $item['address']
            ?? $item['wallet_address']
            ?? ''
        ));

        $image = trim((string)(
            $metadata['image']
            ?? $jetton['image']
            ?? ''
        ));

        $out[] = [
            'name' => $name,
            'symbol' => $symbol,
            'master' => $master,
            'wallet' => $wallet,
            'decimals' => $decimals,
            'balance_raw' => $balanceRaw,
            'balance' => poado_toncenter_to_decimal($balanceRaw, $decimals, 6),
            'image' => $image,
            'raw' => $item,
        ];
    }

    usort($out, static function (array $a, array $b): int {
        return strcmp($a['symbol'] . $a['name'], $b['symbol'] . $b['name']);
    });

    return $out;
}

$address = trim((string)($_GET['address'] ?? $_POST['address'] ?? ''));
$run = ($address !== '');

$ton = null;
$jettons = null;
$normalized = [];
$error = '';

if ($run) {
    try {
        $ton = tester_fetch_ton($address);
        $jettons = tester_fetch_jettons($address);
        $normalized = tester_normalize_jettons((array)($jettons['items'] ?? []));
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TON v3 Token Tester</title>
<style>
:root{
  --bg:#071018;
  --panel:#0d1722;
  --line:rgba(255,255,255,.10);
  --text:#f4f7fb;
  --muted:#9db0c6;
  --ok:#65d38a;
  --warn:#f0c36b;
  --bad:#ff8b8b;
  --accent:#76b7ff;
}
*{box-sizing:border-box}
body{
  margin:0;
  background:var(--bg);
  color:var(--text);
  font:14px/1.45 Inter,Arial,sans-serif;
}
.wrap{
  max-width:1200px;
  margin:0 auto;
  padding:24px;
}
.card{
  background:var(--panel);
  border:1px solid var(--line);
  border-radius:16px;
  padding:18px;
  margin-bottom:16px;
}
h1,h2,h3{margin:0 0 12px}
.muted{color:var(--muted)}
.ok{color:var(--ok)}
.warn{color:var(--warn)}
.bad{color:var(--bad)}
.row{
  display:flex;
  gap:12px;
  flex-wrap:wrap;
}
input[type=text]{
  flex:1 1 760px;
  min-width:280px;
  border:1px solid var(--line);
  background:#09121b;
  color:var(--text);
  border-radius:12px;
  padding:12px 14px;
}
button{
  border:1px solid var(--line);
  background:#142234;
  color:var(--text);
  border-radius:12px;
  padding:12px 18px;
  cursor:pointer;
}
button:hover{border-color:var(--accent)}
.grid{
  display:grid;
  grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
  gap:12px;
}
.kv{
  background:#09121b;
  border:1px solid var(--line);
  border-radius:12px;
  padding:12px;
}
.kv .k{color:var(--muted);font-size:12px;margin-bottom:6px}
.kv .v{word-break:break-word;font-size:16px}
table{
  width:100%;
  border-collapse:collapse;
}
th,td{
  text-align:left;
  vertical-align:top;
  border-top:1px solid var(--line);
  padding:10px 8px;
}
th{color:var(--muted);font-weight:600}
pre{
  margin:0;
  white-space:pre-wrap;
  word-break:break-word;
  background:#09121b;
  border:1px solid var(--line);
  border-radius:12px;
  padding:12px;
  overflow:auto;
}
.small{font-size:12px}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>TON v3 Token Tester</h1>
    <div class="muted">Key in any TON wallet address to inspect native TON and all jetton balances from TON Center v3.</div>
    <form method="get" class="row" style="margin-top:14px;">
      <input type="text" name="address" placeholder="0:... or UQ..." value="<?= tester_h($address) ?>">
      <button type="submit">Check Address</button>
    </form>
  </div>

  <?php if ($error !== ''): ?>
    <div class="card"><div class="bad">Runtime error: <?= tester_h($error) ?></div></div>
  <?php endif; ?>

  <?php if ($run): ?>
    <div class="card">
      <h2>Request Summary</h2>
      <div class="grid">
        <div class="kv"><div class="k">Address</div><div class="v"><?= tester_h($address) ?></div></div>
        <div class="kv"><div class="k">TON Center v3 Base</div><div class="v"><?= tester_h($jettons['base_url'] ?? '') ?></div></div>
        <div class="kv"><div class="k">TON Fetch</div><div class="v <?= (($ton['ok'] ?? false) ? 'ok' : 'bad') ?>"><?= (($ton['ok'] ?? false) ? 'OK' : 'FAILED') ?></div></div>
        <div class="kv"><div class="k">Jetton Fetch</div><div class="v <?= (($jettons['ok'] ?? false) ? 'ok' : 'bad') ?>"><?= (($jettons['ok'] ?? false) ? 'OK' : 'FAILED') ?></div></div>
        <div class="kv"><div class="k">Jetton Count</div><div class="v"><?= (int)count($normalized) ?></div></div>
        <div class="kv"><div class="k">Native TON</div><div class="v"><?= tester_h($ton['balance'] ?? '0.000000') ?></div></div>
      </div>
    </div>

    <div class="card">
      <h2>All On-Chain Tokens</h2>
      <?php if (($jettons['ok'] ?? false) !== true): ?>
        <div class="bad">Jetton fetch failed.</div>
        <div class="small muted" style="margin-top:8px;">This means the request itself failed before token matching. Inspect raw response below.</div>
      <?php elseif (!$normalized): ?>
        <div class="warn">No jettons returned for this address.</div>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Symbol</th>
              <th>Name</th>
              <th>Balance</th>
              <th>Decimals</th>
              <th>Master</th>
              <th>Jetton Wallet</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($normalized as $i => $row): ?>
            <tr>
              <td><?= $i + 1 ?></td>
              <td><?= tester_h($row['symbol'] !== '' ? $row['symbol'] : '-') ?></td>
              <td><?= tester_h($row['name'] !== '' ? $row['name'] : '-') ?></td>
              <td>
                <strong><?= tester_h($row['balance']) ?></strong><br>
                <span class="small muted">raw: <?= tester_h($row['balance_raw']) ?></span>
              </td>
              <td><?= (int)$row['decimals'] ?></td>
              <td class="small"><?= tester_h($row['master']) ?></td>
              <td class="small"><?= tester_h($row['wallet']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2>Raw Debug</h2>
      <h3>TON</h3>
      <pre><?= tester_h(json_encode($ton, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
      <h3 style="margin-top:14px;">Jettons</h3>
      <pre><?= tester_h(json_encode($jettons, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
    </div>
  <?php endif; ?>
</div>
</body>
</html>