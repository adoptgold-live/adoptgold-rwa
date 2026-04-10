<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/api/pdf-debug-tester.php
 *
 * Full debug tester for cert PDF pipeline.
 * Safe operator tool:
 * - loads cert row
 * - checks helper includes
 * - checks Dompdf bootstrap
 * - checks theme mapping
 * - checks HTML render
 * - checks PDF render
 *
 * URL:
 *   https://adoptgold.app/rwa/cert/api/pdf-debug-tester.php?cert_uid=RK92-EMA-20260327-REAL0001
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/db.php';
require_once _once $_SERVER['DOCUMENT_ROOT'] . '/rwa/cert/api/_qr-local.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/pdf.php';
require_once dirname(__DIR__) . '/api/_pdf-template-map.php';
require_once dirname(__DIR__) . '/api/_render-pdf-html.php';

header('Content-Type: text/html; charset=utf-8');

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function section(string $title, $data = null): void {
    echo '<div class="sec">';
    echo '<h2>' . h($title) . '</h2>';
    if ($data !== null) {
        echo '<pre>' . h(is_string($data) ? $data : json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre>';
    }
    echo '</div>';
}

$uid = trim((string)($_GET['cert_uid'] ?? $_GET['uid'] ?? ''));
if ($uid === '') {
    echo '<h1>PDF Debug Tester</h1>';
    echo '<p>Missing cert_uid.</p>';
    echo '<p>Example:</p>';
    echo '<pre>?cert_uid=RK92-EMA-20260327-REAL0001</pre>';
    exit;
}

$debug = [
    'cert_uid' => $uid,
    'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? '',
    'php_version' => PHP_VERSION,
    'cwd' => getcwd(),
];

$row = null;
$theme = null;
$html = null;
$pdfBin = null;
$pdfFilename = preg_replace('/[^A-Za-z0-9._-]/', '_', $uid) . '.pdf';
$verifyUrl = 'https://adoptgold.app/rwa/cert/verify.php?uid=' . rawurlencode($uid);
$qrDataUri = '';
$dompdfClassExistsBefore = class_exists(\Dompdf\Dompdf::class);
$optionsClassExistsBefore = class_exists(\Dompdf\Options::class);

try {
    $debug['autoload_file'] = '/var/www/html/public/rwa/vendor/autoload.php';
    $debug['autoload_exists'] = is_file('/var/www/html/public/rwa/vendor/autoload.php');
    $debug['poado_rwa_pdf_bootstrap_exists'] = function_exists('poado_rwa_pdf_bootstrap');

    if (function_exists('poado_rwa_pdf_bootstrap')) {
        poado_rwa_pdf_bootstrap();
    }

    $debug['dompdf_class_exists_before_bootstrap'] = $dompdfClassExistsBefore;
    $debug['options_class_exists_before_bootstrap'] = $optionsClassExistsBefore;
    $debug['dompdf_class_exists_after_bootstrap'] = class_exists(\Dompdf\Dompdf::class);
    $debug['options_class_exists_after_bootstrap'] = class_exists(\Dompdf\Options::class);

    $pdo = rwa_db();

    $sql = "
        SELECT
            id,
            cert_uid,
            rwa_type,
            family,
            rwa_code,
            price_wems,
            price_units,
            fingerprint_hash,
            router_tx_hash,
            owner_user_id,
            ton_wallet,
            pdf_path,
            nft_image_path,
            metadata_path,
            verify_url,
            meta_json,
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

    $debug['row_found'] = (bool)$row;

    if (!$row) {
        throw new RuntimeException('Certificate row not found');
    }

    $debug['row_summary'] = [
        'id' => $row['id'] ?? null,
        'cert_uid' => $row['cert_uid'] ?? null,
        'rwa_type' => $row['rwa_type'] ?? null,
        'family' => $row['family'] ?? null,
        'rwa_code' => $row['rwa_code'] ?? null,
        'owner_user_id' => $row['owner_user_id'] ?? null,
        'ton_wallet' => $row['ton_wallet'] ?? null,
        'status' => $row['status'] ?? null,
        'nft_minted' => $row['nft_minted'] ?? null,
        'nft_item_address' => $row['nft_item_address'] ?? null,
        'pdf_path' => $row['pdf_path'] ?? null,
        'metadata_path' => $row['metadata_path'] ?? null,
    ];

    $debug['template_map_keys'] = array_keys(poado_cert_pdf_template_map());

    $debug['detected_type_before_theme'] = function_exists('poado_cert_pdf_detect_type')
        ? poado_cert_pdf_detect_type($row)
        : null;

    $theme = poado_cert_pdf_theme($row);

    $debug['theme'] = $theme;

    if (function_exists('cert_local_qr_svg_data_uri')) {
        $qrDataUri = (string)cert_local_qr_svg_data_uri($verifyUrl);
    }

    $html = poado_cert_render_pdf_html($row, [
        'verify_url'    => $verifyUrl,
        'qr_data_uri'   => $qrDataUri,
        'qr_public_url' => '',
    ]);

    $debug['html_length'] = strlen((string)$html);
    $debug['html_non_empty'] = trim((string)$html) !== '';
    $debug['html_preview'] = substr((string)$html, 0, 1200);

    if (!class_exists(\Dompdf\Options::class) || !class_exists(\Dompdf\Dompdf::class)) {
        throw new RuntimeException('Dompdf classes still unavailable after bootstrap');
    }

    $options = new \Dompdf\Options();
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);
    $options->set('chroot', $_SERVER['DOCUMENT_ROOT']);

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml((string)$html, 'UTF-8');
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    $pdfBin = $dompdf->output();

    $debug['pdf_render_ok'] = true;
    $debug['pdf_size'] = strlen((string)$pdfBin);

} catch (Throwable $e) {
    $debug['exception'] = [
        'class' => get_class($e),
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace_head' => substr($e->getTraceAsString(), 0, 2000),
    ];
}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>PDF Debug Tester</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{
    margin:0;
    background:#06070a;
    color:#f3f5f7;
    font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
}
.wrap{
    max-width:1400px;
    margin:20px auto;
    padding:16px;
}
h1{
    margin:0 0 16px;
    font-size:24px;
}
.grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:16px;
}
.sec{
    background:#0f1318;
    border:1px solid #2b3642;
    border-radius:12px;
    padding:14px;
    margin-bottom:16px;
}
.sec h2{
    margin:0 0 10px;
    font-size:16px;
    color:#9fd3ff;
}
pre{
    margin:0;
    white-space:pre-wrap;
    word-break:break-word;
    font-size:12px;
    line-height:1.45;
}
.ok{ color:#6ee7a8; }
.bad{ color:#ff8a8a; }
.toolbar{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-bottom:16px;
}
a.btn{
    display:inline-block;
    padding:10px 14px;
    border-radius:10px;
    border:1px solid #35506d;
    color:#dbeeff;
    text-decoration:none;
    background:#11202f;
}
iframe{
    width:100%;
    min-height:720px;
    border:1px solid #2b3642;
    border-radius:12px;
    background:#fff;
}
textarea{
    width:100%;
    min-height:280px;
    background:#081018;
    color:#d7ebff;
    border:1px solid #2b3642;
    border-radius:10px;
    padding:10px;
    font:inherit;
}
</style>
</head>
<body>
<div class="wrap">
    <h1>PDF Debug Tester — <?= h($uid) ?></h1>

    <div class="toolbar">
        <a class="btn" href="?cert_uid=<?= rawurlencode($uid) ?>">Reload</a>
        <a class="btn" href="/rwa/cert/pdf.php?cert_uid=<?= rawurlencode($uid) ?>" target="_blank">Open PDF Endpoint</a>
        <a class="btn" href="/rwa/cert/verify.php?uid=<?= rawurlencode($uid) ?>" target="_blank">Open Verify Page</a>
    </div>

    <div class="grid">
        <div>
            <?php section('Environment / Bootstrap', [
                'cert_uid' => $debug['cert_uid'] ?? null,
                'document_root' => $debug['document_root'] ?? null,
                'autoload_exists' => $debug['autoload_exists'] ?? null,
                'poado_rwa_pdf_bootstrap_exists' => $debug['poado_rwa_pdf_bootstrap_exists'] ?? null,
                'dompdf_class_exists_before_bootstrap' => $debug['dompdf_class_exists_before_bootstrap'] ?? null,
                'options_class_exists_before_bootstrap' => $debug['options_class_exists_before_bootstrap'] ?? null,
                'dompdf_class_exists_after_bootstrap' => $debug['dompdf_class_exists_after_bootstrap'] ?? null,
                'options_class_exists_after_bootstrap' => $debug['options_class_exists_after_bootstrap'] ?? null,
            ]); ?>

            <?php section('Database Row Summary', $debug['row_summary'] ?? ['row_found' => false]); ?>

            <?php section('Template Detect / Theme', [
                'template_map_keys' => $debug['template_map_keys'] ?? null,
                'detected_type_before_theme' => $debug['detected_type_before_theme'] ?? null,
                'theme' => $debug['theme'] ?? null,
            ]); ?>

            <?php
            if (!empty($debug['exception'])) {
                section('Exception', $debug['exception']);
            } else {
                section('Render Status', [
                    'html_length' => $debug['html_length'] ?? null,
                    'html_non_empty' => $debug['html_non_empty'] ?? null,
                    'pdf_render_ok' => $debug['pdf_render_ok'] ?? null,
                    'pdf_size' => $debug['pdf_size'] ?? null,
                ]);
            }
            ?>
        </div>

        <div>
            <?php section('HTML Preview (first 1200 chars)', $debug['html_preview'] ?? ''); ?>

            <div class="sec">
                <h2>Rendered PDF Preview</h2>
                <?php if (!empty($pdfBin) && is_string($pdfBin)): ?>
                    <?php $b64 = base64_encode($pdfBin); ?>
                    <iframe src="data:application/pdf;base64,<?= $b64 ?>"></iframe>
                <?php else: ?>
                    <pre>No PDF binary rendered yet.</pre>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</body>
</html>
