<?php
declare(strict_types=1);

// /rwa/swap/employer/worker.php
// SWAP 2.0 Employer Worker Drilldown
// v2.0.0-20260326-redesign

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/swap/inc/swap-helpers.php';

$user = swap_require_login();
$pdo  = swap_db();

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/*
Baseline compatibility:
- current employer project scope is taken from logged-in user payload
- later can be swapped to rwa_hr_employers mapping if needed
*/
$projectKey = trim((string)($user['project_key'] ?? ''));
if ($projectKey === '') {
    http_response_code(403);
    exit('NO PROJECT ASSIGNED');
}

$workerUid = trim((string)($_GET['worker_uid'] ?? ''));
if ($workerUid === '') {
    http_response_code(400);
    exit('MISSING WORKER UID');
}

/* ===== WORKER ===== */
$stmt = $pdo->prepare("
    SELECT *
    FROM rwa_hr_workers
    WHERE worker_uid = :uid
      AND project_key = :pk
    LIMIT 1
");
$stmt->execute([
    ':uid' => $workerUid,
    ':pk'  => $projectKey,
]);
$worker = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$worker) {
    http_response_code(404);
    exit('WORKER NOT FOUND');
}

/* ===== WORK LOGS ===== */
$workLogs = [];
try {
    $q = $pdo->prepare("
        SELECT *
        FROM rwa_hr_work_logs
        WHERE worker_uid = :uid
        ORDER BY work_date DESC, id DESC
        LIMIT 50
    ");
    $q->execute([':uid' => $workerUid]);
    $workLogs = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $workLogs = [];
}

/* ===== CERT HISTORY ===== */
$certLogs = [];
try {
    $q = $pdo->prepare("
        SELECT *
        FROM rwa_hr_cert_logs
        WHERE worker_uid = :uid
        ORDER BY id DESC
        LIMIT 20
    ");
    $q->execute([':uid' => $workerUid]);
    $certLogs = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $certLogs = [];
}

$mobileDigits = preg_replace('/\D+/', '', (string)($worker['mobile_e164'] ?? $worker['mobile'] ?? ''));
$waLink = $mobileDigits !== '' ? 'https://wa.me/' . $mobileDigits : '';

$fullName = trim((string)($worker['full_name'] ?? ''));
$passport = trim((string)($worker['passport_no'] ?? ''));
$maskedPassport = $passport !== ''
    ? substr($passport, 0, 2) . str_repeat('*', max(strlen($passport) - 4, 2)) . substr($passport, -2)
    : '';

$wallet = trim((string)($worker['wallet_address'] ?? $worker['wallet'] ?? ''));
$walletShort = $wallet !== '' ? substr($wallet, 0, 6) . '...' . substr($wallet, -4) : '';

$workerStatus = trim((string)($worker['worker_status'] ?? ''));
$deployable = trim((string)($worker['deployable_status'] ?? ''));
$riskLevel = trim((string)($worker['risk_level'] ?? ''));
$welfareScore = (string)($worker['welfare_score'] ?? '');
$welfareBand = trim((string)($worker['welfare_band'] ?? ''));
$nextAction = trim((string)($worker['next_action'] ?? ''));

$locationBits = array_filter([
    (string)($worker['country_name'] ?? ''),
    (string)($worker['state_name'] ?? $worker['state'] ?? ''),
    (string)($worker['area_name'] ?? $worker['area'] ?? $worker['region'] ?? ''),
], fn($v) => trim((string)$v) !== '');
$locationText = $locationBits ? implode(' · ', $locationBits) : '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Employer Worker Detail</title>
<link rel="stylesheet" href="/rwa/swap/assets/css/swap.css?v=2.3.0-20260326">
<style>
.employer-worker-grid{
  display:grid;
  grid-template-columns:1.05fr .95fr;
  gap:14px;
  margin-top:14px;
}
.employer-worker-stack{
  display:flex;
  flex-direction:column;
  gap:14px;
}
.employer-summary-grid{
  display:grid;
  grid-template-columns:repeat(4,1fr);
  gap:10px;
  margin-top:12px;
}
.employer-detail-grid{
  display:grid;
  grid-template-columns:repeat(2,1fr);
  gap:10px;
  margin-top:12px;
}
.employer-detail-item{
  border:1px solid rgba(255,255,255,.06);
  border-radius:14px;
  background:rgba(255,255,255,.02);
  padding:10px 12px;
}
.employer-detail-label{
  font-size:11px;
  color:var(--swap-muted);
}
.employer-detail-value{
  margin-top:4px;
  font-size:14px;
  font-weight:900;
  line-height:1.4;
  word-break:break-word;
}
.employer-list{
  display:flex;
  flex-direction:column;
  gap:10px;
  margin-top:12px;
}
.employer-log-card{
  border:1px solid rgba(255,255,255,.06);
  border-radius:16px;
  background:rgba(255,255,255,.02);
  padding:12px;
}
.employer-log-head{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:10px;
}
.employer-log-title{
  font-size:14px;
  font-weight:900;
}
.employer-log-sub{
  margin-top:4px;
  font-size:12px;
  color:var(--swap-muted);
}
.employer-log-meta{
  display:grid;
  grid-template-columns:repeat(3,1fr);
  gap:10px;
  margin-top:10px;
}
.employer-log-meta .result-item{
  padding:10px;
}
.employer-empty{
  color:var(--swap-muted);
  font-size:13px;
  line-height:1.5;
}
@media (max-width:980px){
  .employer-worker-grid,
  .employer-summary-grid,
  .employer-detail-grid,
  .employer-log-meta{
    grid-template-columns:1fr 1fr;
  }
}
@media (max-width:640px){
  .employer-worker-grid,
  .employer-summary-grid,
  .employer-detail-grid,
  .employer-log-meta{
    grid-template-columns:1fr;
  }
  .hero-actions{
    flex-direction:column;
    align-items:stretch;
  }
  .hero-actions .btn,
  .hero-actions .small-btn{
    width:100%;
  }
}
</style>
</head>
<body class="swap-page with-nav">
<?php require $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-topbar-nav.php'; ?>

<main class="swap-shell">

  <div class="swap-headbar">
    <div class="swap-title-wrap">
      <div class="swap-page-title" data-i18n="page_title">Worker Detail</div>
      <div class="swap-page-sub" data-i18n="page_sub">Employer project-scoped worker identity, compliance, work logs and certificate history.</div>
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
          <span class="badge green" data-i18n="badge_worker_detail">Worker Detail</span>
          <span class="badge cyan"><?= h($projectKey) ?></span>
        </div>

        <div class="hero-title"><?= h($workerUid) ?><?php if ($fullName !== ''): ?> · <?= h($fullName) ?><?php endif; ?></div>
        <div class="hero-sub">
          <?= h($locationText !== '' ? $locationText : '—') ?>
        </div>

        <div class="hero-badge-row" style="margin-top:14px;">
          <span class="badge"><?= h($workerStatus !== '' ? $workerStatus : '—') ?></span>
          <span class="badge gold"><?= h($deployable !== '' ? $deployable : '—') ?></span>
          <span class="badge <?= strtolower($riskLevel) === 'critical' ? 'warn' : 'green' ?>"><?= h($riskLevel !== '' ? $riskLevel : '—') ?></span>
          <?php if ($walletShort !== ''): ?>
            <span class="badge"><?= h($walletShort) ?></span>
          <?php endif; ?>
        </div>

        <div class="hero-actions">
          <a class="btn secondary" href="/rwa/swap/employer/index.php" data-i18n="btn_back_dashboard">Back to Employer Dashboard</a>
          <?php if ($waLink !== ''): ?>
            <a class="btn" href="<?= h($waLink) ?>" target="_blank" rel="noopener noreferrer" data-i18n="btn_open_whatsapp">Open WhatsApp</a>
          <?php endif; ?>
          <a class="btn green" href="/rwa/swap/admin/print-worker.php?worker_uid=<?= urlencode($workerUid) ?>" target="_blank" rel="noopener noreferrer" data-i18n="btn_print_worker">Print Worker</a>
        </div>
      </div>

      <div class="badge" data-i18n="session_ready">SESSION READY</div>
    </div>

    <div class="employer-summary-grid">
      <div class="kpi">
        <div class="kpi-label" data-i18n="field_welfare_score">Welfare Score</div>
        <div class="kpi-value"><?= h($welfareScore !== '' ? $welfareScore : '0') ?></div>
      </div>
      <div class="kpi">
        <div class="kpi-label" data-i18n="field_welfare_band">Welfare Band</div>
        <div class="kpi-value"><?= h($welfareBand !== '' ? $welfareBand : '—') ?></div>
      </div>
      <div class="kpi">
        <div class="kpi-label" data-i18n="field_deployable_status">Deployable</div>
        <div class="kpi-value"><?= h($deployable !== '' ? $deployable : '—') ?></div>
      </div>
      <div class="kpi">
        <div class="kpi-label" data-i18n="field_next_action">Next Action</div>
        <div class="kpi-value"><?= h($nextAction !== '' ? $nextAction : '—') ?></div>
      </div>
    </div>
  </section>

  <div class="employer-worker-grid">
    <section class="card employer-worker-stack">
      <div>
        <div class="section-title" data-i18n="identity_title">Worker Identity Summary</div>
        <div class="section-sub" data-i18n="identity_sub">Project-scoped worker identity and core contact fields.</div>

        <div class="employer-detail-grid">
          <div class="employer-detail-item">
            <div class="employer-detail-label" data-i18n="field_worker_uid">Worker UID</div>
            <div class="employer-detail-value"><?= h($workerUid) ?></div>
          </div>
          <div class="employer-detail-item">
            <div class="employer-detail-label" data-i18n="field_full_name">Full Name</div>
            <div class="employer-detail-value"><?= h($fullName !== '' ? $fullName : '—') ?></div>
          </div>
          <div class="employer-detail-item">
            <div class="employer-detail-label" data-i18n="field_passport_masked">Masked Passport</div>
            <div class="employer-detail-value"><?= h($maskedPassport !== '' ? $maskedPassport : '—') ?></div>
          </div>
          <div class="employer-detail-item">
            <div class="employer-detail-label" data-i18n="field_project_code">Project Key</div>
            <div class="employer-detail-value"><?= h($projectKey) ?></div>
          </div>
          <div class="employer-detail-item">
            <div class="employer-detail-label" data-i18n="field_mobile">Mobile</div>
            <div class="employer-detail-value"><?= h($mobileDigits !== '' ? $mobileDigits : '—') ?></div>
          </div>
          <div class="employer-detail-item">
            <div class="employer-detail-label" data-i18n="field_location">Location</div>
            <div class="employer-detail-value"><?= h($locationText !== '' ? $locationText : '—') ?></div>
          </div>
        </div>
      </div>

      <div>
        <div class="section-title" data-i18n="compliance_title">Compliance / Welfare Overview</div>
        <div class="section-sub" data-i18n="compliance_sub">Employer-facing status, risk, readiness and follow-up focus.</div>

        <div class="employer-detail-grid">
          <div class="employer-detail-item">
            <div class="employer-detail-label" data-i18n="field_worker_status">Worker Status</div>
            <div class="employer-detail-value"><?= h($workerStatus !== '' ? $workerStatus : '—') ?></div>
          </div>
          <div class="employer-detail-item">
            <div class="employer-detail-label" data-i18n="field_deployable_status">Deployable</div>
            <div class="employer-detail-value"><?= h($deployable !== '' ? $deployable : '—') ?></div>
          </div>
          <div class="employer-detail-item">
            <div class="employer-detail-label" data-i18n="field_risk_level">Risk Level</div>
            <div class="employer-detail-value"><?= h($riskLevel !== '' ? $riskLevel : '—') ?></div>
          </div>
          <div class="employer-detail-item">
            <div class="employer-detail-label" data-i18n="field_welfare_band">Welfare Band</div>
            <div class="employer-detail-value"><?= h($welfareBand !== '' ? $welfareBand : '—') ?></div>
          </div>
          <div class="employer-detail-item" style="grid-column:1 / -1;">
            <div class="employer-detail-label" data-i18n="field_next_action">Next Action</div>
            <div class="employer-detail-value"><?= h($nextAction !== '' ? $nextAction : '—') ?></div>
          </div>
        </div>
      </div>
    </section>

    <section class="card employer-worker-stack">
      <div>
        <div class="section-title" data-i18n="support_title">Quick Actions</div>
        <div class="section-sub" data-i18n="support_sub">Open direct worker action links and print output.</div>

        <div class="hero-actions" style="margin-top:12px;">
          <?php if ($waLink !== ''): ?>
            <a class="btn" href="<?= h($waLink) ?>" target="_blank" rel="noopener noreferrer" data-i18n="btn_open_whatsapp">Open WhatsApp</a>
          <?php endif; ?>
          <a class="btn secondary" href="/rwa/swap/employer/index.php" data-i18n="btn_back_dashboard">Back to Employer Dashboard</a>
          <a class="btn green" href="/rwa/swap/admin/print-worker.php?worker_uid=<?= urlencode($workerUid) ?>" target="_blank" rel="noopener noreferrer" data-i18n="btn_print_worker">Print Worker</a>
        </div>
      </div>

      <div>
        <div class="section-title" data-i18n="notes_title">Employer Note</div>
        <div class="section-sub" data-i18n="notes_sub">Project-scoped review only. Sensitive fields remain controlled by role scope.</div>

        <div class="notice" style="margin-top:12px;">
          <?= h($nextAction !== '' ? $nextAction : 'No specific next action recorded.') ?>
        </div>
      </div>
    </section>
  </div>

  <section class="card swap-section">
    <div class="section-title" data-i18n="work_logs_title">Work Logs</div>
    <div class="section-sub" data-i18n="work_logs_sub">Latest logged work-hour records for this worker.</div>

    <div class="employer-list">
      <?php if (!$workLogs): ?>
        <div class="employer-empty" data-i18n="work_logs_empty">No work logs found for this worker.</div>
      <?php else: ?>
        <?php foreach ($workLogs as $log): ?>
          <?php
            $workDate = (string)($log['work_date'] ?? $log['created_at'] ?? '—');
            $hoursVal = (string)($log['hours'] ?? $log['work_hours'] ?? $log['hours_logged'] ?? '—');
            $memoVal  = (string)($log['memo'] ?? $log['note'] ?? $log['remark'] ?? '');
            $typeVal  = (string)($log['log_type'] ?? $log['type'] ?? 'WORK');
          ?>
          <div class="employer-log-card">
            <div class="employer-log-head">
              <div>
                <div class="employer-log-title"><?= h($typeVal) ?></div>
                <div class="employer-log-sub"><?= h($workDate) ?></div>
              </div>
              <span class="badge gold"><?= h($hoursVal) ?></span>
            </div>

            <div class="employer-log-meta">
              <div class="result-item">
                <div class="result-key" data-i18n="field_work_date">Work Date</div>
                <div class="result-val"><?= h($workDate) ?></div>
              </div>
              <div class="result-item">
                <div class="result-key" data-i18n="field_work_hours">Hours</div>
                <div class="result-val"><?= h($hoursVal) ?></div>
              </div>
              <div class="result-item">
                <div class="result-key" data-i18n="field_work_memo">Memo</div>
                <div class="result-val"><?= h($memoVal !== '' ? $memoVal : '—') ?></div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </section>

  <section class="card swap-section">
    <div class="section-title" data-i18n="cert_history_title">Certificate History</div>
    <div class="section-sub" data-i18n="cert_history_sub">Latest RHRD certificate issuance records linked to this worker.</div>

    <div class="employer-list">
      <?php if (!$certLogs): ?>
        <div class="employer-empty" data-i18n="cert_history_empty">No certificate history found for this worker.</div>
      <?php else: ?>
        <?php foreach ($certLogs as $cert): ?>
          <?php
            $certUid = (string)($cert['cert_uid'] ?? $cert['rwa_uid'] ?? $cert['uid'] ?? '—');
            $issuedAt = (string)($cert['issued_at'] ?? $cert['created_at'] ?? '—');
            $hoursUsed = (string)($cert['hours_used'] ?? $cert['consumed_hours'] ?? '—');
            $statusVal = (string)($cert['status'] ?? 'issued');
          ?>
          <div class="employer-log-card">
            <div class="employer-log-head">
              <div>
                <div class="employer-log-title"><?= h($certUid) ?></div>
                <div class="employer-log-sub"><?= h($issuedAt) ?></div>
              </div>
              <span class="badge green"><?= h($statusVal) ?></span>
            </div>

            <div class="employer-log-meta">
              <div class="result-item">
                <div class="result-key" data-i18n="field_cert_uid">Cert UID</div>
                <div class="result-val"><?= h($certUid) ?></div>
              </div>
              <div class="result-item">
                <div class="result-key" data-i18n="field_cert_hours">Hours Used</div>
                <div class="result-val"><?= h($hoursUsed) ?></div>
              </div>
              <div class="result-item">
                <div class="result-key" data-i18n="field_cert_status">Status</div>
                <div class="result-val"><?= h($statusVal) ?></div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </section>

</main>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-bottom-nav.php'; ?>

<script>
(function(){
  const dict = {
    en: {
      page_title:'Worker Detail',
      page_sub:'Employer project-scoped worker identity, compliance, work logs and certificate history.',
      badge_worker_detail:'Worker Detail',
      session_ready:'SESSION READY',
      btn_back_dashboard:'Back to Employer Dashboard',
      btn_open_whatsapp:'Open WhatsApp',
      btn_print_worker:'Print Worker',
      field_welfare_score:'Welfare Score',
      field_welfare_band:'Welfare Band',
      field_deployable_status:'Deployable',
      field_next_action:'Next Action',
      identity_title:'Worker Identity Summary',
      identity_sub:'Project-scoped worker identity and core contact fields.',
      field_worker_uid:'Worker UID',
      field_full_name:'Full Name',
      field_passport_masked:'Masked Passport',
      field_project_code:'Project Key',
      field_mobile:'Mobile',
      field_location:'Location',
      compliance_title:'Compliance / Welfare Overview',
      compliance_sub:'Employer-facing status, risk, readiness and follow-up focus.',
      field_worker_status:'Worker Status',
      field_risk_level:'Risk Level',
      support_title:'Quick Actions',
      support_sub:'Open direct worker action links and print output.',
      notes_title:'Employer Note',
      notes_sub:'Project-scoped review only. Sensitive fields remain controlled by role scope.',
      work_logs_title:'Work Logs',
      work_logs_sub:'Latest logged work-hour records for this worker.',
      work_logs_empty:'No work logs found for this worker.',
      field_work_date:'Work Date',
      field_work_hours:'Hours',
      field_work_memo:'Memo',
      cert_history_title:'Certificate History',
      cert_history_sub:'Latest RHRD certificate issuance records linked to this worker.',
      cert_history_empty:'No certificate history found for this worker.',
      field_cert_uid:'Cert UID',
      field_cert_hours:'Hours Used',
      field_cert_status:'Status'
    },
    zh: {
      page_title:'工人详情',
      page_sub:'雇主项目范围内的工人身份、合规、工时日志与证书历史。',
      badge_worker_detail:'工人详情',
      session_ready:'会话已就绪',
      btn_back_dashboard:'返回雇主面板',
      btn_open_whatsapp:'打开 WhatsApp',
      btn_print_worker:'打印工人',
      field_welfare_score:'福利分数',
      field_welfare_band:'福利等级',
      field_deployable_status:'可部署',
      field_next_action:'下一步动作',
      identity_title:'工人身份摘要',
      identity_sub:'项目范围内的工人身份与核心联系字段。',
      field_worker_uid:'工人 UID',
      field_full_name:'姓名',
      field_passport_masked:'护照掩码',
      field_project_code:'项目键',
      field_mobile:'手机',
      field_location:'地点',
      compliance_title:'合规 / 福利概览',
      compliance_sub:'面向雇主的状态、风险、准备度与后续重点。',
      field_worker_status:'工人状态',
      field_risk_level:'风险等级',
      support_title:'快捷操作',
      support_sub:'打开直接操作链接与打印输出。',
      notes_title:'雇主备注',
      notes_sub:'仅限项目范围审核。敏感字段仍受角色范围控制。',
      work_logs_title:'工时日志',
      work_logs_sub:'该工人的最新工时记录。',
      work_logs_empty:'该工人暂无工时日志。',
      field_work_date:'工作日期',
      field_work_hours:'工时',
      field_work_memo:'备注',
      cert_history_title:'证书历史',
      cert_history_sub:'与该工人关联的最新 RHRD 证书记录。',
      cert_history_empty:'该工人暂无证书历史。',
      field_cert_uid:'证书 UID',
      field_cert_hours:'使用工时',
      field_cert_status:'状态'
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