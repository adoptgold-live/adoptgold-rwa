<?php
// /var/www/html/public/rwa/login-select.php
// v1.3.0-20260326-login-select-tertiary-rwa
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

/**
 * Keep original loop fix:
 * - do NOT include /rwa/inc/rwa-session.php here
 * - use same auth basis as /rwa/index.php to avoid loop
 */
$userId = function_exists('session_user_id') ? (int) session_user_id() : 0;
if ($userId <= 0) {
    header('Location: /rwa/index.php', true, 302);
    exit;
}

$user = function_exists('session_user') ? session_user() : null;
if (!is_array($user)) {
    $user = [];
}

$nickname      = trim((string)($user['nickname'] ?? ($_SESSION['nickname'] ?? '')));
$walletAddress = trim((string)($user['wallet_address'] ?? ($_SESSION['wallet_address'] ?? '')));
$loginMethod   = trim((string)($user['auth_method'] ?? ($_SESSION['auth_method'] ?? 'RWA')));

$hasTon = ($walletAddress !== '');

$roleCards = [
    ['key' => 'health',   'emoji' => '❤', 'href' => '/rwa/login-select.php?industry=health'],
    ['key' => 'property', 'emoji' => '▣', 'href' => '/rwa/login-select.php?industry=property'],
    ['key' => 'travel',   'emoji' => '✈', 'href' => '/rwa/login-select.php?industry=travel'],
];

$currentIndustry = strtolower(trim((string)($_GET['industry'] ?? '')));
if (!in_array($currentIndustry, ['health', 'property', 'travel', 'hr'], true)) {
    $currentIndustry = '';
}

$bindHref = '/rwa/profile/index.php#bind-ton-card';

$liveRows = [
    ['industry' => 'health',   'ace' => 'ACE102', 'amount' => '120 USDT-TON', 'time' => '08:10'],
    ['industry' => 'property', 'ace' => 'ACE088', 'amount' => '80 USDT-TON',  'time' => '08:02'],
    ['industry' => 'travel',   'ace' => 'ACE211', 'amount' => '50 USDT-TON',  'time' => '07:54'],
    ['industry' => 'health',   'ace' => 'ACE019', 'amount' => '200 USDT-TON', 'time' => '07:41'],
];

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>RWA Launcher</title>
<style>
:root{
  --bg:#070211;
  --bg2:#11061f;
  --card:#0d0717;
  --card2:#130a23;
  --line:rgba(193,116,255,.26);
  --line2:rgba(255,215,0,.20);
  --text:#efe7ff;
  --muted:rgba(239,231,255,.68);
  --purple:#b56cff;
  --purple2:#8a39ff;
  --gold:#ffd86b;
  --green:#7bff8a;
  --warn:#ffcf70;
  --shadow:0 0 28px rgba(181,108,255,.12);
  --radius:18px;
}
*{box-sizing:border-box}
html,body{
  margin:0;
  padding:0;
  background:
    radial-gradient(circle at top, rgba(149,76,255,.12), transparent 28%),
    linear-gradient(180deg,var(--bg2),var(--bg));
  color:var(--text);
  font-family:Inter,ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
}
a{text-decoration:none;color:inherit}
button{font:inherit}
.rwa-shell{max-width:760px;margin:0 auto;padding:12px 12px 98px}
.rwa-head{display:flex;align-items:center;gap:10px;margin:8px 0 12px}
.rwa-title-wrap{flex:1;min-width:0}
.rwa-title{font-size:20px;font-weight:900;letter-spacing:.02em}
.rwa-sub{font-size:12px;color:var(--muted);margin-top:2px}
.lang-switch{display:flex;gap:6px}
.lang-btn{
  border:1px solid var(--line);
  background:rgba(181,108,255,.08);
  color:var(--text);
  padding:8px 10px;
  border-radius:12px;
  font-size:12px;
  font-weight:800;
  cursor:pointer;
}
.lang-btn.active{
  border-color:var(--gold);
  background:rgba(255,216,107,.12);
  color:var(--gold);
}
.card{
  background:linear-gradient(180deg,rgba(18,10,34,.96),rgba(10,6,17,.96));
  border:1px solid var(--line);
  border-radius:var(--radius);
  box-shadow:0 0 28px rgba(181,108,255,.12);
  padding:14px;
  margin-bottom:12px;
}
.search-card{padding:10px 12px}
.search-wrap{
  display:flex;
  align-items:center;
  gap:10px;
  background:rgba(255,255,255,.02);
  border:1px solid var(--line2);
  border-radius:14px;
  padding:10px 12px;
}
.search-ico{font-size:14px;color:var(--gold)}
.search-input{
  border:0;
  background:transparent;
  color:#fff;
  width:100%;
  outline:none;
  font-size:14px;
}
.search-input::placeholder{color:rgba(255,255,255,.42)}
.section-title{font-size:15px;font-weight:900;letter-spacing:.02em}
.section-sub{font-size:12px;color:var(--muted);margin-top:3px}
.bind-card{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
}
.bind-left{min-width:0;flex:1}
.chip{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-height:28px;
  padding:5px 10px;
  border-radius:999px;
  border:1px solid var(--line);
  font-size:11px;
  font-weight:900;
}
.chip.ok{
  border-color:rgba(123,255,138,.35);
  color:var(--green);
  background:rgba(123,255,138,.08);
}
.chip.warn{
  border-color:rgba(255,207,112,.35);
  color:var(--warn);
  background:rgba(255,207,112,.08);
}
.chip.live{
  border-color:rgba(255,216,107,.35);
  color:var(--gold);
  background:rgba(255,216,107,.08);
}
.bind-meta{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}
.cta{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-height:46px;
  border-radius:14px;
  padding:10px 16px;
  border:1px solid rgba(255,216,107,.38);
  background:linear-gradient(180deg,rgba(255,216,107,.14),rgba(181,108,255,.10));
  color:#fff;
  font-size:13px;
  font-weight:900;
  min-width:152px;
}
.industry-grid{
  display:grid;
  grid-template-columns:1fr 1fr 1fr;
  gap:10px;
  margin-top:10px;
}
.industry-btn{
  min-height:110px;
  border-radius:16px;
  border:1px solid rgba(181,108,255,.34);
  background:linear-gradient(180deg,rgba(181,108,255,.12),rgba(255,255,255,.03));
  box-shadow:0 0 20px rgba(181,108,255,.08);
  display:flex;
  flex-direction:column;
  align-items:center;
  justify-content:center;
  gap:8px;
  padding:12px 10px;
  text-align:center;
  position:relative;
  overflow:hidden;
}
.industry-btn.active{
  border-color:rgba(255,216,107,.55);
  background:linear-gradient(180deg,rgba(255,216,107,.16),rgba(181,108,255,.10));
  box-shadow:0 0 0 1px rgba(255,216,107,.14), 0 0 26px rgba(255,216,107,.10);
}
.industry-btn:before{
  content:"";
  position:absolute;
  inset:0;
  background:radial-gradient(circle at top, rgba(255,255,255,.08), transparent 46%);
  pointer-events:none;
}
.industry-emoji{
  font-size:26px;
  line-height:1;
  position:relative;
  z-index:1;
}
.industry-name{
  font-size:14px;
  font-weight:900;
  position:relative;
  z-index:1;
}
.industry-note{
  font-size:11px;
  color:var(--muted);
  position:relative;
  z-index:1;
}

/* NEW: tertiary full-width button */
.tertiary-btn{
  width:100%;
  min-height:96px;
  margin-top:12px;
  border-radius:16px;
  border:1px solid rgba(123,255,138,.35);
  background:linear-gradient(180deg,rgba(123,255,138,.12),rgba(255,255,255,.03));
  box-shadow:0 0 20px rgba(123,255,138,.08);
  display:flex;
  flex-direction:row;
  align-items:center;
  justify-content:flex-start;
  gap:14px;
  padding:16px 18px;
  text-align:left;
  position:relative;
  overflow:hidden;
}
.tertiary-btn.active{
  border-color:rgba(255,216,107,.55);
  background:linear-gradient(180deg,rgba(255,216,107,.16),rgba(123,255,138,.08));
  box-shadow:0 0 0 1px rgba(255,216,107,.14), 0 0 26px rgba(255,216,107,.10);
}
.tertiary-btn .industry-emoji{
  font-size:30px;
  min-width:34px;
}
.tertiary-copy{
  display:flex;
  flex-direction:column;
  gap:4px;
  position:relative;
  z-index:1;
}
.tertiary-copy .industry-name{
  font-size:15px;
}
.tertiary-copy .industry-note{
  font-size:12px;
}
.live-head{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:10px;
  margin-bottom:10px;
}
.live-list{display:flex;flex-direction:column;gap:8px}
.live-row{
  display:grid;
  grid-template-columns:auto 1fr auto;
  gap:8px;
  align-items:center;
  border:1px solid rgba(255,255,255,.05);
  background:rgba(255,255,255,.02);
  border-radius:12px;
  padding:9px 10px;
}
.live-time{font-size:11px;color:var(--gold);font-weight:900}
.live-main{min-width:0}
.live-industry{font-size:12px;font-weight:900}
.live-meta{font-size:11px;color:var(--muted)}
.live-amt{font-size:12px;font-weight:900;color:#fff}
.id-badge{
  display:inline-flex;
  align-items:center;
  padding:4px 8px;
  border-radius:999px;
  border:1px solid var(--line);
  font-size:11px;
  font-weight:800;
  color:var(--muted);
}
@media (max-width:560px){
  .bind-card{flex-direction:column;align-items:stretch}
  .cta{width:100%}
  .tertiary-btn{
    align-items:flex-start;
    padding:14px;
  }
}
</style>
</head>
<body>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-topbar-nav.php'; ?>

<div class="rwa-shell">
  <div class="rwa-head">
    <div class="rwa-title-wrap">
      <div class="rwa-title" data-i18n="launcher_title">RWA Launcher</div>
      <div class="rwa-sub" data-i18n="launcher_sub">Select industry</div>
    </div>
    <div class="id-badge">#<?= (int)$userId ?></div>
    <div class="lang-switch">
      <button type="button" class="lang-btn" data-lang-btn="en">EN</button>
      <button type="button" class="lang-btn" data-lang-btn="zh">中文</button>
    </div>
  </div>

  <div class="card search-card">
    <div class="search-wrap">
      <div class="search-ico">⌕</div>
      <input id="launcher-search" class="search-input" type="text" data-i18n-placeholder="search_placeholder" placeholder="Search RWA">
    </div>
  </div>

  <div class="card">
    <div class="bind-card">
      <div class="bind-left">
        <div class="section-title" data-i18n="bind_ton_address">Bind TON Address</div>
        <div class="section-sub" data-i18n="<?= $hasTon ? 'ton_bound_note' : 'ton_missing_note' ?>">
          <?= $hasTon ? 'Primary ecosystem address is ready' : 'Bind TON before using Storage, Mining, Cert and Market' ?>
        </div>
        <div class="bind-meta">
          <span class="chip <?= $hasTon ? 'ok' : 'warn' ?>" data-i18n="<?= $hasTon ? 'ton_bound' : 'need_ton' ?>">
            <?= $hasTon ? 'TON Bound' : 'Need TON' ?>
          </span>
          <span class="chip ok"><?= h($nickname !== '' ? $nickname : ('User '.$userId)) ?></span>
          <span class="chip ok"><?= h($loginMethod !== '' ? $loginMethod : 'RWA') ?></span>
          <?php if ($walletAddress !== ''): ?>
            <span class="chip ok"><?= h(substr($walletAddress, 0, 6) . '...' . substr($walletAddress, -4)) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <a class="cta" href="<?= h($bindHref) ?>" data-i18n="btn_bind_ton_address">
        <?= $hasTon ? 'Open TON Binding' : 'Bind TON Address' ?>
      </a>
    </div>
  </div>

  <div class="card">
    <div class="section-title" data-i18n="select_rwa_industry">Select RWA Industry</div>
    <div class="section-sub" data-i18n="select_rwa_industry_note">Select role is used only for Adoptee, ACE and Adopter</div>

    <div class="industry-grid">
      <?php foreach ($roleCards as $r): ?>
        <a class="industry-btn<?= $currentIndustry === $r['key'] ? ' active' : '' ?>" href="<?= h($r['href']) ?>" data-search="<?= h($r['key']) ?>">
          <div class="industry-emoji"><?= h($r['emoji']) ?></div>
          <div class="industry-name" data-i18n="industry_<?= h($r['key']) ?>"><?= ucfirst($r['key']) ?></div>
          <div class="industry-note" data-i18n="tap_to_open">Tap to open</div>
        </a>
      <?php endforeach; ?>
    </div>

    <a class="tertiary-btn<?= $currentIndustry === 'hr' ? ' active' : '' ?>" href="/rwa/swap/index.php" data-search="human resources hr swap rhrd tertiary rwa">
      <div class="industry-emoji">👷</div>
      <div class="tertiary-copy">
        <div class="industry-name" data-i18n="industry_hr">Human Resources RWA</div>
        <div class="industry-note" data-i18n="industry_hr_note">Tertiary RWA · RHRD-EMA</div>
      </div>
    </a>
  </div>

  <div class="card">
    <div class="live-head">
      <div>
        <div class="section-title" data-i18n="gold_packet_contribution_live">Gold Packet Contribution (Live)</div>
        <div class="section-sub" data-i18n="gold_packet_contribution_live_note">Recent ACE contribution records from each Secondary RWA dashboard</div>
      </div>
      <span class="chip live">LIVE</span>
    </div>

    <div class="live-list">
      <?php foreach ($liveRows as $row): ?>
        <div class="live-row">
          <div class="live-time"><?= h($row['time']) ?></div>
          <div class="live-main">
            <div class="live-industry" data-i18n="industry_<?= h($row['industry']) ?>"><?= ucfirst($row['industry']) ?></div>
            <div class="live-meta"><?= h($row['ace']) ?></div>
          </div>
          <div class="live-amt"><?= h($row['amount']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-bottom-nav.php'; ?>

<script>
(function(){
  const dict = {
    en: {
      launcher_title:'RWA Launcher',
      launcher_sub:'Select industry',
      search_placeholder:'Search RWA',
      bind_ton_address:'Bind TON Address',
      ton_bound:'TON Bound',
      need_ton:'Need TON',
      ton_bound_note:'Primary ecosystem address is ready',
      ton_missing_note:'Bind TON before using Storage, Mining, Cert and Market',
      btn_bind_ton_address:'Bind TON Address',
      btn_open_ton_binding:'Open TON Binding',
      select_rwa_industry:'Select RWA Industry',
      select_rwa_industry_note:'Select role is used only for Adoptee, ACE and Adopter',
      industry_health:'Health',
      industry_property:'Property',
      industry_travel:'Travel',
      industry_hr:'Human Resources RWA',
      industry_hr_note:'Tertiary RWA · RHRD-EMA',
      tap_to_open:'Tap to open',
      gold_packet_contribution_live:'Gold Packet Contribution (Live)',
      gold_packet_contribution_live_note:'Recent ACE contribution records from each Secondary RWA dashboard'
    },
    zh: {
      launcher_title:'RWA 启动器',
      launcher_sub:'选择行业',
      search_placeholder:'搜索 RWA',
      bind_ton_address:'绑定 TON 地址',
      ton_bound:'TON 已绑定',
      need_ton:'需要 TON',
      ton_bound_note:'主生态地址已就绪',
      ton_missing_note:'使用 Storage、Mining、Cert 和 Market 前请先绑定 TON',
      btn_bind_ton_address:'绑定 TON 地址',
      btn_open_ton_binding:'打开 TON 绑定',
      select_rwa_industry:'选择 RWA 行业',
      select_rwa_industry_note:'Select Role 仅用于 Adoptee、ACE 和 Adopter',
      industry_health:'健康',
      industry_property:'房产',
      industry_travel:'旅游',
      industry_hr:'人力资源 RWA',
      industry_hr_note:'三级 RWA · RHRD-EMA',
      tap_to_open:'点击进入',
      gold_packet_contribution_live:'Gold Packet Contribution（实时）',
      gold_packet_contribution_live_note:'显示来自各 Secondary RWA 面板的 ACE 最新贡献记录'
    }
  };

  function detectLang(){
    const saved = localStorage.getItem('poado_lang');
    if (saved === 'en' || saved === 'zh') return saved;
    const nav = (navigator.language || '').toLowerCase();
    return nav.includes('zh') ? 'zh' : 'en';
  }

  function setLang(lang){
    const pack = dict[lang] || dict.en;
    document.documentElement.lang = lang === 'zh' ? 'zh-CN' : 'en';
    localStorage.setItem('poado_lang', lang);

    document.querySelectorAll('[data-i18n]').forEach(el => {
      const key = el.getAttribute('data-i18n');
      if (pack[key]) el.textContent = pack[key];
    });

    document.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
      const key = el.getAttribute('data-i18n-placeholder');
      if (pack[key]) el.setAttribute('placeholder', pack[key]);
    });

    const bindBtn = document.querySelector('[data-i18n="btn_bind_ton_address"]');
    if (bindBtn) {
      const hasTon = <?= $hasTon ? 'true' : 'false' ?>;
      bindBtn.textContent = hasTon
        ? (pack.btn_open_ton_binding || 'Open TON Binding')
        : (pack.btn_bind_ton_address || 'Bind TON Address');
    }

    document.querySelectorAll('[data-lang-btn]').forEach(btn => {
      btn.classList.toggle('active', btn.getAttribute('data-lang-btn') === lang);
    });
  }

  const lang = detectLang();
  setLang(lang);

  document.querySelectorAll('[data-lang-btn]').forEach(btn => {
    btn.addEventListener('click', function(){
      setLang(this.getAttribute('data-lang-btn'));
    });
  });

  const search = document.getElementById('launcher-search');
  const cards = Array.from(document.querySelectorAll('.industry-btn, .tertiary-btn, .live-row'));
  if (search) {
    search.addEventListener('input', function(){
      const q = (this.value || '').trim().toLowerCase();
      cards.forEach(card => {
        const hay = ((card.getAttribute('data-search') || '') + ' ' + card.textContent).toLowerCase();
        card.style.display = (!q || hay.includes(q)) ? '' : 'none';
      });
    });
  }
})();
</script>
</body>
</html>