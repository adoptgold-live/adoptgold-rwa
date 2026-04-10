<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/inc/core/qr.php
 * Version: v2.2.1-20260330-endroid-autoload-safe
 *
 * Purpose:
 * - load Composer autoload safely
 * - provide SVG / PNG QR helpers
 * - support Endroid QrCode create() API
 * - fail clearly if vendor/autoload.php or Endroid classes are unavailable
 */

if (!function_exists('poado_qr_require_autoload')) {
    function poado_qr_require_autoload(): void
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }

        $candidates = [
            dirname(__DIR__, 2) . '/vendor/autoload.php',      // /var/www/html/public/rwa/vendor/autoload.php
            dirname(__DIR__, 3) . '/vendor/autoload.php',      // /var/www/html/public/vendor/autoload.php
            dirname(__DIR__, 4) . '/vendor/autoload.php',      // /var/www/html/vendor/autoload.php
        ];

        foreach ($candidates as $file) {
            if (is_file($file)) {
                require_once $file;
                $loaded = true;
                break;
            }
        }
    }
}

poado_qr_require_autoload();

if (!class_exists(\Endroid\QrCode\QrCode::class)) {
    throw new RuntimeException('ENDROID_QRCODE_CLASS_NOT_FOUND');
}

if (!class_exists(\Endroid\QrCode\Writer\PngWriter::class)) {
    throw new RuntimeException('ENDROID_PNG_WRITER_CLASS_NOT_FOUND');
}

if (!class_exists(\Endroid\QrCode\Writer\SvgWriter::class)) {
    throw new RuntimeException('ENDROID_SVG_WRITER_CLASS_NOT_FOUND');
}

use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelMedium;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode\RoundBlockSizeModeMargin;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;

if (!function_exists('poado_qr_build')) {
    function poado_qr_build(string $data, int $size = 320, int $margin = 10): QrCode
    {
        $data = trim($data);
        if ($data === '') {
            throw new InvalidArgumentException('QR_DATA_REQUIRED');
        }

        if ($size < 64) {
            $size = 64;
        }
        if ($margin < 0) {
            $margin = 0;
        }

        return QrCode::create($data)
            ->setEncoding(new Encoding('UTF-8'))
            ->setErrorCorrectionLevel(new ErrorCorrectionLevelMedium())
            ->setSize($size)
            ->setMargin($margin)
            ->setRoundBlockSizeMode(new RoundBlockSizeModeMargin())
            ->setForegroundColor(new Color(0, 0, 0))
            ->setBackgroundColor(new Color(255, 255, 255));
    }
}

if (!function_exists('poado_qr_svg')) {
    function poado_qr_svg(string $data, int $size = 320, int $margin = 10): string
    {
        $qr = poado_qr_build($data, $size, $margin);
        $writer = new SvgWriter();
        $result = $writer->write($qr);
        return $result->getString();
    }
}

if (!function_exists('poado_qr_svg_data_uri')) {
    function poado_qr_svg_data_uri(string $data, int $size = 320, int $margin = 10): string
    {
        $svg = poado_qr_svg($data, $size, $margin);
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}

if (!function_exists('poado_qr_png_binary')) {
    function poado_qr_png_binary(string $data, int $size = 320, int $margin = 10): string
    {
        $qr = poado_qr_build($data, $size, $margin);
        $writer = new PngWriter();
        $result = $writer->write($qr);
        return $result->getString();
    }
}

if (!function_exists('poado_qr_png_data_uri')) {
    function poado_qr_png_data_uri(string $data, int $size = 320, int $margin = 10): string
    {
        $bin = poado_qr_png_binary($data, $size, $margin);
        return 'data:image/png;base64,' . base64_encode($bin);
    }
}

if (!function_exists('qr_svg_data_uri')) {
    function qr_svg_data_uri(string $data, int $size = 320, int $margin = 10): string
    {
        return poado_qr_svg_data_uri($data, $size, $margin);
    }
}

if (!function_exists('qr_png_data_uri')) {
    function qr_png_data_uri(string $data, int $size = 320, int $margin = 10): string
    {
        return poado_qr_png_data_uri($data, $size, $margin);
    }
}


if (!function_exists('poado_qr_http_output_png')) {
    function poado_qr_http_output_png(string $data, int $size = 320, int $margin = 10): void
    {
        $bin = poado_qr_png_binary($data, $size, $margin);
        if (!headers_sent()) {
            header('Content-Type: image/png');
            header('Content-Length: ' . strlen($bin));
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('X-Content-Type-Options: nosniff');
        }
        echo $bin;
        exit;
    }
}

if (!function_exists('poado_qr_is_direct_http_request')) {
    function poado_qr_is_direct_http_request(): bool
    {
        if (PHP_SAPI === 'cli') {
            return false;
        }

        $self = isset($_SERVER['SCRIPT_FILENAME']) ? (string)$_SERVER['SCRIPT_FILENAME'] : '';
        if ($self === '') {
            return false;
        }

        return realpath($self) === realpath(__FILE__);
    }
}

if (poado_qr_is_direct_http_request()) {
    try {
        $text = trim((string)($_GET['text'] ?? ''));
        $size = (int)($_GET['size'] ?? 320);
        $margin = (int)($_GET['margin'] ?? 10);

        if ($text === '') {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'QR_TEXT_REQUIRED';
            exit;
        }

        poado_qr_http_output_png($text, $size, $margin);
    } catch (\Throwable $e) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo 'QR_RENDER_FAILED: ' . $e->getMessage();
        exit;
    }
}
