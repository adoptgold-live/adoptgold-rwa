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

$csrfApprove = '';
$csrfReject = '';
$csrfMemo = '';
if (function_exists('csrf_token')) {
    try { $csrfApprove = (string) csrf_token('swap_admin_approve'); } catch (Throwable $e) {}
    try { $csrfReject  = (string) csrf_token('swap_admin_reject'); } catch (Throwable $e) {}
    try { $csrfMemo    = (string) csrf_token('swap_admin_update_memo'); } catch (Throwable $e) {}
}

function h(?string $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function app_status_badge(string $status): string
{
    $s = strtolower(trim($status));
    $cls = 'swap-badge';
    if (in_array($s, ['approved','assigned'], true)) $cls .= ' swap-status-active';
    elseif (in_array($s, ['pending','shortlisted'], true)) $cls .= ' swap-status-pending';
    elseif ($s === 'rejected') $cls .= ' swap-status-rejected';
    return '<span class="' . $cls . '">' . h(ucwords(str_replace('_', ' ', $s ?: 'pending'))) . '</span>';
}

function app_stage_label(string $stage): string
{
    $map = [
        'pending_docs'   => 'Document Review',
        'under_review'   => 'Under Review',
        'shortlisted'    => 'Shortlisted',
        'approved'       => 'Approved',
        'assigned'       => 'Assigned',
        'rejected'       => 'Rejected',
        'pending_fomema' => 'Medical Check Required',
        'pending_permit' => 'Permit Processing',
        'pending_hostel' => 'Accommodation Pending',
        'ready'          => 'Ready for Arrival',
    ];
    return $map[$stage] ?? ucwords(str_replace('_', ' ', $stage ?: '-'));
}

$allowedStatuses = ['pending','shortlisted','approved','rejected','assigned'];
$status = trim((string)($_GET['status'] ?? 'pending'));
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'pending';
}

$q = trim((string)($_GET['q'] ?? ''));
$projectKey = trim((string)($_GET['project_key'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];

$where[] = 'r.application_status = :status';
$params[':status'] = $status;

if ($q !== '') {
    $where[] = '(r.request_uid LIKE :q OR r.passport_no LIKE :q OR r.mobile_e164 LIKE :q OR r.full_name LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}

if ($projectKey !== '') {
    $where[] = 'r.project_key = :project_key';
    $params[':project_key'] = $projectKey;
}

if (!$isAdmin) {
    $where[] = '(
        r.project_key IN (
            SELECT a.project_key
            FROM rwa_hr_agents a
            WHERE a.user_id = :agent_uid
              AND a.is_active = 1
        )
        OR r.project_key IS NULL
    )';
    $params[':agent_uid'] = $userId;
}

$sqlWhere = implode(' AND ', $where);

$countSql = "
    SELECT COUNT(*)
    FROM rwa_hr_job_requests r
    WHERE {$sqlWhere}
";
$countStmt = $pdo->prepare($countSql);
foreach ($params as $k => $v) {
    $countStmt->bindValue($k, $v, PDO::PARAM_STR);
}
$countStmt->execute();
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$listSql = "
    SELECT
        r.id,
        r.request_uid,
        r.job_uid,
        r.project_key,
        r.full_name,
        r.passport_no,
        r.nationality,
        r.nationality_other,
        r.gender,
        r.date_of_birth,
        r.mobile_e164,
        r.whatsapp_url,
        r.tg_username,
        r.preferred_industry,
        r.industry_other,
        r.preferred_location,
        r.experience_years,
        r.skill_notes,
        r.worked_in_malaysia_before,
        r.application_status,
        r.status_stage,
        r.next_action,
        r.memo_text,
        r.memo_updated_at,
        r.updated_at,
        r.created_at,
        j.title AS job_title
    FROM rwa_hr_job_requests r
    LEFT JOIN rwa_hr_job_alerts j
      ON j.job_uid = r.job_uid
    WHERE {$sqlWhere}
    ORDER BY r.updated_at DESC, r.id DESC
    LIMIT :limit OFFSET :offset
";
$listStmt = $pdo->prepare($listSql);
foreach ($params as $k => $v) {
    $listStmt->bindValue($k, $v, PDO::PARAM_STR);
}
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$rows = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$baseQs = [
    'status' => $status,
    'q' => $q,
    'project_key' => $projectKey,
];
function app_qs(array $arr): string {
    return http_build_query(array_filter($arr, static fn($v) => $v !== '' && $v !== null));
}
?>
<!doctype html>
<html lang="en" data-lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>RHRD-EMA · Applications</title>
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
    .swap-app-card{border:1px solid var(--swap-border);border-radius:12px;padding:10px;background:#0d1013}
    .swap-app-head{display:flex;justify-content:space-between;gap:8px;align-items:flex-start}
    .swap-app-meta{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px}
    .swap-app-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
    .swap-inline-form{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
    .swap-inline-form textarea{min-width:260px;min-height:72px}
    .swap-pager{display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:flex-end}
    .swap-link-btn{text-decoration:none;display:inline-flex;align-items:center;justify-content:center}
    .swap-small{font-size:11px;color:var(--swap-muted)}
    @media (max-width:768px){
      .swap-app-meta{grid-template-columns:1fr}
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
        <h1 class="swap-title">Applications Queue</h1>
        <p class="swap-subtitle">Review, approve, reject, assign, and update memo for worker applications.</p>
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
        <select name="status" class="swap-select">
          <?php foreach ($allowedStatuses as $opt): ?>
            <option value="<?= h($opt) ?>" <?= $status === $opt ? 'selected' : '' ?>><?= h(ucwords($opt)) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="q" value="<?= h($q) ?>" class="swap-input" placeholder="Search request UID / passport / mobile / name">
        <input type="text" name="project_key" value="<?= h($projectKey) ?>" class="swap-input" placeholder="Project key">
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
      <h2>Applications</h2>
      <p class="swap-card-note">Use quick actions below. Approval/rejection endpoints are wired separately.</p>
    </div>

    <div class="swap-table-wrap">
      <table class="swap-table">
        <thead>
          <tr>
            <th>Request</th>
            <th>Worker</th>
            <th>Preference</th>
            <th>Status</th>
            <th>Memo</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="6">No applications found.</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <?php
              $industry = (string)($r['preferred_industry'] ?? '');
              if ($industry === 'OTHER' && !empty($r['industry_other'])) $industry = (string)$r['industry_other'];
              $passportMasked = swap_mask_passport((string)$r['passport_no']);
              $wa = swap_wa_link((string)$r['mobile_e164']);
            ?>
            <tr>
              <td>
                <strong><?= h((string)$r['request_uid']) ?></strong><br>
                <span class="swap-small"><?= h((string)($r['job_uid'] ?: '-')) ?></span><br>
                <span class="swap-small"><?= h((string)($r['project_key'] ?: '-')) ?></span>
              </td>
              <td>
                <strong><?= h((string)$r['full_name']) ?></strong><br>
                <span class="swap-small"><?= h($passportMasked) ?></span><br>
                <span class="swap-small"><?= h((string)$r['mobile_e164']) ?></span>
                <?php if ($wa !== ''): ?><br><a class="swap-small" href="<?= h($wa) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?>
              </td>
              <td>
                <strong><?= h($industry) ?></strong><br>
                <span class="swap-small"><?= h((string)$r['preferred_location']) ?></span><br>
                <span class="swap-small"><?= h((string)($r['job_title'] ?: '-')) ?></span>
              </td>
              <td>
                <?= app_status_badge((string)$r['application_status']) ?><br>
                <span class="swap-small"><?= h(app_stage_label((string)$r['status_stage'])) ?></span><br>
                <span class="swap-small"><?= h((string)($r['next_action'] ?: '-')) ?></span>
              </td>
              <td>
                <div class="swap-small"><?= nl2br(h((string)($r['memo_text'] ?: '-'))) ?></div>
                <div class="swap-small"><?= h((string)($r['memo_updated_at'] ?: $r['updated_at'])) ?></div>
              </td>
              <td>
                <div class="swap-app-actions">
                  <?php if (in_array((string)$r['application_status'], ['pending','shortlisted'], true)): ?>
                    <form class="swap-inline-form" method="post" action="/rwa/swap/api/admin/approve-application.php">
                      <input type="hidden" name="csrf" value="<?= h($csrfApprove) ?>">
                      <input type="hidden" name="request_uid" value="<?= h((string)$r['request_uid']) ?>">
                      <button type="submit" class="swap-btn swap-btn-primary">Approve</button>
                    </form>

                    <form class="swap-inline-form" method="post" action="/rwa/swap/api/admin/reject-application.php">
                      <input type="hidden" name="csrf" value="<?= h($csrfReject) ?>">
                      <input type="hidden" name="request_uid" value="<?= h((string)$r['request_uid']) ?>">
                      <button type="submit" class="swap-btn swap-btn-secondary">Reject</button>
                    </form>
                  <?php endif; ?>

                  <form class="swap-inline-form" method="post" action="/rwa/swap/api/admin/update-memo.php">
                    <input type="hidden" name="csrf" value="<?= h($csrfMemo) ?>">
                    <input type="hidden" name="request_uid" value="<?= h((string)$r['request_uid']) ?>">
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
        <div class="swap-app-card">No applications found.</div>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <?php
            $industry = (string)($r['preferred_industry'] ?? '');
            if ($industry === 'OTHER' && !empty($r['industry_other'])) $industry = (string)$r['industry_other'];
            $passportMasked = swap_mask_passport((string)$r['passport_no']);
            $wa = swap_wa_link((string)$r['mobile_e164']);
          ?>
          <div class="swap-app-card">
            <div class="swap-app-head">
              <div>
                <strong><?= h((string)$r['request_uid']) ?></strong><br>
                <span class="swap-small"><?= h((string)$r['full_name']) ?></span>
              </div>
              <div><?= app_status_badge((string)$r['application_status']) ?></div>
            </div>

            <div class="swap-app-meta">
              <div>
                <span class="swap-result-label">Passport</span>
                <strong><?= h($passportMasked) ?></strong>
              </div>
              <div>
                <span class="swap-result-label">Mobile</span>
                <strong><?= h((string)$r['mobile_e164']) ?></strong>
              </div>
              <div>
                <span class="swap-result-label">Industry</span>
                <strong><?= h($industry) ?></strong>
              </div>
              <div>
                <span class="swap-result-label">Location</span>
                <strong><?= h((string)$r['preferred_location']) ?></strong>
              </div>
              <div>
                <span class="swap-result-label">Stage</span>
                <strong><?= h(app_stage_label((string)$r['status_stage'])) ?></strong>
              </div>
              <div>
                <span class="swap-result-label">Next Action</span>
                <strong><?= h((string)($r['next_action'] ?: '-')) ?></strong>
              </div>
            </div>

            <div class="swap-app-actions">
              <?php if ($wa !== ''): ?>
                <a class="swap-btn swap-btn-secondary swap-link-btn" href="<?= h($wa) ?>" target="_blank" rel="noopener">WhatsApp</a>
              <?php endif; ?>

              <?php if (in_array((string)$r['application_status'], ['pending','shortlisted'], true)): ?>
                <form class="swap-inline-form" method="post" action="/rwa/swap/api/admin/approve-application.php">
                  <input type="hidden" name="csrf" value="<?= h($csrfApprove) ?>">
                  <input type="hidden" name="request_uid" value="<?= h((string)$r['request_uid']) ?>">
                  <button type="submit" class="swap-btn swap-btn-primary">Approve</button>
                </form>

                <form class="swap-inline-form" method="post" action="/rwa/swap/api/admin/reject-application.php">
                  <input type="hidden" name="csrf" value="<?= h($csrfReject) ?>">
                  <input type="hidden" name="request_uid" value="<?= h((string)$r['request_uid']) ?>">
                  <button type="submit" class="swap-btn swap-btn-secondary">Reject</button>
                </form>
              <?php endif; ?>
            </div>

            <form class="swap-inline-form" method="post" action="/rwa/swap/api/admin/update-memo.php">
              <input type="hidden" name="csrf" value="<?= h($csrfMemo) ?>">
              <input type="hidden" name="request_uid" value="<?= h((string)$r['request_uid']) ?>">
              <textarea name="memo_text" class="swap-textarea" rows="3" placeholder="Update memo"><?= h((string)($r['memo_text'] ?? '')) ?></textarea>
              <button type="submit" class="swap-btn swap-btn-secondary">Save Memo</button>
            </form>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="swap-pager" style="margin-top:12px;">
      <?php if ($page > 1): ?>
        <a class="swap-btn swap-btn-secondary swap-link-btn" href="?<?= h(app_qs($baseQs + ['page' => $page - 1])) ?>">Prev</a>
      <?php endif; ?>
      <span class="swap-small">Page <?= (int)$page ?> / <?= (int)$totalPages ?></span>
      <?php if ($page < $totalPages): ?>
        <a class="swap-btn swap-btn-secondary swap-link-btn" href="?<?= h(app_qs($baseQs + ['page' => $page + 1])) ?>">Next</a>
      <?php endif; ?>
    </div>
  </section>
</main>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-bottom-nav.php'; ?>
</body>
</html>