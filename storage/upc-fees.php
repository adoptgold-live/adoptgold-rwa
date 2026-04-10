<?php
declare(strict_types=1);
/**
 * /rwa/storage/upc-fees.php
 * RWA Storage — UPC Fees Reference
 * Version: v1.2.0-20260328
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$langDefault = 'en';
?>
<!doctype html>
<html lang="<?= h($langDefault) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="#050607">
  <meta name="color-scheme" content="dark">
  <title>UPC Fees</title>
  <style>
    :root{
      --bg:#050607;
      --panel:#0c0f10;
      --panel2:#101517;
      --line:rgba(255,255,255,.08);
      --text:#e8f2ef;
      --muted:#9fb7af;
      --gold:#f5c96a;
      --green:#6fe3c1;
      --purple:#8b5cf6;
    }
    *{box-sizing:border-box}
    html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font-family:Arial,Helvetica,sans-serif}
    body{min-height:100vh}
    .page{
      width:100%;
      max-width:980px;
      margin:0 auto;
      padding:16px 14px 96px;
    }
    .langbar{
      display:flex;
      justify-content:flex-end;
      align-items:center;
      padding:6px 2px 14px;
      color:var(--muted);
      font-size:13px;
    }
    .langbtn{
      border:0;
      background:none;
      color:var(--muted);
      padding:0 4px;
      cursor:pointer;
      font:inherit;
    }
    .langbtn.active{color:#fff}
    .hero{
      background:linear-gradient(180deg, rgba(139,92,246,.14), rgba(139,92,246,.04));
      border:1px solid rgba(139,92,246,.20);
      border-radius:18px;
      padding:18px 16px;
      margin-bottom:14px;
    }
    .eyebrow{
      font-size:12px;
      letter-spacing:.08em;
      text-transform:uppercase;
      color:var(--muted);
      margin-bottom:8px;
    }
    .title{
      margin:0;
      font-size:24px;
      line-height:1.2;
    }
    .sub{
      margin:8px 0 0;
      color:var(--muted);
      font-size:14px;
      line-height:1.5;
    }
    .card{
      background:var(--panel);
      border:1px solid var(--line);
      border-radius:18px;
      padding:16px;
      margin-bottom:14px;
    }
    .notice{
      background:rgba(245,201,106,.08);
      border:1px solid rgba(245,201,106,.18);
    }
    .notice strong{color:var(--gold)}
    .fee-table{
      width:100%;
      border-collapse:collapse;
      margin-top:8px;
    }
    .fee-table tr{
      border-bottom:1px solid var(--line);
    }
    .fee-table tr:last-child{
      border-bottom:0;
    }
    .fee-table td{
      padding:12px 8px;
      vertical-align:top;
      font-size:14px;
      line-height:1.45;
    }
    .fee-table td:first-child{
      color:var(--muted);
      width:68%;
    }
    .fee-table td:last-child{
      text-align:right;
      color:var(--gold);
      font-weight:700;
      white-space:nowrap;
    }
    .snippet{
      background:var(--panel2);
      border:1px solid var(--line);
      border-radius:14px;
      padding:12px;
      margin-top:8px;
    }
    .snippet-row{
      display:flex;
      justify-content:space-between;
      gap:12px;
      padding:6px 0;
      font-size:14px;
    }
    .snippet-row .left{color:var(--muted)}
    .snippet-row .right{font-weight:700}
    .snippet-row.highlight .right{color:var(--green)}
    .snippet-row.old .right{color:var(--gold)}
    .footnote{
      margin-top:10px;
      color:var(--muted);
      font-size:12px;
      line-height:1.5;
    }
    .backrow{
      display:flex;
      gap:10px;
      flex-wrap:wrap;
      margin-top:14px;
    }
    .btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-height:42px;
      padding:0 14px;
      border-radius:12px;
      text-decoration:none;
      border:1px solid var(--line);
      background:#111617;
      color:#fff;
      font-size:14px;
    }
    .btn.primary{
      border-color:rgba(111,227,193,.24);
      color:var(--green);
    }
    @media (max-width:640px){
      .title{font-size:22px}
      .fee-table td:first-child{width:auto}
      .fee-table td:last-child{white-space:normal}
    }
  </style>
</head>
<body data-lang="<?= h($langDefault) ?>">

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-topbar-nav.php'; ?>

<main class="page">
  <div class="langbar" role="group" aria-label="Language">
    <button type="button" class="langbtn active" id="langEnBtn">EN</button>
    <span>|</span>
    <button type="button" class="langbtn" id="langZhBtn">中</button>
  </div>

  <section class="hero">
    <div class="eyebrow" id="heroEyebrow">Storage Fee Reference</div>
    <h1 class="title" id="heroTitle">UPC Fees</h1>
    <p class="sub" id="heroSub">
      External benchmark reference for the RWA Adoption Card fee notice.
    </p>
  </section>

  <section class="card notice">
    <div class="eyebrow" id="noticeEyebrow">Notice</div>
    <div id="noticeText">
      <strong>UPC fees below are external reference fees only.</strong><br>
      RWA Adoption Card uses a different on-chain and internal fee model.
    </div>
  </section>

  <section class="card">
    <div class="eyebrow" id="feeTableEyebrow">Fee Table</div>
    <table class="fee-table">
      <tr>
        <td id="fee1Label">Card Fee</td>
        <td>HKD 80</td>
      </tr>
      <tr>
        <td id="fee2Label">Foreign Currency Fee</td>
        <td>1%</td>
      </tr>
      <tr>
        <td id="fee3Label">International Withdrawal</td>
        <td>1.75% (min HKD 25)</td>
      </tr>
      <tr>
        <td id="fee4Label">Retail Transaction Fee</td>
        <td>0.3%</td>
      </tr>
      <tr>
        <td id="fee5Label">Small Transfer (below HKD 500)</td>
        <td>1%</td>
      </tr>
      <tr>
        <td id="fee6Label">P2P Transfer</td>
        <td>1%</td>
      </tr>
      <tr>
        <td id="fee7Label">Declined Transaction Receipt</td>
        <td>HKD 30</td>
      </tr>
      <tr>
        <td id="fee8Label">Transaction Verification / Receipt Reprint</td>
        <td>HKD 100</td>
      </tr>
      <tr>
        <td id="fee9Label">Balance Enquiry</td>
        <td>HKD 3</td>
      </tr>
      <tr>
        <td id="fee10Label">Refund Handling Fee</td>
        <td>HKD 6</td>
      </tr>
      <tr>
        <td id="fee11Label">Card Management Fee (Monthly)</td>
        <td>HKD 6</td>
      </tr>
      <tr>
        <td id="fee12Label">Dormant Account Fee</td>
        <td>HKD 10</td>
      </tr>
    </table>
  </section>

  <section class="card">
    <div class="eyebrow" id="snippetEyebrow">Storage Snippet</div>
    <div class="snippet">
      <div class="snippet-row old">
        <div class="left" id="compareOldLabel">Traditional Card Fee Range</div>
        <div class="right">~0.3% – 1.75%</div>
      </div>
      <div class="snippet-row highlight">
        <div class="left" id="compareNewLabel">RWA Adoption Card</div>
        <div class="right">0.1% EMX + 0.1% EMS</div>
      </div>
    </div>
    <div class="footnote" id="snippetNote">
      This comparison block is intended for Storage page notice placement only.
    </div>

    <div class="backrow">
      <a class="btn primary" href="/rwa/storage/">Back to Storage</a>
    </div>
  </section>
</main>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-bottom-nav.php'; ?>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/gt-inline.php'; ?>

<script>
(function () {
  const dict = {
    en: {
      heroEyebrow: 'Storage Fee Reference',
      heroTitle: 'UPC Fees',
      heroSub: 'External benchmark reference for the RWA Adoption Card fee notice.',
      noticeEyebrow: 'Notice',
      noticeText: '<strong>UPC fees below are external reference fees only.</strong><br>RWA Adoption Card uses a different on-chain and internal fee model.',
      feeTableEyebrow: 'Fee Table',
      fee1Label: 'Card Fee',
      fee2Label: 'Foreign Currency Fee',
      fee3Label: 'International Withdrawal',
      fee4Label: 'Retail Transaction Fee',
      fee5Label: 'Small Transfer (below HKD 500)',
      fee6Label: 'P2P Transfer',
      fee7Label: 'Declined Transaction Receipt',
      fee8Label: 'Transaction Verification / Receipt Reprint',
      fee9Label: 'Balance Enquiry',
      fee10Label: 'Refund Handling Fee',
      fee11Label: 'Card Management Fee (Monthly)',
      fee12Label: 'Dormant Account Fee',
      snippetEyebrow: 'Storage Snippet',
      compareOldLabel: 'Traditional Card Fee Range',
      compareNewLabel: 'RWA Adoption Card',
      snippetNote: 'This comparison block is intended for Storage page notice placement only.'
    },
    zh: {
      heroEyebrow: 'Storage 費率參考',
      heroTitle: 'UPC 費用',
      heroSub: '作為 RWA Adoption Card 費率提示的外部參考基準。',
      noticeEyebrow: '提示',
      noticeText: '<strong>以下 UPC 費用僅作外部參考。</strong><br>RWA Adoption Card 採用不同的鏈上及內部費率模型。',
      feeTableEyebrow: '費用表',
      fee1Label: '開卡費',
      fee2Label: '外幣交易費',
      fee3Label: '國際提款費',
      fee4Label: '零售交易費',
      fee5Label: '小額轉帳費（低於 HKD 500）',
      fee6Label: 'P2P 轉帳費',
      fee7Label: '拒絕交易收據費',
      fee8Label: '交易核實 / 補印收據費',
      fee9Label: '查餘額費',
      fee10Label: '退款處理費',
      fee11Label: '卡管理費（月費）',
      fee12Label: '休眠帳戶管理費',
      snippetEyebrow: 'Storage 提示片段',
      compareOldLabel: '傳統卡費率範圍',
      compareNewLabel: 'RWA Adoption Card',
      snippetNote: '此對比片段用於 Storage 頁面中的費率提示位置。'
    }
  };

  function applyLang(lang) {
    const use = dict[lang] || dict.en;
    document.documentElement.lang = lang;
    document.body.setAttribute('data-lang', lang);

    Object.keys(use).forEach((id) => {
      const el = document.getElementById(id);
      if (!el) return;
      if (id === 'noticeText') {
        el.innerHTML = use[id];
      } else {
        el.textContent = use[id];
      }
    });

    document.getElementById('langEnBtn')?.classList.toggle('active', lang === 'en');
    document.getElementById('langZhBtn')?.classList.toggle('active', lang === 'zh');

    if (window.poadoI18n && typeof window.poadoI18n.setLang === 'function') {
      try { window.poadoI18n.setLang(lang); } catch (e) {}
    }
  }

  document.getElementById('langEnBtn')?.addEventListener('click', function () {
    applyLang('en');
  });

  document.getElementById('langZhBtn')?.addEventListener('click', function () {
    applyLang('zh');
  });

  applyLang('en');
})();
</script>
</body>
</html>