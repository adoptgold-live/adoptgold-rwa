<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

function mcp_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$csrfToken = '';
if (function_exists('csrf_token')) {
    try {
        $csrfToken = (string)csrf_token('manual_claims_panel');
    } catch (Throwable $e) {
        $csrfToken = '';
    }
}
if ($csrfToken === '' && isset($_SESSION['csrf_token'])) {
    $csrfToken = (string)$_SESSION['csrf_token'];
}

$user = null;
if (function_exists('session_user')) {
    try {
        $u = session_user();
        if (is_array($u)) {
            $user = $u;
        }
    } catch (Throwable $e) {
    }
}
if (!$user && isset($GLOBALS['session_user']) && is_array($GLOBALS['session_user'])) {
    $user = $GLOBALS['session_user'];
}
$user = is_array($user) ? $user : [];

$displayName = trim((string)($user['nickname'] ?? $user['email'] ?? $user['wallet_address'] ?? $user['wallet'] ?? 'Storage User'));
if ($displayName === '') {
    $displayName = 'Storage User';
}

$cards = [
    [
        'flow_type' => 'claim_ema',
        'title_en' => 'My Unclaimed EMA$',
        'title_zh' => '我的未领取 EMA$',
        'amount' => '0.000000',
        'button_en' => 'CLAIM NOW',
        'button_zh' => '立即领取',
        'token' => 'EMA',
        'icon' => '/rwa/metadata/ema.png',
    ],
    [
        'flow_type' => 'claim_wems',
        'title_en' => 'My Unclaimed Web Gold wEMS',
        'title_zh' => '我的未领取网金 wEMS',
        'amount' => '0.000000',
        'button_en' => 'CLAIM NOW',
        'button_zh' => '立即领取',
        'token' => 'wEMS',
        'icon' => '/rwa/metadata/wems.png',
    ],
    [
        'flow_type' => 'claim_usdt_ton',
        'title_en' => 'My Unclaimed Gold Packet USDT-TON',
        'title_zh' => '我的未领取金红包 USDT-TON',
        'amount' => '0.000000',
        'button_en' => 'CLAIM NOW',
        'button_zh' => '立即领取',
        'token' => 'USDT-TON',
        'icon' => '/rwa/metadata/usdt_ton.png',
    ],
    [
        'flow_type' => 'claim_emx_tips',
        'title_en' => 'My Unclaimed Tips EMX',
        'title_zh' => '我的未领取小费 EMX',
        'amount' => '0.000000',
        'button_en' => 'CLAIM NOW',
        'button_zh' => '立即领取',
        'token' => 'EMX',
        'icon' => '/rwa/metadata/emx.png',
    ],
    [
        'flow_type' => 'fuel_ems',
        'title_en' => 'Fuel Up EMS',
        'title_zh' => '充值 EMS',
        'amount' => '0.000000',
        'button_en' => 'FUEL NOW',
        'button_zh' => '立即充值',
        'token' => 'EMS',
        'icon' => '/rwa/metadata/ems.png',
    ],
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Manual Claims Panel</title>
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="#050607">
  <meta name="csrf-token" content="<?= mcp_h($csrfToken) ?>">
  <link rel="stylesheet" href="/rwa/storage/manual-claims/panel.css?v=20260324-3">
</head>
<body data-manual-claims-panel-page>
<?php
if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-topbar-nav.php')) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-topbar-nav.php';
}
?>

<main class="mcp-page-shell">
  <section class="mcp-langbar">
    <div class="mcp-lang-inline" role="group" aria-label="Language">
      <button type="button" class="mcp-lang-btn is-active" id="mcpLangEnBtn" data-lang="en">EN</button>
      <span class="mcp-lang-sep">|</span>
      <button type="button" class="mcp-lang-btn" id="mcpLangZhBtn" data-lang="zh">中</button>
    </div>
  </section>

  <section class="mcp-root" data-manual-claims-panel-root>
    <input type="hidden" name="csrf_token" value="<?= mcp_h($csrfToken) ?>">

    <div class="mcp-head">
      <div class="mcp-head-copy">
        <div class="mcp-kicker" data-i18n="kicker">OFF CHAIN</div>
        <h1 class="mcp-title" data-i18n="title">Off Chain Unclaimed</h1>
        <div class="mcp-subtitle" data-i18n="subtitle">
          Submit manual requests for off-chain token balances and fuel-up flows. Duplicate pending requests are blocked per asset.
        </div>
      </div>

      <div class="mcp-head-actions">
        <div class="mcp-user-chip"><?= mcp_h($displayName) ?></div>
        <button type="button" class="mcp-btn mcp-btn-ghost" data-manual-claims-refresh data-i18n="refresh">Refresh</button>
      </div>
    </div>

    <div class="mcp-list" role="list">
      <?php foreach ($cards as $card): ?>
        <article
          class="mcp-row"
          role="listitem"
          data-manual-claim-row
          data-manual-claim-flow="<?= mcp_h($card['flow_type']) ?>"
        >
          <div class="mcp-row-left">
            <div class="mcp-token-orb">
              <img class="mcp-token-icon" src="<?= mcp_h($card['icon']) ?>" alt="<?= mcp_h($card['token']) ?>">
            </div>

            <div class="mcp-row-copy">
              <div class="mcp-row-title"
                   data-manual-claim-title
                   data-title-en="<?= mcp_h($card['title_en']) ?>"
                   data-title-zh="<?= mcp_h($card['title_zh']) ?>">
                <?= mcp_h($card['title_en']) ?>
              </div>
              <div class="mcp-row-token"><?= mcp_h($card['token']) ?></div>
              <div class="mcp-row-history" data-manual-claim-history></div>
            </div>
          </div>

          <div class="mcp-row-right">
            <div class="mcp-row-amount-wrap">
              <div class="mcp-row-amount" data-manual-claim-amount><?= mcp_h($card['amount']) ?></div>
              <div class="mcp-row-status" data-manual-claim-status></div>
            </div>

            <div class="mcp-row-action">
              <button
                type="button"
                class="mcp-btn mcp-btn-primary"
                data-manual-claim-button
                data-button-en="<?= mcp_h($card['button_en']) ?>"
                data-button-zh="<?= mcp_h($card['button_zh']) ?>"
              ><?= mcp_h($card['button_en']) ?></button>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>

    <div class="mcp-history-wrap">
      <div class="mcp-history-head">
        <div>
          <div class="mcp-kicker" data-i18n="history_kicker">USER HISTORY</div>
          <h2 class="mcp-history-title" data-i18n="history_title">My Manual Request History</h2>
        </div>

        <div class="mcp-history-actions">
          <select class="mcp-select" data-history-flow aria-label="History flow filter">
            <option value="" data-text-en="All Flows" data-text-zh="全部流程">All Flows</option>
            <option value="claim_ema">claim_ema</option>
            <option value="claim_wems">claim_wems</option>
            <option value="claim_usdt_ton">claim_usdt_ton</option>
            <option value="claim_emx_tips">claim_emx_tips</option>
            <option value="fuel_ems">fuel_ems</option>
          </select>
        </div>
      </div>

      <div class="mcp-statusbar" data-manual-claims-panel-status>Ready.</div>

      <div class="mcp-table-wrap">
        <table class="mcp-table">
          <thead>
            <tr>
              <th data-i18n="th_request">Request</th>
              <th data-i18n="th_flow">Flow</th>
              <th data-i18n="th_amount">Amount</th>
              <th data-i18n="th_status">Status</th>
              <th data-i18n="th_proof">Proof</th>
              <th data-i18n="th_payout">Payout</th>
              <th data-i18n="th_created">Created</th>
            </tr>
          </thead>
          <tbody data-manual-claims-history-body>
            <tr>
              <td colspan="7" class="mcp-empty" data-i18n="loading">Loading…</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </section>
</main>

<?php
if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-bottom-nav.php')) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-bottom-nav.php';
}
?>

<script>
window.MANUAL_CLAIMS_PANEL = {
  apiBase: "/rwa/api/storage/manual-claims",
  csrfToken: <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  initialLang: "en",
  i18n: {
    en: {
      kicker: "OFF CHAIN",
      title: "Off Chain Unclaimed",
      subtitle: "Submit manual requests for off-chain token balances and fuel-up flows. Duplicate pending requests are blocked per asset.",
      refresh: "Refresh",
      history_kicker: "USER HISTORY",
      history_title: "My Manual Request History",
      th_request: "Request",
      th_flow: "Flow",
      th_amount: "Amount",
      th_status: "Status",
      th_proof: "Proof",
      th_payout: "Payout",
      th_created: "Created",
      loading: "Loading…",
      ready_to_request: "Ready to request",
      latest: "Latest",
      pending: "Pending",
      submitted: "Submitted",
      requested: "Requested",
      no_requests: "No manual requests found.",
      missing_csrf: "Missing CSRF token",
      submitting: "Submitting request…",
      nothing_available: "Nothing available to request",
      confirm_submit: "Submit request for",
      loaded_rows: "Loaded request(s).",
      loading_panel: "Loading manual claims…",
      copy_failed: "Copy failed"
    },
    zh: {
      kicker: "链下",
      title: "链下未领取",
      subtitle: "提交链下代币余额与燃料充值的人工申请。相同资产如已有待处理申请，将阻止重复提交。",
      refresh: "刷新",
      history_kicker: "用户记录",
      history_title: "我的人工申请记录",
      th_request: "申请编号",
      th_flow: "流程",
      th_amount: "数量",
      th_status: "状态",
      th_proof: "证明",
      th_payout: "发放",
      th_created: "创建时间",
      loading: "加载中…",
      ready_to_request: "可提交申请",
      latest: "最新",
      pending: "处理中",
      submitted: "已提交",
      requested: "已申请",
      no_requests: "暂无人工申请记录。",
      missing_csrf: "缺少 CSRF token",
      submitting: "正在提交申请…",
      nothing_available: "当前没有可申请数量",
      confirm_submit: "确认提交申请",
      loaded_rows: "已载入申请记录。",
      loading_panel: "正在加载人工申请…",
      copy_failed: "复制失败"
    }
  }
};
</script>
<script src="/rwa/storage/manual-claims/panel.js?v=20260324-2"></script>
</body>
</html>
