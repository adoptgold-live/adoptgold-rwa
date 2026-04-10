<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/testers/ton-v2-token-tester.php
 * AdoptGold / POAdo — TON v2 Token Tester
 * Version: v1.0.0-20260318
 *
 * Purpose:
 * - key in any TON wallet address
 * - fetch native TON balance using Toncenter v2
 * - fetch all jetton wallets using Toncenter v2 getJettonWallets
 * - apply upgraded token mapping layer:
 *   master + symbol + name + alias
 * - show mapped Storage tokens:
 *   EMX / EMA$ / EMS / wEMS / USDT-TON
 * - show raw jetton list for debugging
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

function tester_h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function tester_token_master_map(): array
{
    return [
        'emx' => trim(tester_env('TON_JETTON_EMX_MASTER', tester_env('TON_JETTON_MASTER_EMX', ''))),
        'ema' => trim(tester_env('TON_JETTON_EMA_MASTER', tester_env('TON_JETTON_MASTER_EMA', ''))),
        'ems' => trim(tester_env('TON_JETTON_EMS_MASTER', tester_env('TON_JETTON_MASTER_EMS', ''))),
        'wems' => trim(tester_env('TON_JETTON_WEMS_MASTER', tester_env('TON_JETTON_MASTER_WEMS', ''))),
        'usdt_ton' => trim(tester_env('TON_JETTON_USDT_MASTER', tester_env('TON_JETTON_MASTER_USDT', ''))),
    ];
}

function tester_token_alias_map(): array
{
    return [
        'EMX' => 'emx',
        'EMA' => 'ema',
        'EMA$' => 'ema',
        'EMS' => 'ems',
        'WEMS' => 'wems',
        'USDT' => 'usdt_ton',
        'USDTTON' => 'usdt_ton',
        'USDT-TON' => 'usdt_ton',
        'USD₮' => 'usdt_ton',
    ];
}

function tester_token_name_alias_map(): array
{
    return [
        'TETHER USD' => 'usdt_ton',
        'EMONEY RWA ADOPTION TOKEN' => 'ema',
        'EMONEY SOLVENCY RWA FUEL TOKEN' => 'ems',
        'EMONEY XAU GOLD RWA STABLE TOKEN' => 'emx',
        'WEB3 GOLD MINING REWARD TOKEN' => 'wems',
    ];
}

function tester_token_registry(): array
{
    $masters = tester_token_master_map();

    return [
        'emx' => [
            'label' => 'EMX',
            'masters' => array_values(array_filter([$masters['emx'] ?? ''])),
            'symbols' => ['EMX'],
            'names' => ['EMONEY XAU GOLD RWA STABLE TOKEN'],
        ],
        'ema' => [
            'label' => 'EMA$',
            'masters' => array_values(array_filter([$masters['ema'] ?? ''])),
            'symbols' => ['EMA', 'EMA$'],
            'names' => ['EMONEY RWA ADOPTION TOKEN'],
        ],
        'ems' => [
            'label' => 'EMS',
            'masters' => array_values(array_filter([$masters['ems'] ?? ''])),
            'symbols' => ['EMS'],
            'names' => ['EMONEY SOLVENCY RWA FUEL TOKEN'],
        ],
        'wems' => [
            'label' => 'wEMS',
            'masters' => array_values(array_filter([$masters['wems'] ?? ''])),
            'symbols' => ['WEMS'],
            'names' => ['WEB3 GOLD MINING REWARD TOKEN'],
        ],
        'usdt_ton' => [
            'label' => 'USDT-TON',
            'masters' => array_values(array_filter([$masters['usdt_ton'] ?? ''])),
            'symbols' => ['USDT', 'USD₮', 'USDT-TON', 'USDTTON'],
            'names' => ['TETHER USD'],
        ],
    ];
}

function tester_norm(string $value): string
{
    $value = trim($value);
    if ($value === '') return '';
    $value = strtoupper($value);
    $value = str_replace(['_', '  '], ['-', ' '], $value);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return trim($value);
}

function tester_match_token_key(array $item): ?string
{
    $registry = tester_token_registry();
    $aliasMap = tester_token_alias_map();
    $nameAliasMap = tester_token_name_alias_map();

    $jetton = isset($item['jetton']) && is_array($item['jetton']) ? $item['jetton'] : [];
    $meta = isset($jetton['metadata']) && is_array($jetton['metadata']) ? $jetton['metadata'] : [];

    $master = trim((string)(
        $jetton['address']
        ?? $item['jetton_master']
        ?? $jetton['master']
        ?? $item['master']
        ?? ''
    ));

    $symbol = tester_norm((string)(
        $meta['symbol']
        ?? $jetton['symbol']
        ?? $item['symbol']
        ?? ''
    ));

    $name = tester_norm((string)(
        $meta['name']
        ?? $jetton['name']
        ?? $item['name']
        ?? ''
    ));

    foreach ($registry as $key => $cfg) {
        foreach (($cfg['masters'] ?? []) as $m) {
            if ($m !== '' && $master !== '' && $m === $master) {
                return $key;
            }
        }
    }

    if ($symbol !== '' && isset($aliasMap[$symbol])) {
        return $aliasMap[$symbol];
    }

    if ($name !== '' && isset($nameAliasMap[$name])) {
        return $nameAliasMap[$name];
    }

    foreach ($registry as $key => $cfg) {
        foreach (($cfg['symbols'] ?? []) as $sym) {
            if ($symbol !== '' && tester_norm((string)$sym) === $symbol) {
                return $key;
            }
        }
        foreach (($cfg['names'] ?? []) as $nm) {
            if ($name !== '' && tester_norm((string)$nm) === $name) {
                return $key;
            }
        }
    }

    return null;
}

function tester_fetch_ton(string $address): array
{
    $res = poado_toncenter_get_address_balance($address);
    if (!poado_toncenter_is_ok($res)) {
        return [
            'ok' => false,
            'balance_raw' => '0',
            'balance' => '0.000000',
            'raw' => $res,
        ];
    }

    $raw = (string)($res['result'] ?? '0');

    return [
        'ok' => true,
        'balance_raw' => $raw,
        'balance' => poado_toncenter_to_decimal($raw, 9, 6),
        'raw' => $res,
    ];
}

function tester_fetch_jettons_v2(string $address): array
{
    $res = poado_toncenter_api('/getJettonWallets', [
        'owner' => $address,
    ]);

    if (!is_array($res)) {
        return [
            'ok' => false,
            'error' => 'INVALID_RESPONSE',
            'items' => [],
            'raw' => $res,
        ];
    }

    if (($res['ok'] ?? false) !== true) {
        return [
            'ok' => false,
            'error' => (string)($res['error'] ?? 'TONCENTER_V2_FAILED'),
            'items' => [],
            'raw' => $res,
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
    ];
}

function tester_extract_token_row(array $item): array
{
    $jetton = isset($item['jetton']) && is_array($item['jetton']) ? $item['jetton'] : [];
    $meta = isset($jetton['metadata']) && is_array($jetton['metadata']) ? $jetton['metadata'] : [];

    $decimals = (int)($jetton['decimals'] ?? $item['decimals'] ?? 9);
    $balanceRaw = (string)($item['balance'] ?? '0');

    $symbol = trim((string)(
        $meta['symbol']
        ?? $jetton['symbol']
        ?? $item['symbol']
        ?? ''
    ));

    $name = trim((string)(
        $meta['name']
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

    $mappedKey = tester_match_token_key($item);
    $registry = tester_token_registry();

    return [
        'mapped_key' => $mappedKey,
        'mapped_label' => ($mappedKey !== null && isset($registry[$mappedKey]['label'])) ? $registry[$mappedKey]['label'] : '',
        'symbol' => $symbol,
        'name' => $name,
        'master' => $master,
        'wallet' => $wallet,
        'decimals' => $decimals,
        'balance_raw' => $balanceRaw,
        'balance' => poado_toncenter_to_decimal($balanceRaw, $decimals, 6),
        'raw' => $item,
    ];
}

function tester_build_rows(array $items): array
{
    $rows = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $rows[] = tester_extract_token_row($item);
    }

    usort($rows, static function (array $a, array $b): int {
        return strcmp(
            ($a['mapped_key'] ?? '') . '|' . ($a['symbol'] ?? '') . '|' . ($a['name'] ?? ''),
            ($b['mapped_key'] ?? '') . '|' . ($b['symbol'] ?? '') . '|' . ($b['name'] ?? '')
        );
    });

    return $rows;
}

function tester_build_mapped_summary(array $rows): array
{
    $summary = [
        'emx' => '0.000000',
        'ema' => '0.000000',
        'ems' => '0.000000',
        'wems' => '0.000000',
        'usdt_ton' => '0.000000',
    ];

    foreach ($rows as $row) {
        $k = (string)($row['mapped_key'] ?? '');
        if ($k !== '' && array_key_exists($k, $summary)) {
            $summary[$k] = (string)($row['balance'] ?? '0.000000');
        }
    }

    return $summary;
}

$address = trim((string)($_GET['address'] ?? ''));
$run = ($address !== '');

$ton = null;
$jettons = null;
$rows = [];
$summary = [];
$error = '';

if ($run) {
    try {
        $ton = tester_fetch_ton($address);
        $jettons = tester_fetch_jettons_v2($address);
        $rows = tester_build_rows((array)($jettons['items'] ?? []));
        $summary = tester_build_mapped_summary($rows);
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
<title>TON v2 Token Tester</title>
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
  max-width:1280px;
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
.badge{
  display:inline-block;
  padding:3px 8px;
  border-radius:999px;
  font-size:12px;
  border:1px solid var(--line);
  background:#09121b;
}
.badge-ok{color:var(--ok)}
.badge-bad{color:var(--bad)}
.small{font-size:12px}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>TON v2 Token Tester</h1>
    <div class="muted">Key in any TON wallet address to inspect native TON balance and all jetton wallets using Toncenter v2 getJettonWallets.</div>
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
        <div class="kv"><div class="k">TON Fetch</div><div class="v <?= (($ton['ok'] ?? false) ? 'ok' : 'bad') ?>"><?= (($ton['ok'] ?? false) ? 'OK' : 'FAILED') ?></div></div>
        <div class="kv"><div class="k">Jetton Fetch (v2)</div><div class="v <?= (($jettons['ok'] ?? false) ? 'ok' : 'bad') ?>"><?= (($jettons['ok'] ?? false) ? 'OK' : 'FAILED') ?></div></div>
        <div class="kv"><div class="k">Jetton Count</div><div class="v"><?= count($rows) ?></div></div>
        <div class="kv"><div class="k">Native TON</div><div class="v"><?= tester_h($ton['balance'] ?? '0.000000') ?></div></div>
      </div>
    </div>

    <div class="card">
      <h2>Mapped Storage Tokens</h2>
      <div class="grid">
        <div class="kv"><div class="k">EMX</div><div class="v"><?= tester_h($summary['emx'] ?? '0.000000') ?></div></div>
        <div class="kv"><div class="k">EMA$</div><div class="v"><?= tester_h($summary['ema'] ?? '0.000000') ?></div></div>
        <div class="kv"><div class="k">EMS</div><div class="v"><?= tester_h($summary['ems'] ?? '0.000000') ?></div></div>
        <div class="kv"><div class="k">wEMS</div><div class="v"><?= tester_h($summary['wems'] ?? '0.000000') ?></div></div>
        <div class="kv"><div class="k">USDT-TON</div><div class="v"><?= tester_h($summary['usdt_ton'] ?? '0.000000') ?></div></div>
      </div>
    </div>

    <div class="card">
      <h2>All Jetton Wallets</h2>
      <?php if (($jettons['ok'] ?? false) !== true): ?>
        <div class="bad">Jetton fetch failed.</div>
        <div class="small muted" style="margin-top:8px;">Inspect raw response below.</div>
      <?php elseif (!$rows): ?>
        <div class="warn">No jetton wallets returned for this address.</div>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Mapped</th>
              <th>Symbol</th>
              <th>Name</th>
              <th>Balance</th>
              <th>Decimals</th>
              <th>Master</th>
              <th>Jetton Wallet</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $i => $row): ?>
            <tr>
              <td><?= $i + 1 ?></td>
              <td>
                <?php if (!empty($row['mapped_key'])): ?>
                  <span class="badge badge-ok"><?= tester_h($row['mapped_label'] ?: $row['mapped_key']) ?></span>
                <?php else: ?>
                  <span class="badge badge-bad">UNMAPPED</span>
                <?php endif; ?>
              </td>
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
      <h2>Registry / Raw Debug</h2>
      <h3>Token Registry</h3>
      <pre><?= tester_h(json_encode(tester_token_registry(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>

      <h3 style="margin-top:14px;">TON</h3>
      <pre><?= tester_h(json_encode($ton, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>

      <h3 style="margin-top:14px;">Jettons v2</h3>
      <pre><?= tester_h(json_encode($jettons, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
    </div>
  <?php endif; ?>
</div>
</body>
</html>