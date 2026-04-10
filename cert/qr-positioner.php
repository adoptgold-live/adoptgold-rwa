<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/qr-positioner.php
 *
 * Final test page for all locked NFT template QR positions.
 *
 * Uses:
 * - latest locked map from /rwa/cert/api/_meta-image-map.php
 * - local self-contained test QR marker for visual checking
 *
 * Output preview folder:
 * - /var/www/html/public/rwa/cert/tmp/qr-positioner/
 */

require_once '/var/www/html/public/rwa/inc/core/bootstrap.php';
require_once '/var/www/html/public/rwa/cert/api/_meta-image-map.php';

const POADO_QR_POS_TMP_DIR = '/var/www/html/public/rwa/cert/tmp/qr-positioner';
const POADO_QR_POS_TMP_URL = '/rwa/cert/tmp/qr-positioner';
const POADO_QR_POS_TEMPLATE_ROOT = '/var/www/html/public/rwa/metadata/nft';

function h($v): string
{
    if ($v === null) {
        return '';
    }
    if (is_bool($v)) {
        return $v ? '1' : '0';
    }
    if (is_scalar($v)) {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
    return htmlspecialchars(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '', ENT_QUOTES, 'UTF-8');
}

function poado_qr_pos_ensure_dir(string $dir): void
{
    if ($dir === '') {
        throw new InvalidArgumentException('Empty directory path.');
    }
    if (is_dir($dir)) {
        return;
    }
    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Failed to create directory: ' . $dir);
    }
}

function poado_qr_pos_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = scandir($dir);
    if (!is_array($items)) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            poado_qr_pos_rrmdir($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($dir);
}

function poado_qr_pos_reset_tmp(): void
{
    if (is_dir(POADO_QR_POS_TMP_DIR)) {
        poado_qr_pos_rrmdir(POADO_QR_POS_TMP_DIR);
    }
    poado_qr_pos_ensure_dir(POADO_QR_POS_TMP_DIR);
}

function poado_qr_pos_require_gd(): void
{
    if (!extension_loaded('gd')) {
        throw new RuntimeException('GD extension is required.');
    }

    foreach ([
        'imagecreatefrompng',
        'imagepng',
        'imagecopyresampled',
        'imagefilledrectangle',
        'imagerectangle',
        'imageline',
        'imagestring',
    ] as $fn) {
        if (!function_exists($fn)) {
            throw new RuntimeException('Missing GD function: ' . $fn);
        }
    }
}

function poado_qr_pos_load_png(string $path): GdImage
{
    if (!is_file($path)) {
        throw new RuntimeException('Template file not found: ' . $path);
    }

    $img = @imagecreatefrompng($path);
    if (!$img instanceof GdImage) {
        throw new RuntimeException('Failed to load PNG: ' . $path);
    }

    imagealphablending($img, true);
    imagesavealpha($img, true);

    return $img;
}

function poado_qr_pos_save_png(GdImage $img, string $path): void
{
    poado_qr_pos_ensure_dir(dirname($path));

    if (!imagepng($img, $path, 6)) {
        throw new RuntimeException('Failed to save PNG: ' . $path);
    }
}

function poado_qr_pos_test_uid(string $certType, string $rwaKey): string
{
    $date = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kuala_Lumpur')))->format('Ymd');
    $hex = strtoupper(substr(hash('sha256', $certType . '|' . $rwaKey), 0, 8));
    return strtoupper($rwaKey) . '-' . $date . '-' . $hex;
}

function poado_qr_pos_make_test_qr_png(string $path, int $size, string $label): void
{
    if ($size <= 0) {
        throw new InvalidArgumentException('QR size must be > 0.');
    }

    $img = imagecreatetruecolor($size, $size);
    if (!$img instanceof GdImage) {
        throw new RuntimeException('Failed to allocate QR image.');
    }

    imagealphablending($img, false);
    imagesavealpha($img, true);

    $white = imagecolorallocate($img, 255, 255, 255);
    $black = imagecolorallocate($img, 0, 0, 0);
    $red = imagecolorallocate($img, 220, 40, 40);
    $blue = imagecolorallocate($img, 40, 120, 220);

    imagefilledrectangle($img, 0, 0, $size - 1, $size - 1, $white);

    $modules = 21;
    $cell = max(1, (int)floor($size / $modules));
    $gridSize = $cell * $modules;
    $offset = (int)floor(($size - $gridSize) / 2);
    $hash = hash('sha256', $label);

    for ($y = 0; $y < $modules; $y++) {
        for ($x = 0; $x < $modules; $x++) {
            $isFinder =
                (($x < 7 && $y < 7) ||
                 ($x >= $modules - 7 && $y < 7) ||
                 ($x < 7 && $y >= $modules - 7));

            if ($isFinder) {
                continue;
            }

            $idx = ($x + $y * $modules) % strlen($hash);
            $bit = hexdec($hash[$idx]) % 2;

            if ($bit === 1) {
                $x1 = $offset + $x * $cell;
                $y1 = $offset + $y * $cell;
                imagefilledrectangle($img, $x1, $y1, $x1 + $cell - 1, $y1 + $cell - 1, $black);
            }
        }
    }

    $finderCoords = [
        [0, 0],
        [$modules - 7, 0],
        [0, $modules - 7],
    ];

    foreach ($finderCoords as [$fx, $fy]) {
        $x1 = $offset + $fx * $cell;
        $y1 = $offset + $fy * $cell;
        $x7 = $x1 + 7 * $cell - 1;
        $y7 = $y1 + 7 * $cell - 1;

        imagefilledrectangle($img, $x1, $y1, $x7, $y7, $black);
        imagefilledrectangle($img, $x1 + $cell, $y1 + $cell, $x7 - $cell, $y7 - $cell, $white);
        imagefilledrectangle($img, $x1 + 2 * $cell, $y1 + 2 * $cell, $x7 - 2 * $cell, $y7 - 2 * $cell, $black);
    }

    imagerectangle($img, 0, 0, $size - 1, $size - 1, $red);
    imagerectangle($img, 1, 1, $size - 2, $size - 2, $red);

    imageline($img, (int)floor($size / 2), 0, (int)floor($size / 2), $size - 1, $blue);
    imageline($img, 0, (int)floor($size / 2), $size - 1, (int)floor($size / 2), $blue);

    imagestring($img, 2, 4, max(2, $size - 14), 'TEST', $red);

    poado_qr_pos_save_png($img, $path);
    imagedestroy($img);
}

function poado_qr_pos_generate_preview(array $cfg): array
{
    $certType = (string)$cfg['cert_type'];
    $slug = strtolower($certType);
    $templateFile = (string)$cfg['file'];
    $templatePath = POADO_QR_POS_TEMPLATE_ROOT . '/' . $templateFile;

    $qrX = (int)$cfg['qr']['x'];
    $qrY = (int)$cfg['qr']['y'];
    $qrSize = (int)$cfg['qr']['size'];

    $certUid = poado_qr_pos_test_uid((string)$cfg['cert_type'], (string)$cfg['rwa_key']);

    $outDir = POADO_QR_POS_TMP_DIR . '/' . $slug;
    $qrDir = $outDir . '/qr';

    poado_qr_pos_ensure_dir($outDir);
    poado_qr_pos_ensure_dir($qrDir);

    $previewPath = $outDir . '/preview.png';
    $qrPath = $qrDir . '/verify-qr.png';

    poado_qr_pos_make_test_qr_png($qrPath, $qrSize, $certUid);

    $template = poado_qr_pos_load_png($templatePath);
    $qrImg = poado_qr_pos_load_png($qrPath);

    $tw = imagesx($template);
    $th = imagesy($template);

    if ($qrX < 0 || $qrY < 0 || ($qrX + $qrSize) > $tw || ($qrY + $qrSize) > $th) {
        imagedestroy($template);
        imagedestroy($qrImg);
        throw new RuntimeException('QR placement exceeds template bounds for ' . $certType);
    }

    imagecopyresampled(
        $template,
        $qrImg,
        $qrX,
        $qrY,
        0,
        0,
        $qrSize,
        $qrSize,
        imagesx($qrImg),
        imagesy($qrImg)
    );

    $yellow = imagecolorallocate($template, 255, 235, 59);
    imagerectangle($template, $qrX, $qrY, $qrX + $qrSize - 1, $qrY + $qrSize - 1, $yellow);
    imagerectangle($template, $qrX + 1, $qrY + 1, $qrX + $qrSize - 2, $qrY + $qrSize - 2, $yellow);

    poado_qr_pos_save_png($template, $previewPath);

    imagedestroy($template);
    imagedestroy($qrImg);

    return [
        'cert_type'     => $certType,
        'label'         => (string)$cfg['label'],
        'rwa_key'       => (string)$cfg['rwa_key'],
        'rwa_code'      => (string)$cfg['rwa_code'],
        'family'        => (string)$cfg['family'],
        'template_file' => $templateFile,
        'template_path' => $templatePath,
        'cert_uid'      => $certUid,
        'verify_url'    => poado_cert_verify_url($certUid),
        'preview_path'  => $previewPath,
        'preview_url'   => POADO_QR_POS_TMP_URL . '/' . $slug . '/preview.png',
        'qr_path'       => $qrPath,
        'qr_url'        => POADO_QR_POS_TMP_URL . '/' . $slug . '/qr/verify-qr.png',
        'qr' => [
            'x' => $qrX,
            'y' => $qrY,
            'size' => $qrSize,
        ],
    ];
}

function poado_qr_pos_generate_all(): array
{
    poado_qr_pos_require_gd();
    poado_qr_pos_reset_tmp();

    $results = [];
    foreach (poado_cert_meta_image_map() as $cfg) {
        $results[] = poado_qr_pos_generate_preview($cfg);
    }

    return $results;
}

$error = '';
$results = [];

try {
    $results = poado_qr_pos_generate_all();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$footerText = '© 2026 Blockchain Group RWA FZCO (DMCC, Dubai, UAE) · RWA Standard Organisation (RSO). All rights reserved.';
$cacheBust = (string)time();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<title>RWA NFT Template QR Positioner</title>
<style>
:root{
    --bg:#07090f;
    --bg2:#0f1724;
    --panel:#131b29;
    --line:rgba(214,177,90,.26);
    --line2:rgba(102,199,255,.18);
    --text:#f4efe2;
    --muted:#99a5b3;
    --gold:#d6b15a;
    --blue:#66c7ff;
    --shadow:0 10px 30px rgba(0,0,0,.35);
    --radius:18px;
}
*{box-sizing:border-box}
html,body{
    margin:0;
    background:
        radial-gradient(circle at top right, rgba(214,177,90,.08), transparent 30%),
        radial-gradient(circle at top left, rgba(102,199,255,.08), transparent 28%),
        linear-gradient(180deg,var(--bg),var(--bg2));
    color:var(--text);
    font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
}
a{color:var(--blue);text-decoration:none}
a:hover{text-decoration:underline}
.page{max-width:1440px;margin:0 auto;padding:18px 12px 40px}
.topbar{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px}
.brand{color:var(--gold);font-size:14px;letter-spacing:.08em}
.nav-link{display:inline-flex;align-items:center;gap:8px;min-height:42px;padding:10px 14px;border:1px solid var(--line);border-radius:999px;color:var(--text);background:rgba(255,255,255,.03)}
.hero{border:1px solid var(--line);border-radius:24px;background:linear-gradient(180deg,rgba(255,255,255,.03),rgba(255,255,255,.015));box-shadow:var(--shadow);padding:18px;margin-bottom:16px}
.hero h1{margin:0 0 8px;font-size:22px;line-height:1.25}
.hero p{margin:0;color:var(--muted);font-size:13px;line-height:1.7}
.code{display:inline-block;padding:7px 10px;border-radius:10px;border:1px solid rgba(255,255,255,.06);background:rgba(255,255,255,.03)}
.error-box{border:1px solid rgba(255,107,107,.35);background:rgba(255,107,107,.08);color:#ffd8d8;border-radius:18px;padding:18px;font-size:14px}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.card{border:1px solid var(--line);border-radius:var(--radius);background:rgba(10,16,28,.84);box-shadow:var(--shadow);overflow:hidden}
.card-head{padding:14px 16px;border-bottom:1px solid rgba(255,255,255,.06);display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
.card-title{display:flex;flex-direction:column;gap:5px}
.card-title strong{font-size:16px;color:var(--text)}
.card-title span{font-size:12px;color:var(--muted)}
.card-body{padding:16px}
.preview-wrap{border:1px solid var(--line2);border-radius:14px;overflow:hidden;background:rgba(255,255,255,.02);margin-bottom:14px}
.preview-wrap img{display:block;width:100%;height:auto}
.meta{display:grid;grid-template-columns:150px 1fr;gap:10px 12px}
.meta .k{color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.06em}
.meta .v{color:var(--text);font-size:13px;word-break:break-word}
.actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px}
.btn{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:10px 14px;border-radius:12px;border:1px solid var(--line);background:rgba(255,255,255,.03);color:var(--text);font-size:13px}
.footer{margin-top:24px;text-align:center;color:#8d98a6;font-size:12px;line-height:1.6}
@media (max-width:980px){.grid{grid-template-columns:1fr}}
@media (max-width:640px){.page{padding:14px 10px 28px}.hero{padding:16px}.hero h1{font-size:18px}.meta{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="page">
    <div class="topbar">
        <div class="brand">ADOPT.GOLD · NFT TEMPLATE QR POSITIONER</div>
        <a class="nav-link" href="/rwa/cert/index.php">← Back to Cert Dashboard</a>
    </div>

    <section class="hero">
        <h1>Final test for all QR positions</h1>
        <p>
            This page regenerates all 8 locked NFT template previews using the current final QR coordinates from
            <span class="code">/var/www/html/public/rwa/cert/api/_meta-image-map.php</span>.
            Generated files are written to <span class="code">/var/www/html/public/rwa/cert/tmp/qr-positioner/</span>.
        </p>
    </section>

    <?php if ($error !== ''): ?>
        <div class="error-box">
            <strong>Generation failed.</strong><br>
            <?= h($error) ?>
        </div>
    <?php else: ?>
        <div class="grid">
            <?php foreach ($results as $item): ?>
                <section class="card">
                    <div class="card-head">
                        <div class="card-title">
                            <strong><?= h($item['label']) ?></strong>
                            <span><?= h($item['cert_type']) ?> · <?= h($item['rwa_key']) ?></span>
                        </div>
                        <div class="code"><?= h($item['template_file']) ?></div>
                    </div>
                    <div class="card-body">
                        <div class="preview-wrap">
                            <img src="<?= h($item['preview_url']) ?>?v=<?= h($cacheBust) ?>" alt="<?= h($item['label']) ?>">
                        </div>

                        <div class="meta">
                            <div class="k">Cert Type</div>
                            <div class="v"><?= h($item['cert_type']) ?></div>

                            <div class="k">RWA Key</div>
                            <div class="v"><?= h($item['rwa_key']) ?></div>

                            <div class="k">RWA Code</div>
                            <div class="v"><?= h($item['rwa_code']) ?></div>

                            <div class="k">Family</div>
                            <div class="v"><?= h($item['family']) ?></div>

                            <div class="k">Test Cert UID</div>
                            <div class="v"><span class="code"><?= h($item['cert_uid']) ?></span></div>

                            <div class="k">QR X</div>
                            <div class="v"><?= h($item['qr']['x']) ?></div>

                            <div class="k">QR Y</div>
                            <div class="v"><?= h($item['qr']['y']) ?></div>

                            <div class="k">QR Size</div>
                            <div class="v"><?= h($item['qr']['size']) ?></div>

                            <div class="k">Verify URL</div>
                            <div class="v"><span class="code"><?= h($item['verify_url']) ?></span></div>

                            <div class="k">Preview File</div>
                            <div class="v"><span class="code"><?= h($item['preview_path']) ?></span></div>

                            <div class="k">QR File</div>
                            <div class="v"><span class="code"><?= h($item['qr_path']) ?></span></div>
                        </div>

                        <div class="actions">
                            <a class="btn" href="<?= h($item['preview_url']) ?>?v=<?= h($cacheBust) ?>" target="_blank" rel="noopener">Open Preview PNG</a>
                            <a class="btn" href="<?= h($item['qr_url']) ?>?v=<?= h($cacheBust) ?>" target="_blank" rel="noopener">Open QR PNG</a>
                        </div>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="footer"><?= h($footerText) ?></div>
</div>
</body>
</html>
