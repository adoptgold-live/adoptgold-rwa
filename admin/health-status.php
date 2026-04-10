<?php
declare(strict_types=1);

/**
 * /rwa/admin/health-status.php
 * v1.0.20260304-admin-health-status
 *
 * Stage 1:
 * - Admin can set RLIFE health LEDs (sugar/heart/bmi) for any user
 * - No EMA transfers, no reward logic, no new tables
 * - Persist into users.meta JSON (wems_db)
 */

require __DIR__ . '/../inc/rwa-session.php';
require __DIR__ . '/../../dashboard/inc/session-user.php';
require __DIR__ . '/../../dashboard/inc/gt.php';
require __DIR__ . '/../inc/chain-indicator.php';

// --- Admin guard (minimal, strict) ---
// We assume session-user.php sets user session + user_id.
$admin_user_id = (int)($_SESSION['user_id'] ?? 0);
if ($admin_user_id <= 0) {
    header('Location: /rwa/auth/tg/');
    exit;
}

// Minimal “admin allowlist” for Stage 1 (safest without schema dependency).
// Replace IDs as you like (e.g. your known admin user ids).
$ADMIN_ALLOWLIST = [1, 2];
if (!in_array($admin_user_id, $ADMIN_ALLOWLIST, true)) {
    http_response_code(403);
    echo "FORBIDDEN";
    exit;
}

db_connect();
$pdo = $GLOBALS['pdo'];

function meta_get_rlife(array $meta): array {
    $rwa = $meta['rwa'] ?? [];
    $rlife = is_array($rwa['rlife'] ?? null) ? $rwa['rlife'] : [];
    return [
        'sugar' => (string)($rlife['sugar'] ?? 'green'),
        'heart' => (string)($rlife['heart'] ?? 'green'),
        'bmi'   => (string)($rlife['bmi'] ?? 'green'),
    ];
}

function meta_set_rlife(array &$meta, string $sugar, string $heart, string $bmi, int $admin_id): void {
    if (!isset($meta['rwa']) || !is_array($meta['rwa'])) $meta['rwa'] = [];
    if (!isset($meta['rwa']['rlife']) || !is_array($meta['rwa']['rlife'])) $meta['rwa']['rlife'] = [];
    $meta['rwa']['rlife']['sugar'] = $sugar;
    $meta['rwa']['rlife']['heart'] = $heart;
    $meta['rwa']['rlife']['bmi']   = $bmi;
    $meta['rwa']['rlife']['updated_by'] = $admin_id;
    $meta['rwa']['rlife']['updated_at'] = date('c');
}

function safe_meta_decode(?string $raw): array {
    if (!$raw) return [];
    $raw = trim($raw);
    if ($raw === '') return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function led_label(string $v): string {
    return match ($v) {
        'green' => '● NORMAL',
        'yellow' => '● WARNING',
        'red' => '● RISK',
        default => '● NORMAL',
    };
}

// ---- POST save ----
$ok_msg = '';
$err_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $target_user_id = (int)($_POST['user_id'] ?? 0);

    $sugar = (string)($_POST['sugar'] ?? 'green');
    $heart = (string)($_POST['heart'] ?? 'green');
    $bmi   = (string)($_POST['bmi'] ?? 'green');

    $allowed = ['green','yellow','red'];
    if ($target_user_id <= 0 || !in_array($sugar,$allowed,true) || !in_array($heart,$allowed,true) || !in_array($bmi,$allowed,true)) {
        $err_msg = 'INVALID_INPUT';
    } else {
        $stmt = $pdo->prepare("SELECT id, nickname, email, meta FROM users WHERE id=? LIMIT 1");
        $stmt->execute([$target_user_id]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$u) {
            $err_msg = 'USER_NOT_FOUND';
        } else {
            $meta = safe_meta_decode($u['meta'] ?? '');
            meta_set_rlife($meta, $sugar, $heart, $bmi, $admin_user_id);
            $new_meta = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $upd = $pdo->prepare("UPDATE users SET meta=? WHERE id=? LIMIT 1");
            $upd->execute([$new_meta, $target_user_id]);

            $ok_msg = 'SAVED';
        }
    }
}

// ---- Search/list ----
$q = trim((string)($_GET['q'] ?? ''));
$rows = [];

if ($q !== '') {
    // Search by id / nickname / email (fast enough for admin tool)
    $like = '%' . $q . '%';
    $stmt = $pdo->prepare("
        SELECT id, nickname, email, meta
        FROM users
        WHERE CAST(id AS CHAR) LIKE ?
           OR nickname LIKE ?
           OR email LIKE ?
        ORDER BY id DESC
        LIMIT 30
    ");
    $stmt->execute([$like,$like,$like]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>RLIFE Admin Health Status</title>
<style>
:root{
  --bg:#0b0616;
  --card:#140a2a;
  --stroke:rgba(168,85,247,.35);
  --txt:#e9d5ff;
  --mut:#c084fc;
  --led:#5BFF3C;
}
html,body{height:100%;margin:0;background:var(--bg);color:var(--txt);font-family:ui-monospace,monospace;}
.wrap{max-width:980px;margin:0 auto;padding:16px 14px 90px;}
.card{background:var(--card);border:1px solid var(--stroke);border-radius:14px;padding:14px;margin:12px 0;box-shadow:0 0 14px rgba(168,85,247,.18);}
.h1{display:flex;align-items:center;justify-content:space-between;gap:10px;}
.h1 .t{font-size:14px;opacity:.9}
.small{font-size:12px;color:var(--mut);opacity:.9}
input,select,button{font:inherit}
.inp{width:100%;padding:12px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:#080316;color:var(--txt);outline:none;}
.row{display:grid;grid-template-columns:1fr;gap:10px}
@media(min-width:860px){.row{grid-template-columns:1fr 1fr}}
.btn{width:100%;padding:12px;border-radius:10px;border:1px solid rgba(255,255,255,.16);background:#110721;color:var(--txt);cursor:pointer}
.btn.primary{border:none;background:linear-gradient(90deg,#7e22ce,#a855f7);font-weight:700}
.bad{color:#ff6a8d;font-size:12px}
.good{color:#7CFFB2;font-size:12px}
.table{width:100%;border-collapse:collapse}
.table td,.table th{border-bottom:1px solid rgba(255,255,255,.10);padding:10px 6px;font-size:12px;vertical-align:top}
.tag{display:inline-block;padding:2px 8px;border:1px solid rgba(255,255,255,.14);border-radius:999px;font-size:11px;color:var(--mut)}
.led{color:var(--led);text-shadow:0 0 8px rgba(91,255,60,.6)}
.footer{margin-top:18px;text-align:center;font-size:12px;opacity:.65}
.goog-te-banner-frame.skiptranslate{display:none!important;}
body{top:0!important;}
</style>
</head>
<body>

<div class="wrap">

  <div class="card h1">
    <div>
      <div class="t">RLIFE Admin Health Status</div>
      <div class="small">Stage 1 · Manual override only · Stored in users.meta</div>
    </div>
    <div><?= rwa_chain_indicator_html(); ?></div>
  </div>

  <div class="card">
    <form method="get" action="/rwa/admin/health-status.php">
      <div class="small">Search user (id / nickname / email)</div>
      <div style="height:8px"></div>
      <input class="inp" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="e.g. 1000 / hari / user@email.com">
      <div style="height:10px"></div>
      <button class="btn primary" data-click-sfx>SEARCH</button>
    </form>
  </div>

  <?php if($ok_msg): ?>
    <div class="card"><div class="good">OK: <?= htmlspecialchars($ok_msg) ?></div></div>
  <?php endif; ?>
  <?php if($err_msg): ?>
    <div class="card"><div class="bad">ERROR: <?= htmlspecialchars($err_msg) ?></div></div>
  <?php endif; ?>

  <?php if($q !== ''): ?>
    <div class="card">
      <div class="small">Results</div>
      <div style="height:10px"></div>
      <table class="table">
        <thead>
          <tr>
            <th>User</th>
            <th>Current RLIFE LEDs</th>
            <th>Update</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($rows as $r): ?>
          <?php
            $meta = safe_meta_decode($r['meta'] ?? '');
            $cur = meta_get_rlife($meta);
          ?>
          <tr>
            <td>
              <div><span class="tag">ID <?= (int)$r['id'] ?></span></div>
              <div style="height:6px"></div>
              <div><?= htmlspecialchars((string)($r['nickname'] ?? '')) ?></div>
              <div class="small"><?= htmlspecialchars((string)($r['email'] ?? '')) ?></div>
            </td>
            <td>
              <div class="small">Sugar <span class="led"><?= led_label($cur['sugar']) ?></span></div>
              <div class="small">Heart <span class="led"><?= led_label($cur['heart']) ?></span></div>
              <div class="small">BMI <span class="led"><?= led_label($cur['bmi']) ?></span></div>
            </td>
            <td>
              <form method="post" action="/rwa/admin/health-status.php?q=<?= urlencode($q) ?>">
                <input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>">
                <div class="row">
                  <select class="inp" name="sugar">
                    <option value="green" <?= $cur['sugar']==='green'?'selected':'' ?>>Sugar: Green</option>
                    <option value="yellow" <?= $cur['sugar']==='yellow'?'selected':'' ?>>Sugar: Yellow</option>
                    <option value="red" <?= $cur['sugar']==='red'?'selected':'' ?>>Sugar: Red</option>
                  </select>
                  <select class="inp" name="heart">
                    <option value="green" <?= $cur['heart']==='green'?'selected':'' ?>>Heart: Green</option>
                    <option value="yellow" <?= $cur['heart']==='yellow'?'selected':'' ?>>Heart: Yellow</option>
                    <option value="red" <?= $cur['heart']==='red'?'selected':'' ?>>Heart: Red</option>
                  </select>
                </div>
                <div style="height:10px"></div>
                <select class="inp" name="bmi">
                  <option value="green" <?= $cur['bmi']==='green'?'selected':'' ?>>BMI: Green</option>
                  <option value="yellow" <?= $cur['bmi']==='yellow'?'selected':'' ?>>BMI: Yellow</option>
                  <option value="red" <?= $cur['bmi']==='red'?'selected':'' ?>>BMI: Red</option>
                </select>
                <div style="height:10px"></div>
                <button class="btn primary" data-click-sfx>SAVE</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <div class="footer">
    © 2025 Blockchain Group Ltd. (Hong Kong) · RWA Standard Organisation (RSO). All rights reserved.
  </div>

</div>

<script src="/dashboard/inc/poado-i18n.js"></script>
<?php if(function_exists('poado_gt_render')){ poado_gt_render(); } ?>

<script>
document.addEventListener('click',e=>{
  if(e.target.closest('[data-click-sfx]') && window.__rwaClickSfx){ window.__rwaClickSfx(); }
});
</script>

</body>
</html>