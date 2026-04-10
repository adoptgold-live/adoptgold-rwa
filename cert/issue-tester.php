<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/issue-tester.php
 *
 * Purpose:
 * - finalize certificate issue card format visually before live issue flow
 * - preview all 8 cert formats in one place
 * - switch EN / 中 instantly
 * - inspect title / subtitle / price / weight / family / CTA layout
 * - open selected cert into enlarged focus panel
 *
 * Notes:
 * - visual tester only
 * - does not write DB
 * - does not mint
 * - does not call live issue endpoint
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/session-user.php';

if (function_exists('session_user_require')) {
    session_user_require();
}

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$user = function_exists('session_user') ? (session_user() ?: []) : [];
$nickname = (string)($user['nickname'] ?? $user['display_name'] ?? 'Owner');
$wallet   = (string)($user['wallet'] ?? $user['wallet_address'] ?? '');
$walletShort = $wallet !== '' && strlen($wallet) > 18
    ? substr($wallet, 0, 8) . '...' . substr($wallet, -8)
    : ($wallet !== '' ? $wallet : '-');

$cards = [
    'green' => [
        'type' => 'green',
        'family' => 'GENESIS',
        'label_en' => 'Green Cert',
        'label_zh' => 'Green 证书',
        'name_en' => 'Carbon Responsibility',
        'name_zh' => '碳责任',
        'rwa_key' => 'RCO2C-EMA',
        'weight' => 1,
        'price' => '1000 wEMS',
        'theme' => 'green',
        'template' => '/rwa/metadata/nft/rco2c.png',
        'note_en' => 'Genesis issuance. Base environmental responsibility anchor.',
        'note_zh' => 'Genesis 发行。基础环境责任锚点。',
        'cta_en' => 'Issue Green',
        'cta_zh' => '发行 Green',
    ],
    'blue' => [
        'type' => 'blue',
        'family' => 'GENESIS',
        'label_en' => 'Blue Cert',
        'label_zh' => 'Blue 证书',
        'name_en' => 'Water Responsibility',
        'name_zh' => '水责任',
        'rwa_key' => 'RH2O-EMA',
        'weight' => 2,
        'price' => '5000 wEMS',
        'theme' => 'blue',
        'template' => '/rwa/metadata/nft/rh2o.png',
        'note_en' => 'Requires 10 minted Green certs before issuance.',
        'note_zh' => '发行前需先拥有 10 个已铸造 Green 证书。',
        'cta_en' => 'Issue Blue',
        'cta_zh' => '发行 Blue',
    ],
    'black' => [
        'type' => 'black',
        'family' => 'GENESIS',
        'label_en' => 'Black Cert',
        'label_zh' => 'Black 证书',
        'name_en' => 'Energy Responsibility',
        'name_zh' => '能源责任',
        'rwa_key' => 'RBLACK-EMA',
        'weight' => 3,
        'price' => '10000 wEMS',
        'theme' => 'black',
        'template' => '/rwa/metadata/nft/rblack.png',
        'note_en' => 'Requires 1 minted Gold cert before issuance.',
        'note_zh' => '发行前需先拥有 1 个已铸造 Gold 证书。',
        'cta_en' => 'Issue Black',
        'cta_zh' => '发行 Black',
    ],
    'gold' => [
        'type' => 'gold',
        'family' => 'GENESIS',
        'label_en' => 'Gold Cert',
        'label_zh' => 'Gold 证书',
        'name_en' => 'Gold Mining Responsibility',
        'name_zh' => '黄金开采责任',
        'rwa_key' => 'RK92-EMA',
        'weight' => 5,
        'price' => '50000 wEMS',
        'theme' => 'gold',
        'template' => '/rwa/metadata/nft/rk92.png',
        'note_en' => 'Premium Genesis issuance with highest Genesis weight.',
        'note_zh' => '高级 Genesis 发行，具备最高 Genesis 权重。',
        'cta_en' => 'Issue Gold',
        'cta_zh' => '发行 Gold',
    ],
    'yellow' => [
        'type' => 'yellow',
        'family' => 'TERTIARY',
        'label_en' => 'Yellow Cert',
        'label_zh' => 'Yellow 证书',
        'name_en' => 'Human Resources',
        'name_zh' => '人力资源',
        'rwa_key' => 'RHRD-EMA',
        'weight' => 7,
        'price' => '100 EMA$',
        'theme' => 'yellow',
        'template' => '/rwa/metadata/nft/rhrd.png',
        'note_en' => 'Tertiary governance-class issuance.',
        'note_zh' => 'Tertiary 治理级发行。',
        'cta_en' => 'Issue Human Resources',
        'cta_zh' => '发行人力资源证书',
    ],
    'pink' => [
        'type' => 'pink',
        'family' => 'SECONDARY',
        'label_en' => 'Pink Cert',
        'label_zh' => 'Pink 证书',
        'name_en' => 'Health',
        'name_zh' => '健康',
        'rwa_key' => 'RLIFE-EMA',
        'weight' => 10,
        'price' => '100 EMA$',
        'theme' => 'pink',
        'template' => '/rwa/metadata/nft/rlife.png',
        'note_en' => 'Secondary issuance. Industry match required.',
        'note_zh' => 'Secondary 发行。需行业匹配。',
        'cta_en' => 'Issue Health',
        'cta_zh' => '发行健康证书',
    ],
    'royal_blue' => [
        'type' => 'royal_blue',
        'family' => 'SECONDARY',
        'label_en' => 'Royal Blue Cert',
        'label_zh' => 'Royal Blue 证书',
        'name_en' => 'Property',
        'name_zh' => '房产',
        'rwa_key' => 'RPROP-EMA',
        'weight' => 10,
        'price' => '100 EMA$',
        'theme' => 'royal_blue',
        'template' => '/rwa/metadata/nft/rprop.png',
        'note_en' => 'Secondary issuance. Industry match required.',
        'note_zh' => 'Secondary 发行。需行业匹配。',
        'cta_en' => 'Issue Property',
        'cta_zh' => '发行房产证书',
    ],
    'red' => [
        'type' => 'red',
        'family' => 'SECONDARY',
        'label_en' => 'Red Cert',
        'label_zh' => 'Red 证书',
        'name_en' => 'Travel',
        'name_zh' => '旅游',
        'rwa_key' => 'RTRIP-EMA',
        'weight' => 10,
        'price' => '100 EMA$',
        'theme' => 'red',
        'template' => '/rwa/metadata/nft/rtrip.png',
        'note_en' => 'Secondary issuance. Industry match required.',
        'note_zh' => 'Secondary 发行。需行业匹配。',
        'cta_en' => 'Issue Travel',
        'cta_zh' => '发行旅游证书',
    ],
];

$footerText = '© 2026 Blockchain Group RWA FZCO (DMCC, Dubai, UAE) · RWA Standard Organisation (RSO). All rights reserved.';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>RWA Cert Issue Tester</title>
<style>
:root{
  --bg:#090b10;
  --bg2:#12151d;
  --panel:#11141b;
  --panel2:#171b24;
  --line:rgba(215,187,116,.24);
  --line2:rgba(255,255,255,.07);
  --gold:#d8b774;
  --gold2:#f0d49a;
  --txt:#f3f0e7;
  --muted:#b8b0a2;
  --soft:rgba(216,183,116,.10);
  --shadow:0 18px 44px rgba(0,0,0,.34);
  --ok:#8fe0b1;
  --warn:#f3cf76;
  --bad:#ff9a9a;
  --green:#6ed58d;
  --blue:#69c5ff;
  --pink:#f58cc5;
  --yellow:#f2d27e;
  --red:#ff8f8f;
  --royal:#7ea8ff;
  --radius:18px;
}
*{box-sizing:border-box}
html,body{
  margin:0;
  padding:0;
  min-height:100%;
  background:
    radial-gradient(circle at top right, rgba(216,183,116,.08), transparent 24%),
    radial-gradient(circle at top left, rgba(255,255,255,.03), transparent 18%),
    linear-gradient(180deg, #090b10 0%, #0b0e14 100%);
  color:var(--txt);
  font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;
}
body{min-height:100vh}
a{text-decoration:none;color:inherit}
button,input,select,textarea{font:inherit}

.i18n-zh{display:none}
body.lang-zh .i18n-en{display:none !important}
body.lang-zh .i18n-zh{display:inline !important}
body.lang-en .i18n-en{display:inline !important}
body.lang-en .i18n-zh{display:none !important}

.wrap{
  width:min(100%, 1440px);
  margin:0 auto;
  padding:14px 14px 100px;
}
.topbar{
  display:flex;
  justify-content:space-between;
  align-items:flex-start;
  gap:12px;
  margin-bottom:14px;
}
.brand{
  display:flex;
  flex-direction:column;
  gap:8px;
}
.kicker{
  display:inline-flex;
  align-items:center;
  gap:8px;
  color:var(--gold2);
  font-size:11px;
  letter-spacing:.18em;
  text-transform:uppercase;
}
.kdot{
  width:8px;
  height:8px;
  border-radius:999px;
  background:var(--gold);
  box-shadow:0 0 12px rgba(216,183,116,.5);
}
.title{
  font-size:30px;
  line-height:1.06;
  font-weight:800;
  letter-spacing:.02em;
}
.sub{
  color:var(--muted);
  font-size:13px;
  line-height:1.6;
  max-width:860px;
}
.lang-switch{
  display:inline-flex;
  border:1px solid var(--line);
  border-radius:999px;
  overflow:hidden;
  background:rgba(255,255,255,.03);
}
.lang-btn{
  border:none;
  background:transparent;
  color:var(--muted);
  padding:9px 14px;
  font-weight:700;
  cursor:pointer;
}
.lang-btn.active{
  background:var(--soft);
  color:var(--txt);
}
.head-row{
  display:grid;
  grid-template-columns:1.05fr .95fr;
  gap:14px;
  margin-bottom:14px;
}
.hero,.focus-panel,.panel{
  border:1px solid var(--line);
  border-radius:22px;
  background:
    linear-gradient(180deg, rgba(216,183,116,.07), rgba(255,255,255,.02)),
    linear-gradient(180deg, #0e1218 0%, #11161e 100%);
  box-shadow:var(--shadow);
}
.hero{
  padding:16px;
}
.hero-top{
  display:flex;
  justify-content:space-between;
  align-items:flex-start;
  gap:10px;
}
.hero-title{
  font-size:22px;
  font-weight:800;
}
.hero-note{
  margin-top:6px;
  color:var(--muted);
  font-size:13px;
}
.meta-grid{
  display:grid;
  grid-template-columns:repeat(4,minmax(0,1fr));
  gap:10px;
  margin-top:14px;
}
.meta-box{
  border:1px solid var(--line2);
  border-radius:16px;
  background:rgba(255,255,255,.025);
  padding:12px;
}
.meta-k{
  font-size:11px;
  color:var(--muted);
  letter-spacing:.1em;
  text-transform:uppercase;
  margin-bottom:6px;
}
.meta-v{
  font-size:13px;
  font-weight:700;
  word-break:break-word;
}
.quick-actions{
  display:flex;
  flex-wrap:wrap;
  gap:8px;
  margin-top:14px;
}
.btn{
  border:1px solid var(--line);
  background:linear-gradient(180deg, rgba(216,183,116,.16), rgba(216,183,116,.07));
  color:var(--txt);
  border-radius:14px;
  padding:10px 14px;
  font-weight:700;
  cursor:pointer;
  transition:all .18s ease;
}
.btn:hover{
  border-color:rgba(240,212,154,.42);
  box-shadow:0 0 0 1px rgba(216,183,116,.08) inset;
}
.btn.secondary{
  background:rgba(255,255,255,.03);
}
.btn.small{
  padding:8px 10px;
  font-size:12px;
}
.focus-panel{
  padding:16px;
}
.focus-title{
  font-size:14px;
  font-weight:800;
  letter-spacing:.12em;
  text-transform:uppercase;
  color:var(--gold2);
  margin-bottom:12px;
}
.focus-shell{
  display:grid;
  grid-template-columns:300px 1fr;
  gap:14px;
}
.focus-preview{
  border:1px solid var(--line2);
  border-radius:18px;
  overflow:hidden;
  background:#0f141b;
  min-height:380px;
  display:flex;
  align-items:center;
  justify-content:center;
}
.focus-preview img{
  display:block;
  width:100%;
  height:auto;
}
.focus-info{
  display:grid;
  gap:10px;
}
.focus-card{
  border:1px solid var(--line2);
  border-radius:16px;
  background:rgba(255,255,255,.025);
  padding:12px;
}
.focus-name{
  font-size:24px;
  font-weight:800;
}
.focus-code{
  color:var(--gold2);
  font-size:12px;
  letter-spacing:.12em;
  text-transform:uppercase;
  margin-top:4px;
}
.focus-note{
  color:var(--muted);
  font-size:13px;
  line-height:1.6;
  margin-top:8px;
}
.badge-row{
  display:flex;
  flex-wrap:wrap;
  gap:8px;
  margin-top:10px;
}
.badge{
  border:1px solid var(--line);
  background:rgba(216,183,116,.08);
  border-radius:999px;
  padding:6px 10px;
  font-size:11px;
  color:var(--muted);
}
.focus-grid{
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:10px;
}
.kv{
  border:1px solid var(--line2);
  border-radius:14px;
  background:#10141b;
  padding:12px;
}
.k{
  color:var(--muted);
  font-size:11px;
  letter-spacing:.08em;
  text-transform:uppercase;
  margin-bottom:6px;
}
.v{
  font-size:16px;
  font-weight:800;
  word-break:break-word;
}
.books{
  display:grid;
  grid-template-columns:1fr;
  gap:14px;
  margin-bottom:14px;
}
.book{
  border:1px solid var(--line);
  border-radius:22px;
  background:linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,.01));
  box-shadow:var(--shadow);
  overflow:hidden;
}
.book-head{
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:10px;
  padding:16px;
  border-bottom:1px solid var(--line2);
}
.book-title{
  font-size:18px;
  font-weight:800;
  letter-spacing:.08em;
  text-transform:uppercase;
}
.book-note{
  color:var(--muted);
  font-size:13px;
  margin-top:4px;
}
.book-body{
  padding:16px;
  display:grid;
  grid-template-columns:repeat(4,minmax(0,1fr));
  gap:12px;
}
.book-body.secondary{
  grid-template-columns:repeat(3,minmax(0,1fr));
}
.book-body.tertiary{
  grid-template-columns:repeat(1,minmax(0,1fr));
}
.issue-tile{
  border:1px solid var(--line2);
  border-radius:18px;
  background:
    linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,.015)),
    #0f141b;
  padding:14px;
  display:flex;
  flex-direction:column;
  gap:12px;
  min-height:320px;
  position:relative;
  transition:transform .18s ease, border-color .18s ease, box-shadow .18s ease;
}
.issue-tile:hover{
  transform:translateY(-2px);
  border-color:rgba(240,212,154,.34);
  box-shadow:0 0 0 1px rgba(216,183,116,.08) inset;
}
.issue-tile.active{
  border-color:rgba(240,212,154,.45);
  box-shadow:0 0 0 1px rgba(216,183,116,.12) inset, 0 0 0 3px rgba(216,183,116,.05);
}
.issue-tile[data-theme="green"] .tile-icon{color:var(--green)}
.issue-tile[data-theme="blue"] .tile-icon{color:var(--blue)}
.issue-tile[data-theme="black"] .tile-icon{color:#d9d9d9}
.issue-tile[data-theme="gold"] .tile-icon{color:var(--gold2)}
.issue-tile[data-theme="yellow"] .tile-icon{color:var(--yellow)}
.issue-tile[data-theme="pink"] .tile-icon{color:var(--pink)}
.issue-tile[data-theme="royal_blue"] .tile-icon{color:var(--royal)}
.issue-tile[data-theme="red"] .tile-icon{color:var(--red)}

.tile-top{
  display:flex;
  justify-content:space-between;
  gap:10px;
  align-items:flex-start;
}
.tile-icon{
  width:42px;
  height:42px;
  border-radius:14px;
  display:flex;
  align-items:center;
  justify-content:center;
  font-size:18px;
  font-weight:800;
  border:1px solid var(--line);
  background:var(--soft);
  flex:0 0 auto;
}
.tile-badge{
  border:1px solid var(--line);
  background:rgba(255,255,255,.03);
  border-radius:999px;
  padding:5px 9px;
  font-size:11px;
  color:var(--muted);
  white-space:nowrap;
}
.tile-name{
  font-size:18px;
  font-weight:800;
}
.tile-code{
  color:var(--gold2);
  font-size:12px;
  letter-spacing:.1em;
  text-transform:uppercase;
}
.tile-preview{
  border:1px solid var(--line2);
  border-radius:14px;
  overflow:hidden;
  background:#0b0f14;
  height:168px;
  display:flex;
  align-items:center;
  justify-content:center;
}
.tile-preview img{
  display:block;
  width:100%;
  height:100%;
  object-fit:cover;
}
.tile-meta{
  display:grid;
  gap:8px;
  margin-top:auto;
}
.tile-line{
  display:flex;
  justify-content:space-between;
  gap:10px;
  font-size:13px;
}
.tile-line .k{
  color:var(--muted);
}
.tile-line .v{
  font-weight:700;
  text-align:right;
}
.tile-actions{
  display:flex;
  gap:8px;
}
.tile-actions .btn{
  flex:1;
}
.bottom-grid{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:14px;
}
.panel{
  overflow:hidden;
}
.panel-head{
  display:flex;
  justify-content:space-between;
  gap:10px;
  align-items:center;
  padding:16px;
  border-bottom:1px solid var(--line2);
}
.panel-title{
  font-size:15px;
  font-weight:800;
  letter-spacing:.12em;
  text-transform:uppercase;
  color:var(--gold2);
}
.panel-note{
  color:var(--muted);
  font-size:12px;
  margin-top:4px;
}
.panel-body{
  padding:16px;
}
.format-grid{
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:10px;
}
.inspect{
  border:1px solid var(--line2);
  border-radius:16px;
  background:#10141b;
  padding:12px;
}
.inspect .k{
  color:var(--muted);
  font-size:11px;
  text-transform:uppercase;
  letter-spacing:.08em;
  margin-bottom:6px;
}
.inspect .v{
  font-size:14px;
  font-weight:700;
}
.logbox{
  border:1px solid var(--line2);
  border-radius:16px;
  background:#0b1016;
  min-height:340px;
  max-height:420px;
  overflow:auto;
  padding:12px;
  font-family:ui-monospace,Menlo,Consolas,monospace;
  font-size:12px;
  line-height:1.6;
  color:#d7d0c3;
}
.log-line{
  padding:6px 0;
  border-bottom:1px dashed rgba(255,255,255,.06);
}
.log-line:last-child{
  border-bottom:none;
}
.log-ts{color:#8f887d}
.log-ok{color:var(--ok)}
.log-warn{color:var(--warn)}
.log-bad{color:var(--bad)}
.footer{
  margin-top:18px;
  text-align:center;
  color:var(--muted);
  font-size:11px;
}
@media (max-width:1180px){
  .head-row{grid-template-columns:1fr}
  .focus-shell{grid-template-columns:1fr}
  .book-body{grid-template-columns:repeat(2,minmax(0,1fr))}
  .book-body.secondary{grid-template-columns:repeat(2,minmax(0,1fr))}
  .meta-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
  .bottom-grid{grid-template-columns:1fr}
}
@media (max-width:760px){
  .title{font-size:24px}
  .meta-grid{grid-template-columns:1fr}
  .book-body,
  .book-body.secondary,
  .book-body.tertiary{grid-template-columns:1fr}
  .hero-top,.book-head,.panel-head,.topbar{flex-direction:column;align-items:flex-start}
  .focus-grid,.format-grid{grid-template-columns:1fr}
}
</style>
</head>
<body class="lang-en">
<?php @include $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-topbar-nav.php'; ?>

<div class="wrap">
  <div class="topbar">
    <div class="brand">
      <div class="kicker">
        <span class="kdot"></span>
        <span class="i18n-en">Final Format Validation Desk</span>
        <span class="i18n-zh">最终格式验证台</span>
      </div>
      <div class="title">
        <span class="i18n-en">RWA Certificate Issue Tester</span>
        <span class="i18n-zh">RWA 证书发行测试器</span>
      </div>
      <div class="sub">
        <span class="i18n-en">Use this page to finalize the issue card format, title hierarchy, preview ratio, pricing strip, CTA text, and family layout before connecting the live issue endpoint.</span>
        <span class="i18n-zh">使用此页面在连接真实发行接口前，最终确认发行卡片格式、标题层级、预览比例、价格条、CTA 文案与系列布局。</span>
      </div>
    </div>
    <div class="lang-switch">
      <button type="button" class="lang-btn active" data-lang="en">EN</button>
      <button type="button" class="lang-btn" data-lang="zh">中</button>
    </div>
  </div>

  <div class="head-row">
    <section class="hero">
      <div class="hero-top">
        <div>
          <div class="hero-title">
            <span class="i18n-en">Current Tester Header</span>
            <span class="i18n-zh">当前测试器抬头</span>
          </div>
          <div class="hero-note">
            <span class="i18n-en">Premium black / graphite / restrained gold desk. Issue cards below are non-destructive test components only.</span>
            <span class="i18n-zh">高级黑 / 石墨灰 / 克制金色总台。下方发行卡片仅为非破坏性测试组件。</span>
          </div>
        </div>
        <div class="badge">
          <span class="i18n-en">Visual Only</span>
          <span class="i18n-zh">仅视觉测试</span>
        </div>
      </div>

      <div class="meta-grid">
        <div class="meta-box">
          <div class="meta-k"><span class="i18n-en">Connected Owner</span><span class="i18n-zh">当前持有人</span></div>
          <div class="meta-v"><?= h($nickname) ?></div>
        </div>
        <div class="meta-box">
          <div class="meta-k"><span class="i18n-en">Owner Wallet</span><span class="i18n-zh">持有人钱包</span></div>
          <div class="meta-v"><?= h($walletShort) ?></div>
        </div>
        <div class="meta-box">
          <div class="meta-k"><span class="i18n-en">Cert Families</span><span class="i18n-zh">证书系列</span></div>
          <div class="meta-v">4 + 3 + 1</div>
        </div>
        <div class="meta-box">
          <div class="meta-k"><span class="i18n-en">Treasury Add-on</span><span class="i18n-zh">Treasury 附加</span></div>
          <div class="meta-v">0.10 TON</div>
        </div>
      </div>

      <div class="quick-actions">
        <button type="button" class="btn small" id="btnSelectFirst">
          <span class="i18n-en">Select First Card</span>
          <span class="i18n-zh">选择第一张卡</span>
        </button>
        <button type="button" class="btn small secondary" id="btnExpandGenesis">
          <span class="i18n-en">Focus Genesis</span>
          <span class="i18n-zh">聚焦 Genesis</span>
        </button>
        <button type="button" class="btn small secondary" id="btnBackCert">
          <span class="i18n-en">Back to Cert Desk</span>
          <span class="i18n-zh">返回证书总台</span>
        </button>
      </div>
    </section>

    <section class="focus-panel">
      <div class="focus-title">
        <span class="i18n-en">Selected Issue Format</span>
        <span class="i18n-zh">当前选中发行格式</span>
      </div>
      <div class="focus-shell">
        <div class="focus-preview">
          <img id="focusImage" src="<?= h($cards['green']['template']) ?>" alt="focus preview">
        </div>
        <div class="focus-info">
          <div class="focus-card">
            <div class="focus-name" id="focusNameEn"><?= h($cards['green']['name_en']) ?></div>
            <div class="focus-name i18n-zh" id="focusNameZh"><?= h($cards['green']['name_zh']) ?></div>
            <div class="focus-code" id="focusCode"><?= h($cards['green']['rwa_key']) ?></div>
            <div class="focus-note" id="focusNoteEn"><?= h($cards['green']['note_en']) ?></div>
            <div class="focus-note i18n-zh" id="focusNoteZh"><?= h($cards['green']['note_zh']) ?></div>
            <div class="badge-row">
              <div class="badge" id="focusFamily"><?= h($cards['green']['family']) ?></div>
              <div class="badge" id="focusWeight">Weight <?= h($cards['green']['weight']) ?></div>
              <div class="badge" id="focusPrice"><?= h($cards['green']['price']) ?></div>
            </div>
          </div>

          <div class="focus-grid">
            <div class="kv">
              <div class="k"><span class="i18n-en">Title Hierarchy</span><span class="i18n-zh">标题层级</span></div>
              <div class="v" id="inspectTitle">Pass</div>
            </div>
            <div class="kv">
              <div class="k"><span class="i18n-en">Preview Ratio</span><span class="i18n-zh">预览比例</span></div>
              <div class="v">Fixed Cover Frame</div>
            </div>
            <div class="kv">
              <div class="k"><span class="i18n-en">CTA Label</span><span class="i18n-zh">CTA 文案</span></div>
              <div class="v" id="focusCta"><?= h($cards['green']['cta_en']) ?></div>
            </div>
            <div class="kv">
              <div class="k"><span class="i18n-en">Issue Asset</span><span class="i18n-zh">发行资产</span></div>
              <div class="v" id="inspectAsset">wEMS / EMA$</div>
            </div>
          </div>

          <div class="quick-actions">
            <button type="button" class="btn" id="btnMockIssue">
              <span class="i18n-en">Mock Issue This Format</span>
              <span class="i18n-zh">模拟发行该格式</span>
            </button>
            <button type="button" class="btn secondary" id="btnOpenTemplate">
              <span class="i18n-en">Open Template Image</span>
              <span class="i18n-zh">打开模板图片</span>
            </button>
          </div>
        </div>
      </div>
    </section>
  </div>

  <div class="books">
    <section class="book">
      <div class="book-head">
        <div>
          <div class="book-title"><span class="i18n-en">Genesis Issue Format Book</span><span class="i18n-zh">Genesis 发行格式簿</span></div>
          <div class="book-note"><span class="i18n-en">Test title, code, badge, preview, metrics and CTA composition for Genesis cards.</span><span class="i18n-zh">测试 Genesis 卡片的标题、代码、徽章、预览、指标与 CTA 组合。</span></div>
        </div>
        <div class="badge">4 Formats</div>
      </div>
      <div class="book-body">
        <?php foreach (['green','blue','black','gold'] as $key): $c = $cards[$key]; ?>
        <div class="issue-tile" data-key="<?= h($key) ?>" data-theme="<?= h($c['theme']) ?>">
          <div class="tile-top">
            <div class="tile-icon"><?= h(strtoupper(substr($c['type'], 0, 1))) ?></div>
            <div class="tile-badge"><?= h(str_contains($c['price'], 'wEMS') ? 'wEMS' : 'EMA$') ?></div>
          </div>
          <div>
            <div class="tile-name"><span class="i18n-en"><?= h($c['name_en']) ?></span><span class="i18n-zh"><?= h($c['name_zh']) ?></span></div>
            <div class="tile-code"><?= h($c['rwa_key']) ?></div>
          </div>
          <div class="tile-preview">
            <img src="<?= h($c['template']) ?>" alt="<?= h($c['name_en']) ?>">
          </div>
          <div class="tile-meta">
            <div class="tile-line"><span class="k"><span class="i18n-en">Family</span><span class="i18n-zh">系列</span></span><span class="v"><?= h($c['family']) ?></span></div>
            <div class="tile-line"><span class="k"><span class="i18n-en">Weight</span><span class="i18n-zh">权重</span></span><span class="v"><?= h((string)$c['weight']) ?></span></div>
            <div class="tile-line"><span class="k"><span class="i18n-en">Price</span><span class="i18n-zh">价格</span></span><span class="v"><?= h($c['price']) ?></span></div>
          </div>
          <div class="tile-actions">
            <button class="btn select-card" type="button" data-key="<?= h($key) ?>">
              <span class="i18n-en">Select Format</span>
              <span class="i18n-zh">选择格式</span>
            </button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="book">
      <div class="book-head">
        <div>
          <div class="book-title"><span class="i18n-en">Secondary Issue Format Book</span><span class="i18n-zh">Secondary 发行格式簿</span></div>
          <div class="book-note"><span class="i18n-en">Secondary issue card tester for health, travel and property class.</span><span class="i18n-zh">用于健康、旅游与房产类别的 Secondary 发行卡测试。</span></div>
        </div>
        <div class="badge">3 Formats</div>
      </div>
      <div class="book-body secondary">
        <?php foreach (['pink','red','royal_blue'] as $key): $c = $cards[$key]; ?>
        <div class="issue-tile" data-key="<?= h($key) ?>" data-theme="<?= h($c['theme']) ?>">
          <div class="tile-top">
            <div class="tile-icon"><?= h(strtoupper(substr($c['type'], 0, 1))) ?></div>
            <div class="tile-badge">EMA$</div>
          </div>
          <div>
            <div class="tile-name"><span class="i18n-en"><?= h($c['name_en']) ?></span><span class="i18n-zh"><?= h($c['name_zh']) ?></span></div>
            <div class="tile-code"><?= h($c['rwa_key']) ?></div>
          </div>
          <div class="tile-preview">
            <img src="<?= h($c['template']) ?>" alt="<?= h($c['name_en']) ?>">
          </div>
          <div class="tile-meta">
            <div class="tile-line"><span class="k"><span class="i18n-en">Family</span><span class="i18n-zh">系列</span></span><span class="v"><?= h($c['family']) ?></span></div>
            <div class="tile-line"><span class="k"><span class="i18n-en">Weight</span><span class="i18n-zh">权重</span></span><span class="v"><?= h((string)$c['weight']) ?></span></div>
            <div class="tile-line"><span class="k"><span class="i18n-en">Price</span><span class="i18n-zh">价格</span></span><span class="v"><?= h($c['price']) ?></span></div>
          </div>
          <div class="tile-actions">
            <button class="btn select-card" type="button" data-key="<?= h($key) ?>">
              <span class="i18n-en">Select Format</span>
              <span class="i18n-zh">选择格式</span>
            </button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="book">
      <div class="book-head">
        <div>
          <div class="book-title"><span class="i18n-en">Tertiary Issue Format Book</span><span class="i18n-zh">Tertiary 发行格式簿</span></div>
          <div class="book-note"><span class="i18n-en">Governance-class tertiary tester for RHRD issue card composition.</span><span class="i18n-zh">用于 RHRD 发行卡组合的治理级 tertiary 测试。</span></div>
        </div>
        <div class="badge">1 Format</div>
      </div>
      <div class="book-body tertiary">
        <?php foreach (['yellow'] as $key): $c = $cards[$key]; ?>
        <div class="issue-tile" data-key="<?= h($key) ?>" data-theme="<?= h($c['theme']) ?>">
          <div class="tile-top">
            <div class="tile-icon"><?= h(strtoupper(substr($c['type'], 0, 1))) ?></div>
            <div class="tile-badge">EMA$</div>
          </div>
          <div>
            <div class="tile-name"><span class="i18n-en"><?= h($c['name_en']) ?></span><span class="i18n-zh"><?= h($c['name_zh']) ?></span></div>
            <div class="tile-code"><?= h($c['rwa_key']) ?></div>
          </div>
          <div class="tile-preview">
            <img src="<?= h($c['template']) ?>" alt="<?= h($c['name_en']) ?>">
          </div>
          <div class="tile-meta">
            <div class="tile-line"><span class="k"><span class="i18n-en">Family</span><span class="i18n-zh">系列</span></span><span class="v"><?= h($c['family']) ?></span></div>
            <div class="tile-line"><span class="k"><span class="i18n-en">Weight</span><span class="i18n-zh">权重</span></span><span class="v"><?= h((string)$c['weight']) ?></span></div>
            <div class="tile-line"><span class="k"><span class="i18n-en">Price</span><span class="i18n-zh">价格</span></span><span class="v"><?= h($c['price']) ?></span></div>
          </div>
          <div class="tile-actions">
            <button class="btn select-card" type="button" data-key="<?= h($key) ?>">
              <span class="i18n-en">Select Format</span>
              <span class="i18n-zh">选择格式</span>
            </button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </section>
  </div>

  <div class="bottom-grid">
    <section class="panel">
      <div class="panel-head">
        <div>
          <div class="panel-title"><span class="i18n-en">Format Inspection Grid</span><span class="i18n-zh">格式检查网格</span></div>
          <div class="panel-note"><span class="i18n-en">Use this section to judge whether the selected issue card format is final-ready.</span><span class="i18n-zh">用此区域判断当前发行卡格式是否可最终定版。</span></div>
        </div>
      </div>
      <div class="panel-body">
        <div class="format-grid">
          <div class="inspect"><div class="k"><span class="i18n-en">Selected Type</span><span class="i18n-zh">选中类型</span></div><div class="v" id="fmtType">green</div></div>
          <div class="inspect"><div class="k"><span class="i18n-en">Selected Family</span><span class="i18n-zh">选中系列</span></div><div class="v" id="fmtFamily">GENESIS</div></div>
          <div class="inspect"><div class="k"><span class="i18n-en">Selected Price</span><span class="i18n-zh">选中价格</span></div><div class="v" id="fmtPrice">1000 wEMS</div></div>
          <div class="inspect"><div class="k"><span class="i18n-en">Selected Weight</span><span class="i18n-zh">选中权重</span></div><div class="v" id="fmtWeight">1</div></div>
          <div class="inspect"><div class="k"><span class="i18n-en">Title Style</span><span class="i18n-zh">标题样式</span></div><div class="v"><span class="i18n-en">Large / premium / compact</span><span class="i18n-zh">大标题 / 高级 / 紧凑</span></div></div>
          <div class="inspect"><div class="k"><span class="i18n-en">Preview Style</span><span class="i18n-zh">预览样式</span></div><div class="v"><span class="i18n-en">Cover crop with framed card</span><span class="i18n-zh">封面裁切 + 带边框卡片</span></div></div>
        </div>
      </div>
    </section>

    <section class="panel">
      <div class="panel-head">
        <div>
          <div class="panel-title"><span class="i18n-en">Tester Log</span><span class="i18n-zh">测试日志</span></div>
          <div class="panel-note"><span class="i18n-en">Selection and mock issue actions will appear here.</span><span class="i18n-zh">选择与模拟发行操作将显示在此处。</span></div>
        </div>
      </div>
      <div class="panel-body">
        <div class="logbox" id="logBox"></div>
      </div>
    </section>
  </div>

  <div class="footer"><?= h($footerText) ?></div>
</div>

<script>
const ISSUE_TESTER_CARDS = <?= json_encode($cards, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?>;

const body = document.body;
const langButtons = Array.from(document.querySelectorAll('.lang-btn'));
const cardButtons = Array.from(document.querySelectorAll('.select-card'));
const tiles = Array.from(document.querySelectorAll('.issue-tile'));
const logBox = document.getElementById('logBox');

const focusImage = document.getElementById('focusImage');
const focusNameEn = document.getElementById('focusNameEn');
const focusNameZh = document.getElementById('focusNameZh');
const focusCode = document.getElementById('focusCode');
const focusNoteEn = document.getElementById('focusNoteEn');
const focusNoteZh = document.getElementById('focusNoteZh');
const focusFamily = document.getElementById('focusFamily');
const focusWeight = document.getElementById('focusWeight');
const focusPrice = document.getElementById('focusPrice');
const focusCta = document.getElementById('focusCta');

const fmtType = document.getElementById('fmtType');
const fmtFamily = document.getElementById('fmtFamily');
const fmtPrice = document.getElementById('fmtPrice');
const fmtWeight = document.getElementById('fmtWeight');

const btnMockIssue = document.getElementById('btnMockIssue');
const btnOpenTemplate = document.getElementById('btnOpenTemplate');
const btnSelectFirst = document.getElementById('btnSelectFirst');
const btnExpandGenesis = document.getElementById('btnExpandGenesis');
const btnBackCert = document.getElementById('btnBackCert');

let currentKey = 'green';

function nowTs() {
  const d = new Date();
  return d.toLocaleString();
}

function pushLog(kind, msg) {
  const line = document.createElement('div');
  line.className = 'log-line';
  const kindClass = kind === 'ok' ? 'log-ok' : (kind === 'warn' ? 'log-warn' : 'log-bad');
  line.innerHTML = `<span class="log-ts">[${nowTs()}]</span> <span class="${kindClass}">${msg}</span>`;
  logBox.prepend(line);
}

function setLang(lang) {
  body.classList.remove('lang-en', 'lang-zh');
  body.classList.add(lang === 'zh' ? 'lang-zh' : 'lang-en');
  langButtons.forEach(btn => btn.classList.toggle('active', btn.dataset.lang === lang));
  const card = ISSUE_TESTER_CARDS[currentKey];
  focusCta.textContent = lang === 'zh' ? card.cta_zh : card.cta_en;
  pushLog('ok', lang === 'zh' ? '语言已切换为中文。' : 'Language switched to English.');
}

function activateTile(key) {
  currentKey = key;
  const card = ISSUE_TESTER_CARDS[key];
  if (!card) return;

  tiles.forEach(tile => tile.classList.toggle('active', tile.dataset.key === key));

  focusImage.src = card.template;
  focusNameEn.textContent = card.name_en;
  focusNameZh.textContent = card.name_zh;
  focusCode.textContent = card.rwa_key;
  focusNoteEn.textContent = card.note_en;
  focusNoteZh.textContent = card.note_zh;
  focusFamily.textContent = card.family;
  focusWeight.textContent = 'Weight ' + card.weight;
  focusPrice.textContent = card.price;
  focusCta.textContent = body.classList.contains('lang-zh') ? card.cta_zh : card.cta_en;

  fmtType.textContent = card.type;
  fmtFamily.textContent = card.family;
  fmtPrice.textContent = card.price;
  fmtWeight.textContent = String(card.weight);

  pushLog('ok', `Selected format: ${card.rwa_key} (${card.type})`);
}

langButtons.forEach(btn => {
  btn.addEventListener('click', () => setLang(btn.dataset.lang || 'en'));
});

cardButtons.forEach(btn => {
  btn.addEventListener('click', () => activateTile(btn.dataset.key));
});

tiles.forEach(tile => {
  tile.addEventListener('click', (ev) => {
    if (ev.target.closest('.select-card')) return;
    activateTile(tile.dataset.key);
  });
});

btnMockIssue.addEventListener('click', () => {
  const card = ISSUE_TESTER_CARDS[currentKey];
  if (!card) return;
  pushLog('warn', `Mock issue executed for ${card.rwa_key}. Visual format accepted for dry-run only.`);
});

btnOpenTemplate.addEventListener('click', () => {
  const card = ISSUE_TESTER_CARDS[currentKey];
  if (!card) return;
  window.open(card.template, '_blank', 'noopener');
});

btnSelectFirst.addEventListener('click', () => activateTile('green'));
btnExpandGenesis.addEventListener('click', () => activateTile('gold'));
btnBackCert.addEventListener('click', () => {
  window.location.href = '/rwa/cert/index.php';
});

activateTile('green');
pushLog('ok', 'Issue tester loaded successfully.');
</script>

<?php @include $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-bottom-nav.php'; ?>
</body>
</html>
