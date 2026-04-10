<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/tester/metadata-tester.php
 * Version: v2.0.0-20260331-getgems-metadata-debug
 *
 * Purpose:
 * - show exact metadata URL intended for mint
 * - fetch metadata JSON live
 * - show exact image URL inside metadata
 * - check JSON validity for marketplace usage
 * - preview image live
 * - highlight mismatch / missing fields causing "Metadata Unavailable"
 *
 * Read-only tester:
 * - no DB write
 * - no mint
 * - no finalize
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';

function h(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function mt_pdo(): PDO
{
    if (function_exists('rwa_db')) {
        $pdo = rwa_db();
        if ($pdo instanceof PDO) {
            return $pdo;
        }
    }
    if (($GLOBALS['pdo'] ?? null) instanceof PDO) {
        return $GLOBALS['pdo'];
    }
    throw new RuntimeException('PDO_NOT_AVAILABLE');
}

function mt_env(string $key, string $default = ''): string
{
    $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function mt_json_decode(?string $json): array
{
    $json = trim((string)$json);
    if ($json === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function mt_base_url(): string
{
    $base = trim(mt_env('APP_BASE_URL', ''));
    if ($base !== '') {
        return rtrim($base, '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'adoptgold.app');
    return $https . '://' . $host;
}

function mt_fetch_cert(PDO $pdo, string $uid): ?array
{
    $st = $pdo->prepare("
        SELECT *
        FROM poado_rwa_certs
        WHERE cert_uid = :uid
        LIMIT 1
    ");
    $st->execute([':uid' => $uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function mt_pick_metadata(array $row, array $meta): array
{
    $mint = is_array($meta['mint'] ?? null) ? $meta['mint'] : [];
    $vault = is_array($meta['vault'] ?? null) ? $meta['vault'] : [];

    $metadataPath = '';
    foreach ([
        $mint['metadata_path'] ?? null,
        $vault['metadata_rel'] ?? null,
        $vault['metadata_path'] ?? null,
        $row['metadata_path'] ?? null,
    ] as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate !== '') {
            $metadataPath = ltrim($candidate, '/');
            break;
        }
    }

    $metadataUrl = '';
    foreach ([
        $mint['metadata_url'] ?? null,
        $vault['metadata'] ?? null,
    ] as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate !== '') {
            $metadataUrl = $candidate;
            break;
        }
    }

    if ($metadataUrl === '' && $metadataPath !== '') {
        $metadataUrl = mt_base_url() . '/' . $metadataPath;
    }

    return [
        'metadata_path' => $metadataPath,
        'metadata_url'  => $metadataUrl,
    ];
}

function mt_http_fetch(string $url): array
{
    if ($url === '') {
        return [
            'ok' => false,
            'http_code' => 0,
            'body' => '',
            'headers' => [],
            'error' => 'EMPTY_URL',
        ];
    }

    $headers = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$headers) {
            $len = strlen($line);
            $line = trim($line);
            if ($line !== '' && str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[trim($k)] = trim($v);
            }
            return $len;
        },
        CURLOPT_HTTPHEADER => [
            'Accept: application/json, image/*, */*',
            'User-Agent: AdoptGold-Metadata-Tester/2.0',
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($ctype !== '' && !isset($headers['Content-Type'])) {
        $headers['Content-Type'] = $ctype;
    }

    return [
        'ok' => $errno === 0 && is_string($body),
        'http_code' => $http,
        'body' => is_string($body) ? $body : '',
        'headers' => $headers,
        'error' => $errno !== 0 ? ('CURL_' . $errno . ': ' . $error) : '',
    ];
}

function mt_pick_image(array $metadata, array $meta): array
{
    $image = '';
    foreach ([
        $metadata['image'] ?? null,
        $metadata['image_url'] ?? null,
        $meta['vault']['image'] ?? null,
        $meta['verify_json']['image_url'] ?? null,
        $meta['verify']['image_url'] ?? null,
    ] as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate !== '') {
            $image = $candidate;
            break;
        }
    }

    $name = trim((string)($metadata['name'] ?? $metadata['title'] ?? ''));
    $description = trim((string)($metadata['description'] ?? ''));

    return [
        'image' => $image,
        'name' => $name,
        'description' => $description,
    ];
}

function mt_status_badge(bool $ok, string $yes = 'YES', string $no = 'NO'): string
{
    $cls = $ok ? 'status-ok' : 'status-bad';
    $txt = $ok ? $yes : $no;
    return '<span class="' . $cls . '">' . h($txt) . '</span>';
}

$uid = trim((string)($_GET['uid'] ?? $_POST['uid'] ?? ''));
$error = '';
$cert = null;
$meta = [];
$mint = [];
$metadataRef = ['metadata_path' => '', 'metadata_url' => ''];
$metadataHttp = ['ok' => false, 'http_code' => 0, 'body' => '', 'headers' => [], 'error' => ''];
$metadataJson = [];
$imageInfo = ['image' => '', 'name' => '', 'description' => ''];
$imageHttp = ['ok' => false, 'http_code' => 0, 'body' => '', 'headers' => [], 'error' => ''];
$checks = [
    'metadata_url_present' => false,
    'metadata_http_ok' => false,
    'metadata_json_valid' => false,
    'name_present' => false,
    'image_present' => false,
    'image_http_ok' => false,
    'ready_for_getgems' => false,
];

try {
    if ($uid !== '') {
        $pdo = mt_pdo();
        $cert = mt_fetch_cert($pdo, $uid);
        if (!$cert) {
            $error = 'CERT_NOT_FOUND';
        } else {
            $meta = mt_json_decode((string)($cert['meta_json'] ?? ''));
            $mint = is_array($meta['mint'] ?? null) ? $meta['mint'] : [];
            $metadataRef = mt_pick_metadata($cert, $meta);

            $checks['metadata_url_present'] = $metadataRef['metadata_url'] !== '';

            if ($metadataRef['metadata_url'] !== '') {
                $metadataHttp = mt_http_fetch($metadataRef['metadata_url']);
                $checks['metadata_http_ok'] = $metadataHttp['http_code'] >= 200 && $metadataHttp['http_code'] < 300;

                if ($checks['metadata_http_ok']) {
                    $decoded = json_decode((string)$metadataHttp['body'], true);
                    if (is_array($decoded)) {
                        $metadataJson = $decoded;
                        $checks['metadata_json_valid'] = true;
                    }
                }
            }

            $imageInfo = mt_pick_image($metadataJson, $meta);
            $checks['name_present'] = trim((string)$imageInfo['name']) !== '';
            $checks['image_present'] = trim((string)$imageInfo['image']) !== '';

            if ($checks['image_present']) {
                $imageHttp = mt_http_fetch($imageInfo['image']);
                $checks['image_http_ok'] = $imageHttp['http_code'] >= 200 && $imageHttp['http_code'] < 300;
            }

            $checks['ready_for_getgems'] =
                $checks['metadata_url_present']
                && $checks['metadata_http_ok']
                && $checks['metadata_json_valid']
                && $checks['name_present']
                && $checks['image_present']
                && $checks['image_http_ok'];
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Metadata Mint Image Tester</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#080a10">
  <style>
    :root{
      --bg:#070a10;
      --panel:#101725;
      --line:rgba(255,255,255,.10);
      --text:#f5f7fb;
      --muted:#99a3b3;
      --gold:#e8c98f;
      --ok:#8ce3b4;
      --warn:#ffd07a;
      --bad:#ff9f9f;
      --mono:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
    }
    *{box-sizing:border-box}
    html,body{margin:0;padding:0;background:linear-gradient(180deg,#05070c,#0b111a 55%,#0d1420);color:var(--text);font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}
    body{padding:18px}
    .wrap{max-width:1240px;margin:0 auto;display:grid;gap:16px}
    .card{
      border:1px solid var(--line);
      border-radius:22px;
      background:linear-gradient(180deg,rgba(255,255,255,.04),rgba(255,255,255,.02)),var(--panel);
      padding:18px;
      box-shadow:0 18px 44px rgba(0,0,0,.28);
    }
    .kicker{color:var(--gold);font-size:11px;font-weight:900;letter-spacing:.16em;text-transform:uppercase;margin-bottom:8px}
    h1,h2,h3{margin:0}
    .sub{margin:8px 0 0;color:var(--muted);line-height:1.6}
    form{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px}
    input{
      min-height:48px;border-radius:14px;border:1px solid var(--line);background:#0b1220;color:var(--text);
      padding:0 14px;font:inherit
    }
    button{
      min-height:48px;border-radius:14px;border:1px solid rgba(232,201,143,.28);
      background:linear-gradient(180deg,rgba(232,201,143,.20),rgba(232,201,143,.08));color:var(--text);
      padding:0 18px;font-weight:800;cursor:pointer
    }
    .grid{display:grid;grid-template-columns:1.1fr .9fr;gap:16px}
    .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
    .meta-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
    .mini{
      border:1px solid var(--line);border-radius:16px;background:rgba(255,255,255,.025);padding:14px
    }
    .mini .label{color:var(--muted);font-size:12px;margin-bottom:8px}
    .mini .value{font-weight:800;word-break:break-word}
    .mono{font-family:var(--mono)}
    .status-ok{color:var(--ok);font-weight:900}
    .status-bad{color:var(--bad);font-weight:900}
    .status-warn{color:var(--warn);font-weight:900}
    .preview{
      border:1px dashed rgba(232,201,143,.25);border-radius:18px;min-height:360px;background:rgba(255,255,255,.02);
      display:flex;align-items:center;justify-content:center;padding:16px
    }
    .preview img{max-width:100%;max-height:560px;display:block;border-radius:16px}
    pre{
      margin:0;white-space:pre-wrap;word-break:break-word;overflow:auto;
      border:1px solid var(--line);border-radius:16px;background:#09111d;padding:14px;color:#d8deea;max-height:440px
    }
    .pill{
      display:inline-flex;align-items:center;min-height:34px;padding:0 12px;border-radius:999px;
      border:1px solid var(--line);background:rgba(255,255,255,.03);font-size:12px;font-weight:800
    }
    .pill.ok{color:#08120d;background:linear-gradient(180deg,#d7ffd1,#74d26a);border-color:#8fe284}
    .pill.bad{color:#220b0b;background:linear-gradient(180deg,#ffd4d4,#ff8e8e);border-color:#ff9f9f}
    .check-list{display:grid;gap:10px;margin-top:12px}
    .check-row{
      display:flex;justify-content:space-between;gap:10px;align-items:center;
      border:1px solid var(--line);border-radius:14px;padding:10px 12px;background:rgba(255,255,255,.02)
    }
    .note{font-size:12px;color:var(--muted);line-height:1.6}
    @media (max-width:940px){
      .grid,.grid-2,.meta-grid,form{grid-template-columns:1fr}
    }
  </style>
</head>
<body>
  <main class="wrap">
    <section class="card">
      <div class="kicker">Tester</div>
      <h1>Metadata Mint Image Tester v2</h1>
      <p class="sub">Enter a cert UID. This tester shows the exact metadata URL intended for mint, fetches the JSON live, checks the image field, and tells you whether the NFT is ready for GetGems.</p>
    </section>

    <section class="card">
      <form method="get" action="">
        <input type="text" name="uid" value="<?= h($uid) ?>" placeholder="Example: RCO2C-EMA-20260329-58C304B8">
        <button type="submit">Check Metadata</button>
      </form>
    </section>

    <?php if ($error !== ''): ?>
      <section class="card">
        <div class="status-bad">ERROR · <?= h($error) ?></div>
      </section>
    <?php endif; ?>

    <?php if ($uid !== '' && $cert): ?>
      <section class="card">
        <div class="kicker">Result</div>
        <h2>GetGems readiness</h2>
        <p style="margin-top:12px">
          <?php if ($checks['ready_for_getgems']): ?>
            <span class="pill ok">READY FOR GETGEMS</span>
          <?php else: ?>
            <span class="pill bad">NOT READY FOR GETGEMS</span>
          <?php endif; ?>
        </p>

        <div class="check-list">
          <div class="check-row"><span>Metadata URL present</span><span><?= mt_status_badge($checks['metadata_url_present']) ?></span></div>
          <div class="check-row"><span>Metadata HTTP 200</span><span><?= mt_status_badge($checks['metadata_http_ok']) ?></span></div>
          <div class="check-row"><span>Metadata JSON valid</span><span><?= mt_status_badge($checks['metadata_json_valid']) ?></span></div>
          <div class="check-row"><span>Name field present</span><span><?= mt_status_badge($checks['name_present']) ?></span></div>
          <div class="check-row"><span>Image field present</span><span><?= mt_status_badge($checks['image_present']) ?></span></div>
          <div class="check-row"><span>Image HTTP 200</span><span><?= mt_status_badge($checks['image_http_ok']) ?></span></div>
        </div>
      </section>

      <section class="grid">
        <section class="card">
          <div class="kicker">Mint Source</div>
          <h2>What will be sent to mint</h2>
          <p class="sub">V10 mint sends the metadata URL into the NFT content cell. Wallets and marketplaces then read the image field from that metadata JSON.</p>

          <div class="meta-grid" style="margin-top:14px">
            <div class="mini">
              <div class="label">Cert UID</div>
              <div class="value mono"><?= h((string)$cert['cert_uid']) ?></div>
            </div>
            <div class="mini">
              <div class="label">DB Status</div>
              <div class="value"><?= h((string)($cert['status'] ?? '-')) ?></div>
            </div>
            <div class="mini">
              <div class="label">Metadata Path</div>
              <div class="value mono"><?= h($metadataRef['metadata_path'] !== '' ? $metadataRef['metadata_path'] : '-') ?></div>
            </div>
            <div class="mini">
              <div class="label">Metadata URL</div>
              <div class="value mono"><?= h($metadataRef['metadata_url'] !== '' ? $metadataRef['metadata_url'] : '-') ?></div>
            </div>
            <div class="mini">
              <div class="label">Mint Query ID</div>
              <div class="value mono"><?= h((string)($mint['query_id'] ?? '-')) ?></div>
            </div>
            <div class="mini">
              <div class="label">Mint Item Index</div>
              <div class="value mono"><?= h((string)($mint['item_index'] ?? '-')) ?></div>
            </div>
          </div>

          <div class="mini" style="margin-top:12px">
            <div class="label">NFT Name</div>
            <div class="value"><?= h($imageInfo['name'] !== '' ? $imageInfo['name'] : '-') ?></div>
          </div>

          <div class="mini" style="margin-top:12px">
            <div class="label">Image URL That Marketplace Will Try To Load</div>
            <div class="value mono"><?= h($imageInfo['image'] !== '' ? $imageInfo['image'] : '-') ?></div>
          </div>

          <div class="mini" style="margin-top:12px">
            <div class="label">Description</div>
            <div class="value"><?= h($imageInfo['description'] !== '' ? $imageInfo['description'] : '-') ?></div>
          </div>

          <p class="note" style="margin-top:14px">
            Rule: the collection does not mint a PNG directly. It mints an NFT item whose content points to the metadata URL. The final visible image comes from the <span class="mono">image</span> field inside that metadata JSON.
          </p>
        </section>

        <section class="card">
          <div class="kicker">Preview</div>
          <h2>Mint image preview</h2>
          <div class="preview" style="margin-top:14px">
            <?php if ($imageInfo['image'] !== '' && $checks['image_http_ok']): ?>
              <img src="<?= h($imageInfo['image']) ?>" alt="NFT image preview">
            <?php elseif ($imageInfo['image'] !== ''): ?>
              <div class="status-bad">Image URL found but not reachable.</div>
            <?php else: ?>
              <div class="status-bad">No image field found in metadata.</div>
            <?php endif; ?>
          </div>
        </section>
      </section>

      <section class="grid-2">
        <section class="card">
          <div class="kicker">Metadata HTTP</div>
          <h3>Live metadata fetch</h3>
          <div class="meta-grid" style="margin-top:14px">
            <div class="mini">
              <div class="label">HTTP Code</div>
              <div class="value"><?= h((string)$metadataHttp['http_code']) ?></div>
            </div>
            <div class="mini">
              <div class="label">Content-Type</div>
              <div class="value"><?= h((string)($metadataHttp['headers']['Content-Type'] ?? '-')) ?></div>
            </div>
          </div>
          <div class="mini" style="margin-top:12px">
            <div class="label">Fetch Error</div>
            <div class="value"><?= h((string)($metadataHttp['error'] ?? '-')) ?></div>
          </div>
        </section>

        <section class="card">
          <div class="kicker">Image HTTP</div>
          <h3>Live image fetch</h3>
          <div class="meta-grid" style="margin-top:14px">
            <div class="mini">
              <div class="label">HTTP Code</div>
              <div class="value"><?= h((string)$imageHttp['http_code']) ?></div>
            </div>
            <div class="mini">
              <div class="label">Content-Type</div>
              <div class="value"><?= h((string)($imageHttp['headers']['Content-Type'] ?? '-')) ?></div>
            </div>
          </div>
          <div class="mini" style="margin-top:12px">
            <div class="label">Fetch Error</div>
            <div class="value"><?= h((string)($imageHttp['error'] ?? '-')) ?></div>
          </div>
        </section>
      </section>

      <section class="card">
        <div class="kicker">Metadata JSON</div>
        <h2>Current metadata payload</h2>
        <pre><?= h($metadataJson ? json_encode($metadataJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 'No metadata JSON loaded.') ?></pre>
      </section>

      <section class="card">
        <div class="kicker">Cert Meta</div>
        <h2>Current cert meta_json</h2>
        <pre><?= h($meta ? json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 'No meta_json.') ?></pre>
      </section>
    <?php endif; ?>
  </main>
</body>
</html>
