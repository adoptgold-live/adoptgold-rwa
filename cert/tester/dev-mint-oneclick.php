<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/tester/dev-mint-oneclick.php
 * Version: v1.0.0-20260331-dev-oneclick-v10
 *
 * Dev one-click flow:
 * - requires logged-in user
 * - creates a dev cert row
 * - creates confirmed payment row
 * - creates metadata.json / verify.json / image / pdf placeholders
 * - calls mint-init.php
 * - renders wallet deeplink + QR
 * - polls verify-status.php every 5s
 *
 * Final authority:
 * - verify-status.php only
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-topbar-nav.php')) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-topbar-nav.php';
}

function h(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function app_env(string $key, string $default = ''): string
{
    if (function_exists('poado_env')) {
        $v = poado_env($key, $default);
        return is_string($v) ? trim($v) : $default;
    }
    $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function pdo_db(): PDO
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

function now_iso(): string
{
    return gmdate('c');
}

function base_url(): string
{
    $base = trim(app_env('APP_BASE_URL', 'https://adoptgold.app'));
    return rtrim($base, '/');
}

function json_encode_safe(array $data): string
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('JSON_ENCODE_FAILED');
    }
    return $json;
}

function uuid8(): string
{
    return strtoupper(substr(bin2hex(random_bytes(8)), 0, 8));
}

function payment_ref_for(string $uid): string
{
    return 'PAY-' . strtoupper(str_replace('-', '', $uid)) . '-' . random_int(100000, 999999);
}

function create_dirs(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('MKDIR_FAILED: ' . $path);
    }
}

function write_file(string $path, string $content): void
{
    $dir = dirname($path);
    create_dirs($dir);
    if (file_put_contents($path, $content) === false) {
        throw new RuntimeException('WRITE_FAILED: ' . $path);
    }
}

function maybe_copy_image(string $targetAbs): void
{
    $candidates = [
        $_SERVER['DOCUMENT_ROOT'] . '/rwa/metadata/nft/rco2c.png',
        $_SERVER['DOCUMENT_ROOT'] . '/metadata/ema.png',
        $_SERVER['DOCUMENT_ROOT'] . '/metadata/wems.png',
    ];

    foreach ($candidates as $src) {
        if (is_file($src)) {
            create_dirs(dirname($targetAbs));
            if (!copy($src, $targetAbs)) {
                throw new RuntimeException('COPY_IMAGE_FAILED');
            }
            return;
        }
    }

    $tinyPng = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7Z6mQAAAAASUVORK5CYII='
    );
    if (!is_string($tinyPng)) {
        throw new RuntimeException('PNG_FALLBACK_FAILED');
    }
    write_file($targetAbs, $tinyPng);
}

function write_dummy_pdf(string $path): void
{
    $pdf = "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 300 200] /Contents 4 0 R >> endobj\n4 0 obj << /Length 44 >> stream\nBT /F1 12 Tf 72 120 Td (DEV TEST PDF) Tj ET\nendstream endobj\nxref\n0 5\n0000000000 65535 f \n0000000010 00000 n \n0000000060 00000 n \n0000000117 00000 n \n0000000212 00000 n \ntrailer << /Root 1 0 R /Size 5 >>\nstartxref\n307\n%%EOF\n";
    write_file($path, $pdf);
}

function post_form_json(string $url, array $data): array
{
    $body = http_build_query($data);
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
            'content' => $body,
            'timeout' => 30,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    $decoded = json_decode((string)$raw, true);
    return is_array($decoded) ? $decoded : ['ok' => false, 'error' => 'BAD_JSON', 'raw' => $raw];
}

function create_dev_cert_and_prepare(): array
{
    $pdo = pdo_db();
    $user = function_exists('session_user') ? (session_user() ?: []) : [];
    $ownerUserId = (int)($user['user_id'] ?? $user['id'] ?? 0);
    $tonWallet = trim((string)($user['wallet_address'] ?? $user['wallet'] ?? ''));

    if ($ownerUserId <= 0 || $tonWallet === '') {
        throw new RuntimeException('LOGIN_REQUIRED_WITH_WALLET');
    }

    $uid = 'RCO2C-EMA-' . gmdate('Ymd') . '-' . uuid8();
    $paymentRef = payment_ref_for($uid);
    $base = base_url();

    $rootRel = 'rwa/metadata/cert/devtest/' . $uid;
    $rootAbs = $_SERVER['DOCUMENT_ROOT'] . '/' . $rootRel;

    $metaRel = $rootRel . '/meta/metadata.json';
    $verifyRel = $rootRel . '/verify/verify.json';
    $imageRel = $rootRel . '/nft/image.png';
    $pdfRel = $rootRel . '/cert/certificate.pdf';

    $metaAbs = $_SERVER['DOCUMENT_ROOT'] . '/' . $metaRel;
    $verifyAbs = $_SERVER['DOCUMENT_ROOT'] . '/' . $verifyRel;
    $imageAbs = $_SERVER['DOCUMENT_ROOT'] . '/' . $imageRel;
    $pdfAbs = $_SERVER['DOCUMENT_ROOT'] . '/' . $pdfRel;

    maybe_copy_image($imageAbs);
    write_dummy_pdf($pdfAbs);

    $imageUrl = $base . '/' . $imageRel;
    $verifyUrl = $base . '/rwa/cert/verify.php?uid=' . rawurlencode($uid);
    $metaUrl = $base . '/' . $metaRel;
    $verifyJsonUrl = $base . '/' . $verifyRel;

    $metadata = [
        'name' => 'GREEN RWA CERT #' . $uid,
        'description' => 'GREEN RWA CERT · DEV ONE-CLICK TEST · ' . $uid,
        'image' => $imageUrl,
        'external_url' => $verifyUrl,
        'attributes' => [
            ['trait_type' => 'Cert UID', 'value' => $uid],
            ['trait_type' => 'RWA Code', 'value' => 'RCO2C-EMA'],
            ['trait_type' => 'Type', 'value' => 'GREEN'],
            ['trait_type' => 'Owner Wallet', 'value' => $tonWallet],
            ['trait_type' => 'Issuer', 'value' => 'Blockchain Group RWA FZCO (DMCC, Dubai, UAE)'],
            ['trait_type' => 'Mode', 'value' => 'DEV_ONECLICK'],
        ],
    ];
    write_file($metaAbs, json_encode_safe($metadata));

    $verifyJson = [
        'ok' => true,
        'healthy' => true,
        'used_fallback_placeholder' => false,
        'image_url' => $imageUrl,
        'verify_url' => $verifyUrl,
        'metadata_url' => $metaUrl,
        'checked_at' => now_iso(),
    ];
    write_file($verifyAbs, json_encode_safe($verifyJson));

    $metaJson = [
        'vault' => [
            'metadata' => $metaUrl,
            'metadata_rel' => $metaRel,
            'image' => $imageUrl,
            'qr' => $verifyJsonUrl,
            'verify_json' => $verifyJsonUrl,
            'verify' => $verifyUrl,
        ],
        'nft_health' => [
            'checked_at' => now_iso(),
            'ok' => true,
            'trust_mode' => 'dev_oneclick_seed',
        ],
        'mint' => [
            'metadata_path' => $metaRel,
            'metadata_url' => $metaUrl,
            'verify_url' => $verifyUrl,
            'artifact_health' => [
                'ok' => true,
                'metadata_url' => $metaUrl,
                'image_url' => $imageUrl,
                'verify_json_url' => $verifyJsonUrl,
                'verify_url' => $verifyUrl,
            ],
        ],
        'lifecycle' => [
            'current' => 'issued',
            'history' => [
                [
                    'ts' => now_iso(),
                    'event' => 'dev_oneclick_seed_created',
                    'cert_uid' => $uid,
                ]
            ],
        ],
    ];

    $pdo->beginTransaction();

    $st = $pdo->prepare("
        INSERT INTO poado_rwa_certs (
          cert_uid,
          rwa_type,
          family,
          rwa_code,
          price_wems,
          price_units,
          payment_ref,
          payment_token,
          payment_amount,
          fingerprint_hash,
          owner_user_id,
          ton_wallet,
          pdf_path,
          nft_image_path,
          metadata_path,
          verify_url,
          meta_json,
          status,
          issued_at,
          paid_at,
          nft_minted
        ) VALUES (
          :cert_uid,
          'green',
          'GENESIS',
          'RCO2C-EMA',
          1000,
          '1000',
          :payment_ref,
          'WEMS',
          '1000',
          :fingerprint_hash,
          :owner_user_id,
          :ton_wallet,
          :pdf_path,
          :nft_image_path,
          :metadata_path,
          :verify_url,
          :meta_json,
          'issued',
          NOW(),
          NOW(),
          0
        )
    ");
    $st->execute([
        ':cert_uid' => $uid,
        ':payment_ref' => $paymentRef,
        ':fingerprint_hash' => strtoupper(hash('sha256', $uid . '|' . microtime(true))),
        ':owner_user_id' => $ownerUserId,
        ':ton_wallet' => $tonWallet,
        ':pdf_path' => $base . '/' . $pdfRel,
        ':nft_image_path' => $imageUrl,
        ':metadata_path' => $metaUrl,
        ':verify_url' => $verifyUrl,
        ':meta_json' => json_encode_safe($metaJson),
    ]);

    $st2 = $pdo->prepare("
        INSERT INTO poado_rwa_cert_payments (
          cert_uid,
          payment_ref,
          owner_user_id,
          ton_wallet,
          token_symbol,
          token_master,
          decimals,
          amount,
          amount_units,
          status,
          tx_hash,
          verified,
          paid_at,
          meta_json
        ) VALUES (
          :cert_uid,
          :payment_ref,
          :owner_user_id,
          :ton_wallet,
          'WEMS',
          NULL,
          9,
          1000.00000000,
          '1000000000000',
          'confirmed',
          'DEV_ONECLICK_TX',
          1,
          NOW(),
          :meta_json
        )
    ");
    $st2->execute([
        ':cert_uid' => $uid,
        ':payment_ref' => $paymentRef,
        ':owner_user_id' => $ownerUserId,
        ':ton_wallet' => $tonWallet,
        ':meta_json' => json_encode_safe([
            'payment' => [
                'status' => 'confirmed',
                'verified' => 1,
                'source' => 'dev_oneclick',
            ],
        ]),
    ]);

    $pdo->commit();

    $mintInit = post_form_json($base . '/rwa/cert/api/mint-init.php', [
        'cert_uid' => $uid,
    ]);

    return [
        'cert_uid' => $uid,
        'payment_ref' => $paymentRef,
        'metadata_url' => $metaUrl,
        'image_url' => $imageUrl,
        'verify_url' => $verifyUrl,
        'verify_json_url' => $verifyJsonUrl,
        'mint_init' => $mintInit,
    ];
}

function build_ton_deeplink(string $recipient, string $amountNano, string $payloadB64): string
{
    if ($recipient === '' || $amountNano === '') {
        return '';
    }
    $qs = http_build_query(array_filter([
        'amount' => $amountNano,
        'bin' => $payloadB64 !== '' ? $payloadB64 : null,
    ]));
    return 'ton://transfer/' . rawurlencode($recipient) . '?' . $qs;
}

function build_tonkeeper_link(string $recipient, string $amountNano, string $payloadB64): string
{
    if ($recipient === '' || $amountNano === '') {
        return '';
    }
    $qs = http_build_query(array_filter([
        'amount' => $amountNano,
        'bin' => $payloadB64 !== '' ? $payloadB64 : null,
    ]));
    return 'https://app.tonkeeper.com/transfer/' . rawurlencode($recipient) . '?' . $qs;
}

$result = null;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $result = create_dev_cert_and_prepare();
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Dev One-Click Mint Test</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#070b11">
  <style>
    :root{
      --bg:#070b11;
      --panel:#111827;
      --line:rgba(255,255,255,.10);
      --text:#f3f6fb;
      --muted:#9aa4b2;
      --gold:#e7c98a;
      --ok:#7fe2b2;
      --bad:#ff9a9a;
    }
    *{box-sizing:border-box}
    html,body{margin:0;padding:0;background:linear-gradient(180deg,#05070d,#0b1220 60%,#101728);color:var(--text);font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}
    body{padding:18px}
    .wrap{max-width:1120px;margin:0 auto;display:grid;gap:16px}
    .card{border:1px solid var(--line);border-radius:22px;background:linear-gradient(180deg,rgba(255,255,255,.04),rgba(255,255,255,.02)),var(--panel);padding:18px}
    .kicker{color:var(--gold);font-size:11px;font-weight:900;letter-spacing:.14em;text-transform:uppercase;margin-bottom:8px}
    h1,h2,h3{margin:0}
    p.sub{margin:8px 0 0;color:var(--muted);line-height:1.6}
    button{
      min-height:48px;border:none;border-radius:14px;padding:0 18px;cursor:pointer;
      background:linear-gradient(180deg,#f3ddb0,#caa44d);color:#141414;font-weight:900
    }
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
    .mini{border:1px solid var(--line);border-radius:16px;background:rgba(255,255,255,.025);padding:14px}
    .mini .label{color:var(--muted);font-size:12px;margin-bottom:8px}
    .mini .value{font-weight:800;word-break:break-word}
    .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
    .ok{color:var(--ok);font-weight:900}
    .bad{color:var(--bad);font-weight:900}
    .qr{display:flex;align-items:center;justify-content:center;min-height:280px;border:1px dashed rgba(231,201,138,.25);border-radius:18px;background:rgba(255,255,255,.02)}
    .qr img{max-width:100%;border-radius:14px}
    .links{display:grid;gap:10px;margin-top:12px}
    a.link{color:#fff;text-decoration:none;border:1px solid var(--line);border-radius:12px;padding:10px 12px;display:block;background:rgba(255,255,255,.03);word-break:break-all}
    .status{padding:12px 14px;border-radius:14px;border:1px solid var(--line)}
    pre{margin:0;white-space:pre-wrap;word-break:break-word;background:#0a1120;border:1px solid var(--line);border-radius:16px;padding:14px;overflow:auto}
    @media (max-width:900px){.grid{grid-template-columns:1fr}}
  </style>
</head>
<body>
  <main class="wrap">
    <section class="card">
      <div class="kicker">Dev Tool</div>
      <h1>V10 One-Click Mint Test</h1>
      <p class="sub">This creates a valid dev cert row, confirmed payment row, valid metadata JSON, valid verify JSON, then calls mint-init.php and shows wallet deeplink plus auto verify polling every 5 seconds.</p>
    </section>

    <section class="card">
      <form method="post">
        <button type="submit">Create Dev Cert + Prepare Mint</button>
      </form>
    </section>

    <?php if ($error !== ''): ?>
      <section class="card">
        <div class="status bad">ERROR · <?= h($error) ?></div>
      </section>
    <?php endif; ?>

    <?php if (is_array($result)): ?>
      <?php
        $mint = is_array($result['mint_init'] ?? null) ? $result['mint_init'] : [];
        $recipient = trim((string)($mint['recipient'] ?? ''));
        $amountNano = trim((string)($mint['amount_nano'] ?? ''));
        $payloadB64 = trim((string)($mint['payload_b64'] ?? ''));
        $amountTon = trim((string)($mint['amount_ton'] ?? ''));
        $deeplink = build_ton_deeplink($recipient, $amountNano, $payloadB64);
        $tonkeeper = build_tonkeeper_link($recipient, $amountNano, $payloadB64);
        $qrData = $tonkeeper !== '' ? $tonkeeper : $deeplink;
        $qrImg = $qrData !== '' ? ('https://api.qrserver.com/v1/create-qr-code/?size=320x320&margin=0&data=' . rawurlencode($qrData)) : '';
        $mintReady = !empty($mint['ok']) && !empty($mint['mint_ready']);
      ?>
      <section class="card">
        <div class="kicker">Result</div>
        <h2>Dev cert created</h2>
        <div class="grid" style="margin-top:14px">
          <div class="mini"><div class="label">Cert UID</div><div class="value mono" id="certUid"><?= h($result['cert_uid']) ?></div></div>
          <div class="mini"><div class="label">Payment Ref</div><div class="value mono"><?= h($result['payment_ref']) ?></div></div>
          <div class="mini"><div class="label">Metadata URL</div><div class="value mono"><?= h($result['metadata_url']) ?></div></div>
          <div class="mini"><div class="label">Image URL</div><div class="value mono"><?= h($result['image_url']) ?></div></div>
          <div class="mini"><div class="label">Verify URL</div><div class="value mono"><?= h($result['verify_url']) ?></div></div>
          <div class="mini"><div class="label">Mint Ready</div><div class="value <?= $mintReady ? 'ok' : 'bad' ?>" id="mintReadyText"><?= $mintReady ? 'YES' : 'NO' ?></div></div>
        </div>
      </section>

      <section class="grid">
        <section class="card">
          <div class="kicker">Wallet</div>
          <h3>Sign mint transaction</h3>
          <div class="mini" style="margin-top:14px">
            <div class="label">Recipient</div>
            <div class="value mono"><?= h($recipient !== '' ? $recipient : '-') ?></div>
          </div>
          <div class="mini" style="margin-top:12px">
            <div class="label">Amount</div>
            <div class="value"><?= h($amountTon !== '' ? ($amountTon . ' TON') : '-') ?></div>
          </div>

          <div class="links">
            <?php if ($tonkeeper !== ''): ?><a class="link" href="<?= h($tonkeeper) ?>" target="_blank" rel="noopener">Open Tonkeeper Deeplink</a><?php endif; ?>
            <?php if ($deeplink !== ''): ?><a class="link" href="<?= h($deeplink) ?>" target="_blank" rel="noopener">Open TON Deeplink</a><?php endif; ?>
            <?php if ($result['verify_url'] !== ''): ?><a class="link" href="<?= h($result['verify_url']) ?>" target="_blank" rel="noopener">Open Verify Page</a><?php endif; ?>
          </div>
        </section>

        <section class="card">
          <div class="kicker">QR</div>
          <h3>Scan to mint</h3>
          <div class="qr" style="margin-top:14px">
            <?php if ($qrImg !== ''): ?>
              <img src="<?= h($qrImg) ?>" alt="Mint QR">
            <?php else: ?>
              <div class="bad">QR not ready</div>
            <?php endif; ?>
          </div>
        </section>
      </section>

      <section class="card">
        <div class="kicker">Auto Verify</div>
        <h3>verify-status.php final authority</h3>
        <div id="statusBox" class="status" style="margin-top:14px">Waiting for wallet submit. This page will poll every 5 seconds.</div>
      </section>

      <section class="card">
        <div class="kicker">mint-init.php JSON</div>
        <pre><?= h(json_encode($mint, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
      </section>

      <script>
      (function () {
        const uid = <?= json_encode((string)$result['cert_uid']) ?>;
        const statusBox = document.getElementById('statusBox');
        let tick = 0;
        let done = false;

        async function poll() {
          if (done || !uid) return;
          tick += 1;
          try {
            const res = await fetch('/rwa/cert/api/verify-status.php?uid=' + encodeURIComponent(uid), {
              credentials: 'same-origin',
              headers: { 'Accept': 'application/json' }
            });
            const json = await res.json();
            const item = json && json.item ? json.item : null;
            const status = item && item.status ? String(item.status) : 'unknown';
            const nftMinted = item && Number(item.nft_minted || 0) === 1;
            const nftItemAddress = item && item.nft_item_address ? String(item.nft_item_address) : '';

            if (nftMinted || status.toLowerCase() === 'minted') {
              statusBox.innerHTML =
                '<span class="ok">MINT SUCCESS</span><br>' +
                'Status: ' + status + '<br>' +
                'NFT Item: ' + (nftItemAddress || '-') + '<br>' +
                'verify-status.php confirmed mint.';
              done = true;
              return;
            }

            statusBox.innerHTML =
              'Polling every 5s... #' + tick + '<br>' +
              'Current status: ' + status + '<br>' +
              'NFT Item: ' + (nftItemAddress || '-');
          } catch (e) {
            statusBox.innerHTML = '<span class="bad">Poll failed</span><br>' + String(e);
          }
        }

        poll();
        setInterval(poll, 5000);
      })();
      </script>
    <?php endif; ?>
  </main>
</body>
</html>
