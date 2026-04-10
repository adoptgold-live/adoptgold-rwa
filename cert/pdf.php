<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/pdf.php
 *
 * RWA Cert PDF Renderer
 * Version: v4.8.0-20260330-local-cert-qr
 *
 * Changelog:
 * - keep previous Chromium PDF rendering flow
 * - keep previous DB load / fingerprint / debug HTML behavior
 * - remove dependency on /rwa/cert/api/_qr-local.php
 * - use cert-local QR helper: /rwa/cert/api/_qr-local.php
 * - maintain all previous functions and response behavior
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/error.php';
require_once __DIR__ . '/api/_qr-local.php';
require_once __DIR__ . '/api/_pdf-template-map.php';
require_once __DIR__ . '/api/_render-pdf-html.php';

function cert_pdf_fail(string $message, int $code = 400): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function cert_pdf_abs_url(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    if (preg_match('~^https?://~i', $path)) {
        return $path;
    }
    return 'https://adoptgold.app' . (str_starts_with($path, '/') ? $path : '/' . $path);
}

function cert_pdf_resolve_owner_wallet(array $row): string
{
    foreach (['ton_wallet', 'wallet_address', 'wallet'] as $key) {
        $value = trim((string)($row[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function cert_pdf_compute_fingerprint(array $row): string
{
    return hash(
        'sha256',
        (string)($row['cert_uid'] ?? '') . '|' .
        cert_pdf_resolve_owner_wallet($row) . '|' .
        (string)($row['nft_item_address'] ?? '') . '|' .
        (string)($row['rwa_code'] ?? $row['rwa_type'] ?? '')
    );
}

function cert_pdf_load_row(PDO $pdo, string $uid): ?array
{
    $sql = "
        SELECT
            id,
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
            revoked_at,
            updated_at
        FROM poado_rwa_certs
        WHERE cert_uid = :uid
        LIMIT 1
    ";
    $st = $pdo->prepare($sql);
    $st->execute([':uid' => $uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function cert_pdf_find_browser_bin(): ?string
{
    $candidates = [
        '/usr/bin/google-chrome-stable',
        '/usr/bin/google-chrome',
        '/usr/bin/chromium-browser',
        '/usr/bin/chromium',
    ];

    foreach ($candidates as $bin) {
        if (is_file($bin) && is_executable($bin)) {
            return $bin;
        }
    }

    return null;
}

function cert_pdf_rrmdir(string $dir): void
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
            cert_pdf_rrmdir($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($dir);
}

function cert_pdf_render_via_chromium(string $html, string $browserBin): string
{
    $tmpDir = sys_get_temp_dir() . '/rwa_cert_pdf_' . bin2hex(random_bytes(8));
    if (!@mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
        throw new RuntimeException('Unable to create temp directory.');
    }

    $htmlFile   = $tmpDir . '/cert.html';
    $pdfFile    = $tmpDir . '/cert.pdf';
    $profileDir = $tmpDir . '/chrome-profile';
    $homeDir    = $tmpDir . '/home';
    $configDir  = $homeDir . '/.config';
    $cacheDir   = $homeDir . '/.cache';
    $dataDir    = $homeDir . '/.local/share';
    $runtimeDir = $tmpDir . '/runtime';

    foreach ([$profileDir, $homeDir, $configDir, $cacheDir, $dataDir, $runtimeDir] as $dir) {
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            cert_pdf_rrmdir($tmpDir);
            throw new RuntimeException('Unable to create browser temp dir: ' . $dir);
        }
    }

    if (file_put_contents($htmlFile, $html) === false) {
        cert_pdf_rrmdir($tmpDir);
        throw new RuntimeException('Unable to write temp HTML file.');
    }

    $envPrefix = implode(' ', [
        'HOME=' . escapeshellarg($homeDir),
        'XDG_CONFIG_HOME=' . escapeshellarg($configDir),
        'XDG_CACHE_HOME=' . escapeshellarg($cacheDir),
        'XDG_DATA_HOME=' . escapeshellarg($dataDir),
        'XDG_RUNTIME_DIR=' . escapeshellarg($runtimeDir),
    ]);

    $cmd = $envPrefix . ' ' . implode(' ', [
        escapeshellarg($browserBin),
        '--headless=new',
        '--disable-gpu',
        '--no-sandbox',
        '--disable-dev-shm-usage',
        '--disable-crash-reporter',
        '--disable-breakpad',
        '--no-first-run',
        '--no-default-browser-check',
        '--hide-scrollbars',
        '--allow-file-access-from-files',
        '--enable-local-file-accesses',
        '--run-all-compositor-stages-before-draw',
        '--virtual-time-budget=3000',
        '--user-data-dir=' . escapeshellarg($profileDir),
        '--print-to-pdf=' . escapeshellarg($pdfFile),
        escapeshellarg('file://' . $htmlFile),
    ]);

    $output = [];
    $code = 0;
    exec($cmd . ' 2>&1', $output, $code);

    if ($code !== 0 || !is_file($pdfFile) || filesize($pdfFile) < 1000) {
        $err = trim(implode("\n", $output));
        cert_pdf_rrmdir($tmpDir);
        throw new RuntimeException('Chromium print-to-pdf failed: ' . $err);
    }

    $pdfBinary = file_get_contents($pdfFile);
    cert_pdf_rrmdir($tmpDir);

    if ($pdfBinary === false || $pdfBinary === '') {
        throw new RuntimeException('Chromium generated empty PDF.');
    }

    return $pdfBinary;
}

function cert_pdf_pick_qr_data_uri(string $verifyUrl): string
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

$uid = trim((string)($_GET['uid'] ?? $_GET['cert_uid'] ?? ''));
if ($uid === '') {
    cert_pdf_fail('Missing uid or cert_uid.');
}

$download = (int)($_GET['download'] ?? 0) === 1;
$debug    = (int)($_GET['debug'] ?? 0) === 1;

try {
    $pdo = rwa_db();
    $row = cert_pdf_load_row($pdo, $uid);

    if (!$row) {
        cert_pdf_fail('Certificate not found.', 404);
    }

    if (empty($row['rwa_code']) && !empty($row['rwa_type'])) {
        $row['rwa_code'] = (string)$row['rwa_type'];
    }

    if (trim((string)($row['fingerprint_hash'] ?? '')) === '') {
        $row['fingerprint_hash'] = cert_pdf_compute_fingerprint($row);
        try {
            $up = $pdo->prepare("
                UPDATE poado_rwa_certs
                SET fingerprint_hash = :fp, updated_at = UTC_TIMESTAMP()
                WHERE id = :id
                LIMIT 1
            ");
            $up->execute([
                ':fp' => $row['fingerprint_hash'],
                ':id' => $row['id'],
            ]);
        } catch (Throwable $ignore) {
        }
    }

    if ((string)$row['cert_uid'] === 'RK92-EMA-20260327-REAL0001') {
        $verifyUrl = 'https://adoptgold.app/rwa/cert/verify.php?uid=RK92-EMA-20260327-REAL0001';
    } else {
        $verifyUrl = trim((string)($row['verify_url'] ?? ''));
        if ($verifyUrl === '') {
            $verifyUrl = cert_pdf_abs_url('/rwa/cert/verify.php?uid=' . rawurlencode((string)$row['cert_uid']));
        }
    }

    $qrDataUri = cert_pdf_pick_qr_data_uri($verifyUrl);

    $html = poado_cert_render_pdf_html($row, [
        'verify_url'  => $verifyUrl,
        'qr_data_uri' => $qrDataUri,
    ]);

    if ($debug) {
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    $browserBin = cert_pdf_find_browser_bin();
    if (!$browserBin) {
        throw new RuntimeException('Chrome/Chromium binary not found.');
    }

    $pdfBinary = cert_pdf_render_via_chromium($html, $browserBin);

    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($pdfBinary));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    header(
        'Content-Disposition: ' .
        ($download ? 'attachment' : 'inline') .
        '; filename="' . rawurlencode((string)$row['cert_uid']) . '.pdf"'
    );
    echo $pdfBinary;
    exit;
} catch (Throwable $e) {
    if (function_exists('poado_error')) {
        poado_error('rwa_cert', 'pdf_render_failed', [
            'uid'       => $uid,
            'message'   => $e->getMessage(),
            'trace'     => $e->getTraceAsString(),
            'endpoint'  => '/rwa/cert/pdf.php',
            'severity'  => 'error',
            'category'  => 'pdf',
        ]);
    }
    cert_pdf_fail('PDF render failed: ' . $e->getMessage(), 500);
}
