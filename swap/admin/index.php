<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/swap/inc/swap-helpers.php';

$user = swap_require_admin_or_agent();
$role = strtolower(trim((string)($user['role'] ?? '')));
$name = (string)($user['nickname'] ?? $user['name'] ?? $user['full_name'] ?? 'User');

$isAdmin = ($role === 'admin');
?>
<!doctype html>
<html lang="en" data-lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>RHRD-EMA · SWAP Admin</title>
  <link rel="stylesheet" href="/rwa/assets/css/rwa-design-system.css">
  <link rel="stylesheet" href="/rwa/swap/assets/css/swap.css">
</head>
<body class="swap-page">
  <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-topbar-nav.php'; ?>

  <main class="swap-shell">

    <section class="swap-hero card">
      <div class="swap-hero-head">
        <div>
          <div class="swap-kicker">RHRD-EMA · Tertiary RWA — Human Resource Development</div>
          <h1 class="swap-title">SWAP Admin Panel</h1>
          <p class="swap-subtitle">
            Role: <?= htmlspecialchars(strtoupper($role), ENT_QUOTES, 'UTF-8') ?> · Welcome <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>
          </p>
        </div>
      </div>
    </section>

    <section class="swap-main-grid">

      <section class="swap-left card">
        <div class="swap-card-head">
          <h2>Operations</h2>
          <p class="swap-card-note">Core management pages for applications, workers, and jobs.</p>
        </div>

        <div class="swap-jobs-list">
          <div class="swap-job-item">
            <div class="swap-job-head">
              <strong>Applications Queue</strong>
              <span class="swap-badge">Admin/Agent</span>
            </div>
            <div class="swap-job-meta">
              <div>Review pending applications</div>
              <div>Approve / reject / assign</div>
            </div>
            <div class="swap-job-actions">
              <a class="swap-btn swap-btn-primary" href="/rwa/swap/admin/applications.php">Open Applications</a>
            </div>
          </div>

          <div class="swap-job-item">
            <div class="swap-job-head">
              <strong>Workers</strong>
              <span class="swap-badge">Admin/Agent</span>
            </div>
            <div class="swap-job-meta">
              <div>View worker list</div>
              <div>Memo update / print access</div>
            </div>
            <div class="swap-job-actions">
              <a class="swap-btn swap-btn-primary" href="/rwa/swap/admin/workers.php">Open Workers</a>
            </div>
          </div>

          <div class="swap-job-item">
            <div class="swap-job-head">
              <strong>Jobs Announcement</strong>
              <span class="swap-badge">Admin<?php if (!$isAdmin): ?>-view<?php endif; ?></span>
            </div>
            <div class="swap-job-meta">
              <div>Create and update hiring notices</div>
              <div>Apply Now source for public index</div>
            </div>
            <div class="swap-job-actions">
              <a class="swap-btn swap-btn-primary" href="/rwa/swap/admin/jobs.php">Open Jobs</a>
            </div>
          </div>
        </div>
      </section>

      <aside class="swap-right card">
        <div class="swap-card-head">
          <h2>Access Rules</h2>
          <p class="swap-card-note">Locked permission summary.</p>
        </div>

        <div class="swap-result-grid">
          <div class="swap-result-item swap-result-item-wide">
            <span class="swap-result-label">Worker List / Detail Print</span>
            <strong>Admin and Agent only</strong>
          </div>
          <div class="swap-result-item swap-result-item-wide">
            <span class="swap-result-label">Agent Scope</span>
            <strong>Assigned project only</strong>
          </div>
          <div class="swap-result-item swap-result-item-wide">
            <span class="swap-result-label">Public Search</span>
            <strong>Non-sensitive data only</strong>
          </div>
          <div class="swap-result-item swap-result-item-wide">
            <span class="swap-result-label">Human Resource Development</span>
            <strong>10 hours = 1 cert · 100 EMA$ = 1 cert</strong>
          </div>
        </div>
      </aside>

    </section>

  </main>

  <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-bottom-nav.php'; ?>
</body>
</html>