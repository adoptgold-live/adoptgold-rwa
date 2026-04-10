<?php
declare(strict_types=1);

// /rwa/swap/employer/index.php
// SWAP 2.0 Employer Dashboard
// FIX: remove dependency on undefined swap_require_login()
// v2.1.0-20260326-employer-index-session-fix

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/swap/inc/swap-helpers.php';

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/* ===== AUTH FIX ===== */
$userId = function_exists('session_user_id') ? (int) session_user_id() : 0;
if ($userId <= 0) {
    header('Location: /rwa/index.php', true, 302);
    exit;
}

$user = function_exists('session_user') ? session_user() : null;
if (!is_array($user)) {
    $user = [];
}

$pdo = function_exists('swap_db') ? swap_db() : ($GLOBALS['pdo'] ?? null);
if (!$pdo instanceof PDO) {
    http_response_code(500);
    exit('DB NOT READY');
}

/*
Compatibility baseline:
- current project scope comes from logged-in user payload
- later can switch to rwa_hr_employers mapping if needed
*/
$projectKey = trim((string)($user['project_key'] ?? ''));

if ($projectKey === '') {
    http_response_code(403);
    ?>
    <!doctype html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width,initial-scale=1">
      <title>Employer Dashboard</title>
      <link rel="stylesheet" href="/rwa/swap/assets/css/swap.css?v=2.3.0-20260326">
    </head>
    <body class="swap-page with-nav">
    <?php require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/rwa-topbar-nav.php'; ?>
    <div class="swap-shell">
      <section class="swap-hero">
        <div class="hero-title">Employer Dashboard</div>
        <div class="hero-sub">No project is assigned to this employer account.</div>
        <div class="hero-actions">
          <a class="btn secondary" href="/rwa/swap/index.php">Back to SWAP</a>
        </div>
      </section>
    </div>
    <?php require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/rwa-bottom-nav.php'; ?>
    </body>
    </html>
    <?php
    exit;
}

/* ===== KPI ===== */
$kpi = $pdo->prepare("
    SELECT
      COUNT(*) AS total,
      COALESCE(SUM(worker_status = 'active'), 0) AS active,
      COALESCE(SUM(deployable_status = 'yes'), 0) AS deployable,
      COALESCE(AVG(welfare_score), 0) AS avg_welfare
    FROM rwa_hr_workers
    WHERE project_key = :pk
");
$kpi->execute([':pk' => $projectKey]);
$k = $kpi->fetch(PDO::FETCH_ASSOC) ?: [
    'total' => 0,
    'active' => 0,
    'deployable' => 0,
    'avg_welfare' => 0,
];

/* ===== ALERTS ===== */
$alertsStmt = $pdo->prepare("
    SELECT
      worker_uid,
      full_name,
      risk_level,
      worker_status,
      deployable_status,
      next_action,
      welfare_score
    FROM rwa_hr_workers
    WHERE project_key = :pk
      AND (
        LOWER(COALESCE(risk_level,'')) IN ('high','critical')
        OR LOWER(COALESCE(deployable_status,'')) = 'no'
        OR LOWER(COALESCE(worker_status,'')) IN ('pending_fomema','pending_permit','non_compliant')
      )
    ORDER BY
      CASE LOWER(COALESCE(risk_level,'')) WHEN 'critical' THEN 0 WHEN 'high' THEN 1 ELSE 2 END,
      welfare_score ASC,
      worker_uid DESC
    LIMIT 8
");
$alertsStmt->execute([':pk' => $projectKey]);
$alerts = $alertsStmt->fetchAll(PDO::FETCH_ASSOC);

/* ===== WORKERS ===== */
$list = $pdo->prepare("
    SELECT
      worker_uid,
      full_name,
      worker_status,
      welfare_score,
      welfare_band,
      risk_level,
      deployable_status,
      next_action,
      country_name,
      state_name,
      area_name,
      mobile_e164
    FROM rwa_hr_workers
    WHERE project_key = :pk
    ORDER BY
      welfare_score ASC,
      CASE LOWER(COALESCE(risk_level,'')) WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END,
      worker_uid DESC
    LIMIT 50
");
$list->execute([':pk' => $projectKey]);
$rows = $list->fetchAll(PDO::FETCH_ASSOC);

$nickname = trim((string)($user['nickname'] ?? 'Employer'));
$wallet   = trim((string)($user['wallet_address'] ?? $user['wallet'] ?? ''));
$walletShort = $wallet !== '' ? substr($wallet, 0, 6) . '...' . substr($wallet, -4) : '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Employer Dashboard</title>
<link rel="stylesheet" href="/rwa/swap/assets/css/swap.css?v=2.3.0-20260326">
<style>
.employer-grid{
  display:grid;
  grid-template-columns:1.1fr .9fr;
  gap:14px;
  margin-top:14px;
}
.employer-kpis{
  display:grid;
  grid-template-columns:repeat(4,1fr);
  gap:10px;
  margin-top:14px;
}
.employer-alert-list,
.employer-worker-list{
  display:flex;
  flex-direction:column;
  gap:10px;
  margin-top:12px;
}
.employer-alert-card,
.employer-worker-card{
  border:1px solid rgba(255,255,255,.06);
  border-radius:16px;
  background:rgba(255,255,255,.02);
  padding:12px;
}
.employer-card-head{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:10px;
}
.employer-card-title{
  font-size:15px;
  font-weight:900;
  line-height:1.35;
}
.employer-card-sub{
  font-size:12px;
  color:var(--swap-muted);
  margin-top:3px;
}
.employer-meta-grid{
  display:grid;
  grid-template-columns:repeat(4,1fr);
  gap:10px;
  margin-top:10px;
}
.employer-meta-item{
  border:1px solid rgba(255,255,255,.05);
  border-radius:12px;
  background:rgba(255,255,255,.02);
  padding:10px;
}
.employer-meta-label{
  font-size:11px;
  color:var(--swap-muted);
}
.employer-meta-value{
  margin-top:4px;
  font-size:13px;
  font-weight:900;
  line-height:1.35;
}
.employer-actions{
  display:flex;
  flex-wrap:wrap;
  gap:8px;
  margin-top:12px;
}
.employer-empty{
  color:var(--swap-muted);
  font-size:13px;
  line-height:1.45;
}
@media (max-width:980px){
  .employer-grid,
  .employer-kpis,
  .employer-meta-grid{
    grid-template-columns:1fr 1fr;
  }
}
@media (max-width:640px){
  .employer-grid,
  .employer-kpis,
  .employer-meta-grid{
    grid-template-columns:1fr;
  }
  .employer-actions{
    flex-direction:column;
    align-items:stretch;
  }
  .employer-actions .btn,
  .employer-actions .small-btn{
    width:100%;
  }
}
</style>
</head>
<body class="swap-page with-nav">
<?php require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/rwa-topbar-nav.php'; ?>

<main class="swap-shell">

  <div class="swap-headbar">
    <div class="swap-title-wrap">
      <div class="swap-page-title" data-i18n="page_title">Employer Dashboard</div>
      <div class="swap-page-sub" data-i18n="page_sub">Project worker overview, alerts, deployable readiness and welfare priority.</div>
    </div>
    <div class="lang-switch">
      <button type="button" class="lang-btn" data-lang-btn="en">EN</button>
      <button type="button" class="lang-btn" data-lang-btn="zh">中文</button>
    </div>
  </div>

  <section class="swap-hero">
    <div class="hero-top">
      <div>
        <div class="hero-badge-row">
          <span class="badge gold">SWAP 2.0</span>
          <span class="badge green" data-i18n="badge_employer">Employer</span>
          <span class="badge cyan"><?= h($projectKey) ?></span>
        </div>
        <div class="hero-title" data-i18n="hero_title">Project Workforce Control</div>
        <div class="hero-sub" data-i18n="hero_sub">
          Monitor worker count, deployable readiness, welfare average, alert conditions and priority follow-up for your assigned project.
        </div>

        <div class="hero-badge-row" style="margin-top:14px;">
          <span class="badge green"><?= h($nickname !== '' ? $nickname : 'Employer') ?></span>
          <?php if ($walletShort !== ''): ?>
            <span class="badge"><?= h($walletShort) ?></span>
          <?php endif; ?>
          <span class="badge" data-i18n="project_label">Project</span>
          <span class="badge gold"><?= h($projectKey) ?></span>
        </div>

        <div class="hero-actions">
          <a class="btn" href="/rwa/swap/employer/worker.php" data-i18n="btn_open_worker">Open Worker Drilldown</a>
          <a class="btn secondary" href="/rwa/swap/index.php" data-i18n="btn_back_swap">Back to SWAP</a>
        </div>
      </div>
      <div class="badge" data-i18n="session_ready">SESSION READY</div>
    </div>

    <div class="employer-kpis">
      <div class="kpi">
        <div class="kpi-label" data-i18n="kpi_total_workers">Total Workers</div>
        <div class="kpi-value"><?= (int)$k['total'] ?></div>
      </div>
      <div class="kpi">
        <div class="kpi-label" data-i18n="kpi_active_workers">Active</div>
        <div class="kpi-value"><?= (int)$k['active'] ?></div>
      </div>
      <div class="kpi">
        <div class="kpi-label" data-i18n="kpi_deployable_workers">Deployable</div>
        <div class="kpi-value"><?= (int)$k['deployable'] ?></div>
      </div>
      <div class="kpi">
        <div class="kpi-label" data-i18n="kpi_avg_welfare">Avg Welfare</div>
        <div class="kpi-value"><?= number_format((float)$k['avg_welfare'], 1) ?></div>
      </div>
    </div>
  </section>

  <div class="employer-grid">
    <section class="card">
      <div class="section-title" data-i18n="alerts_title">Priority Alerts</div>
      <div class="section-sub" data-i18n="alerts_sub">
        High-risk, non-deployable, or incomplete compliance workers requiring faster action.
      </div>

      <div class="employer-alert-list">
        <?php if (!$alerts): ?>
          <div class="employer-empty" data-i18n="alerts_empty">No priority alerts for this project right now.</div>
        <?php else: ?>
          <?php foreach ($alerts as $a): ?>
            <div class="employer-alert-card">
              <div class="employer-card-head">
                <div>
                  <div class="employer-card-title"><?= h($a['worker_uid']) ?> · <?= h($a['full_name']) ?></div>
                  <div class="employer-card-sub"><?= h($a['next_action']) ?></div>
                </div>
                <span class="badge warn"><?= h($a['risk_level'] ?: 'ALERT') ?></span>
              </div>

              <div class="employer-meta-grid">
                <div class="employer-meta-item">
                  <div class="employer-meta-label" data-i18n="field_worker_status">Worker Status</div>
                  <div class="employer-meta-value"><?= h($a['worker_status']) ?></div>
                </div>
                <div class="employer-meta-item">
                  <div class="employer-meta-label" data-i18n="field_deployable_status">Deployable</div>
                  <div class="employer-meta-value"><?= h($a['deployable_status']) ?></div>
                </div>
                <div class="employer-meta-item">
                  <div class="employer-meta-label" data-i18n="field_risk_level">Risk</div>
                  <div class="employer-meta-value"><?= h($a['risk_level']) ?></div>
                </div>
                <div class="employer-meta-item">
                  <div class="employer-meta-label" data-i18n="field_welfare_score">Welfare</div>
                  <div class="employer-meta-value"><?= h($a['welfare_score']) ?></div>
                </div>
              </div>

              <div class="employer-actions">
                <a class="small-btn" href="/rwa/swap/employer/worker.php?worker_uid=<?= urlencode((string)$a['worker_uid']) ?>" data-i18n="btn_view_worker">View Worker</a>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </section>

    <section class="card">
      <div class="section-title" data-i18n="summary_title">Project Summary</div>
      <div class="section-sub" data-i18n="summary_sub">
        Quick employer-facing operational summary for this assigned project.
      </div>

      <div class="result-grid" style="margin-top:12px;">
        <div class="result-item">
          <div class="result-key" data-i18n="project_label">Project</div>
          <div class="result-val"><?= h($projectKey) ?></div>
        </div>
        <div class="result-item">
          <div class="result-key" data-i18n="field_employer_name">Employer</div>
          <div class="result-val"><?= h($nickname !== '' ? $nickname : 'Employer') ?></div>
        </div>
        <div class="result-item">
          <div class="result-key" data-i18n="kpi_total_workers">Total Workers</div>
          <div class="result-val"><?= (int)$k['total'] ?></div>
        </div>
        <div class="result-item">
          <div class="result-key" data-i18n="kpi_deployable_workers">Deployable</div>
          <div class="result-val"><?= (int)$k['deployable'] ?></div>
        </div>
      </div>

      <div class="notice" style="margin-top:12px;" data-i18n="summary_notice">
        This page is project-scoped. Employers should only see workers under their assigned project key.
      </div>
    </section>
  </div>

  <section class="card swap-section">
    <div class="section-title" data-i18n="workers_title">Workers (Risk Priority)</div>
    <div class="section-sub" data-i18n="workers_sub">
      Lowest welfare and highest risk workers are shown first for faster employer follow-up.
    </div>

    <div class="employer-worker-list">
      <?php if (!$rows): ?>
        <div class="employer-empty" data-i18n="workers_empty">No workers found for this project.</div>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <?php
            $waLink = '';
            $digits = preg_replace('/\D+/', '', (string)($r['mobile_e164'] ?? ''));
            if ($digits !== '') {
                $waLink = 'https://wa.me/' . $digits;
            }
          ?>
          <div class="employer-worker-card">
            <div class="employer-card-head">
              <div>
                <div class="employer-card-title"><?= h($r['worker_uid']) ?> · <?= h($r['full_name']) ?></div>
                <div class="employer-card-sub">
                  <?= h(trim((string)($r['country_name'] ?? ''))) ?>
                  <?php if (!empty($r['state_name'])): ?> · <?= h($r['state_name']) ?><?php endif; ?>
                  <?php if (!empty($r['area_name'])): ?> · <?= h($r['area_name']) ?><?php endif; ?>
                </div>
              </div>
              <span class="badge <?= strtolower((string)($r['risk_level'] ?? '')) === 'critical' ? 'warn' : 'gold' ?>">
                <?= h($r['risk_level'] ?: 'normal') ?>
              </span>
            </div>

            <div class="employer-meta-grid">
              <div class="employer-meta-item">
                <div class="employer-meta-label" data-i18n="field_welfare_score">Welfare</div>
                <div class="employer-meta-value">
                  <?= h($r['welfare_score']) ?>
                  <?php if (!empty($r['welfare_band'])): ?> (<?= h($r['welfare_band']) ?>)<?php endif; ?>
                </div>
              </div>
              <div class="employer-meta-item">
                <div class="employer-meta-label" data-i18n="field_deployable_status">Deployable</div>
                <div class="employer-meta-value"><?= h($r['deployable_status']) ?></div>
              </div>
              <div class="employer-meta-item">
                <div class="employer-meta-label" data-i18n="field_worker_status">Worker Status</div>
                <div class="employer-meta-value"><?= h($r['worker_status']) ?></div>
              </div>
              <div class="employer-meta-item">
                <div class="employer-meta-label" data-i18n="field_next_action">Next Action</div>
                <div class="employer-meta-value"><?= h($r['next_action']) ?></div>
              </div>
            </div>

            <div class="employer-actions">
              <a class="small-btn" href="/rwa/swap/employer/worker.php?worker_uid=<?= urlencode((string)$r['worker_uid']) ?>" data-i18n="btn_view_worker">View Worker</a>
              <?php if ($waLink !== ''): ?>
                <a class="small-btn secondary" href="<?= h($waLink) ?>" target="_blank" rel="noopener noreferrer" data-i18n="btn_open_whatsapp">Open WhatsApp</a>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </section>

</main>

<?php require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/rwa-bottom-nav.php'; ?>

<script>
(function(){
  const dict = {
    en: {
      page_title:'Employer Dashboard',
      page_sub:'Project worker overview, alerts, deployable readiness and welfare priority.',
      badge_employer:'Employer',
      hero_title:'Project Workforce Control',
      hero_sub:'Monitor worker count, deployable readiness, welfare average, alert conditions and priority follow-up for your assigned project.',
      btn_open_worker:'Open Worker Drilldown',
      btn_back_swap:'Back to SWAP',
      session_ready:'SESSION READY',
      kpi_total_workers:'Total Workers',
      kpi_active_workers:'Active',
      kpi_deployable_workers:'Deployable',
      kpi_avg_welfare:'Avg Welfare',
      alerts_title:'Priority Alerts',
      alerts_sub:'High-risk, non-deployable, or incomplete compliance workers requiring faster action.',
      alerts_empty:'No priority alerts for this project right now.',
      field_worker_status:'Worker Status',
      field_deployable_status:'Deployable',
      field_risk_level:'Risk',
      field_welfare_score:'Welfare',
      btn_view_worker:'View Worker',
      summary_title:'Project Summary',
      summary_sub:'Quick employer-facing operational summary for this assigned project.',
      project_label:'Project',
      field_employer_name:'Employer',
      summary_notice:'This page is project-scoped. Employers should only see workers under their assigned project key.',
      workers_title:'Workers (Risk Priority)',
      workers_sub:'Lowest welfare and highest risk workers are shown first for faster employer follow-up.',
      workers_empty:'No workers found for this project.',
      field_next_action:'Next Action',
      btn_open_whatsapp:'Open WhatsApp'
    },
    zh: {
      page_title:'雇主面板',
      page_sub:'项目工人概览、提醒、可部署状态与福利优先级。',
      badge_employer:'雇主',
      hero_title:'项目工人控制台',
      hero_sub:'监控你所属项目的工人数、可部署状态、平均福利分数、提醒条件和优先跟进事项。',
      btn_open_worker:'打开工人详情',
      btn_back_swap:'返回 SWAP',
      session_ready:'会话已就绪',
      kpi_total_workers:'工人总数',
      kpi_active_workers:'在职',
      kpi_deployable_workers:'可部署',
      kpi_avg_welfare:'平均福利',
      alerts_title:'优先提醒',
      alerts_sub:'需要更快处理的高风险、不可部署或合规未完成工人。',
      alerts_empty:'当前项目暂无优先提醒。',
      field_worker_status:'工人状态',
      field_deployable_status:'可部署',
      field_risk_level:'风险',
      field_welfare_score:'福利',
      btn_view_worker:'查看工人',
      summary_title:'项目摘要',
      summary_sub:'此项目的雇主侧运营快速摘要。',
      project_label:'项目',
      field_employer_name:'雇主',
      summary_notice:'此页面按项目范围显示。雇主只能看到自己项目键下的工人。',
      workers_title:'工人列表（风险优先）',
      workers_sub:'福利最低和风险最高的工人优先显示，方便更快跟进。',
      workers_empty:'此项目下没有工人。',
      field_next_action:'下一步动作',
      btn_open_whatsapp:'打开 WhatsApp'
    }
  };

  function detectLang(){
    const saved = localStorage.getItem('poado_lang');
    if (saved === 'en' || saved === 'zh') return saved;
    const nav = (navigator.language || '').toLowerCase();
    return nav.includes('zh') ? 'zh' : 'en';
  }

  function applyLang(lang){
    const pack = dict[lang] || dict.en;
    document.documentElement.lang = lang === 'zh' ? 'zh-CN' : 'en';

    document.querySelectorAll('[data-i18n]').forEach((el) => {
      const key = el.getAttribute('data-i18n');
      if (pack[key]) el.textContent = pack[key];
    });

    document.querySelectorAll('[data-lang-btn]').forEach((btn) => {
      btn.classList.toggle('active', btn.getAttribute('data-lang-btn') === lang);
    });

    localStorage.setItem('poado_lang', lang);
  }

  const lang = detectLang();
  applyLang(lang);

  document.querySelectorAll('[data-lang-btn]').forEach((btn) => {
    btn.addEventListener('click', function(){
      applyLang(this.getAttribute('data-lang-btn'));
    });
  });
})();
</script>
</body>
</html>