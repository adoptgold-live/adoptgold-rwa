<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/pdf-design.php
 * Version: v1.1.0-20260330-cert-local-qr
 *
 * Changelog:
 * - replace /rwa/cert/api/_qr-local.php with /rwa/cert/api/_qr-local.php
 * - keep existing preview/finaliser layout
 * - keep existing demo/sample row flow
 * - keep real row load by UID
 * - keep raw HTML preview and production PDF preview behavior
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/db.php';
require_once __DIR__ . '/api/_qr-local.php';
require_once __DIR__ . '/api/_pdf-template-map.php';
require_once __DIR__ . '/api/_render-pdf-html.php';

function pdfd_e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function pdfd_theme_order(): array
{
    return ['green', 'blue', 'black', 'gold', 'yellow', 'pink', 'royal_blue', 'red'];
}

function pdfd_sample_uid(string $family): string
{
    $codeMap = [
        'green'      => 'RCO2C-EMA',
        'blue'       => 'RH2O-EMA',
        'black'      => 'RBLACK-EMA',
        'gold'       => 'RK92-EMA',
        'yellow'     => 'RHRD-EMA',
        'pink'       => 'RLIFE-EMA',
        'royal_blue' => 'RPROP-EMA',
        'red'        => 'RTRIP-EMA',
    ];

    $code = $codeMap[$family] ?? 'RK92-EMA';
    return $code . '-20260327-A1B2C3D4';
}

function pdfd_sample_price(string $family): array
{
    $map = [
        'green'      => ['1000', 'wEMS'],
        'blue'       => ['5000', 'wEMS'],
        'black'      => ['10000', 'wEMS'],
        'gold'       => ['50000', 'wEMS'],
        'yellow'     => ['100', 'EMA$'],
        'pink'       => ['100', 'EMA$'],
        'royal_blue' => ['100', 'EMA$'],
        'red'        => ['100', 'EMA$'],
    ];
    return $map[$family] ?? ['100', 'EMA$'];
}

function pdfd_build_sample_row(string $family, ?string $uid = null): array
{
    $theme = poado_cert_pdf_template_map()[$family] ?? poado_cert_pdf_template_map()['gold'];
    [$price, $units] = pdfd_sample_price($family);

    return [
        'cert_uid'         => $uid ?: pdfd_sample_uid($family),
        'rwa_type'         => $theme['rwa_code'],
        'price_wems'       => $price,
        'price_units'      => $units,
        'fingerprint_hash' => '0x8d9f3a84c8e3b9ee7219f87162d729a4fe8bcfa8c11f0b92faec0d76013a7791',
        'router_tx_hash'   => '',
        'owner_user_id'    => 13,
        'ton_wallet'       => '0:717359c4fcc1ad210a72d704f3567a104cf7d09afe6cb7fbb640959234d02f6d',
        'pdf_path'         => '',
        'nft_image_path'   => '',
        'metadata_path'    => '',
        'nft_item_address' => '',
        'nft_minted'       => 1,
        'status'           => 'issued',
        'issued_at'        => '2026-03-27 10:20:30',
        'paid_at'          => '',
        'minted_at'        => '2026-03-27 11:06:10',
        'updated_at'       => '2026-03-27 11:06:10',
        'cert_type'        => $family,
    ];
}

function pdfd_load_real_row_by_uid(string $uid): ?array
{
    if ($uid === '') {
        return null;
    }

    try {
        $pdo = rwa_db();
        $sql = "
            SELECT
                id,
                cert_uid,
                rwa_type,
                price_wems,
                price_units,
                fingerprint_hash,
                router_tx_hash,
                owner_user_id,
                ton_wallet,
                pdf_path,
                nft_image_path,
                metadata_path,
                nft_item_address,
                nft_minted,
                status,
                issued_at,
                paid_at,
                minted_at,
                updated_at
            FROM poado_rwa_certs
            WHERE cert_uid = :uid
            LIMIT 1
        ";
        $st = $pdo->prepare($sql);
        $st->execute([':uid' => $uid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    } catch (Throwable $e) {
        error_log('rwa_cert_pdf_design_load_uid_failed: ' . $e->getMessage());
        return null;
    }
}

function pdfd_resolve_row(string $family, string $uid): array
{
    $real = pdfd_load_real_row_by_uid($uid);
    if ($real) {
        if (empty($real['cert_type'])) {
            $real['cert_type'] = poado_cert_pdf_detect_type($real);
        }
        return $real;
    }
    return pdfd_build_sample_row($family, $uid !== '' ? $uid : null);
}

function pdfd_verify_url(string $uid): string
{
    return 'https://adoptgold.app/rwa/cert/verify.php?uid=' . rawurlencode($uid);
}

function pdfd_pick_qr_data_uri(string $verifyUrl): string
{
    $verifyUrl = trim($verifyUrl);
    if ($verifyUrl === '') {
        return '';
    }

    if (function_exists('poado_qr_pick_best_data_uri')) {
        return (string) poado_qr_pick_best_data_uri($verifyUrl, 320, 8);
    }
    if (function_exists('cert_local_qr_png_data_uri')) {
        return (string) cert_local_qr_png_data_uri($verifyUrl, 320, 8);
    }
    if (function_exists('cert_local_qr_svg_data_uri')) {
        return (string) cert_local_qr_svg_data_uri($verifyUrl, 320, 8);
    }

    return '';
}

$allThemes = poado_cert_pdf_template_map();

$view = strtolower(trim((string)($_GET['view'] ?? 'single')));
if (!in_array($view, ['single', 'all'], true)) {
    $view = 'single';
}

$family = strtolower(trim((string)($_GET['family'] ?? 'gold')));
if (!isset($allThemes[$family])) {
    $family = 'gold';
}

$uid = trim((string)($_GET['uid'] ?? ''));
$renderMode = strtolower(trim((string)($_GET['render'] ?? 'html')));
if (!in_array($renderMode, ['html', 'raw'], true)) {
    $renderMode = 'html';
}

$familyOrder = pdfd_theme_order();
$singleRow = pdfd_resolve_row($family, $uid);
$singleVerifyUrl = pdfd_verify_url((string)$singleRow['cert_uid']);
$singleQrDataUri = pdfd_pick_qr_data_uri($singleVerifyUrl);
$singleHtml = poado_cert_render_pdf_html($singleRow, [
    'verify_url'  => $singleVerifyUrl,
    'qr_data_uri' => $singleQrDataUri,
]);

if ($renderMode === 'raw') {
    header('Content-Type: text/html; charset=utf-8');
    echo $singleHtml;
    exit;
}

$previewCards = [];
foreach ($familyOrder as $fam) {
    $row = ($view === 'single' && $fam === $family) ? $singleRow : pdfd_build_sample_row($fam);
    $verifyUrl = pdfd_verify_url((string)$row['cert_uid']);
    $qrDataUri = pdfd_pick_qr_data_uri($verifyUrl);
    $previewCards[$fam] = [
        'theme' => poado_cert_pdf_template_map()[$fam],
        'row'   => $row,
        'html'  => poado_cert_render_pdf_html($row, [
            'verify_url'  => $verifyUrl,
            'qr_data_uri' => $qrDataUri,
        ]),
    ];
}

$rawUrl = '/rwa/cert/pdf-design.php?view=single&family=' . rawurlencode($family) . '&uid=' . rawurlencode($uid) . '&render=raw';
$pdfRouteUrl = '/rwa/cert/pdf.php?uid=' . rawurlencode((string)$singleRow['cert_uid']);
$pdfPreviewUrl = $pdfRouteUrl . '#toolbar=1&navpanes=0&scrollbar=1';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>RWA PDF Design Finaliser</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{--line:rgba(255,255,255,.12);--soft:rgba(255,255,255,.07);--text:#f4efff;--muted:#b7aecf;--good:#78e1b0}
*{box-sizing:border-box}
html,body{margin:0;padding:0;background:linear-gradient(180deg,#090710 0%,#100a18 45%,#140d20 100%);color:var(--text);font-family:Arial,Helvetica,sans-serif}
a{color:#d5b6ff;text-decoration:none}
.wrap{max-width:1800px;margin:0 auto;padding:18px}
.topbar{display:flex;gap:12px;align-items:center;justify-content:space-between;padding:14px 16px;border:1px solid var(--line);background:rgba(255,255,255,.03);border-radius:18px;box-shadow:0 10px 40px rgba(0,0,0,.22)}
.topbar h1{margin:0;font-size:20px;line-height:1.2}
.topbar .sub{color:var(--muted);font-size:13px;margin-top:4px}
.actions{display:flex;gap:8px;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:0 14px;border-radius:12px;border:1px solid var(--line);background:rgba(255,255,255,.04);color:var(--text);font-weight:700;font-size:13px}
.btn:hover{background:rgba(255,255,255,.08)}
.btn-good{background:rgba(120,225,176,.10);border-color:rgba(120,225,176,.35);color:#dfffee}
.controls,.helper,.note{margin-top:14px;padding:16px;border:1px solid var(--line);border-radius:18px;background:rgba(255,255,255,.03)}
.grid{display:grid;grid-template-columns:repeat(12,1fr);gap:12px}
.field{grid-column:span 3}.field.wide{grid-column:span 6}.field.full{grid-column:span 12}
label{display:block;font-size:12px;color:var(--muted);margin-bottom:6px;font-weight:700}
input,select{width:100%;min-height:42px;border-radius:12px;border:1px solid var(--line);background:#120f1d;color:var(--text);padding:0 12px;font-size:14px}
button.btn{cursor:pointer}
.theme-bar{margin-top:14px;display:grid;grid-template-columns:repeat(8,1fr);gap:10px}
.theme-chip{min-height:56px;border-radius:14px;border:1px solid var(--line);padding:10px 12px;background:rgba(255,255,255,.03);display:flex;flex-direction:column;justify-content:center}
.theme-chip .k{font-size:11px;color:var(--muted);margin-bottom:3px;text-transform:uppercase}
.theme-chip .v{font-size:13px;font-weight:700}
.layout{margin-top:16px;display:grid;grid-template-columns:330px 1fr;gap:14px;align-items:start}
.side{position:sticky;top:14px;padding:16px;border:1px solid var(--line);border-radius:18px;background:rgba(255,255,255,.03)}
.kv{display:grid;grid-template-columns:110px 1fr;gap:8px 10px;font-size:13px;line-height:1.45}
.kv .k{color:var(--muted)} .kv .v{word-break:break-word}
.side-actions{margin-top:14px;display:grid;gap:8px}
.preview-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.preview{padding:14px;border:1px solid var(--line);border-radius:18px;background:rgba(255,255,255,.03)}
.canvas{width:100%;overflow:auto;border-radius:16px;border:1px solid var(--line);background:#06070a;padding:12px}
.frame{width:1123px;min-width:1123px;background:#06070a;border-radius:12px;overflow:hidden;box-shadow:0 10px 40px rgba(0,0,0,.35)}
.frame iframe{width:1123px;height:794px;border:0;display:block;background:#06070a}
.pdf-panel{border-radius:16px;border:1px solid var(--line);background:#ffffff;overflow:hidden;min-height:820px}
.pdf-panel iframe{width:100%;height:820px;border:0;display:block;background:#fff}
.all-grid{margin-top:14px;display:grid;grid-template-columns:repeat(2,1fr);gap:14px}
.card{padding:12px;border:1px solid var(--line);border-radius:18px;background:rgba(255,255,255,.03)}
.card-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;gap:10px}
.card-title{font-size:15px;font-weight:700}.card-meta{font-size:12px;color:var(--muted)}
.mini-frame{width:100%;overflow:auto;border-radius:14px;border:1px solid var(--line);background:#06070a;padding:10px}
.mini-frame iframe{width:1123px;height:794px;border:0;display:block;background:#06070a}
.badge{display:inline-block;padding:4px 8px;border-radius:999px;border:1px solid var(--line);font-size:11px;color:#fff;background:rgba(255,255,255,.05)}
.section-title{font-size:14px;font-weight:700;margin-bottom:10px}
@media (max-width:1500px){.preview-grid{grid-template-columns:1fr}.pdf-panel iframe{height:760px}}
@media (max-width:1200px){.layout{grid-template-columns:1fr}.side{position:static}.theme-bar{grid-template-columns:repeat(2,1fr)}.all-grid{grid-template-columns:1fr}.field,.field.wide{grid-column:span 12}}
</style>
</head>
<body>
<div class="wrap">
    <div class="topbar">
        <div>
            <h1>RWA PDF Design Finaliser</h1>
            <div class="sub">HTML design preview + real production PDF preview in one page.</div>
        </div>
        <div class="actions">
            <a class="btn" href="/rwa/cert/pdf-design.php?view=single&family=green">Green</a>
            <a class="btn" href="/rwa/cert/pdf-design.php?view=single&family=blue">Blue</a>
            <a class="btn" href="/rwa/cert/pdf-design.php?view=single&family=black">Black</a>
            <a class="btn" href="/rwa/cert/pdf-design.php?view=single&family=gold">Gold</a>
            <a class="btn" href="/rwa/cert/pdf-design.php?view=single&family=yellow">Yellow</a>
            <a class="btn" href="/rwa/cert/pdf-design.php?view=single&family=pink">Pink</a>
            <a class="btn" href="/rwa/cert/pdf-design.php?view=single&family=royal_blue">Royal Blue</a>
            <a class="btn" href="/rwa/cert/pdf-design.php?view=single&family=red">Red</a>
            <a class="btn btn-good" href="/rwa/cert/pdf-design.php?view=all">View All</a>
        </div>
    </div>

    <form class="controls" method="get" action="/rwa/cert/pdf-design.php">
        <div class="grid">
            <div class="field">
                <label for="view">View</label>
                <select id="view" name="view">
                    <option value="single" <?= $view === 'single' ? 'selected' : '' ?>>single</option>
                    <option value="all" <?= $view === 'all' ? 'selected' : '' ?>>all</option>
                </select>
            </div>
            <div class="field">
                <label for="family">Family</label>
                <select id="family" name="family">
                    <?php foreach ($familyOrder as $fam): ?>
                        <option value="<?= pdfd_e($fam) ?>" <?= $family === $fam ? 'selected' : '' ?>><?= pdfd_e($fam) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field wide">
                <label for="uid">Optional real Cert UID</label>
                <input id="uid" name="uid" type="text" value="<?= pdfd_e($uid) ?>" placeholder="leave blank to use demo sample row">
            </div>
            <div class="field">
                <label>&nbsp;</label>
                <button class="btn" type="submit" style="width:100%;">Apply Preview</button>
            </div>
        </div>
    </form>

    <div class="theme-bar">
        <?php foreach ($familyOrder as $fam): $theme = $allThemes[$fam]; ?>
            <div class="theme-chip" style="box-shadow: inset 0 0 0 1px <?= pdfd_e($theme['accent_line']) ?>;">
                <div class="k"><?= pdfd_e($fam) ?></div>
                <div class="v" style="color:<?= pdfd_e($theme['accent_line']) ?>;"><?= pdfd_e($theme['title']) ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($view === 'single'): ?>
        <?php $theme = poado_cert_pdf_theme($singleRow); ?>
        <div class="layout">
            <div class="side">
                <h2>Current PDF Theme</h2>
                <div style="margin-bottom:12px;">
                    <span class="badge" style="border-color:<?= pdfd_e($theme['accent_line']) ?>;color:<?= pdfd_e($theme['accent_line']) ?>;">
                        <?= pdfd_e($theme['family_label']) ?>
                    </span>
                </div>
                <div class="kv">
                    <div class="k">Title</div><div class="v"><?= pdfd_e($theme['title']) ?></div>
                    <div class="k">Subtitle</div><div class="v"><?= pdfd_e($theme['subtitle']) ?></div>
                    <div class="k">RWA Code</div><div class="v"><?= pdfd_e($theme['rwa_code']) ?></div>
                    <div class="k">Weight</div><div class="v"><?= pdfd_e((string)$theme['weight']) ?></div>
                    <div class="k">Cert UID</div><div class="v"><?= pdfd_e((string)$singleRow['cert_uid']) ?></div>
                </div>
                <div class="side-actions">
                    <a class="btn btn-good" href="<?= pdfd_e($pdfRouteUrl) ?>" target="_blank" rel="noopener">Download PDF</a>
                    <a class="btn" href="<?= pdfd_e($rawUrl) ?>" target="_blank" rel="noopener">Open Raw HTML Preview</a>
                </div>
            </div>

            <div class="preview-grid">
                <div class="preview">
                    <div class="section-title">HTML Design Preview</div>
                    <div class="canvas">
                        <div class="frame">
                            <iframe title="PDF Design Preview" srcdoc="<?= pdfd_e($singleHtml) ?>" loading="lazy"></iframe>
                        </div>
                    </div>
                </div>

                <div class="preview">
                    <div class="section-title">Production PDF Preview</div>
                    <div class="pdf-panel">
                        <iframe title="Production PDF Preview" src="<?= pdfd_e($pdfPreviewUrl) ?>"></iframe>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="all-grid">
            <?php foreach ($familyOrder as $fam): $card = $previewCards[$fam]; $theme = $card['theme']; ?>
                <div class="card">
                    <div class="card-head">
                        <div>
                            <div class="card-title" style="color:<?= pdfd_e($theme['accent_line']) ?>;"><?= pdfd_e($theme['title']) ?></div>
                            <div class="card-meta"><?= pdfd_e($theme['family_label']) ?> · <?= pdfd_e($theme['rwa_code']) ?> · Weight <?= pdfd_e((string)$theme['weight']) ?></div>
                        </div>
                        <div>
                            <a class="btn" href="/rwa/cert/pdf-design.php?view=single&family=<?= rawurlencode($fam) ?>">Open</a>
                        </div>
                    </div>
                    <div class="mini-frame">
                        <iframe title="<?= pdfd_e($fam) ?> Preview" srcdoc="<?= pdfd_e($card['html']) ?>" loading="lazy"></iframe>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
