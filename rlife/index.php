<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/rlife/index.php
 * Version: v1.1.20260307-rlife-date-width-fix
 */

require_once __DIR__ . '/../inc/rwa-session.php';
require_once __DIR__ . '/../inc/rwa-topbar-nav.php';

$nickname = 'Guest';
$walletShort = 'SESSION: NONE';

if (isset($GLOBALS['poado_user']) && is_array($GLOBALS['poado_user'])) {
    $u = $GLOBALS['poado_user'];
    $nickname = trim((string)($u['nickname'] ?? 'Guest')) ?: 'Guest';
    $wallet = trim((string)($u['wallet'] ?? $u['wallet_address'] ?? ''));
    if ($wallet !== '') {
        $walletShort = substr($wallet, 0, 6) . '...' . substr($wallet, -4);
    }
}

function h(?string $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$weight = isset($_POST['weight']) ? (float)$_POST['weight'] : 68.0;
$heightCm = isset($_POST['height_cm']) ? (float)$_POST['height_cm'] : 170.0;
$branch = trim((string)($_POST['branch_name'] ?? '香港授权健康分行'));
$checkDate = trim((string)($_POST['check_date'] ?? gmdate('Y-m-d')));
$adminStatus = trim((string)($_POST['admin_status'] ?? '待审核'));

$bmi = 0.0;
if ($heightCm > 0) {
    $m = $heightCm / 100;
    $bmi = $weight / ($m * $m);
}
$bmiText = number_format($bmi, 1);

$statusKey = 'red';
$statusName = '红色警示';
$statusDesc = 'BMI 不在可发证健康区间，当前不可申请 Health Cert。';
$canIssue = false;

if ($bmi >= 18.5 && $bmi <= 24.9) {
    $statusKey = 'green';
    $statusName = '绿色健康';
    $statusDesc = 'BMI 处于健康区间，可申请 Health Cert，并按 1 Day Healthy Unit 进行记录。';
    $canIssue = true;
} elseif ($bmi >= 25.0 && $bmi <= 29.9) {
    $statusKey = 'amber';
    $statusName = '黄色关注';
    $statusDesc = 'BMI 偏高，属于关注区间，可提交承诺申请，由管理员审核后决定证书状态。';
    $canIssue = true;
}

$unitRule = '1 Day Healthy Unit based BMI';
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>RLIFE 健康证书面板</title>
<style>
:root{
    --bg:#12081f;
    --bg2:#1c0d2f;
    --panel:#24113a;
    --panel2:#2f174a;
    --line:rgba(208,165,255,.24);
    --text:#f5edff;
    --muted:#cdb8e8;
    --hi:#c86bff;
    --hi2:#8d3dff;
    --green:#30e6a1;
    --amber:#ffd166;
    --red:#ff6b8a;
}
*{box-sizing:border-box}
html,body{
    margin:0;
    padding:0;
    background:
        radial-gradient(circle at top, rgba(200,107,255,.16), transparent 28%),
        linear-gradient(180deg,var(--bg),var(--bg2));
    color:var(--text);
    font-family:Arial,"Microsoft YaHei","PingFang SC","Noto Sans SC",sans-serif;
    min-height:100%;
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
    border:1px solid var(--line);
    background:linear-gradient(180deg,rgba(255,255,255,.04),rgba(255,255,255,.02));
    border-radius:20px;
    padding:16px;
    box-shadow:0 0 0 1px rgba(255,255,255,.02) inset, 0 18px 40px rgba(0,0,0,.26);
}
.kicker{
    font-size:12px;
    color:#f0c7ff;
    letter-spacing:.12em;
    text-transform:uppercase;
}
h1{
    margin:8px 0 6px;
    font-size:28px;
    line-height:1.15;
}
.sub{
    color:var(--muted);
    font-size:14px;
    line-height:1.5;
}
.grid{
    display:grid;
    grid-template-columns:1fr;
    gap:12px;
    margin-top:12px;
}
.card{
    border:1px solid var(--line);
    background:linear-gradient(180deg,var(--panel),var(--panel2));
    border-radius:20px;
    padding:14px;
    box-shadow:0 12px 30px rgba(0,0,0,.24);
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
    box-shadow:0 0 0 2px rgba(48,230,161,.12);
}
input[type="date"]{
    text-align:center;
}
input[type="date"]::-webkit-date-and-time-value{
    text-align:center;
}
input[type="date"]::-webkit-calendar-picker-indicator{
    opacity:.9;
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
    cursor:pointer;
    color:#fff;
    background:linear-gradient(180deg,var(--hi),var(--hi2));
}
.btn.alt{
    background:linear-gradient(180deg,#3c2559,#251537);
    border:1px solid var(--line);
}
.badge{
    display:inline-block;
    padding:8px 12px;
    border-radius:999px;
    font-size:13px;
    font-weight:700;
}
.badge.green{background:rgba(48,230,161,.14); color:var(--green); border:1px solid rgba(48,230,161,.30);}
.badge.amber{background:rgba(255,209,102,.12); color:var(--amber); border:1px solid rgba(255,209,102,.28);}
.badge.red{background:rgba(255,107,138,.12); color:var(--red); border:1px solid rgba(255,107,138,.28);}
.stat-big{
    font-size:42px;
    line-height:1;
    font-weight:800;
    margin:8px 0 10px;
}
.desc{
    color:var(--muted);
    line-height:1.6;
    font-size:14px;
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
.tv{font-size:14px;color:#fff;line-height:1.5}
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
.range-item strong{display:block;margin-bottom:6px}
.note{
    margin-top:10px;
    padding:12px;
    border-radius:14px;
    background:rgba(255,255,255,.04);
    border:1px solid rgba(255,255,255,.08);
    color:#efe6ff;
    font-size:14px;
    line-height:1.6;
}
.warn{
    border-color:rgba(255,107,138,.28);
    background:rgba(255,107,138,.08);
}
.ok{
    border-color:rgba(48,230,161,.24);
    background:rgba(48,230,161,.07);
}
.mini{
    font-size:12px;
    color:var(--muted);
}
.issue-box{
    margin-top:10px;
    padding:14px;
    border-radius:16px;
    border:1px solid rgba(255,255,255,.10);
    background:#140d21;
}
.issue-lock{
    margin-top:10px;
    padding:14px;
    border-radius:16px;
    border:1px solid rgba(255,107,138,.26);
    background:rgba(255,107,138,.08);
    color:#ffd8e1;
}
.top-meta{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    margin-top:8px;
}
.pill{
    border:1px solid var(--line);
    border-radius:999px;
    padding:7px 11px;
    font-size:12px;
    color:#eadcff;
    background:rgba(255,255,255,.04);
}
@media (min-width: 920px){
    .grid{
        grid-template-columns:1.1fr .9fr;
    }
    .row{
        grid-template-columns:1fr 1fr;
    }
    .btns{
        grid-template-columns:1fr 1fr;
    }
}
</style>
</head>
<body>
<div class="page">

    <section class="hero">
        <div class="kicker">RLIFE-EMA · 健康责任证书</div>
        <h1>健康证书发证面板</h1>
        <div class="sub">
            以 BMI 为健康门槛，发放 <strong>Health Cert</strong>。本页面用于中文健康证书发证控制台、BMI 状态判定、承诺申请说明，以及管理员审核入口展示。
        </div>
        <div class="top-meta">
            <span class="pill">欢迎：<?php echo h($nickname); ?></span>
            <span class="pill">会话：<?php echo h($walletShort); ?></span>
            <span class="pill">证书类型：HC / Health Cert</span>
            <span class="pill">单位规则：<?php echo h($unitRule); ?></span>
        </div>
    </section>

    <div class="grid">

        <section class="card">
            <h2>1）BMI 检测与发证门槛</h2>

            <form method="post">
                <div class="row">
                    <div>
                        <div class="label">体重（kg）</div>
                        <input class="input" type="number" step="0.1" name="weight" value="<?php echo h((string)$weight); ?>">
                    </div>
                    <div>
                        <div class="label">身高（cm）</div>
                        <input class="input" type="number" step="0.1" name="height_cm" value="<?php echo h((string)$heightCm); ?>">
                    </div>
                </div>

                <div class="row" style="margin-top:10px;">
                    <div>
                        <div class="label">授权检测分行</div>
                        <input class="input" type="text" name="branch_name" value="<?php echo h($branch); ?>" placeholder="例如：香港授权健康分行 / 吉隆坡授权分行">
                    </div>
                    <div>
                        <div class="label">检测日期</div>
                        <input class="input" type="date" name="check_date" value="<?php echo h($checkDate); ?>">
                    </div>
                </div>

                <div class="row" style="margin-top:10px;">
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
                    <div>
                        <div class="label">当前 BMI</div>
                        <input class="input" type="text" value="<?php echo h($bmiText); ?>" readonly>
                    </div>
                </div>

                <div class="btns">
                    <button class="btn" type="submit">更新 BMI 状态</button>
                    <button class="btn alt" type="button" onclick="window.location.href='/rwa/rlife/index.php'">重置</button>
                </div>
            </form>

            <div class="issue-box">
                <div class="label">实时健康状态</div>
                <div class="badge <?php echo h($statusKey); ?>"><?php echo h($statusName); ?></div>
                <div class="stat-big"><?php echo h($bmiText); ?></div>
                <div class="desc"><?php echo h($statusDesc); ?></div>
            </div>

            <?php if ($canIssue): ?>
                <div class="note ok">
                    <strong>可进入发证流程：</strong><br>
                    当前 BMI 状态允许提交 Health Cert 承诺申请。最终发证状态仍须由管理员复核，并记录检测 BMI 的授权分行与审核结果。
                </div>
            <?php else: ?>
                <div class="issue-lock">
                    <strong>红色警示：不可发证</strong><br>
                    当 BMI 处于红色警示区间时，用户 <strong>无权申请或签发 Health Cert</strong>。需先改善健康状态并重新在授权分行完成 BMI 检测后，才可再次提交申请。
                </div>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2>2）BMI 三档健康状态说明</h2>
            <div class="range-list">
                <div class="range-item">
                    <strong style="color:var(--green)">绿色健康（可发证）</strong>
                    BMI 18.5 – 24.9<br>
                    说明：属于健康区间，可按 <strong>1 Day Healthy Unit based BMI</strong> 进行健康责任记录，并可申请 Health Cert。
                </div>
                <div class="range-item">
                    <strong style="color:var(--amber)">黄色关注（可申请 / 待管理员审核）</strong>
                    BMI 25.0 – 29.9<br>
                    说明：属于偏高关注区间，可提交承诺申请，但证书状态须由管理员标记，必要时可要求复检或补充健康管理计划。
                </div>
                <div class="range-item">
                    <strong style="color:var(--red)">红色警示（不可发证）</strong>
                    BMI &lt; 18.5 或 BMI ≥ 30.0<br>
                    说明：不符合当前发证门槛，<strong>不得签发 Health Cert</strong>。
                </div>
            </div>

            <div class="note">
                <strong>单位规则：</strong>1 Day Healthy Unit based BMI<br>
                即：以 BMI 日度健康状态作为健康责任单位的基础记录逻辑。
            </div>

            <div class="note">
                <strong>承诺申请与兑换规则：</strong><br>
                Health Cert 采用承诺申请模式。每累计 <strong>1000 元 POAdo</strong> 的认证健康应用额度，可兑换 <strong>1000 EMA$</strong>，用于认证健康产品，以支持降低 BMI、改善体重管理及健康状态。
            </div>
        </section>

        <section class="card">
            <h2>3）Health Cert 发证逻辑</h2>
            <div class="table">
                <div class="tr">
                    <div class="tk">证书名称</div>
                    <div class="tv">Health Cert / 健康证书</div>
                </div>
                <div class="tr">
                    <div class="tk">证书用途</div>
                    <div class="tv">作为健康监测责任记录与承诺应用凭证，不属于金融产品、收益工具或投资回报承诺。</div>
                </div>
                <div class="tr">
                    <div class="tk">发证门槛</div>
                    <div class="tv">必须先完成 BMI 检测，并依据绿色 / 黄色 / 红色三档状态进行发证权限控制。</div>
                </div>
                <div class="tr">
                    <div class="tk">红色规则</div>
                    <div class="tv">红色警示用户无发证资格。</div>
                </div>
                <div class="tr">
                    <div class="tk">授权分行记录</div>
                    <div class="tv">每次检测必须记录在何处授权分行完成 BMI 检测，以便管理员审阅与后续追踪。</div>
                </div>
                <div class="tr">
                    <div class="tk">管理员处理</div>
                    <div class="tv">管理员需标记证书状态，并记录 BMI 检测分行、检测日期、审核意见与证书状态。</div>
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
                    <div class="tk">BMI 数值</div>
                    <div class="tv"><?php echo h($bmiText); ?></div>
                </div>
                <div class="tr">
                    <div class="tk">健康状态</div>
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
                    <div class="tv"><?php echo $canIssue ? '允许进入承诺申请 / 发证审核流程' : '红色警示，禁止发证'; ?></div>
                </div>
            </div>

            <div class="note">
                <strong>管理员说明：</strong><br>
                管理员将对该证书进行状态标记，并同步记录：BMI 检测值、检测授权分行、检测日期、审核状态，以及后续是否允许签发或复检。
            </div>

            <div class="mini" style="margin-top:12px;">
                建议后续对接：/rwa/cert/ 发证页、/rwa/profile/ 用户资料页、管理员审核 API、授权分行主数据表。
            </div>
        </section>

    </div>
</div>

<?php require_once __DIR__ . '/../inc/rwa-bottom-nav.php'; ?>
</body>
</html>