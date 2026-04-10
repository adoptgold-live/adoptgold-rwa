<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';

function h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}

function scan_by_uid($uid){
    $root=$_SERVER['DOCUMENT_ROOT'].'/rwa/metadata/cert/_fallback_vault/RWA_CERT';
    $cmd="find ".escapeshellarg($root)." -type d -name ".escapeshellarg($uid)." 2>/dev/null | head -n 1";
    $found=trim((string)shell_exec($cmd));
    return ($found!=='' && is_dir($found))?$found:'';
}

$uid=trim($_GET['uid']??'');

$artifact=scan_by_uid($uid);

$verifyPath=$artifact!==''?$artifact.'/verify/verify.json':'';

$verify=[];
if($verifyPath && is_file($verifyPath)){
    $verify=json_decode(file_get_contents($verifyPath),true)?:[];
}

$ready = !empty($verify['ok']) && empty($verify['used_fallback_placeholder']);
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>NFT Debug</title></head>
<body style="background:#000;color:#0f0;font-family:monospace;padding:20px">

<h2>NFT Ready Tester (FORCED SCAN MODE)</h2>

<p><b>UID:</b> <?=h($uid)?></p>

<p><b>Artifact Path:</b><br><?=h($artifact)?></p>

<p><b>Verify Path:</b><br><?=h($verifyPath)?></p>

<p><b>Result:</b>
<?= $ready ? '<span style="color:#0f0">NFT READY</span>' : '<span style="color:#f00">NFT BROKEN</span>' ?>
</p>

<pre><?=h(json_encode($verify,JSON_PRETTY_PRINT))?></pre>

</body>
</html>
