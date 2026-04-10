<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/qr-preview.php
 * Version: v1.0.1-20260329-rwa-local-qr-preview
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/cert/api/_qr-local.php';

$uid  = trim((string)($_GET['uid'] ?? ''));
$size = max(64, min(1200, (int)($_GET['size'] ?? 200)));
$fmt  = strtolower(trim((string)($_GET['fmt'] ?? 'png')));

if ($uid === '') {
    header('Content-Type: text/plain; charset=utf-8');
    exit('Usage: /rwa/cert/qr-preview.php?uid=YOUR_UID&size=200&fmt=png');
}

$url = 'https://adoptgold.app/rwa/cert/verify.php?uid=' . rawurlencode($uid);

try {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    if ($fmt === 'svg') {
        header('Content-Type: image/svg+xml; charset=utf-8');
        echo cert_local_qr_svg_string($url, $size, 0);
        exit;
    }

    cert_local_qr_png_binary($url, $size, 0);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'QR ERROR: ' . $e->getMessage();
    exit;
}
