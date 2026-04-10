<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/testers/qr-tester.php
 * Simple tester for /rwa/inc/core/qr.php
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$sampleBoundAddress = '0:717359c4fcc1ad210a72d704f3567a104cf7d09afe6cb7fbb640959234d02f6d';

$boundAddress = trim((string)($_GET['address'] ?? $sampleBoundAddress));
$size = (int)($_GET['size'] ?? 320);
if ($size < 120) $size = 120;
if ($size > 800) $size = 800;

$qrPayload = $boundAddress !== '' ? ('ton://transfer/' . $boundAddress) : '';
$qrUrl = '/rwa/inc/core/qr.php?text=' . rawurlencode($qrPayload) . '&size=' . $size;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>QR Tester</title>
  <style>
    :root{
      --bg:#050607;
      --panel:#0c0f11;
      --border:rgba(246,215,104,.18);
      --text:#f4f4f4;
      --muted:rgba(255,255,255,.65);
      --gold:#f6d768;
      --green:#7dff9f;
      --red:#ff8e7f;
    }
    *{box-sizing:border-box}
    body{
      margin:0;
      background:var(--bg);
      color:var(--text);
      font:14px/1.45 Arial,Helvetica,sans-serif;
      padding:20px;
    }
    .wrap{
      max-width:760px;
      margin:0 auto;
    }
    .card{
      background:var(--panel);
      border:1px solid var(--border);
      border-radius:18px;
      padding:18px;
      margin-bottom:16px;
    }
    h1{
      margin:0 0 8px;
      font-size:22px;
      color:var(--gold);
    }
    .muted{
      color:var(--muted);
    }
    label{
      display:block;
      margin:0 0 6px;
      font-weight:700;
    }
    input{
      width:100%;
      min-height:44px;
      padding:10px 12px;
      border-radius:12px;
      border:1px solid var(--border);
      background:#070909;
      color:#fff;
      outline:none;
    }
    .row{
      display:grid;
      grid-template-columns:1fr 140px;
      gap:12px;
    }
    .btns{
      display:flex;
      gap:10px;
      flex-wrap:wrap;
      margin-top:14px;
    }
    button,.linkbtn{
      min-height:42px;
      padding:10px 14px;
      border-radius:12px;
      border:1px solid var(--border);
      background:#111517;
      color:#fff;
      cursor:pointer;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      justify-content:center;
    }
    .qrbox{
      text-align:center;
    }
    .qrimg{
      display:inline-block;
      background:#fff;
      padding:10px;
      border-radius:16px;
      max-width:100%;
    }
    code, .mono{
      word-break:break-all;
      color:var(--green);
    }
    .bad{
      color:var(--red);
      font-weight:700;
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h1>QR Tester</h1>
      <div class="muted">Simple tester for <code>/rwa/inc/core/qr.php</code></div>
    </div>

    <div class="card">
      <form method="get">
        <div style="margin-bottom:12px;">
          <label for="address">Bound TON Address</label>
          <input
            id="address"
            name="address"
            type="text"
            value="<?= h($boundAddress) ?>"
            placeholder="0:..."
            spellcheck="false"
            autocomplete="off"
          >
        </div>

        <div class="row">
          <div>
            <label for="size">QR Size</label>
            <input
              id="size"
              name="size"
              type="number"
              min="120"
              max="800"
              step="10"
              value="<?= h((string)$size) ?>"
            >
          </div>
          <div style="display:flex;align-items:end;">
            <button type="submit" style="width:100%;">Render QR</button>
          </div>
        </div>
      </form>

      <div class="btns">
        <a class="linkbtn" href="?address=<?= rawurlencode($sampleBoundAddress) ?>&size=320">Load Sample</a>
        <a class="linkbtn" href="<?= h($qrUrl) ?>" target="_blank" rel="noopener">Open QR Only</a>
      </div>
    </div>

    <div class="card">
      <div style="margin-bottom:8px;"><strong>Raw Address</strong></div>
      <div class="mono"><?= $boundAddress !== '' ? h($boundAddress) : '<span class="bad">EMPTY</span>' ?></div>

      <div style="margin:16px 0 8px;"><strong>QR Payload</strong></div>
      <div class="mono"><?= $qrPayload !== '' ? h($qrPayload) : '<span class="bad">EMPTY</span>' ?></div>

      <div style="margin:16px 0 8px;"><strong>QR URL</strong></div>
      <div class="mono"><?= h($qrUrl) ?></div>
    </div>

    <div class="card qrbox">
      <div style="margin-bottom:12px;"><strong>QR Preview</strong></div>
      <?php if ($qrPayload !== ''): ?>
        <img
          class="qrimg"
          src="<?= h($qrUrl) ?>"
          alt="TON QR"
          width="<?= h((string)$size) ?>"
          height="<?= h((string)$size) ?>"
        >
      <?php else: ?>
        <div class="bad">No address provided</div>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>