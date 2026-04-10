<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/rh2o/index.php
 * Version: v1.2.20260307-rh2o-date-width-fix
 */

require_once __DIR__ . '/../inc/rwa-session.php';
require_once __DIR__ . '/../inc/rwa-topbar-nav.php';

function h(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$nickname = 'Guest';
$walletShort = 'SESSION: NONE';

if (isset($GLOBALS['poado_user']) && is_array($GLOBALS['poado_user'])) {
    $u = $GLOBALS['poado_user'];
    $nickname = trim((string)($u['nickname'] ?? 'Guest')) ?: 'Guest';
    $wallet = trim((string)($u['wallet'] ?? ($u['wallet_address'] ?? '')));
    if ($wallet !== '') {
        $walletShort = substr($wallet, 0, 6) . '...' . substr($wallet, -4);
    }
}

$liters      = isset($_POST['liters']) ? max(0, (float)$_POST['liters']) : 260.0;
$ph          = isset($_POST['ph']) ? (float)$_POST['ph'] : 7.2;
$tds         = isset($_POST['tds']) ? max(0, (float)$_POST['tds']) : 120.0;
$branch      = trim((string)($_POST['branch_name'] ?? '香港授权净水检测分行'));
$checkDate   = trim((string)($_POST['check_date'] ?? gmdate('Y-m-d')));
$adminStatus = trim((string)($_POST['admin_status'] ?? '待审核'));

$units = (int)floor($liters / 100);

$statusKey = 'red';
$statusName = '红色警示';
$statusDesc = '当前净水数据不符合 RH2O Clean Water Cert 发证标准，不可申请或签发净水证书。';
$canIssue = false;

if ($liters >= 100 && $ph >= 6.5 && $ph <= 8.5 && $tds >= 50 && $tds <= 300) {
    $statusKey = 'green';
    $statusName = '绿色合格';
    $statusDesc = '水质处于合格区间，可申请 RH2O Clean Water Cert，并按每 100L 形成 1 个净水责任单位。';
    $canIssue = true;
} elseif ($liters >= 100 && $ph >= 6.0 && $ph <= 9.0 && $tds >= 20 && $tds <= 500) {
    $statusKey = 'amber';
    $statusName = '黄色关注';
    $statusDesc = '水质处于关注区间，可提交净水证书申请，但须由管理员复核后决定证书状态。';
    $canIssue = true;
}

$unitRule = 'Every 100L of Clean Water = 1 Clean Water Unit';
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>RH2O 净水证书面板</title>
<style>
:root{
    --bg:#10091b;
    --bg2:#1a1030;
    --card:#241240;
    --card2:#2d1850;
    --line:rgba(150,214,255,.20);
    --text:#f4efff;
    --muted:#cfc0ea;
    --cyan:#7cecff;
    --cyan2:#55a7ff;
    --green:#31e3a0;
    --amber:#ffd166;
    --red:#ff6f8f;
}
*{box-sizing:border-box}
html,body{
    margin:0;
    padding:0;
    min-height:100%;
    background:
        radial-gradient(circle at top, rgba(124,236,255,.14), transparent 24%),
        linear-gradient(180deg,var(--bg),var(--bg2));
    color:var(--text);
    font-family:Arial,"Microsoft YaHei","PingFang SC","Noto Sans SC",sans-serif;
}
body{
    padding:calc(env(safe-area-inset-top,0px)) 0 calc(84px + env(safe-area-inset-bottom,0px)) 0;
}
.page{
    width:min(1180px,100%);
    margin:0 auto;
    padding:12px;
}
.hero{
    position:relative;
    overflow:hidden;
    border:1px solid var(--line);
    border-radius:22px;
    padding:18px;
    background:
        linear-gradient(180deg, rgba(255,255,255,.045), rgba(255,255,255,.02)),
        linear-gradient(135deg, #19112b, #120d1d);
    box-shadow:0 18px 40px rgba(0,0,0,.24);
}
.hero:before{
    content:"";
    position:absolute;
    inset:0;
    background:
        radial-gradient(circle at 90% 10%, rgba(124,236,255,.12), transparent 22%),
        radial-gradient(circle at 10% 0%, rgba(85,167,255,.10), transparent 24%);
    pointer-events:none;
}
.kicker{
    position:relative;
    z-index:1;
    font-size:12px;
    color:#c9f6ff;
    letter-spacing:.12em;
    text-transform:uppercase;
}
h1{
    position:relative;
    z-index:1;
    margin:8px 0 6px;
    font-size:30px;
    line-height:1.12;
}
.sub{
    position:relative;
    z-index:1;
    color:var(--muted);
    font-size:14px;
    line-height:1.58;
}
.top-meta{
    position:relative;
    z-index:1;
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    margin-top:10px;
}
.pill{
    border:1px solid var(--line);
    background:rgba(255,255,255,.05);
    color:#e8fbff;
    border-radius:999px;
    padding:7px 11px;
    font-size:12px;
}
.grid{
    display:grid;
    grid-template-columns:1fr;
    gap:12px;
    margin-top:12px;
}
.card{
    border:1px solid var(--line);
    border-radius:20px;
    padding:14px;
    background:linear-gradient(180deg,var(--card),var(--card2));
    box-shadow:0 12px 30px rgba(0,0,0,.22);
}
.card h2{
    margin:0 0 10px;
    font-size:18px;
}
.label{
    font-size:12px;
    color:var(--muted);
    margin-bottom:6px;
}
.input,
select,
input[type="date"],
input[type="number"],
input[type="text"]{
    display:block;
    width:100%;
    min-width:0;
    height:46px;
    min-height:46px;
    border-radius:14px;
    border:1px solid rgba(255,255,255,.12);
    background:#000;
    color:#fff;
    padding:0 14px;
    outline:none;
    box-sizing:border-box;
    font-size:16px;
    line-height:46px;
    -webkit-appearance:none;
    appearance:none;
}
.input:focus,
select:focus,
input[type="date"]:focus,
input[type="number"]:focus,
input[type="text"]:focus{
    border-color:var(--green);
    box-shadow:0 0 0 2px rgba(49,227,160,.12);
}
input[type="date"]{
    text-align:center;
}
input[type="date"]::-webkit-date-and-time-value{
    text-align:center;
}
input[type="date"]::-webkit-calendar-picker-indicator{
    opacity:.95;
}
select{
    padding-right:40px;
    line-height:normal;
}
.row{
    display:grid;
    grid-template-columns:1fr;
    gap:10px;
}
.btns{
    display:grid;
    grid-template-columns:1fr;
    gap:10px;
    margin-top:12px;
}
.btn{
    min-height:48px;
    border:0;
    border-radius:14px;
    padding:12px 16px;
    font-weight:700;
    color:#fff;
    cursor:pointer;
    background:linear-gradient(180deg,var(--cyan),var(--cyan2));
}
.btn.alt{
    background:linear-gradient(180deg,#3d285f,#27183d);
    border:1px solid var(--line);
}
.badge{
    display:inline-block;
    padding:8px 12px;
    border-radius:999px;
    font-size:13px;
    font-weight:700;
}
.badge.green{background:rgba(49,227,160,.14); color:var(--green); border:1px solid rgba(49,227,160,.30);}
.badge.amber{background:rgba(255,209,102,.12); color:var(--amber); border:1px solid rgba(255,209,102,.28);}
.badge.red{background:rgba(255,111,143,.12); color:var(--red); border:1px solid rgba(255,111,143,.28);}
.hero-metric{
    margin-top:12px;
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:10px;
}
.metric{
    border:1px solid rgba(255,255,255,.10);
    border-radius:16px;
    padding:12px;
    background:rgba(255,255,255,.03);
}
.metric .m-label{
    font-size:12px;
    color:var(--muted);
}
.metric .m-value{
    margin-top:6px;
    font-size:30px;
    line-height:1;
    font-weight:800;
    color:#fff;
}
.issue-box{
    margin-top:10px;
    border:1px solid rgba(255,255,255,.10);
    border-radius:16px;
    padding:14px;
    background:#150f24;
}
.desc{
    color:var(--muted);
    font-size:14px;
    line-height:1.6;
}
.note{
    margin-top:10px;
    border:1px solid rgba(255,255,255,.10);
    border-radius:14px;
    padding:12px;
    background:rgba(255,255,255,.04);
    font-size:14px;
    line-height:1.6;
}
.note.ok{
    border-color:rgba(49,227,160,.24);
    background:rgba(49,227,160,.08);
}
.note.warn{
    border-color:rgba(255,111,143,.24);
    background:rgba(255,111,143,.08);
}
.range-list{
    display:grid;
    gap:10px;
}
.range-item{
    border:1px solid rgba(255,255,255,.10);
    border-radius:16px;
    padding:12px;
    background:rgba(255,255,255,.03);
}
.range-item strong{
    display:block;
    margin-bottom:6px;
}
.table{
    display:grid;
    gap:8px;
}
.tr{
    display:grid;
    grid-template-columns:120px 1fr;
    gap:10px;
    padding:10px 0;
    border-bottom:1px dashed rgba(255,255,255,.10);
}
.tr:last-child{border-bottom:0}
.tk{font-size:12px;color:var(--muted)}
.tv{font-size:14px;color:#fff;line-height:1.58}
.mini{
    font-size:12px;
    color:var(--muted);
}
@media (min-width: 920px){
    .grid{grid-template-columns:1.08fr .92fr}
    .row{grid-template-columns:1fr 1fr}
    .btns{grid-template-columns:1fr 1fr}
}
</style>
</head>
<body>
<div class="page">

    <section class="hero">
        <div class="kicker">RH2O-EMA · CLEAN WATER CERT</div>
        <h1>RH2O 净水责任证书测试面板</h1>
        <div class="sub">
            本页为 RH2O 净水证书测试仪表板。核心规则锁定为：
            <strong>Every 100L of Clean Water = 1 Clean Water Unit</strong>。
            系统根据净水量、pH 与 TDS 区间判断绿色合格、黄色关注或红色警示。红色警示状态不得发证。
        </div>
        <div class="top-meta">
            <span class="pill">欢迎：<?php echo h($nickname); ?></span>
            <span class="pill">会话：<?php echo h($walletShort); ?></span>
            <span class="pill">证书类型：RH2O / Clean Water Cert</span>
            <span class="pill">单位规则：<?php echo h($unitRule); ?></span>
        </div>
        <div class="hero-metric">
            <div class="metric">
                <div class="m-label">净水量（L）</div>
                <div class="m-value"><?php echo h(number_format($liters, 1)); ?></div>
            </div>
            <div class="metric">
                <div class="m-label">Clean Water Unit</div>
                <div class="m-value"><?php echo h((string)$units); ?></div>
            </div>
        </div>
    </section>

    <div class="grid">

        <section class="card">
            <h2>1）净水检测与发证门槛</h2>

            <form method="post">
                <div class="row">
                    <div>
                        <div class="label">净水量（L）</div>
                        <input class="input" type="number" step="0.1" min="0" name="liters" value="<?php echo h((string)$liters); ?>">
                    </div>
                    <div>
                        <div class="label">pH 值</div>
                        <input class="input" type="number" step="0.1" min="0" name="ph" value="<?php echo h((string)$ph); ?>">
                    </div>
                </div>

                <div class="row" style="margin-top:10px;">
                    <div>
                        <div class="label">TDS（ppm）</div>
                        <input class="input" type="number" step="0.1" min="0" name="tds" value="<?php echo h((string)$tds); ?>">
                    </div>
                    <div>
                        <div class="label">授权检测分行</div>
                        <input class="input" type="text" name="branch_name" value="<?php echo h($branch); ?>" placeholder="例如：香港授权净水检测分行">
                    </div>
                </div>

                <div class="row" style="margin-top:10px;">
                    <div>
                        <div class="label">检测日期</div>
                        <input class="input" type="date" name="check_date" value="<?php echo h($checkDate); ?>">
                    </div>
                    <div>
                        <div class="label">管理员状态</div>
                        <select name="admin_status">
                            <?php
                            $opts = ['待审核','已确认','需复检','已拒绝','已发证'];
                            foreach ($opts as $opt) {
                                $sel = $adminStatus === $opt ? ' selected' : '';
                                echo '<option value="' . h($opt) . '"' . $sel . '>' . h($opt) . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                </div>

                <div class="btns">
                    <button class="btn" type="submit">更新净水状态</button>
                    <button class="btn alt" type="button" onclick="window.location.href='/rwa/rh2o/index.php'">重置</button>
                </div>
            </form>

            <div class="issue-box">
                <div class="label">实时净水状态</div>
                <div class="badge <?php echo h($statusKey); ?>"><?php echo h($statusName); ?></div>
                <div class="desc" style="margin-top:10px;"><?php echo h($statusDesc); ?></div>
            </div>

            <?php if ($canIssue): ?>
                <div class="note ok">
                    <strong>可进入发证流程：</strong><br>
                    当前数据允许提交 RH2O Clean Water Cert 申请。最终状态仍须由管理员复核，并记录净水检测发生在哪一个授权分行。
                </div>
            <?php else: ?>
                <div class="note warn">
                    <strong>红色警示：不可发证</strong><br>
                    当净水量未达到 100L，或 pH / TDS 超出允许区间时，不得申请或签发 RH2O Clean Water Cert。
                </div>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2>2）RH2O 三档状态说明</h2>
            <div class="range-list">
                <div class="range-item">
                    <strong style="color:var(--green)">绿色合格（可发证）</strong>
                    条件：净水量 ≥ 100L，pH 6.5–8.5，TDS 50–300 ppm。<br>
                    说明：净水指标处于合格区间，可按每 100L 形成 1 个 Clean Water Unit，并可进入发证审核。
                </div>
                <div class="range-item">
                    <strong style="color:var(--amber)">黄色关注（可申请 / 待管理员审核）</strong>
                    条件：净水量 ≥ 100L，pH 6.0–9.0，TDS 20–500 ppm。<br>
                    说明：可提交申请，但管理员可要求补充资料、复检或人工确认。
                </div>
                <div class="range-item">
                    <strong style="color:var(--red)">红色警示（不可发证）</strong>
                    条件：净水量不足 100L，或 pH / TDS 超出允许范围。<br>
                    说明：当前无发证资格。
                </div>
            </div>

            <div class="note">
                <strong>单位规则：</strong><?php echo h($unitRule); ?><br>
                即：每 100 升合格净水，记录 1 个净水责任单位。
            </div>
        </section>

        <section class="card">
            <h2>3）Clean Water Cert 发证逻辑</h2>
            <div class="table">
                <div class="tr">
                    <div class="tk">证书名称</div>
                    <div class="tv">RH2O / Clean Water Cert</div>
                </div>
                <div class="tr">
                    <div class="tk">证书用途</div>
                    <div class="tv">作为净水责任记录与清洁饮水应用证明，不属于金融产品、收益工具或投资回报承诺。</div>
                </div>
                <div class="tr">
                    <div class="tk">单位规则</div>
                    <div class="tv"><?php echo h($unitRule); ?></div>
                </div>
                <div class="tr">
                    <div class="tk">发证门槛</div>
                    <div class="tv">必须先满足净水量门槛，并通过 pH 与 TDS 区间评估后，方可进入申请 / 发证审核流程。</div>
                </div>
                <div class="tr">
                    <div class="tk">红色规则</div>
                    <div class="tv">红色警示状态不得发证。</div>
                </div>
                <div class="tr">
                    <div class="tk">授权分行记录</div>
                    <div class="tv">管理员需记录用户本次净水检测发生在哪一个授权分行 / 授权检测点。</div>
                </div>
            </div>
        </section>

        <section class="card">
            <h2>4）管理员审核记录面板</h2>
            <div class="table">
                <div class="tr">
                    <div class="tk">申请人</div>
                    <div class="tv"><?php echo h($nickname); ?></div>
                </div>
                <div class="tr">
                    <div class="tk">会话钱包</div>
                    <div class="tv"><?php echo h($walletShort); ?></div>
                </div>
                <div class="tr">
                    <div class="tk">净水量</div>
                    <div class="tv"><?php echo h(number_format($liters, 1)); ?> L</div>
                </div>
                <div class="tr">
                    <div class="tk">Clean Water Unit</div>
                    <div class="tv"><?php echo h((string)$units); ?> Unit</div>
                </div>
                <div class="tr">
                    <div class="tk">pH</div>
                    <div class="tv"><?php echo h(number_format($ph, 1)); ?></div>
                </div>
                <div class="tr">
                    <div class="tk">TDS</div>
                    <div class="tv"><?php echo h(number_format($tds, 1)); ?> ppm</div>
                </div>
                <div class="tr">
                    <div class="tk">净水状态</div>
                    <div class="tv"><?php echo h($statusName); ?></div>
                </div>
                <div class="tr">
                    <div class="tk">授权检测分行</div>
                    <div class="tv"><?php echo h($branch); ?></div>
                </div>
                <div class="tr">
                    <div class="tk">检测日期</div>
                    <div class="tv"><?php echo h($checkDate); ?></div>
                </div>
                <div class="tr">
                    <div class="tk">管理员状态</div>
                    <div class="tv"><?php echo h($adminStatus); ?></div>
                </div>
                <div class="tr">
                    <div class="tk">发证资格</div>
                    <div class="tv"><?php echo $canIssue ? '允许进入申请 / 发证审核流程' : '红色警示，禁止发证'; ?></div>
                </div>
            </div>

            <div class="note">
                <strong>管理员说明：</strong><br>
                管理员将对 RH2O 净水证书申请进行状态标记，并同步记录净水量、水质参数、检测分行、检测日期以及最终审核结果。
            </div>

            <div class="mini" style="margin-top:12px;">
                下一步建议：对接 /rwa/cert/ 发证页、RH2O 检测记录 API、授权分行主数据表。
            </div>
        </section>

    </div>
</div>

<?php require_once __DIR__ . '/../inc/rwa-bottom-nav.php'; ?>
</body>
</html>