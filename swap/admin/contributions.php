<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/swap/inc/swap-helpers.php';

$user = swap_require_admin_or_agent();
$role = strtolower(trim((string)($user['role'] ?? '')));
$userId = (int)($user['id'] ?? 0);
$isAdmin = ($role === 'admin');

$pdo = swap_db();

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$q = trim((string)($_GET['q'] ?? ''));
$projectKey = trim((string)($_GET['project_key'] ?? ''));

$where = [];
$params = [];

if ($q !== '') {
    $where[] = '(w.worker_uid LIKE :q OR w.full_name LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}

if ($projectKey !== '') {
    $where[] = 'w.project_key = :project_key';
    $params[':project_key'] = $projectKey;
}

if (!$isAdmin) {
    $where[] = 'w.project_key IN (
        SELECT project_key FROM rwa_hr_agents
        WHERE user_id = :uid AND is_active = 1
    )';
    $params[':uid'] = $userId;
}

$sqlWhere = $where ? 'WHERE '.implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
SELECT w.*
FROM rwa_hr_workers w
{$sqlWhere}
ORDER BY w.updated_at DESC
LIMIT 100
");

foreach ($params as $k=>$v) $stmt->bindValue($k,$v);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Contribution Dashboard</title>
<link rel="stylesheet" href="/rwa/swap/assets/css/swap.css">
</head>

<body class="swap-page">
<?php require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/rwa-topbar-nav.php'; ?>

<main class="swap-shell">

<section class="card">
<h2>Contribution Dashboard</h2>

<form method="get" class="swap-search-row">
<input name="q" class="swap-input" placeholder="Search worker">
<input name="project_key" class="swap-input" placeholder="Project">
<button class="swap-btn swap-btn-primary">Search</button>
</form>

</section>

<section class="card">

<?php foreach($rows as $r): 

$total = (float)($r['hours_contributed_year'] ?? 0);
$used  = (float)($r['hours_used_year'] ?? 0);
$avail = max(0, $total - $used);

$certs = floor($avail / 10);
?>

<div class="swap-worker-card">

<div class="swap-worker-head">
<strong><?=h($r['worker_uid'])?></strong>
<span><?=h($r['full_name'])?></span>
</div>

<div class="swap-worker-meta">

<div>
<span class="swap-result-label">Total Hours</span>
<strong><?= $total ?></strong>
</div>

<div>
<span class="swap-result-label">Used</span>
<strong><?= $used ?></strong>
</div>

<div>
<span class="swap-result-label">Available</span>
<strong><?= $avail ?></strong>
</div>

<div>
<span class="swap-result-label">Certs</span>
<strong><?= $certs ?></strong>
</div>

</div>

<div class="swap-worker-actions">

<button class="swap-btn swap-btn-primary"
onclick="mintCert('<?=h($r['worker_uid'])?>',1)">
Mint 1 Cert
</button>

<button class="swap-btn swap-btn-secondary"
onclick="mintCert('<?=h($r['worker_uid'])?>',<?= $certs ?>)">
Mint All
</button>

</div>

<div class="swap-inline-form">
<input type="date" id="d_<?=h($r['worker_uid'])?>">
<input type="number" id="h_<?=h($r['worker_uid'])?>" placeholder="hours">

<button class="swap-btn"
onclick="logHours('<?=h($r['worker_uid'])?>')">
Log Hours
</button>
</div>

</div>

<?php endforeach; ?>

</section>

</main>

<script>

async function logHours(uid){
  let d = document.getElementById('d_'+uid).value;
  let h = document.getElementById('h_'+uid).value;

  let res = await fetch('/rwa/swap/api/admin/log-work-hours.php',{
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({
      worker_uid: uid,
      work_date: d,
      hours_worked: h
    })
  });

  let j = await res.json();
  if(!j.ok){ alert(j.error); return; }

  alert('Hours logged');
  location.reload();
}

async function mintCert(uid, count){
  if(count<=0){ alert('No available cert'); return; }

  let res = await fetch('/rwa/swap/api/mint-cert.php',{
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({
      worker_uid: uid,
      cert_count: count
    })
  });

  let j = await res.json();
  if(!j.ok){ alert(j.error); return; }

  alert('Minted: '+j.cert_count);
  location.reload();
}

</script>

<?php require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/rwa-bottom-nav.php'; ?>
</body>
</html>