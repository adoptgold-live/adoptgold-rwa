<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/test-bundle.php
 * Bundle tester for 8 RWA types
 *
 * Purpose:
 * - test template + verify URL QR composition
 * - output 8 preview cards in one page
 * - provide debug mode
 *
 * Usage:
 *   /rwa/cert/test-bundle.php
 *   /rwa/cert/test-bundle.php?debug=1
 *   /rwa/cert/test-bundle.php?render=1&type=green
 *   /rwa/cert/test-bundle.php?render=1&type=gold&debug=1
 */

ini_set('display_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function tester_site_url(): string {
    return 'https://adoptgold.app';
}

function tester_public_root(): string {
    return '/var/www/html/public';
}

function tester_tmp_root(): string {
    $dir = tester_public_root() . '/rwa/cert/tmp/bundle-tester';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function tester_cert_uid(string $code): string {
    return $code . '-' . gmdate('Ymd') . '-TEST0001';
}

function tester_verify_url(string $uid): string {
    return tester_site_url() . '/rwa/cert/verify.php?uid=' . rawurlencode($uid);
}

function tester_types(): array {
    return [
        'green' => [
            'label' => 'Green',
            'rwa_code' => 'RCO2C-EMA',
            'template_candidates' => [
                '/var/www/html/public/rwa/cert/assets/templates/rco2c.png',
                '/var/www/html/public/rwa/cert/assets/nft/rco2c.png',
                '/var/www/html/public/rwa/cert/assets/img/rco2c.png',
                '/var/www/html/public/rwa/cert/assets/templates/green.png',
            ],
            'slot' => ['x' => 419, 'y' => 557, 'size' => 200],
        ],
        'blue' => [
            'label' => 'Blue',
            'rwa_code' => 'RH2O-EMA',
            'template_candidates' => [
                '/var/www/html/public/rwa/cert/assets/templates/rh2o.png',
                '/var/www/html/public/rwa/cert/assets/nft/rh2o.png',
                '/var/www/html/public/rwa/cert/assets/img/rh2o.png',
                '/var/www/html/public/rwa/cert/assets/templates/blue.png',
            ],
            'slot' => ['x' => 419, 'y' => 557, 'size' => 200],
        ],
        'black' => [
            'label' => 'Black',
            'rwa_code' => 'RBLACK-EMA',
            'template_candidates' => [
                '/var/www/html/public/rwa/cert/assets/templates/rblack.png',
                '/var/www/html/public/rwa/cert/assets/nft/rblack.png',
                '/var/www/html/public/rwa/cert/assets/img/rblack.png',
                '/var/www/html/public/rwa/cert/assets/templates/black.png',
            ],
            'slot' => ['x' => 419, 'y' => 557, 'size' => 200],
        ],
        'gold' => [
            'label' => 'Gold',
            'rwa_code' => 'RK92-EMA',
            'template_candidates' => [
                '/var/www/html/public/rwa/cert/assets/templates/rk92.png',
                '/var/www/html/public/rwa/cert/assets/nft/rk92.png',
                '/var/www/html/public/rwa/cert/assets/img/rk92.png',
                '/var/www/html/public/rwa/cert/assets/templates/gold.png',
            ],
            'slot' => ['x' => 419, 'y' => 557, 'size' => 200],
        ],
        'pink' => [
            'label' => 'Health',
            'rwa_code' => 'RLIFE-EMA',
            'template_candidates' => [
                '/var/www/html/public/rwa/cert/assets/templates/rlife.png',
                '/var/www/html/public/rwa/cert/assets/nft/rlife.png',
                '/var/www/html/public/rwa/cert/assets/img/rlife.png',
                '/var/www/html/public/rwa/cert/assets/templates/pink.png',
            ],
            'slot' => ['x' => 419, 'y' => 557, 'size' => 200],
        ],
        'red' => [
            'label' => 'Travel',
            'rwa_code' => 'RTRIP-EMA',
            'template_candidates' => [
                '/var/www/html/public/rwa/cert/assets/templates/rtrip.png',
                '/var/www/html/public/rwa/cert/assets/nft/rtrip.png',
                '/var/www/html/public/rwa/cert/assets/img/rtrip.png',
                '/var/www/html/public/rwa/cert/assets/templates/red.png',
            ],
            'slot' => ['x' => 419, 'y' => 557, 'size' => 200],
        ],
        'royal_blue' => [
            'label' => 'Property',
            'rwa_code' => 'RPROP-EMA',
            'template_candidates' => [
                '/var/www/html/public/rwa/cert/assets/templates/rprop.png',
                '/var/www/html/public/rwa/cert/assets/nft/rprop.png',
                '/var/www/html/public/rwa/cert/assets/img/rprop.png',
                '/var/www/html/public/rwa/cert/assets/templates/royal_blue.png',
            ],
            'slot' => ['x' => 419, 'y' => 557, 'size' => 200],
        ],
        'yellow' => [
            'label' => 'Human Resources',
            'rwa_code' => 'RHRD-EMA',
            'template_candidates' => [
                '/var/www/html/public/rwa/cert/assets/templates/rhrd.png',
                '/var/www/html/public/rwa/cert/assets/nft/rhrd.png',
                '/var/www/html/public/rwa/cert/assets/img/rhrd.png',
                '/var/www/html/public/rwa/cert/assets/templates/yellow.png',
            ],
            'slot' => ['x' => 419, 'y' => 557, 'size' => 200],
        ],
    ];
}

function tester_find_template(array $cfg): array {
    require_once '/var/www/html/public/rwa/cert/api/_meta-image-map.php';
    require_once '/var/www/html/public/rwa/cert/api/_qr-map-resolver.php';

    $map = cert_v2_meta_image_map();
    $key = cert_v2_normalize_rwa_layout_key((string)$cfg['rwa_code']);

    if (!isset($map[$key]['file'])) {
        throw new RuntimeException('TEMPLATE_MAP_FILE_MISSING_FOR_KEY: ' . $key);
    }

    $file = (string)$map[$key]['file'];
    $path = '/var/www/html/public/rwa/metadata/nft/' . $file;

    if (!is_file($path)) {
        throw new RuntimeException('TEMPLATE_FILE_NOT_FOUND: ' . $path);
    }

    return ['path' => $path, 'source' => 'meta_image_map'];
}

function tester_png_data_url(string $file): string {
    if (!is_file($file)) {
        return '';
    }
    return 'data:image/png;base64,' . base64_encode((string)file_get_contents($file));
}

function tester_svg_data_url(string $file): string {
    if (!is_file($file)) {
        return '';
    }
    return 'data:image/svg+xml;base64,' . base64_encode((string)file_get_contents($file));
}

function tester_find_convert(): string {
    foreach (['/usr/bin/convert', '/usr/local/bin/convert', trim((string)shell_exec('command -v convert 2>/dev/null'))] as $p) {
        if ($p !== '' && is_file($p)) {
            return $p;
        }
    }
    return 'convert';
}

function tester_svg_to_png(string $svgPath, string $pngPath): array {
    $convert = tester_find_convert();
    $cmd = sprintf(
        '%s %s %s 2>&1',
        escapeshellarg($convert),
        escapeshellarg($svgPath),
        escapeshellarg($pngPath)
    );
    exec($cmd, $out, $code);
    return ['ok' => $code === 0 && is_file($pngPath), 'cmd' => $cmd, 'out' => $out, 'code' => $code];
}

function tester_build_qr_png(string $verifyUrl, string $outPng): array {
    $tmpSvg = $outPng . '.svg';

    if (!class_exists(\Endroid\QrCode\Builder\Builder::class)) {
        $autoloads = [
            '/var/www/html/public/rwa/vendor/autoload.php',
            '/var/www/html/public/vendor/autoload.php',
            '/var/www/html/vendor/autoload.php',
        ];
        foreach ($autoloads as $a) {
            if (is_file($a)) {
                require_once $a;
                break;
            }
        }
    }

    if (class_exists(\Endroid\QrCode\Builder\Builder::class) && class_exists(\Endroid\QrCode\Writer\PngWriter::class)) {
        try {
            $result = \Endroid\QrCode\Builder\Builder::create()
                ->writer(new \Endroid\QrCode\Writer\PngWriter())
                ->data($verifyUrl)
                ->size(400)
                ->margin(10)
                ->build();
            file_put_contents($outPng, $result->getString());
            return ['ok' => is_file($outPng), 'engine' => 'endroid_png'];
        } catch (\Throwable $e) {
            // continue to fallback
        }
    }

    $safe = h($verifyUrl);
    $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="400" height="400">
  <rect width="100%" height="100%" fill="#fff"/>
  <rect x="10" y="10" width="380" height="380" fill="none" stroke="#000" stroke-width="8"/>
  <text x="24" y="200" fill="#000" font-size="16" font-family="Arial">QR PLACEHOLDER</text>
  <text x="24" y="225" fill="#000" font-size="10" font-family="Arial">{$safe}</text>
</svg>
SVG;
    file_put_contents($tmpSvg, $svg);
    $conv = tester_svg_to_png($tmpSvg, $outPng);
    return ['ok' => $conv['ok'], 'engine' => 'svg_fallback', 'convert' => $conv];
}

function tester_compose(string $templatePath, string $qrPngPath, string $outPngPath, array $slot): array {
    $convert = tester_find_convert();
    $cmd = sprintf(
        '%s %s %s -geometry %dx%d+%d+%d -composite %s 2>&1',
        escapeshellarg($convert),
        escapeshellarg($templatePath),
        escapeshellarg($qrPngPath),
        (int)$slot['size'],
        (int)$slot['size'],
        (int)$slot['x'],
        (int)$slot['y'],
        escapeshellarg($outPngPath)
    );
    exec($cmd, $out, $code);
    return [
        'ok' => $code === 0 && is_file($outPngPath),
        'cmd' => $cmd,
        'out' => $out,
        'code' => $code,
    ];
}

function tester_run_one(string $typeKey, array $cfg, bool $debug): array {
    $uid = tester_cert_uid($cfg['rwa_code']);
    $verifyUrl = tester_verify_url($uid);
    $tmpBase = tester_tmp_root() . '/' . $typeKey;
    if (!is_dir($tmpBase)) {
        @mkdir($tmpBase, 0755, true);
    }

    $template = tester_find_template($cfg);
    $qrPng = $tmpBase . '/verify-qr.png';
    $outPng = $tmpBase . '/bundle-output.png';

    $qr = tester_build_qr_png($verifyUrl, $qrPng);
    $compose = ['ok' => false, 'cmd' => '', 'out' => [], 'code' => 999];

    if ($qr['ok'] && is_file($template['path'])) {

        require_once __DIR__ . '/api/_qr-map-resolver.php';
        $slot = cert_v2_resolve_qr_layout($cfg['rwa_code']);
        $compose = tester_compose($template['path'], $qrPng, $outPng, $slot);

    }

    return [
        'type' => $typeKey,
        'label' => $cfg['label'],
        'rwa_code' => $cfg['rwa_code'],
        'uid' => $uid,
        'verify_url' => $verifyUrl,
        'slot' => $slot,
        'template_source' => $template['source'],
        'template_path' => $template['path'],
        'template_preview' => tester_png_data_url($template['path']),
        'qr_preview' => tester_png_data_url($qrPng),
        'output_preview' => tester_png_data_url($outPng),
        'output_file' => $outPng,
        'ok' => $qr['ok'] && $compose['ok'],
        'debug' => $debug ? [
            'qr' => $qr,
            'compose' => $compose,
        ] : null,
    ];
}

$types = tester_types();

if (isset($_GET['render']) && $_GET['render'] === '1') {
    $type = (string)($_GET['type'] ?? '');
    if (!isset($types[$type])) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'TYPE_NOT_FOUND'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(tester_run_one($type, $types[$type], $DEBUG), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

$results = [];
foreach ($types as $typeKey => $cfg) {
    $results[] = tester_run_one($typeKey, $cfg, $DEBUG);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>RWA Bundle Tester</title>
<style>
body{margin:0;background:#070708;color:#f3f3f3;font-family:Arial,sans-serif}
.wrap{max-width:1500px;margin:0 auto;padding:20px}
h1{margin:0 0 8px}
.sub{opacity:.8;margin-bottom:18px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:16px}
.card{background:#111317;border:1px solid rgba(212,175,55,.2);border-radius:16px;padding:14px}
.top{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:10px}
.badge{padding:6px 10px;border-radius:999px;background:#1f232b;font-size:12px}
.badge.ok{background:#10381f;color:#97f5b5}
.badge.bad{background:#4a1717;color:#ffb4b4}
.preview{display:grid;grid-template-columns:1fr;gap:10px}
.preview img{width:100%;height:auto;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:#000}
.small{font-size:12px;opacity:.85;word-break:break-all}
code,pre{white-space:pre-wrap;word-break:break-word;background:#0b0d11;border-radius:10px;padding:10px;border:1px solid rgba(255,255,255,.08)}
.tools{display:flex;gap:10px;flex-wrap:wrap;margin:0 0 16px}
a.btn{display:inline-block;padding:10px 14px;border-radius:10px;background:#1b2027;color:#fff;text-decoration:none;border:1px solid rgba(255,255,255,.12)}
.cols{display:grid;grid-template-columns:1fr 1fr;gap:10px}
</style>
</head>
<body>
<div class="wrap">
  <h1>RWA Bundle Tester</h1>
  <div class="sub">8-type template + sample verify URL QR compositor. Add <code>?debug=1</code> for full command output.</div>

  <div class="tools">
    <a class="btn" href="/rwa/cert/test-bundle.php">Normal</a>
    <a class="btn" href="/rwa/cert/test-bundle.php?debug=1">Debug Mode</a>
  </div>

  <div class="grid">
    <?php foreach ($results as $r): ?>
      <div class="card">
        <div class="top">
          <div>
            <strong><?= h($r['label']) ?></strong>
            <div class="small"><?= h($r['rwa_code']) ?></div>
          </div>
          <div class="badge <?= $r['ok'] ? 'ok' : 'bad' ?>"><?= $r['ok'] ? 'OK' : 'FAIL' ?></div>
        </div>

        <div class="small"><strong>UID:</strong> <?= h($r['uid']) ?></div>
        <div class="small"><strong>Verify URL:</strong> <?= h($r['verify_url']) ?></div>
        <div class="small"><strong>Template Source:</strong> <?= h($r['template_source']) ?></div>
        <div class="small"><strong>Slot:</strong> x=<?= h((string)$r['slot']['x']) ?> y=<?= h((string)$r['slot']['y']) ?> size=<?= h((string)$r['slot']['size']) ?></div>

        <div class="preview" style="margin-top:10px">
          <div class="cols">
            <div>
              <div class="small"><strong>Template</strong></div>
              <?php if ($r['template_preview'] !== ''): ?>
                <img src="<?= h($r['template_preview']) ?>" alt="template">
              <?php else: ?>
                <code>Template preview missing</code>
              <?php endif; ?>
            </div>
            <div>
              <div class="small"><strong>QR</strong></div>
              <?php if ($r['qr_preview'] !== ''): ?>
                <img src="<?= h($r['qr_preview']) ?>" alt="qr">
              <?php else: ?>
                <code>QR preview missing</code>
              <?php endif; ?>
            </div>
          </div>

          <div>
            <div class="small"><strong>Output</strong></div>
            <?php if ($r['output_preview'] !== ''): ?>
              <img src="<?= h($r['output_preview']) ?>" alt="output">
            <?php else: ?>
              <code>Output preview missing</code>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($DEBUG && is_array($r['debug'])): ?>
          <div class="small" style="margin-top:10px"><strong>Debug</strong></div>
          <pre><?= h(json_encode($r['debug'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
</body>
</html>
