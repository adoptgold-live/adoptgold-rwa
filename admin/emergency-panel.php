<?php
declare(strict_types=1);

/*
POAdo Emergency Panel
Admin Kill Switch
*/

require_once $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/dashboard/inc/session-user.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/dashboard/inc/bootstrap.php';

$wallet = get_wallet_session();
if(!$wallet){
    header("Location: /rwa/index.php");
    exit;
}

db_connect();
$pdo = $GLOBALS['pdo'];

if(isset($_POST['flag'])){

    $flag = $_POST['flag'];

    $pdo->prepare("
        UPDATE poado_system_flags
        SET flag_value = IF(flag_value=1,0,1)
        WHERE flag_key = ?
    ")->execute([$flag]);

    header("Location: emergency-panel.php");
    exit;
}

$stmt = $pdo->query("SELECT flag_key,flag_value FROM poado_system_flags");
$flags = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

?>
<!DOCTYPE html>
<html>
<head>

<meta charset="utf-8">
<title>Emergency Panel</title>
<meta name="viewport" content="width=device-width,initial-scale=1">

<style>

body{
background:#000;
color:#e8d9a5;
font-family:system-ui;
margin:0;
}

.wrap{
max-width:800px;
margin:auto;
padding:20px;
}

.title{
font-size:22px;
color:#ff4444;
margin-bottom:20px;
}

.card{
background:#0b0b0b;
border:1px solid #6f5b1d;
padding:15px;
margin-bottom:10px;
display:flex;
justify-content:space-between;
align-items:center;
}

button{
background:#111;
border:1px solid #6f5b1d;
color:#e8d9a5;
padding:6px 12px;
cursor:pointer;
}

.status-on{color:#ff6c6c;}
.status-off{color:#6cff6c;}

</style>

</head>

<body>

<?php require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/rwa-topbar-nav.php'; ?>

<div class="wrap">

<?php require __DIR__.'/_nav.php'; ?>

<div class="title">
Emergency Control Panel
</div>

<?php foreach($flags as $k=>$v): ?>

<div class="card">

<div>
<?=$k?><br>
<span class="<?=$v?'status-on':'status-off'?>">
<?=$v?'PAUSED':'RUNNING'?>
</span>
</div>

<form method="post">
<input type="hidden" name="flag" value="<?=$k?>">
<button>Toggle</button>
</form>

</div>

<?php endforeach ?>

</div>

<?php require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/rwa-bottom-nav.php'; ?>

</body>
</html>