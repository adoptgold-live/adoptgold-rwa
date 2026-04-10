<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/calc/index.php
 * RWA POS Big Calculator
 *
 * Added:
 * - assign adoptee by mobile_e164
 * - RWA adoption project dropdown
 * - default project = RK92-EMA
 * - all 8 R......-EMA options
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

if (!function_exists('rwa_db')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'DB helper missing';
    exit;
}

$pdo = rwa_db();

$user = function_exists('session_user') ? (session_user() ?: []) : [];
$session_wallet = (string)($user['wallet_address'] ?? $user['wallet'] ?? '');
$session_role   = (string)($user['role'] ?? '');

if ($session_wallet === '') {
    header('Location: /rwa/index.php');
    exit;
}

$csrf_calc = function_exists('csrf_token') ? csrf_token('rwa_calc_pos') : bin2hex(random_bytes(16));

$TON_TREASURY      = (string)(getenv('TON_TREASURY') ?: getenv('TREASURY_ADDRESS') ?: '');
$TON_JETTON_MASTER = (string)(getenv('TON_JETTON_MASTER') ?: '');
$TONCENTER_BASE    = (string)(getenv('TONCENTER_BASE') ?: 'https://toncenter.com/api/v3');

$projectOptions = [
    ['code'=>'RK92-EMA',   'label_en'=>'Gold',            'label_zh'=>'黄金',   'type_key'=>'gold'],
    ['code'=>'RCO2C-EMA',  'label_en'=>'Green',           'label_zh'=>'绿色',   'type_key'=>'green'],
    ['code'=>'RH2O-EMA',   'label_en'=>'Blue',            'label_zh'=>'蓝色',   'type_key'=>'blue'],
    ['code'=>'RBLACK-EMA', 'label_en'=>'Black',           'label_zh'=>'黑色',   'type_key'=>'black'],
    ['code'=>'RLIFE-EMA',  'label_en'=>'Health',          'label_zh'=>'健康',   'type_key'=>'health'],
    ['code'=>'RTRIP-EMA',  'label_en'=>'Travel',          'label_zh'=>'旅游',   'type_key'=>'travel'],
    ['code'=>'RPROP-EMA',  'label_en'=>'Property',        'label_zh'=>'房产',   'type_key'=>'property'],
    ['code'=>'RHRM-EMA',   'label_en'=>'Human Resources', 'label_zh'=>'人力资源', 'type_key'=>'human_resources'],
];

function jexit(array $x, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($x, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function table_exists(PDO $pdo, string $table): bool {
    try {
        $st = $pdo->prepare("
            SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :t
            LIMIT 1
        ");
        $st->execute([':t' => $table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function dec_to_units9(string $s): string {
    $s = trim($s);
    if ($s === '') return '0';
    if (!preg_match('/^\d+(\.\d+)?$/', $s)) return '0';
    $parts = explode('.', $s, 2);
    $a = $parts[0];
    $b = $parts[1] ?? '';
    $b = substr(str_pad($b, 9, '0'), 0, 9);
    $u = ltrim($a . $b, '0');
    return $u === '' ? '0' : $u;
}

function units9_to_dec(string $u): string {
    $u = ltrim($u, '0');
    if ($u === '' || !ctype_digit($u)) return '0';
    if (strlen($u) <= 9) $u = str_pad($u, 10, '0', STR_PAD_LEFT);
    $int = substr($u, 0, -9);
    $dec = substr($u, -9);
    $dec = rtrim($dec, '0');
    return $dec === '' ? $int : ($int . '.' . $dec);
}

function gen_uid(string $prefix): string {
    return $prefix . '-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
}

$QR_LIB = $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/qr.php';
if (file_exists($QR_LIB)) {
    require_once $QR_LIB;
}

function qr_data_uri(string $payload): string {
    if (function_exists('poado_qr_svg_data_uri')) {
        return (string)poado_qr_svg_data_uri($payload, 320, 10);
    }
    return '';
}

function normalize_digits(string $s): string {
    return preg_replace('/\D+/', '', $s) ?? '';
}

function find_user_by_mobile_e164(PDO $pdo, string $mobileE164): ?array {
    $mobileE164 = normalize_digits($mobileE164);
    if ($mobileE164 === '') return null;

    if (!table_exists($pdo, 'users')) return null;

    $sql = "
        SELECT id, nickname, wallet_address, mobile_e164, email, role
        FROM users
        WHERE mobile_e164 = :m
           OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(mobile,''), '+',''), '-', ''), ' ', ''), '(', ''), ')', '') = :m
        ORDER BY id ASC
        LIMIT 1
    ";
    $st = $pdo->prepare($sql);
    $st->execute([':m' => $mobileE164]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function project_map(array $projectOptions): array {
    $out = [];
    foreach ($projectOptions as $it) {
        $out[(string)$it['code']] = $it;
    }
    return $out;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    $action = (string)($_POST['action'] ?? '');

    if (function_exists('csrf_check') && !csrf_check('rwa_calc_pos', (string)($_POST['csrf'] ?? ''))) {
        jexit(['ok'=>false, 'error'=>'BAD_CSRF'], 400);
    }
    if ($session_wallet === '') {
        jexit(['ok'=>false, 'error'=>'NO_SESSION'], 401);
    }

    if ($action === 'ping') {
        $missing = [];
        if ($TON_TREASURY === '') $missing[] = 'TON_TREASURY';
        if ($TON_JETTON_MASTER === '') $missing[] = 'TON_JETTON_MASTER';
        if ($missing) {
            jexit(['ok'=>false, 'error'=>'ENV_MISSING', 'missing'=>$missing, 'base'=>$TONCENTER_BASE]);
        }
        jexit([
            'ok'=>true,
            'base'=>$TONCENTER_BASE,
            'treasury'=>$TON_TREASURY,
            'jetton_master'=>$TON_JETTON_MASTER
        ]);
    }

    if ($action === 'lookup_adoptee') {
        $mobileE164 = normalize_digits((string)($_POST['mobile_e164'] ?? ''));
        if ($mobileE164 === '') {
            jexit(['ok'=>true, 'found'=>false, 'mobile_e164'=>'']);
        }

        $adoptee = find_user_by_mobile_e164($pdo, $mobileE164);
        if (!$adoptee) {
            jexit([
                'ok'=>true,
                'found'=>false,
                'mobile_e164'=>$mobileE164,
            ]);
        }

        jexit([
            'ok'=>true,
            'found'=>true,
            'mobile_e164'=>$mobileE164,
            'adoptee'=>[
                'id'=>(int)($adoptee['id'] ?? 0),
                'nickname'=>(string)($adoptee['nickname'] ?? ''),
                'wallet_address'=>(string)($adoptee['wallet_address'] ?? ''),
                'email'=>(string)($adoptee['email'] ?? ''),
                'role'=>(string)($adoptee['role'] ?? ''),
            ]
        ]);
    }

    if ($action === 'create_deal') {
        $main = trim((string)($_POST['main'] ?? '0'));
        $tips = trim((string)($_POST['tips'] ?? '0'));
        $email = trim((string)($_POST['customer_email'] ?? ''));
        $mobile_e164 = normalize_digits((string)($_POST['customer_mobile_e164'] ?? ''));
        $project_code = trim((string)($_POST['project_code'] ?? 'RK92-EMA'));

        $projectMap = project_map($projectOptions);
        if (!isset($projectMap[$project_code])) {
            jexit(['ok'=>false, 'error'=>'BAD_PROJECT'], 400);
        }
        $project = $projectMap[$project_code];

        if ($email === '' && $mobile_e164 === '') {
            jexit(['ok'=>false,'error'=>'CONTACT_REQUIRED','msg'=>'Enter mobile or email'], 400);
        }

        if ($main === '' || !preg_match('/^\d+(\.\d+)?$/', $main)) jexit(['ok'=>false,'error'=>'BAD_MAIN'], 400);
        if ($tips === '' || !preg_match('/^\d+(\.\d+)?$/', $tips)) jexit(['ok'=>false,'error'=>'BAD_TIPS'], 400);

        $main_units = dec_to_units9($main);
        $tips_units = dec_to_units9($tips);
        if ($main_units === '0') jexit(['ok'=>false,'error'=>'MAIN_REQUIRED'], 400);

        $tips_trim = ltrim($tips_units, '0');
        if ($tips_trim === '') $tips_trim = '0';
        $digits = str_split(strrev($tips_trim));
        $carry = 0;
        $out = [];
        foreach ($digits as $d) {
            $v = ((int)$d) * 4 + $carry;
            $out[] = (string)($v % 10);
            $carry = intdiv($v, 10);
        }
        while ($carry > 0) {
            $out[] = (string)($carry % 10);
            $carry = intdiv($carry, 10);
        }
        $tips_x4 = ltrim(strrev(implode('', $out)), '0');
        if ($tips_x4 === '') $tips_x4 = '0';

        $main_trim = ltrim($main_units, '0');
        if ($main_trim === '') $main_trim = '0';

        $capFail = (strlen($tips_x4) > strlen($main_trim)) || (strlen($tips_x4) === strlen($main_trim) && strcmp($tips_x4, $main_trim) > 0);
        if ($capFail) jexit(['ok'=>false,'error'=>'TIPS_CAP','msg'=>'Tips max 25%'], 400);

        $required_units = $main_units;
        $deal_uid = gen_uid('POS');

        $payload = 'ton://transfer/' . $TON_TREASURY
            . '?jetton=' . rawurlencode($TON_JETTON_MASTER)
            . '&amount=' . rawurlencode($required_units)
            . '&text=' . rawurlencode($deal_uid);

        $adoptee = $mobile_e164 !== '' ? find_user_by_mobile_e164($pdo, $mobile_e164) : null;

        $meta = [
            'calc' => [
                'customer_email' => $email,
                'customer_mobile_e164' => $mobile_e164,
                'tips_model' => 'v2_main_only_customer_pays',
                'tips_cap' => '25%',
                'project_code' => $project_code,
                'project_label_en' => (string)$project['label_en'],
                'project_label_zh' => (string)$project['label_zh'],
                'project_type_key' => (string)$project['type_key'],
                'assigned_adoptee' => $adoptee ? [
                    'id'=>(int)($adoptee['id'] ?? 0),
                    'nickname'=>(string)($adoptee['nickname'] ?? ''),
                    'wallet_address'=>(string)($adoptee['wallet_address'] ?? ''),
                    'email'=>(string)($adoptee['email'] ?? ''),
                    'mobile_e164'=>$mobile_e164,
                ] : null,
            ],
            'payment' => [
                'payload' => $payload,
                'required_units' => $required_units,
                'decimals' => 9,
                'jetton_master' => $TON_JETTON_MASTER,
                'treasury' => $TON_TREASURY,
            ],
        ];

        $inserted = false;
        if (table_exists($pdo, 'poado_pos_deals')) {
            try {
                $st = $pdo->prepare("
                    INSERT INTO poado_pos_deals
                        (deal_uid, wallet, treasury, jetton_master, decimals,
                         amount_main_units, amount_tips_units, amount_total_units,
                         salt_units, required_units, status, meta, created_at)
                    VALUES
                        (:deal_uid, :wallet, :treasury, :jetton, :decimals,
                         :main_u, :tips_u, :total_u,
                         0, :required_u, 'pending', :meta, CURRENT_TIMESTAMP)
                ");
                $st->execute([
                    ':deal_uid' => $deal_uid,
                    ':wallet' => $session_wallet,
                    ':treasury' => $TON_TREASURY,
                    ':jetton' => $TON_JETTON_MASTER,
                    ':decimals' => 9,
                    ':main_u' => $main_units,
                    ':tips_u' => $tips_units,
                    ':total_u' => $main_units,
                    ':required_u' => $required_units,
                    ':meta' => json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]);
                $inserted = true;
            } catch (Throwable $e) {
                $inserted = false;
            }
        }

        $_SESSION['rwa_calc_pos'] = [
            'deal_uid' => $deal_uid,
            'required_units' => $required_units,
            'payload' => $payload,
            'main' => $main,
            'tips' => units9_to_dec($tips_units),
            'email' => $email,
            'mobile_e164' => $mobile_e164,
            'project_code' => $project_code,
            'project_label_en' => (string)$project['label_en'],
            'created_at' => time(),
        ];

        jexit([
            'ok'=>true,
            'deal_uid'=>$deal_uid,
            'required_units'=>$required_units,
            'payload'=>$payload,
            'qr'=>qr_data_uri($payload),
            'main'=>$main,
            'tips'=>units9_to_dec($tips_units),
            'project_code'=>$project_code,
            'project_label_en'=>(string)$project['label_en'],
            'project_label_zh'=>(string)$project['label_zh'],
            'assigned_adoptee'=>$adoptee ? [
                'id'=>(int)($adoptee['id'] ?? 0),
                'nickname'=>(string)($adoptee['nickname'] ?? ''),
                'wallet_address'=>(string)($adoptee['wallet_address'] ?? ''),
                'email'=>(string)($adoptee['email'] ?? ''),
                'mobile_e164'=>$mobile_e164,
            ] : null,
            'stored'=>$inserted,
        ]);
    }

    if ($action === 'last5') {
        if (!table_exists($pdo, 'poado_pos_deals')) {
            jexit(['ok'=>true, 'rows'=>[]]);
        }

        $st = $pdo->prepare("
            SELECT deal_uid, status, amount_main_units, amount_tips_units, tx_hash, paid_at, created_at, meta
            FROM poado_pos_deals
            ORDER BY id DESC
            LIMIT 5
        ");
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$r) {
            $r['main'] = units9_to_dec((string)($r['amount_main_units'] ?? '0'));
            $r['tips'] = units9_to_dec((string)($r['amount_tips_units'] ?? '0'));
            $r['tx_short'] = isset($r['tx_hash']) && is_string($r['tx_hash']) && $r['tx_hash'] !== ''
                ? (substr($r['tx_hash'], 0, 8) . '…' . substr($r['tx_hash'], -8))
                : '';

            $meta = json_decode((string)($r['meta'] ?? '{}'), true);
            $r['project_code'] = (string)($meta['calc']['project_code'] ?? '');
        }
        unset($r);

        jexit(['ok'=>true, 'rows'=>$rows]);
    }

    jexit(['ok'=>false, 'error'=>'UNKNOWN_ACTION'], 400);
}

$wallet_short = substr($session_wallet, 0, 6) . '…' . substr($session_wallet, -6);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>RWA Calc</title>
<style>
  :root{
    --bg:#050507;
    --line:rgba(214,180,90,.28);
    --mut:#d7caa1;
    --ok:#49ff7a;
    --bad:#ff6666;
  }
  html,body{height:100%}
  body{margin:0;background:var(--bg);color:#fff6d6;font-family:ui-monospace,Menlo,Consolas,monospace}
  .wrap{max-width:1240px;margin:0 auto;padding:12px}
  .hdr{border:1px solid var(--line);background:rgba(214,180,90,.06);border-radius:14px;padding:10px 12px;display:flex;gap:10px;flex-wrap:wrap;align-items:center}
  .chip{border:1px solid var(--line);background:rgba(214,180,90,.06);border-radius:999px;padding:6px 10px;font-size:12px;color:var(--mut)}
  .grid{display:grid;grid-template-columns:1.3fr .7fr;gap:12px;margin-top:12px}
  @media(max-width:980px){.grid{grid-template-columns:1fr}}
  .card{border:1px solid var(--line);background:rgba(214,180,90,.05);border-radius:16px;padding:12px;box-shadow:0 0 18px rgba(214,180,90,.10)}
  .big{font-size:42px;line-height:1.05;font-weight:900;letter-spacing:.5px;text-align:right;padding:16px;border-radius:14px;border:1px solid rgba(214,180,90,.35);background:rgba(0,0,0,.45);color:#fff}
  .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
  .lbl{font-size:12px;color:var(--mut)}
  .in{width:100%;box-sizing:border-box;border:1px solid rgba(214,180,90,.25);background:#07070a;color:#fff;border-radius:12px;padding:12px 12px;font-size:16px;outline:none}
  .btn{user-select:none;border:1px solid rgba(214,180,90,.35);background:rgba(214,180,90,.12);color:#fff;border-radius:14px;padding:10px 12px;font-weight:900;cursor:pointer}
  .btn:active{transform:scale(.99)}
  .btn2{border:1px solid rgba(214,180,90,.22);background:rgba(214,180,90,.08)}
  .kpad{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:12px}
  .key{border:1px solid rgba(214,180,90,.35);background:rgba(214,180,90,.10);border-radius:16px;padding:16px 10px;text-align:center;font-weight:900;font-size:18px;cursor:pointer}
  .key:active{transform:scale(.99)}
  .key.wide{grid-column:span 2}
  .stat{border:1px dashed rgba(214,180,90,.25);border-radius:14px;padding:10px;background:rgba(0,0,0,.35);min-height:56px}
  .ok{color:var(--ok);font-weight:900}
  .bad{color:var(--bad);font-weight:900}
  .mini{font-size:12px;color:var(--mut)}
  table{width:100%;border-collapse:collapse;font-size:12px}
  th,td{padding:8px;border-bottom:1px solid rgba(214,180,90,.15);text-align:left}
  .back{position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:rgba(0,0,0,.75);padding:14px;z-index:50}
  .back.show{display:flex}
  .modal{width:min(760px,96vw);border:1px solid rgba(214,180,90,.35);background:#07070a;border-radius:16px;padding:12px;box-shadow:0 0 24px rgba(214,180,90,.18)}
  .mhead{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px}
  .qrWrap{display:flex;gap:12px;flex-wrap:wrap}
  .qrBox{background:#fff;border-radius:14px;padding:10px}
  .qrBox img{display:block;max-width:320px;width:320px;height:auto}
  pre{margin:0;white-space:pre-wrap;word-break:break-word;font-size:12px;background:rgba(0,0,0,.35);border:1px dashed rgba(214,180,90,.25);border-radius:12px;padding:10px;color:#fff}
  .shine{animation:shine 2.2s linear infinite}
  @keyframes shine{
    0%{box-shadow:0 0 10px rgba(214,180,90,.10)}
    50%{box-shadow:0 0 22px rgba(214,180,90,.22)}
    100%{box-shadow:0 0 10px rgba(214,180,90,.10)}
  }
  .countryName{font-weight:900;color:#fff}
</style>
</head>
<body>

<div class="wrap">
  <div class="hdr">
    <div class="chip">WALLET: <?= htmlspecialchars($wallet_short, ENT_QUOTES, 'UTF-8') ?></div>
    <div class="chip">ROLE: <?= htmlspecialchars($session_role ?: '-', ENT_QUOTES, 'UTF-8') ?></div>
    <div class="chip">TREASURY: <?= htmlspecialchars($TON_TREASURY ?: '-', ENT_QUOTES, 'UTF-8') ?></div>
    <div class="chip">JETTON: <?= htmlspecialchars($TON_JETTON_MASTER ?: '-', ENT_QUOTES, 'UTF-8') ?></div>
    <button type="button" class="btn btn2" id="btnBackHome" onclick="window.location.href='/rwa/login-select.php'">Back to Home</button>
  </div>

  <div class="grid">
    <div class="card">
      <div class="row" style="justify-content:space-between;align-items:flex-end;">
        <div style="flex:1;min-width:220px;">
          <div class="lbl">Main (M)</div>
          <div class="big" id="dispMain">0</div>
        </div>
        <div style="flex:1;min-width:220px;">
          <div class="lbl">Tips (T)</div>
          <div class="big" id="dispTips">0</div>
        </div>
      </div>

      <div class="row" style="margin-top:10px;">
        <button class="btn btn2" id="btnPing">Ping</button>
        <button class="btn" id="btnLookup">Assign Adoptee</button>
        <button class="btn" id="btnCreate">Create Deal</button>
        <button class="btn btn2" id="btnQR">QR</button>
        <button class="btn btn2" id="btnAuto">Auto Confirm</button>
        <button class="btn btn2" id="btnPrint">Print</button>
      </div>

      <div class="kpad" aria-label="keypad">
        <div class="key" data-k="7">7</div><div class="key" data-k="8">8</div><div class="key" data-k="9">9</div><div class="key" data-k="Backspace">⌫</div>
        <div class="key" data-k="4">4</div><div class="key" data-k="5">5</div><div class="key" data-k="6">6</div><div class="key" data-k="M">M</div>
        <div class="key" data-k="1">1</div><div class="key" data-k="2">2</div><div class="key" data-k="3">3</div><div class="key" data-k="T">T</div>
        <div class="key" data-k="0">0</div><div class="key" data-k=".">.</div><div class="key" data-k="C">C</div><div class="key" data-k="Enter">Enter</div>
        <div class="key wide" data-k="Esc">Esc</div><div class="key wide" data-k="P">P</div>
      </div>

      <div class="stat" style="margin-top:12px;">
        <div class="mini">Total Pay = Main</div>
        <div class="mini">Treasury = Main - Tips</div>
        <div class="mini" id="calcLine">0</div>
      </div>

      <div class="stat" style="margin-top:10px;" id="statusBox">Ready</div>

      <div class="card" style="margin-top:12px;">
        <div style="font-weight:900;margin-bottom:8px;">Last 5</div>
        <div style="overflow:auto;">
          <table>
            <thead><tr><th>Time</th><th>Deal</th><th>Project</th><th>Status</th><th>Main</th></tr></thead>
            <tbody id="last5"></tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="card">
      <div style="font-weight:900;margin-bottom:10px;">Customer</div>

      <div class="lbl">Mobile</div>
      <div class="row" style="gap:8px;align-items:center;">
        <img id="flagImg" src="/rwa/assets/flags/my.png" alt="flag" style="width:28px;height:20px;border-radius:4px;object-fit:cover;border:1px solid rgba(214,180,90,.25)">
        <div class="countryName" id="countryName">Malaysia</div>
        <select id="prefixSel" class="in" style="max-width:160px;">
          <option value="">+--</option>
        </select>
      </div>

      <div style="height:8px"></div>
      <input id="mobileIn" class="in" inputmode="numeric" placeholder="mobile digits" maxlength="15" autocomplete="off">
      <div class="mini" id="e164Line" style="margin-top:6px;">e164: -</div>

      <div style="height:10px"></div>
      <div class="lbl">Email</div>
      <input id="emailIn" class="in" type="email" placeholder="email (optional)" autocomplete="off">
      <div class="mini" id="emailHint" style="margin-top:6px;"></div>

      <div style="height:12px"></div>
      <div class="lbl">RWA Adoption Project</div>
      <select id="projectSel" class="in">
        <?php foreach ($projectOptions as $opt): ?>
          <option value="<?= h($opt['code']) ?>" <?= $opt['code'] === 'RK92-EMA' ? 'selected' : '' ?>>
            <?= h($opt['code']) ?> - <?= h($opt['label_en']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <div style="height:12px"></div>
      <div style="font-weight:900;margin-bottom:8px;">Assigned Adoptee</div>
      <pre id="adopteeInfo">None</pre>

      <div style="height:12px"></div>
      <div style="font-weight:900;margin-bottom:8px;">Deal</div>
      <pre id="dealInfo">None</pre>
    </div>
  </div>
</div>

<div class="back" id="qrBack">
  <div class="modal">
    <div class="mhead">
      <div style="font-weight:900;">Pay</div>
      <button class="btn btn2" id="btnCloseQR">Close</button>
    </div>
    <div class="mini" id="qrTitle">-</div>
    <div class="qrWrap" style="margin-top:10px;">
      <div class="qrBox" id="qrBox"></div>
      <div style="flex:1;min-width:260px;"><pre id="qrText">-</pre></div>
    </div>
    <div style="margin-top:10px;"><div class="stat" id="payStatus">Waiting</div></div>
  </div>
</div>

<div class="back" id="rcBack">
  <div class="modal">
    <div class="mhead">
      <div style="font-weight:900;">Receipt</div>
      <button class="btn btn2" id="btnCloseRC">Close</button>
    </div>
    <pre id="rcText">-</pre>
    <div class="row" style="margin-top:10px;justify-content:flex-end;">
      <button class="btn" id="btnDoPrint">Print</button>
    </div>
  </div>
</div>

<input type="hidden" id="csrfCalc" value="<?= htmlspecialchars($csrf_calc, ENT_QUOTES, 'UTF-8') ?>">

<script>
(() => {
  'use strict';

  const $ = (s) => document.querySelector(s);

  const csrfCalc = $('#csrfCalc').value;

  let active = 'M';
  let main = '0';
  let tips = '0';
  let currentDeal = null;
  let currentAdoptee = null;

  let pollTimer = null;
  let pollBusy = false;

  const dispMain = $('#dispMain');
  const dispTips = $('#dispTips');
  const statusBox = $('#statusBox');
  const calcLine = $('#calcLine');
  const dealInfo = $('#dealInfo');
  const adopteeInfo = $('#adopteeInfo');

  const qrBack = $('#qrBack');
  const qrBox  = $('#qrBox');
  const qrText = $('#qrText');
  const qrTitle= $('#qrTitle');
  const payStatus = $('#payStatus');

  const rcBack = $('#rcBack');
  const rcText = $('#rcText');

  const prefixSel = $('#prefixSel');
  const flagImg = $('#flagImg');
  const countryNameEl = $('#countryName');
  const mobileIn = $('#mobileIn');
  const emailIn = $('#emailIn');
  const emailHint = $('#emailHint');
  const e164Line = $('#e164Line');
  const projectSel = $('#projectSel');

  function esc(s){
    return String(s ?? '').replace(/[&<>"']/g, (m)=>({ "&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#039;" }[m]));
  }

  function setStatus(t, ok=null) {
    if (ok === true) {
      statusBox.innerHTML = `<span class="ok">${esc(t)}</span>`;
    } else if (ok === false) {
      statusBox.innerHTML = `<span class="bad">${esc(t)}</span>`;
    } else {
      statusBox.textContent = String(t);
    }
  }

  function fmt(x){
    if (!x || x === '') return '0';
    if (!/^\d+(\.\d+)?$/.test(x)) return '0';
    const n = Number(x);
    if (!isFinite(n)) return '0';
    return (Math.round(n * 1e6) / 1e6).toString();
  }

  function updateDisplay(){
    dispMain.textContent = fmt(main);
    dispTips.textContent = fmt(tips);

    const m = Number(main)||0;
    const t = Number(tips)||0;
    const tre = Math.max(0, m - t);
    calcLine.textContent = `Pay: ${fmt(main)} | Treasury: ${fmt(String(tre))} | Tips: ${fmt(tips)} | Mode: ${active}`;
  }

  function isValidEmail(s){
    s = String(s || '').trim();
    if (!s) return true;
    return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(s);
  }

  function updateEmailHint(){
    const v = (emailIn.value || '').trim();
    if (!v) { emailHint.textContent = ''; return; }
    if (isValidEmail(v)) emailHint.innerHTML = '<span class="ok">Email OK</span>';
    else emailHint.innerHTML = '<span class="bad">Email invalid</span>';
  }
  emailIn.addEventListener('input', updateEmailHint);

  function updateE164(){
    const code = prefixSel.value || '';
    const mobile = (mobileIn.value || '').replace(/\D/g,'').slice(0,15);
    mobileIn.value = mobile;

    if (!code || !mobile) {
      e164Line.textContent = 'e164: -';
      return '';
    }
    const cc = code.replace(/\D/g,'');
    const e164 = (cc + mobile).replace(/\D/g,'');
    e164Line.textContent = 'e164: ' + e164;
    return e164;
  }

  function isContactFocus(){
    const el = document.activeElement;
    return el === mobileIn || el === emailIn || el === prefixSel || el === projectSel;
  }

  async function post(action, data={}){
    const fd = new FormData();
    fd.append('action', action);
    fd.append('csrf', csrfCalc);
    Object.keys(data).forEach(k => fd.append(k, data[k]));
    const r = await fetch(location.pathname, { method:'POST', body: fd, credentials: 'same-origin' });
    const txt = await r.text();
    if (txt.trim().startsWith('<')) throw new Error('API returned HTML');
    return JSON.parse(txt);
  }

  async function loadPrefixes(){
    try{
      const r = await fetch('/rwa/api/geo/prefixes.php', { credentials:'same-origin' });
      const txt = await r.text();
      if (txt.trim().startsWith('<')) throw new Error('prefixes api returned HTML');
      const j = JSON.parse(txt);

      const rows = Array.isArray(j) ? j : (Array.isArray(j.data) ? j.data : []);
      prefixSel.innerHTML = '<option value="">+--</option>';

      for (const it of rows) {
        const iso2 = String(it.iso2 || it.country_iso2 || '').toLowerCase() || 'my';
        const code = String(it.calling_code || it.prefix || it.code || '').replace(/^\+/, '');
        if (!code) continue;

        const label =
          String(it.name_en || it.country_name || it.label || it.name || '').trim() ||
          iso2.toUpperCase();

        const opt = document.createElement('option');
        opt.value = '+' + code;
        opt.textContent = '+' + code;
        opt.dataset.iso2 = iso2;
        opt.dataset.label = label;
        prefixSel.appendChild(opt);
      }

      for (const opt of prefixSel.options) {
        if (opt.value === '+60') { prefixSel.value = '+60'; break; }
      }

      setCountryUIFromPrefix();
      updateE164();
    } catch (e){
      prefixSel.innerHTML = '<option value="+60">+60</option>';
      prefixSel.value = '+60';
      setCountryUIFromPrefix();
      updateE164();
      setStatus('Prefix load fallback', false);
    }
  }

  function setCountryUIFromPrefix(){
    const opt = prefixSel.options[prefixSel.selectedIndex];
    const iso2 = (opt && opt.dataset && opt.dataset.iso2) ? opt.dataset.iso2 : 'my';
    const label = (opt && opt.dataset && opt.dataset.label) ? opt.dataset.label : '-';

    countryNameEl.textContent = label;
    flagImg.src = `/rwa/assets/flags/${iso2}.png`;
    flagImg.onerror = () => { flagImg.src = '/rwa/assets/flags/my.png'; };
  }

  prefixSel.addEventListener('change', () => { setCountryUIFromPrefix(); updateE164(); });
  mobileIn.addEventListener('input', () => updateE164());

  function renderAdoptee(adoptee){
    currentAdoptee = adoptee || null;
    if (!adoptee) {
      adopteeInfo.textContent = 'None';
      return;
    }
    adopteeInfo.textContent =
      `ID: ${adoptee.id || '-'}\n` +
      `Nickname: ${adoptee.nickname || '-'}\n` +
      `Wallet: ${adoptee.wallet_address || '-'}\n` +
      `Email: ${adoptee.email || '-'}\n` +
      `Mobile E164: ${adoptee.mobile_e164 || updateE164() || '-'}`;
  }

  async function lookupAdoptee(){
    const e164 = updateE164();
    if (!e164) {
      renderAdoptee(null);
      setStatus('Enter mobile first', false);
      return null;
    }
    setStatus('Looking up adoptee...');
    try {
      const j = await post('lookup_adoptee', { mobile_e164: e164 });
      if (j.ok && j.found && j.adoptee) {
        j.adoptee.mobile_e164 = j.mobile_e164 || e164;
        renderAdoptee(j.adoptee);
        setStatus('Adoptee assigned by mobile_e164', true);
        return j.adoptee;
      }
      renderAdoptee(null);
      setStatus('No adoptee match for mobile_e164', false);
      return null;
    } catch (e) {
      renderAdoptee(null);
      setStatus('Lookup error', false);
      return null;
    }
  }

  function stopPoll(){
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    pollBusy = false;
  }

  async function silentConfirmOnce(forceMsg=false){
    if (!currentDeal) { if(forceMsg) setStatus('Create deal first', false); return; }
    if (pollBusy) return;
    pollBusy = true;

    try{
      const fd = new FormData();
      fd.append('deal_uid', currentDeal.deal_uid);
      fd.append('csrf_token', csrfCalc);

      const r = await fetch('/dashboard/payment/confirm-payment.php', { method:'POST', body: fd, credentials:'same-origin' });
      const txt = await r.text();
      if (txt.trim().startsWith('<')) throw new Error('confirm returned HTML');
      const j = JSON.parse(txt);

      if (j.ok) {
        payStatus.innerHTML = '<span class="ok shine">PAID</span>';
        setStatus('Paid', true);

        dealInfo.textContent =
          `Deal: ${currentDeal.deal_uid}\n` +
          `Project: ${currentDeal.project_code || '-'}\n` +
          `Paid: yes\n` +
          `Tx: ${(j.tx_hash || '')}\n` +
          `EMA$: ${(j.ema_amount || '')}\n`;

        await refreshLast5();
        buildReceipt(j);
        stopPoll();
      } else {
        payStatus.innerHTML = '<span class="bad">Not paid</span>';
        if (forceMsg) setStatus(j.error || 'Not paid', false);
      }
    } catch(e){
      payStatus.innerHTML = '<span class="bad">Error</span>';
      if (forceMsg) setStatus('Verify error', false);
    } finally {
      pollBusy = false;
    }
  }

  function startPoll(){
    stopPoll();
    silentConfirmOnce(false);
    pollTimer = setInterval(() => silentConfirmOnce(false), 5000);
  }

  function openQR(){
    if (!currentDeal) return;

    qrTitle.textContent = `Deal: ${currentDeal.deal_uid} | Project: ${currentDeal.project_code || '-'} | Units: ${currentDeal.required_units}`;
    qrBox.innerHTML = '';

    if (currentDeal.qr) {
      const img = document.createElement('img');
      img.src = currentDeal.qr;
      qrBox.appendChild(img);
    } else {
      qrBox.textContent = 'QR error';
    }

    qrText.textContent = currentDeal.payload || '';
    payStatus.textContent = 'Waiting';

    qrBack.classList.add('show');
    startPoll();
  }

  function closeQR(){
    qrBack.classList.remove('show');
    stopPoll();
  }

  function openRC(){ rcBack.classList.add('show'); }
  function closeRC(){ rcBack.classList.remove('show'); }

  function resetAll(){
    stopPoll();
    main = '0';
    tips = '0';
    active = 'M';
    currentDeal = null;
    renderAdoptee(null);
    dealInfo.textContent = 'None';
    projectSel.value = 'RK92-EMA';
    setStatus('Ready');
    closeQR();
    closeRC();
    updateE164();
    updateDisplay();
  }

  function appendChar(ch){
    let v = (active === 'M') ? main : tips;
    if (ch === '.') {
      if (v.includes('.')) return;
      v = v === '' ? '0.' : (v + '.');
    } else {
      if (!/^\d$/.test(ch)) return;
      if (v === '0') v = ch;
      else v = v + ch;
    }
    if (active === 'M') main = v; else tips = v;
    updateDisplay();
  }

  function backspace(){
    let v = (active === 'M') ? main : tips;
    if (v.length <= 1) v = '0';
    else v = v.slice(0, -1);
    if (v === '' || v === '-') v = '0';
    if (active === 'M') main = v; else tips = v;
    updateDisplay();
  }

  function clearActive(){
    if (active === 'M') main = '0'; else tips = '0';
    updateDisplay();
  }

  function tipsCapOk(){
    const m = Number(main)||0;
    const t = Number(tips)||0;
    return t <= (m * 0.25 + 1e-9);
  }

  async function createDeal(showQr=true){
    const m = Number(main)||0;
    if (m <= 0) { setStatus('Main required', false); return null; }
    if (!tipsCapOk()) { setStatus('Tips max 25%', false); return null; }

    const e164 = updateE164();
    const email = (emailIn.value || '').trim();
    const projectCode = projectSel.value || 'RK92-EMA';

    if (!e164 && !email) { setStatus('Enter mobile or email', false); return null; }
    if (!isValidEmail(email)) { setStatus('Email invalid', false); return null; }

    if (e164 && !currentAdoptee) {
      await lookupAdoptee();
    }

    setStatus('Creating...');
    try{
      const j = await post('create_deal', {
        main: fmt(main),
        tips: fmt(tips),
        customer_email: email,
        customer_mobile_e164: e164,
        project_code: projectCode
      });

      if (!j.ok) { setStatus(j.msg || j.error || 'Create failed', false); return null; }

      currentDeal = j;
      if (j.assigned_adoptee) {
        renderAdoptee(j.assigned_adoptee);
      }

      dealInfo.textContent =
        `Deal: ${j.deal_uid}\n` +
        `Project: ${j.project_code || '-'}\n` +
        `Project EN: ${j.project_label_en || '-'}\n` +
        `Units: ${j.required_units}\n` +
        `Stored: ${j.stored ? 'yes' : 'no'}`;

      setStatus('Deal ready', true);

      await refreshLast5();
      if (showQr) openQR();
      return j;
    } catch(e){
      setStatus('Create error', false);
      return null;
    }
  }

  function buildReceipt(confirmJson=null){
    const m = fmt(main);
    const t = fmt(tips);
    const tre = (Math.max(0, (Number(m)||0) - (Number(t)||0))).toString();
    const d = currentDeal ? currentDeal.deal_uid : '-';
    const units = currentDeal ? currentDeal.required_units : '-';
    const projectCode = currentDeal ? (currentDeal.project_code || '-') : (projectSel.value || '-');

    let out = '';
    out += `DEAL: ${d}\n`;
    out += `PROJECT: ${projectCode}\n`;
    out += `MAIN: ${m} EMX\n`;
    out += `TIPS: ${t} EMX\n`;
    out += `TREASURY: ${fmt(tre)} EMX\n`;
    out += `UNITS: ${units}\n`;

    if (currentAdoptee) {
      out += `ADOPTEE ID: ${currentAdoptee.id || '-'}\n`;
      out += `ADOPTEE: ${currentAdoptee.nickname || '-'}\n`;
      out += `ADOPTEE WALLET: ${currentAdoptee.wallet_address || '-'}\n`;
    }

    if (confirmJson && confirmJson.ok) {
      out += `PAID: YES\n`;
      out += `TX: ${(confirmJson.tx_hash || '')}\n`;
      out += `EMA$: ${(confirmJson.ema_amount || '')}\n`;
      out += `EMA$ PRICE: ${(confirmJson.ema_price_emx || '')}\n`;
    } else {
      out += `PAID: NO\n`;
    }
    rcText.textContent = out;
  }

  async function refreshLast5(){
    try{
      const j = await post('last5');
      const tb = $('#last5');
      tb.innerHTML = '';
      const rows = (j.rows || []);
      for (const r of rows) {
        const tr = document.createElement('tr');
        tr.innerHTML =
          `<td>${esc(String((r.paid_at || r.created_at || '')).slice(0,19))}</td>` +
          `<td>${esc(String(r.deal_uid || '').slice(0,12))}</td>` +
          `<td>${esc(r.project_code || '')}</td>` +
          `<td>${esc(r.status || '')}</td>` +
          `<td>${esc(r.main || '')}</td>`;
        tb.appendChild(tr);
      }
    } catch(e){}
  }

  $('#btnPing').onclick = async () => {
    setStatus('Ping...');
    try {
      const j = await post('ping');
      if (j.ok) setStatus('OK', true);
      else setStatus('Env missing', false);
    } catch(e){
      setStatus('Ping error', false);
    }
  };

  $('#btnLookup').onclick = async () => { await lookupAdoptee(); };
  $('#btnCreate').onclick = () => createDeal(true);
  $('#btnQR').onclick = async () => { if (!currentDeal) await createDeal(true); else openQR(); };
  $('#btnAuto').onclick = async () => { await silentConfirmOnce(true); };

  $('#btnCloseQR').onclick = closeQR;
  qrBack.addEventListener('click', (e)=>{ if (e.target === qrBack) closeQR(); });

  $('#btnPrint').onclick = () => { buildReceipt(null); openRC(); };
  $('#btnCloseRC').onclick = closeRC;
  rcBack.addEventListener('click', (e)=>{ if (e.target === rcBack) closeRC(); });

  $('#btnDoPrint').onclick = () => {
    const w = window.open('', '_blank');
    if (!w) return;
    const html = esc(rcText.textContent).replace(/\n/g,'<br>');
    w.document.write(`<html><head><title>Receipt</title></head><body style="font-family:monospace;background:#fff;color:#000;padding:14px;">${html}</body></html>`);
    w.document.close();
    w.focus();
    w.print();
  };

  document.addEventListener('keydown', async (e) => {
    if (isContactFocus()) return;
    const k = e.key;

    if (k === 'Escape') { e.preventDefault(); resetAll(); return; }
    if (k === 'Backspace') { e.preventDefault(); backspace(); return; }
    if (k === 'Enter') { e.preventDefault(); await createDeal(true); return; }

    if (k === 'm' || k === 'M') { e.preventDefault(); active='M'; updateDisplay(); return; }
    if (k === 't' || k === 'T') { e.preventDefault(); active='T'; updateDisplay(); return; }
    if (k === 'c' || k === 'C') { e.preventDefault(); clearActive(); return; }
    if (k === 'p' || k === 'P') { e.preventDefault(); buildReceipt(null); openRC(); return; }

    if (/^\d$/.test(k)) { e.preventDefault(); appendChar(k); return; }
    if (k === '.') { e.preventDefault(); appendChar('.'); return; }
  });

  document.querySelectorAll('.key').forEach(btn => {
    btn.addEventListener('click', async () => {
      const k = btn.dataset.k;
      if (!k) return;

      if (k === 'Esc') { resetAll(); return; }
      if (k === 'Backspace') { backspace(); return; }
      if (k === 'Enter') { await createDeal(true); return; }
      if (k === 'M') { active='M'; updateDisplay(); return; }
      if (k === 'T') { active='T'; updateDisplay(); return; }
      if (k === 'C') { clearActive(); return; }
      if (k === 'P') { buildReceipt(null); openRC(); return; }

      if (/^\d$/.test(k)) appendChar(k);
      else if (k === '.') appendChar('.');
    });
  });

  updateDisplay();
  loadPrefixes();
  refreshLast5();
  updateEmailHint();
})();
</script>

</body>
</html>
