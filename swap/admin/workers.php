<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/swap/inc/swap-helpers.php';

$user = swap_require_admin_or_agent();
$role = strtolower(trim((string)($user['role'] ?? '')));
$userId = (int)($user['id'] ?? 0);
$isAdmin = ($role === 'admin');

$pdo = swap_db();

$csrfMemo = '';
if (function_exists('csrf_token')) {
    try { $csrfMemo = (string) csrf_token('swap_admin_update_worker_memo'); } catch (Throwable $e) {}
}

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function worker_badge(string $status): string
{
    $s = strtolower(trim($status));
    $cls = 'swap-badge';
    if (in_array($s, ['active', 'ready', 'arrived', 'started'], true)) $cls .= ' swap-status-active';
    elseif (in_array($s, ['pending_docs', 'pending_fomema', 'pending_permit', 'pending_hostel'], true)) $cls .= ' swap-status-pending';
    elseif (in_array($s, ['non_compliant', 'expired'], true)) $cls .= ' swap-status-rejected';
    return '<span class="' . $cls . '">' . h(ucwords(str_replace('_', ' ', $s ?: 'pending'))) . '</span>';
}

$q = trim((string)($_GET['q'] ?? ''));
$projectKey = trim((string)($_GET['project_key'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];

if ($q !== '') {
    $where[] = '(w.worker_uid LIKE :q OR w.passport_no LIKE :q OR w.full_name LIKE :q OR w.mobile_e164 LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}

if ($projectKey !== '') {
    $where[] = 'w.project_key = :project_key';
    $params[':project_key'] = $projectKey;
}

if ($status !== '') {
    $where[] = 'w.worker_status = :status';
    $params[':status'] = $status;
}

if (!$isAdmin) {
    $where[] = 'w.project_key IN (
        SELECT a.project_key
        FROM rwa_hr_agents a
        WHERE a.user_id = :uid
          AND a.is_active = 1
    )';
    $params[':uid'] = $userId;
}

$sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM rwa_hr_workers w {$sqlWhere}");
foreach ($params as $k => $v) {
    $totalStmt->bindValue($k, $v, PDO::PARAM_STR);
}
$totalStmt->execute();
$totalRows = (int)$totalStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$listStmt = $pdo->prepare("
    SELECT *
    FROM rwa_hr_workers w
    {$sqlWhere}
    ORDER BY w.updated_at DESC, w.id DESC
    LIMIT :limit OFFSET :offset
");
foreach ($params as $k => $v) {
    $listStmt->bindValue($k, $v, PDO::PARAM_STR);
}
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$rows = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$baseQs = [
    'q' => $q,
    'project_key' => $projectKey,
    'status' => $status,
];
function qs_build(array $arr): string {
    return http_build_query(array_filter($arr, static fn($v) => $v !== '' && $v !== null));
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>RHRD-EMA · Workers</title>
  <link rel="stylesheet" href="/rwa/assets/css/rwa-design-system.css">
  <link rel="stylesheet" href="/rwa/swap/assets/css/swap.css">
  <style>
    .swap-toolbar{display:grid;grid-template-columns:1fr;gap:10px}
    .swap-toolbar-row{display:flex;gap:8px;flex-wrap:wrap}
    .swap-toolbar .swap-input,.swap-toolbar .swap-select{max-width:100%}
    .swap-table-wrap{overflow:auto}
    .swap-table{width:100%;border-collapse:collapse;font-size:12px}
    .swap-table th,.swap-table td{border-bottom:1px solid rgba(123,92,255,0.18);padding:8px;vertical-align:top;text-align:left}
    .swap-table th{color:var(--swap-muted);font-size:11px}
    .swap-card-stack{display:flex;flex-direction:column;gap:10px}
    .swap-worker-card{border:1px solid var(--swap-border);border-radius:12px;padding:10px;background:#0d1013}
    .swap-worker-head{display:flex;justify-content:space-between;gap:8px;align-items:flex-start}
    .swap-worker-meta{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px}
    .swap-worker-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
    .swap-inline-form{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
    .swap-inline-form textarea{min-width:260px;min-height:72px}
    .swap-pager{display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:flex-end}
    .swap-link-btn{text-decoration:none;display:inline-flex;align-items:center;justify-content:center}
    .swap-small{font-size:11px;color:var(--swap-muted)}
    .swap-pill{display:inline-block;padding:2px 8px;border:1px solid rgba(255,255,255,.18);border-radius:999px;font-size:11px}
    @media (max-width:768px){
      .swap-worker-meta{grid-template-columns:1fr}
      .swap-table-wrap{display:none}
    }
    @media (min-width:769px){
      .swap-card-stack{display:none}
    }
  </style>
</head>
<body class="swap-page">
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-topbar-nav.php'; ?>

<main class="swap-shell">
  <section class="swap-hero card">
    <div class="swap-hero-head">
      <div>
        <div class="swap-kicker">RHRD-EMA · Tertiary RWA — Human Resource Development</div>
        <h1 class="swap-title">Workers</h1>
        <p class="swap-subtitle">Manage workers, print worker details, update memo, and recalculate welfare.</p>
      </div>
      <div class="swap-login-note">
        <strong>Role:</strong> <?= h(strtoupper($role)) ?><br>
        <span class="swap-small"><?= $isAdmin ? 'Full access' : 'Assigned project scope only' ?></span>
      </div>
    </div>
  </section>

  <section class="card">
    <form method="get" class="swap-toolbar">
      <div class="swap-toolbar-row">
        <input type="text" name="q" value="<?= h($q) ?>" class="swap-input" placeholder="Search worker UID / passport / mobile / name">
        <input type="text" name="project_key" value="<?= h($projectKey) ?>" class="swap-input" placeholder="Project key">
        <select name="status" class="swap-select">
          <option value="">All Status</option>
          <?php foreach (['pending_docs','pending_fomema','pending_permit','pending_hostel','ready','arrived','started','active','non_compliant','expired'] as $opt): ?>
            <option value="<?= h($opt) ?>" <?= $status === $opt ? 'selected' : '' ?>><?= h(ucwords(str_replace('_',' ',$opt))) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="swap-btn swap-btn-primary">Filter</button>
        <a class="swap-btn swap-btn-secondary swap-link-btn" href="/rwa/swap/admin/index.php">Back</a>
      </div>
      <div class="swap-small">
        Total: <?= (int)$totalRows ?> · Page <?= (int)$page ?> / <?= (int)$totalPages ?>
      </div>
    </form>
  </section>

  <section class="card">
    <div class="swap-card-head">
      <h2>Worker List</h2>
      <p class="swap-card-note">Only admin and agent can print worker list and worker detail. Agent is limited to assigned projects.</p>
    </div>

    <div class="swap-table-wrap">
      <table class="swap-table">
        <thead>
          <tr>
            <th>Worker</th>
            <th>Contact</th>
            <th>Project / Job</th>
            <th>Compliance / Welfare</th>
            <th>Memo</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="6">No workers found.</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <?php
              $passportMasked = swap_mask_passport((string)$r['passport_no']);
              $wa = swap_wa_link((string)$r['mobile_e164']);
              $projectShort = swap_project_short((string)($r['project_key'] ?? ''));
              $welfare = (string)($r['welfare_score'] ?? '0');
            ?>
            <tr>
              <td>
                <strong><?= h((string)$r['worker_uid']) ?></strong><br>
                <span class="swap-small"><?= h((string)$r['full_name']) ?></span><br>
                <span class="swap-small"><?= h($passportMasked) ?></span>
              </td>
              <td>
                <strong><?= h((string)$r['mobile_e164']) ?></strong><br>
                <span class="swap-small"><?= h((string)($r['tg_username'] ?: '-')) ?></span><br>
                <?php if ($wa !== ''): ?>
                  <a class="swap-small" href="<?= h($wa) ?>" target="_blank" rel="noopener">WhatsApp</a>
                <?php endif; ?>
              </td>
              <td>
                <strong><?= h($projectShort) ?></strong><br>
                <span class="swap-small"><?= h((string)($r['sector'] ?: '-')) ?></span><br>
                <span class="swap-small"><?= h((string)($r['site_name'] ?: '-')) ?></span>
              </td>
              <td>
                <?= worker_badge((string)$r['worker_status']) ?><br>
                <span class="swap-small">Welfare: <?= h($welfare) ?> / <?= h((string)($r['welfare_band'] ?: '-')) ?></span><br>
                <span class="swap-small">Deployable: <?= h((string)($r['deployable_status'] ?: '-')) ?></span><br>
                <span class="swap-small">Next: <?= h((string)($r['next_action'] ?: '-')) ?></span>
              </td>
              <td>
                <div class="swap-small"><?= nl2br(h((string)($r['memo_text'] ?: '-'))) ?></div>
                <div class="swap-small"><?= h((string)($r['memo_updated_at'] ?: $r['updated_at'])) ?></div>
              </td>
              <td>
                <div class="swap-worker-actions">
                  <?php if ($wa !== ''): ?>
                    <a class="swap-btn swap-btn-secondary swap-link-btn" href="<?= h($wa) ?>" target="_blank" rel="noopener">WhatsApp</a>
                  <?php endif; ?>

                  <a class="swap-btn swap-btn-primary swap-link-btn" href="/rwa/swap/admin/print-worker.php?worker_uid=<?= urlencode((string)$r['worker_uid']) ?>" target="_blank">Print</a>

                  <form class="swap-inline-form" onsubmit="return recalcWelfare(event,'<?= h((string)$r['worker_uid']) ?>')">
                    <button type="submit" class="swap-btn swap-btn-secondary">Recalculate Welfare</button>
                  </form>

                  <form class="swap-inline-form" method="post" action="/rwa/swap/api/admin/update-worker-memo.php">
                    <input type="hidden" name="csrf" value="<?= h($csrfMemo) ?>">
                    <input type="hidden" name="worker_uid" value="<?= h((string)$r['worker_uid']) ?>">
                    <textarea name="memo_text" class="swap-textarea" rows="3" placeholder="Update memo"><?= h((string)($r['memo_text'] ?? '')) ?></textarea>
                    <button type="submit" class="swap-btn swap-btn-secondary">Save Memo</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="swap-card-stack">
      <?php if (!$rows): ?>
        <div class="swap-worker-card">No workers found.</div>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <?php
            $passportMasked = swap_mask_passport((string)$r['passport_no']);
            $wa = swap_wa_link((string)$r['mobile_e164']);
            $projectShort = swap_project_short((string)($r['project_key'] ?? ''));
            $welfare = (string)($r['welfare_score'] ?? '0');
          ?>
          <div class="swap-worker-card">
            <div class="swap-worker-head">
              <div>
                <strong><?= h((string)$r['worker_uid']) ?></strong><br>
                <span class="swap-small"><?= h((string)$r['full_name']) ?></span>
              </div>
              <div><?= worker_badge((string)$r['worker_status']) ?></div>
            </div>

            <div class="swap-worker-meta">
              <div>
                <span class="swap-result-label">Passport</span>
                <strong><?= h($passportMasked) ?></strong>
              </div>
              <div>
                <span class="swap-result-label">Mobile</span>
                <strong><?= h((string)$r['mobile_e164']) ?></strong>
              </div>
              <div>
                <span class="swap-result-label">Project</span>
                <strong><?= h($projectShort) ?></strong>
              </div>
              <div>
                <span class="swap-result-label">Industry</span>
                <strong><?= h((string)($r['sector'] ?: '-')) ?></strong>
              </div>
              <div>
                <span class="swap-result-label">Welfare</span>
                <strong><?= h($welfare) ?> / <?= h((string)($r['welfare_band'] ?: '-')) ?></strong>
              </div>
              <div>
                <span class="swap-result-label">Next Action</span>
                <strong><?= h((string)($r['next_action'] ?: '-')) ?></strong>
              </div>
            </div>

            <div class="swap-worker-actions">
              <?php if ($wa !== ''): ?>
                <a class="swap-btn swap-btn-secondary swap-link-btn" href="<?= h($wa) ?>" target="_blank" rel="noopener">WhatsApp</a>
              <?php endif; ?>

              <a class="swap-btn swap-btn-primary swap-link-btn" href="/rwa/swap/admin/print-worker.php?worker_uid=<?= urlencode((string)$r['worker_uid']) ?>" target="_blank">Print</a>

              <form class="swap-inline-form" onsubmit="return recalcWelfare(event,'<?= h((string)$r['worker_uid']) ?>')">
                <button type="submit" class="swap-btn swap-btn-secondary">Recalculate Welfare</button>
              </form>
            </div>

            <form class="swap-inline-form" method="post" action="/rwa/swap/api/admin/update-worker-memo.php">
              <input type="hidden" name="csrf" value="<?= h($csrfMemo) ?>">
              <input type="hidden" name="worker_uid" value="<?= h((string)$r['worker_uid']) ?>">
              <textarea name="memo_text" class="swap-textarea" rows="3" placeholder="Update memo"><?= h((string)($r['memo_text'] ?? '')) ?></textarea>
              <button type="submit" class="swap-btn swap-btn-secondary">Save Memo</button>
            </form>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="swap-pager" style="margin-top:12px;">
      <?php if ($page > 1): ?>
        <a class="swap-btn swap-btn-secondary swap-link-btn" href="?<?= h(qs_build($baseQs + ['page' => $page - 1])) ?>">Prev</a>
      <?php endif; ?>
      <span class="swap-small">Page <?= (int)$page ?> / <?= (int)$totalPages ?></span>
      <?php if ($page < $totalPages): ?>
        <a class="swap-btn swap-btn-secondary swap-link-btn" href="?<?= h(qs_build($baseQs + ['page' => $page + 1])) ?>">Next</a>
      <?php endif; ?>
    </div>
  </section>
</main>

<script>
async function recalcWelfare(e, workerUid){
  e.preventDefault();
  try{
    const res = await fetch('/rwa/swap/api/admin/recalculate-welfare.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({worker_uid: workerUid})
    });
    const j = await res.json();
    if(!j.ok){
      alert(j.error || 'Failed to recalculate welfare');
      return false;
    }
    alert('Welfare updated: ' + j.welfare_score + ' (' + j.welfare_band + ')');
    location.reload();
  }catch(err){
    alert('Failed to recalculate welfare');
  }
  return false;
}
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-bottom-nav.php'; ?>
</body>
</html>