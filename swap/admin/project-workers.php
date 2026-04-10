<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/qr.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/swap/inc/swap-helpers.php';

$user = swap_require_admin_or_agent();
$role = strtolower(trim((string)($user['role'] ?? '')));
$isAdmin = ($role === 'admin');

$pdo = swap_db();

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$projectKey = trim((string)($_GET['project_key'] ?? ''));
$printMode = ((string)($_GET['print'] ?? '') === '1');

if ($projectKey === '') {
    http_response_code(422);
    exit('PROJECT KEY REQUIRED');
}

if (!$isAdmin && !swap_can_access_project($projectKey, $user)) {
    http_response_code(403);
    exit('NOT AUTHORIZED FOR THIS PROJECT');
}

$projectStmt = $pdo->prepare("
    SELECT *
    FROM rwa_hr_projects
    WHERE project_key = :project_key
    LIMIT 1
");
$projectStmt->execute([':project_key' => $projectKey]);
$project = $projectStmt->fetch(PDO::FETCH_ASSOC) ?: null;

$listStmt = $pdo->prepare("
    SELECT *
    FROM rwa_hr_workers
    WHERE project_key = :project_key
    ORDER BY full_name ASC, worker_uid ASC
");
$listStmt->execute([':project_key' => $projectKey]);
$rows = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$projectShort = swap_project_short($projectKey);
$title = 'RHRD-EMA · Project Worker List';
$subtitle = 'Admin / Agent only';

function worker_status_badge_text(string $status): string {
    $s = trim($status);
    return $s !== '' ? ucwords(str_replace('_', ' ', $s)) : '-';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Project Workers</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/rwa/assets/css/rwa-design-system.css">
  <link rel="stylesheet" href="/rwa/swap/assets/css/swap.css">
  <style>
    .pw-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}
    .pw-meta{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px}
    .pw-tools{display:flex;gap:8px;flex-wrap:wrap}
    .pw-table-wrap{overflow:auto}
    .pw-table{width:100%;border-collapse:collapse;font-size:12px}
    .pw-table th,.pw-table td{border-bottom:1px solid rgba(123,92,255,.18);padding:8px;vertical-align:top;text-align:left}
    .pw-table th{font-size:11px;color:var(--swap-muted)}
    .pw-qr img{width:52px;height:52px;display:block}
    .pw-small{font-size:11px;color:var(--swap-muted)}
    .pw-status{display:inline-block;padding:2px 8px;border:1px solid rgba(255,255,255,.18);border-radius:999px;font-size:11px}
    @media print {
      @page { size: A4 portrait; margin: 12mm; }
      body.swap-page { background:#fff !important; color:#000 !important; }
      .no-print { display:none !important; }
      .card { box-shadow:none !important; background:#fff !important; border:1px solid #bbb !important; }
      .pw-table th,.pw-table td { border-color:#bbb !important; }
      a { color:#000 !important; text-decoration:none !important; }
    }
    @media (max-width:768px){
      .pw-meta{grid-template-columns:1fr}
    }
  </style>
</head>
<body class="swap-page">
<?php if (!$printMode): ?>
  <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-topbar-nav.php'; ?>
<?php endif; ?>

<main class="swap-shell">
  <section class="card">
    <div class="pw-head">
      <div>
        <div class="swap-kicker"><?= h($title) ?></div>
        <h1 class="swap-title" style="margin:4px 0;"><?= h($projectShort) ?></h1>
        <p class="swap-subtitle"><?= h($subtitle) ?></p>
      </div>

      <div class="pw-tools no-print">
        <a class="swap-btn swap-btn-secondary swap-link-btn" href="/rwa/swap/admin/workers.php?project_key=<?= urlencode($projectKey) ?>">Back</a>
        <a class="swap-btn swap-btn-primary swap-link-btn" href="/rwa/swap/admin/project-workers.php?project_key=<?= urlencode($projectKey) ?>&print=1" target="_blank">Print A4</a>
      </div>
    </div>

    <div class="pw-meta">
      <div class="swap-result-item">
        <span class="swap-result-label">Project Key</span>
        <strong><?= h($projectKey) ?></strong>
      </div>
      <div class="swap-result-item">
        <span class="swap-result-label">Company</span>
        <strong><?= h((string)($project['company_short'] ?? '-')) ?></strong>
      </div>
      <div class="swap-result-item">
        <span class="swap-result-label">Industry</span>
        <strong><?= h((string)($project['industry_type'] ?? '-')) ?></strong>
      </div>
      <div class="swap-result-item">
        <span class="swap-result-label">Location</span>
        <strong><?= h((string)($project['location_code'] ?? '-')) ?></strong>
      </div>
      <div class="swap-result-item">
        <span class="swap-result-label">Workers</span>
        <strong><?= (int)count($rows) ?></strong>
      </div>
      <div class="swap-result-item">
        <span class="swap-result-label">Generated</span>
        <strong><?= h(date('Y-m-d H:i:s')) ?></strong>
      </div>
    </div>
  </section>

  <section class="card">
    <div class="swap-card-head">
      <h2>Project Worker List</h2>
      <p class="swap-card-note">Print access is limited to admin and agent only.</p>
    </div>

    <div class="pw-table-wrap">
      <table class="pw-table">
        <thead>
          <tr>
            <th style="width:40px;">#</th>
            <th>Worker</th>
            <th>Passport</th>
            <th>Contact</th>
            <th>Industry / Job</th>
            <th>Status</th>
            <th>Welfare</th>
            <th>WhatsApp QR</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr>
            <td colspan="8">No workers found for this project.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($rows as $i => $r): ?>
            <?php
              $wa = swap_wa_link((string)($r['mobile_e164'] ?? ''));
              $waQr = '';
              if ($wa !== '' && function_exists('poado_qr_svg_data_uri')) {
                  try { $waQr = (string)poado_qr_svg_data_uri($wa); } catch (Throwable $e) { $waQr = ''; }
              }
            ?>
            <tr>
              <td><?= (int)($i + 1) ?></td>
              <td>
                <strong><?= h((string)$r['worker_uid']) ?></strong><br>
                <span class="pw-small"><?= h((string)$r['full_name']) ?></span>
              </td>
              <td>
                <strong><?= h(swap_mask_passport((string)$r['passport_no'])) ?></strong>
              </td>
              <td>
                <strong><?= h((string)($r['mobile_e164'] ?: '-')) ?></strong><br>
                <?php if ($wa !== ''): ?>
                  <span class="pw-small"><?= h($wa) ?></span>
                <?php else: ?>
                  <span class="pw-small">-</span>
                <?php endif; ?>
              </td>
              <td>
                <strong><?= h((string)($r['sector'] ?: '-')) ?></strong><br>
                <span class="pw-small"><?= h((string)($r['job_title'] ?: '-')) ?></span>
              </td>
              <td>
                <span class="pw-status"><?= h(worker_status_badge_text((string)$r['worker_status'])) ?></span><br>
                <span class="pw-small"><?= h((string)($r['site_name'] ?: '-')) ?></span>
              </td>
              <td>
                <strong><?= h((string)($r['welfare_score'] ?? '0')) ?></strong><br>
                <span class="pw-small"><?= h((string)($r['welfare_band'] ?: '-')) ?></span>
              </td>
              <td class="pw-qr">
                <?php if ($waQr !== ''): ?>
                  <img src="<?= h($waQr) ?>" alt="WA QR">
                <?php else: ?>
                  <span class="pw-small">-</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>

<?php if (!$printMode): ?>
  <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-bottom-nav.php'; ?>
<?php endif; ?>
</body>
</html>