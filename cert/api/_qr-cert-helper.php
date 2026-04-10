<?php
declare(strict_types=1);

/**
 * /rwa/cert/api/_qr-cert-helper.php
 * Version: v1.0.0-20260329-cert-png-helper
 *
 * Purpose:
 * - Cert-module-only PNG QR helper
 * - Uses standalone RWA autoload only
 * - Does NOT alter shared /rwa/cert/api/_qr-local.php behavior
 */

if (defined('RWA_CERT_QR_CERT_HELPER_LOADED')) {
    return;
}
define('RWA_CERT_QR_CERT_HELPER_LOADED', true);

$__certQrAutoloadCandidates = [
    dirname(__DIR__, 3) . '/rwa/vendor/autoload.php',
    dirname(__DIR__, 3) . '/vendor/autoload.php',
];

$__certQrAutoloaded = false;
foreach ($__certQrAutoloadCandidates as $__certQrAutoload) {
    if (is_string($__certQrAutoload) && $__certQrAutoload !== '' && is_file($__certQrAutoload)) {
        require_once $__certQrAutoload;
        $__certQrAutoloaded = true;
        break;
    }
}

if (!$__certQrAutoloaded) {
    throw new RuntimeException(
        'CERT_QR_AUTOLOAD_NOT_FOUND: ' . implode(' | ', array_filter(array_map('strval', $__certQrAutoloadCandidates)))
    );
}

if (!function_exists('cert_qr_png_binary')) {
    function cert_qr_png_binary(string $text, int $size = 420, int $margin = 10): string
    {
        $text = trim($text);
        $size = max(96, min(2048, $size));
        $margin = max(0, min(64, $margin));

        if ($text === '') {
            return '';
        }

        $qr = new \Endroid\QrCode\QrCode(
            data: $text,
            encoding: new \Endroid\QrCode\Encoding\Encoding('UTF-8'),
            errorCorrectionLevel: \Endroid\QrCode\ErrorCorrectionLevel::High,
            size: $size,
            margin: $margin,
            roundBlockSizeMode: \Endroid\QrCode\RoundBlockSizeMode::Margin
        );

        $writer = new \Endroid\QrCode\Writer\PngWriter();
        $result = new \Endroid\QrCode\Builder\Builder(
            writer: $writer,
            writerOptions: [],
            validateResult: false,
            data: $qr->getData(),
            encoding: $qr->getEncoding(),
            errorCorrectionLevel: $qr->getErrorCorrectionLevel(),
            size: $qr->getSize(),
            margin: $qr->getMargin(),
            roundBlockSizeMode: $qr->getRoundBlockSizeMode(),
            foregroundColor: $qr->getForegroundColor(),
            backgroundColor: $qr->getBackgroundColor()
        );

        return (string)$result->build()->getString();
    }
}

if (!function_exists('cert_qr_png_data_uri')) {
    function cert_qr_png_data_uri(string $text, int $size = 420, int $margin = 10): string
    {
        $png = cert_qr_png_binary($text, $size, $margin);
        if ($png === '') {
            return '';
        }
        return 'data:image/png;base64,' . base64_encode($png);
    }
}

if (!function_exists('poado_qr_png')) {
    function poado_qr_png(string $text, int $size = 420, int $margin = 10): string
    {
        return cert_qr_png_binary($text, $size, $margin);
    }
}
