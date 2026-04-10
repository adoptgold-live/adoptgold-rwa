<?php
declare(strict_types=1);

if (function_exists('cert_local_qr_svg_string')) {
    return;
}

/**
 * /var/www/html/public/rwa/cert/api/_qr-local.php
 * Version: v1.3.1-20260330-rwa-vendor-autoload
 *
 * Cert-local QR helper.
 * Loads /var/www/html/public/rwa/vendor/autoload.php
 * Does NOT touch /rwa/cert/api/_qr-local.php
 */

function cert_local_qr_require_autoload(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    $candidates = [
        dirname(__DIR__, 2) . '/vendor/autoload.php', // /var/www/html/public/rwa/vendor/autoload.php
        dirname(__DIR__, 3) . '/vendor/autoload.php', // /var/www/html/public/vendor/autoload.php
        dirname(__DIR__, 4) . '/vendor/autoload.php', // /var/www/html/vendor/autoload.php
    ];

    foreach ($candidates as $file) {
        if (is_file($file)) {
            require_once $file;
            $loaded = true;
            return;
        }
    }

    throw new RuntimeException('CERT_LOCAL_AUTOLOAD_NOT_FOUND');
}

function cert_local_qr_png_binary(string $data, int $size = 420, int $margin = 10): string
{
    cert_local_qr_require_autoload();

    if (!class_exists(\Endroid\QrCode\QrCode::class)) {
        throw new RuntimeException('ENDROID_QRCODE_CLASS_NOT_FOUND');
    }

    $qr = \Endroid\QrCode\QrCode::create(trim($data))
        ->setSize(max(64, $size))
        ->setMargin(max(0, $margin));

    if (class_exists(\Endroid\QrCode\Writer\PngWriter::class)) {
        $writer = new \Endroid\QrCode\Writer\PngWriter();
        $result = $writer->write($qr);
        return $result->getString();
    }

    if (method_exists($qr, 'writeString')) {
        $bin = $qr->writeString();
        if (is_string($bin) && strlen($bin) > 100) {
            return $bin;
        }
    }

    throw new RuntimeException('CERT_LOCAL_PNG_OUTPUT_UNAVAILABLE');
}

function cert_local_qr_svg_string(string $data, int $size = 420, int $margin = 10): string
{
    cert_local_qr_require_autoload();

    if (!class_exists(\Endroid\QrCode\QrCode::class)) {
        throw new RuntimeException('ENDROID_QRCODE_CLASS_NOT_FOUND');
    }

    $qr = \Endroid\QrCode\QrCode::create(trim($data))
        ->setSize(max(64, $size))
        ->setMargin(max(0, $margin));

    if (class_exists(\Endroid\QrCode\Writer\SvgWriter::class)) {
        $writer = new \Endroid\QrCode\Writer\SvgWriter();
        $result = $writer->write($qr);
        return $result->getString();
    }

    if (method_exists($qr, 'writeString')) {
        $out = $qr->writeString();
        if (is_string($out) && str_contains($out, '<svg')) {
            return $out;
        }
    }

    return '';
}

function cert_local_qr_png_data_uri(string $data, int $size = 420, int $margin = 10): string
{
    return 'data:image/png;base64,' . base64_encode(cert_local_qr_png_binary($data, $size, $margin));
}

function cert_local_qr_svg_data_uri(string $data, int $size = 420, int $margin = 10): string
{
    $svg = cert_local_qr_svg_string($data, $size, $margin);
    return $svg === '' ? '' : 'data:image/svg+xml;base64,' . base64_encode($svg);
}

if (!function_exists('poado_qr_svg')) {
    function cert_local_qr_svg_string(string $data, int $size = 420, int $margin = 10): string
    {
        return cert_local_qr_svg_string($data, $size, $margin);
    }
}

if (!function_exists('cert_local_qr_svg_data_uri')) {
    function cert_local_qr_svg_data_uri(string $data, int $size = 420, int $margin = 10): string
    {
        return cert_local_qr_svg_data_uri($data, $size, $margin);
    }
}

if (!function_exists('cert_local_qr_png_data_uri')) {
    function cert_local_qr_png_data_uri(string $data, int $size = 420, int $margin = 10): string
    {
        return cert_local_qr_png_data_uri($data, $size, $margin);
    }
}