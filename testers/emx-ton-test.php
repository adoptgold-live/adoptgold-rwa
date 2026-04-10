<?php
declare(strict_types=1);

/**
 * EMX TON TESTER PHP + NODE BRIDGE
 * Version: v1.2.0-20260319
 *
 * FINAL RULE:
 * If jetton + amount + ref match -> ACCEPT
 *
 * Standalone tester:
 * - QR + payload
 * - random ref by default
 * - keeps same ref if provided in form/query
 * - one-shot verify
 * - refresh auto confirm
 */

header('Content-Type: text/html; charset=utf-8');

const NODE_BIN = '/usr/bin/node';
const JS_FILE  = __DIR__ . '/emx-ton-test.js';

const TREASURY_OWNER = 'UQBdYfGArtoCUmBs5TjYQtfPFfQuGC2Ydbj2pQr3zIlNrDta';
const EMX_JETTON_MASTER_RAW = '0:63d3319c1cebcde48b013ff040006e4d462b806bf48b06efb18ec267ec078ce2';
const AMOUNT_UNITS = '1000000000';

const DEFAULT_LIMIT = 100;
const DEFAULT_POLL = 0;
const DEFAULT_MAX = 10;

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function make_random_ref(string $prefix = 'EMX1-TEST'): string {
    $ts = new DateTime('now');
    $rand = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    return sprintf(
        '%s-%s-%s-%s',
        $prefix,
        $ts->format('Ymd'),
        $ts->format('His'),
        $rand
    );
}

function build_payload(string $treasury, string $jetton, string $amountUnits, string $ref): string {
    $q = http_build_query([
        'jetton' => $jetton,
        'amount' => $amountUnits,
        'text'   => $ref,
    ]);
    return "ton://transfer/{$treasury}?{$q}";
}

function qr_img_url(string $payload): string {
    return 'https://quickchart.io/qr?size=320&text=' . rawurlencode($payload);
}

function run_node_verify(array $params): array {
    if (!is_file(JS_FILE)) {
        return [
            'ok' => false,
            'code' => 'JS_NOT_FOUND',
            'message' => 'emx-ton-test.js not found beside this php file.',
            'path' => JS_FILE,
        ];
    }

    $cmd = escapeshellcmd(NODE_BIN) . ' ' . escapeshellarg(JS_FILE);

    $map = [
        'from'       => '--from',
        'ref'        => '--ref',
        'ref_prefix' => '--ref-prefix',
        'limit'      => '--limit',
        'poll'       => '--poll',
        'max'        => '--max',
    ];

    foreach ($map as $k => $flag) {
        if (!isset($params[$k])) continue;
        $v = trim((string)$params[$k]);
        if ($v === '') continue;
        $cmd .= ' ' . $flag . ' ' . escapeshellarg($v);
    }

    $out = [];
    $exit = 0;
    exec($cmd . ' 2>&1', $out, $exit);

    $raw = implode("\n", $out);
    $parsed = null;

    for ($i = 0; $i < count($out); $i++) {
        $slice = implode("\n", array_slice($out, $i));
        $tmp = json_decode($slice, true);
        if (is_array($tmp)) {
            $parsed = $tmp;
            break;
        }
    }

    return [
        'ok'        => true,
        'code'      => 'NODE_EXECUTED',
        'cmd'       => $cmd,
        'exit_code' => $exit,
        'parsed'    => $parsed,
        'raw'       => $raw,
    ];
}

$from = trim($_REQUEST['from'] ?? '');
$refInput = trim($_REQUEST['ref'] ?? '');
$refPrefix = trim($_REQUEST['ref_prefix'] ?? 'EMX1-TEST');
if ($refPrefix === '') $refPrefix = 'EMX1-TEST';

// keep same ref if present; only generate if blank
$ref = $refInput !== '' ? $refInput : make_random_ref($refPrefix);

$limit = (string) max(1, (int)($_REQUEST['limit'] ?? DEFAULT_LIMIT));
$poll  = (string) max(0, (int)($_REQUEST['poll'] ?? DEFAULT_POLL));
$max   = (string) max(1, (int)($_REQUEST['max'] ?? DEFAULT_MAX));
$action = trim($_REQUEST['action'] ?? '');

$payload = build_payload(TREASURY_OWNER, EMX_JETTON_MASTER_RAW, AMOUNT_UNITS, $ref);
$qrUrl = qr_img_url($payload);

$verifyResult = null;
if ($action === 'verify_once' || $action === 'refresh_auto') {
    if ($from !== '') {
        $verifyParams = [
            'from'       => $from,
            'ref'        => $ref,
            'ref_prefix' => $refPrefix,
            'limit'      => $limit,
            'poll'       => $action === 'refresh_auto' ? max(0, (int)$poll) : 0,
            'max'        => $action === 'refresh_auto' ? max(1, (int)$max) : 1,
        ];
        $verifyResult = run_node_verify($verifyParams);
    } else {
        $verifyResult = [
            'ok' => false,
            'code' => 'FROM_REQUIRED',
            'message' => 'Please fill From Address first.'
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>EMX TON Test</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{
  --bg:#07110d;
  --panel:#081711;
  --line:rgba(90,255,170,.18);
  --text:#eafff4;
  --muted:#9fd8b8;
  --green:#70ffb0;
  --gold:#ffd86b;
  --danger:#ff9c8f;
}
*{box-sizing:border-box}
body{
  margin:0;
  padding:20px;
  background:linear-gradient(180deg,#06100c,#07140f 40%,#07100d);
  color:var(--text);
  font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
}
.wrap{max-width:1200px;margin:0 auto}
.grid{display:grid;grid-template-columns:1.05fr .95fr;gap:18px}
@media(max-width:980px){.grid{grid-template-columns:1fr}}
.card{
  background:linear-gradient(180deg,rgba(8,23,17,.94),rgba(6,18,13,.96));
  border:1px solid var(--line);
  border-radius:18px;
  padding:18px;
  box-shadow:0 0 0 1px rgba(40,120,80,.06) inset,0 12px 30px rgba(0,0,0,.22);
}
h1,h2,h3{margin:0 0 12px}
h1{font-size:32px}
h2{font-size:22px}
h3{font-size:17px;color:var(--gold)}
.sub{color:var(--muted);margin-bottom:14px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:760px){.form-grid{grid-template-columns:1fr}}
label{display:block;font-size:13px;color:var(--muted);margin-bottom:6px}
input,textarea{
  width:100%;
  padding:12px 13px;
  border-radius:12px;
  border:1px solid var(--line);
  background:#06120d;
  color:var(--text);
  outline:none;
}
.actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px}
button,.btn{
  appearance:none;
  border:1px solid var(--line);
  background:#0a1f17;
  color:var(--text);
  padding:12px 14px;
  border-radius:12px;
  cursor:pointer;
  text-decoration:none;
  display:inline-block;
}
button.primary,.btn.primary{background:#0d2e21;border-color:rgba(112,255,176,.28)}
.kv{display:grid;grid-template-columns:220px 1fr;gap:10px 14px;align-items:start}
@media(max-width:760px){.kv{grid-template-columns:1fr}}
.k{color:var(--muted)}
.v{word-break:break-all}
.qrbox{display:flex;justify-content:center;align-items:center;padding:8px}
.qrbox img{
  max-width:320px;
  width:100%;
  height:auto;
  border-radius:16px;
  background:#fff;
  padding:10px;
}
.code{
  background:#04100b;
  border:1px solid var(--line);
  border-radius:14px;
  padding:14px;
  white-space:pre-wrap;
  word-break:break-word;
  overflow:auto;
}
.ok{color:var(--green)}
.bad{color:var(--danger)}
.note{
  margin-top:10px;
  padding:12px 14px;
  border-radius:12px;
  background:#07140f;
  border:1px solid var(--line);
  color:var(--muted);
}
.small{font-size:12px;color:var(--muted)}
hr{border:0;border-top:1px solid var(--line);margin:16px 0}
</style>
</head>
<body>
<div class="wrap">
  <h1>EMX TON Test</h1>
  <div class="sub">Final standalone tester. Acceptance rule: <strong>jetton + amount + ref match = ACCEPT</strong>.</div>

  <div class="grid">
    <div class="card">
      <h2>Test Setup</h2>
      <form method="get">
        <div class="form-grid">
          <div style="grid-column:1/-1">
            <label>From Address</label>
            <input name="from" value="<?= h($from) ?>" placeholder="0:... or UQ..." required>
          </div>

          <div>
            <label>Ref</label>
            <input name="ref" value="<?= h($ref) ?>" placeholder="leave blank for auto-generate on first load">
          </div>

          <div>
            <label>Ref Prefix</label>
            <input name="ref_prefix" value="<?= h($refPrefix) ?>" placeholder="EMX1-TEST">
          </div>

          <div>
            <label>Limit</label>
            <input name="limit" value="<?= h($limit) ?>">
          </div>

          <div>
            <label>Poll Seconds</label>
            <input name="poll" value="<?= h($poll) ?>">
          </div>

          <div>
            <label>Max Attempts</label>
            <input name="max" value="<?= h($max) ?>">
          </div>
        </div>

        <div class="actions">
          <button class="primary" type="submit">Generate / Refresh QR</button>
          <button type="submit" name="action" value="verify_once">Check Auto Verify</button>
          <button type="submit" name="action" value="refresh_auto">Refresh Auto Confirm</button>
        </div>
      </form>

      <div class="note">
        <div><strong>Effective Ref:</strong> <span class="ok"><?= h($ref) ?></span></div>
        <div class="small">This tester preserves the current ref unless you replace it manually.</div>
      </div>

      <hr>

      <h2>QR & Payload</h2>
      <div class="sub">Send exactly 1 EMX using this ref, then verify.</div>

      <div class="qrbox">
        <img src="<?= h($qrUrl) ?>" alt="QR">
      </div>

      <div class="kv">
        <div class="k">Payload</div>
        <div class="v code"><?= h($payload) ?></div>

        <div class="k">Treasury Owner</div>
        <div class="v"><?= h(TREASURY_OWNER) ?></div>

        <div class="k">EMX Raw Master</div>
        <div class="v"><?= h(EMX_JETTON_MASTER_RAW) ?></div>

        <div class="k">Amount Units</div>
        <div class="v"><?= h(AMOUNT_UNITS) ?></div>

        <div class="k">Amount</div>
        <div class="v">1 EMX</div>

        <div class="k">Text / Ref</div>
        <div class="v ok"><?= h($ref) ?></div>
      </div>

      <div class="actions">
        <a class="btn primary" href="<?= h($payload) ?>">Open Payload</a>
        <button type="button" onclick="copyText('payloadBox')">Copy Payload</button>
        <button type="button" onclick="copyText('refBox')">Copy Ref</button>
      </div>

      <textarea id="payloadBox" style="position:absolute;left:-99999px;top:-99999px"><?= h($payload) ?></textarea>
      <textarea id="refBox" style="position:absolute;left:-99999px;top:-99999px"><?= h($ref) ?></textarea>

      <hr>

      <h2>Quick Test Flow</h2>
      <div class="code">1. Fill From Address
2. Keep the shown Ref
3. Scan QR and send exactly 1 EMX
4. Click "Check Auto Verify"
5. If indexing is slow, click "Refresh Auto Confirm"</div>
    </div>

    <div class="card">
      <h2>Verify Result</h2>
      <?php if ($verifyResult === null): ?>
        <div class="note">No verification run yet. After sending EMX, click <strong>Check Auto Verify</strong> or <strong>Refresh Auto Confirm</strong>.</div>
      <?php else: ?>
        <?php
          $topOk = (bool)($verifyResult['ok'] ?? false);
          $parsed = $verifyResult['parsed'] ?? null;
          $parsedOk = is_array($parsed) ? (bool)($parsed['result']['ok'] ?? false) : false;
          $parsedCode = $parsedOk ? 'MATCH' : 'NO_MATCH';
        ?>
        <div class="note">
          PHP bridge: <?= $topOk ? '<span class="ok">OK</span>' : '<span class="bad">FAIL</span>' ?>
          <?php if (is_array($parsed)): ?>
            <br>Node result: <?= $parsedOk ? '<span class="ok">' . h($parsedCode) . '</span>' : '<span class="bad">' . h($parsedCode) . '</span>' ?>
          <?php endif; ?>
        </div>

        <h3>Parsed</h3>
        <div class="code"><?= h(json_encode($verifyResult['parsed'] ?? $verifyResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></div>

        <?php if (!empty($verifyResult['cmd'])): ?>
          <h3>Command</h3>
          <div class="code"><?= h($verifyResult['cmd']) ?></div>
        <?php endif; ?>

        <?php if (!empty($verifyResult['raw'])): ?>
          <h3>Raw Output</h3>
          <div class="code"><?= h($verifyResult['raw']) ?></div>
        <?php endif; ?>
      <?php endif; ?>

      <hr>

      <h2>Locked Test Values</h2>
      <div class="code">TREASURY_OWNER=<?= h(TREASURY_OWNER) . "\n" ?>
EMX_JETTON_MASTER_RAW=<?= h(EMX_JETTON_MASTER_RAW) . "\n" ?>
AMOUNT_UNITS=<?= h(AMOUNT_UNITS) . "\n" ?>
JS_FILE=<?= h(JS_FILE) . "\n" ?>
NODE_BIN=<?= h(NODE_BIN) ?></div>

      <div class="note">Standalone tester only. No production Storage files are amended by this tester.</div>
    </div>
  </div>
</div>

<script>
function copyText(id){
  const el = document.getElementById(id);
  el.select();
  el.setSelectionRange(0, 999999);
  document.execCommand('copy');
  alert('Copied');
}
</script>
</body>
</html>