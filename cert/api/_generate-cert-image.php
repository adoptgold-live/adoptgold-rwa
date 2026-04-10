<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/api/_generate-cert-image.php
 *
 * Production-grade NFT image generator for RWA Cert Dashboard v3.
 *
 * Locked rules:
 * - cert type uses core color only
 * - template source root: /var/www/html/public/rwa/metadata/nft/
 * - QR helper must use: /var/www/html/public/rwa/cert/api/_qr-local.php
 * - QR target URL must be:
 *   https://adoptgold.app/rwa/cert/verify.php?uid={CERT_UID}
 * - QR placement must use the locked fixed pixel map from:
 *   /var/www/html/public/rwa/cert/api/_meta-image-map.php
 *
 * Output:
 * - final nft/image.png
 * - optional qr/verify-qr.png
 */

require_once '/var/www/html/public/rwa/cert/api/_meta-image-map.php';
require_once _once '/var/www/html/public/rwa/cert/api/_qr-local.php';

if (!function_exists('poado_cert_generate_image_ensure_dir')) {
    function poado_cert_generate_image_ensure_dir(string $dir): void
    {
        if ($dir === '') {
            throw new InvalidArgumentException('Directory path cannot be empty.');
        }

        if (is_dir($dir)) {
            return;
        }

        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create directory: ' . $dir);
        }
    }
}

if (!function_exists('poado_cert_generate_image_require_gd')) {
    function poado_cert_generate_image_require_gd(): void
    {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('GD extension is required for certificate image generation.');
        }

        foreach ([
            'imagecreatefrompng',
            'imagepng',
            'imagecopyresampled',
            'imagecreatetruecolor',
            'imagefilledrectangle',
        ] as $fn) {
            if (!function_exists($fn)) {
                throw new RuntimeException('Required GD function missing: ' . $fn);
            }
        }
    }
}

if (!function_exists('poado_cert_generate_image_load_png')) {
    function poado_cert_generate_image_load_png(string $path): GdImage
    {
        if (!is_file($path)) {
            throw new RuntimeException('PNG file not found: ' . $path);
        }

        $img = @imagecreatefrompng($path);
        if (!$img instanceof GdImage) {
            throw new RuntimeException('Failed to load PNG: ' . $path);
        }

        imagealphablending($img, true);
        imagesavealpha($img, true);

        return $img;
    }
}

if (!function_exists('poado_cert_generate_image_save_png')) {
    function poado_cert_generate_image_save_png(GdImage $img, string $path): void
    {
        poado_cert_generate_image_ensure_dir(dirname($path));

        if (!imagepng($img, $path, 9)) {
            throw new RuntimeException('Failed to save PNG: ' . $path);
        }
    }
}

if (!function_exists('poado_cert_generate_image_qr_svg_from_helper')) {
    /**
     * Read QR SVG from canonical qr.php helper.
     * Supports several helper variants defensively.
     */
    function poado_cert_generate_image_qr_svg_from_helper(string $verifyUrl): string
    {
        $verifyUrl = trim($verifyUrl);
        if ($verifyUrl === '') {
            throw new InvalidArgumentException('Verify URL is required for QR generation.');
        }

        $svg = '';

        if (function_exists('poado_qr_svg')) {
            $svg = (string) cert_local_qr_svg_string($verifyUrl);
        } elseif (function_exists('poado_qr_svg_string')) {
            $svg = (string) poado_qr_svg_string($verifyUrl);
        } elseif (function_exists('cert_local_qr_svg_data_uri')) {
            $dataUri = (string) cert_local_qr_svg_data_uri($verifyUrl);
            if (preg_match('#^data:image/svg\+xml(?:;charset=[^;]+)?(?:;base64)?,(.+)$#', $dataUri, $m)) {
                $payload = $m[1];
                $decoded = base64_decode($payload, true);
                $svg = $decoded !== false ? $decoded : rawurldecode($payload);
            }
        }

        $svg = trim($svg);
        if ($svg === '') {
            throw new RuntimeException(
                'Canonical QR helper did not return SVG. Check /var/www/html/public/rwa/cert/api/_qr-local.php'
            );
        }

        return $svg;
    }
}

if (!function_exists('poado_cert_generate_image_svg_to_png')) {
    /**
     * Convert SVG QR into PNG with crisp rendering.
     * Tries Imagick first, then CLI fallbacks.
     */
    function poado_cert_generate_image_svg_to_png(string $svg, string $pngPath, int $targetSize): void
    {
        poado_cert_generate_image_ensure_dir(dirname($pngPath));

        if ($svg === '') {
            throw new InvalidArgumentException('QR SVG content is empty.');
        }

        if ($targetSize <= 0) {
            throw new InvalidArgumentException('QR target size must be > 0.');
        }

        if (extension_loaded('imagick') && class_exists('Imagick')) {
            try {
                $img = new Imagick();
                $img->setBackgroundColor(new ImagickPixel('white'));
                $img->readImageBlob($svg);
                $img->setImageFormat('png');
                $img->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                $img->resizeImage($targetSize, $targetSize, Imagick::FILTER_POINT, 1, true);
                $ok = $img->writeImage($pngPath);
                $img->clear();
                $img->destroy();

                if ($ok && is_file($pngPath) && filesize($pngPath) > 0) {
                    return;
                }
            } catch (Throwable $e) {
                // fall through
            }
        }

        $tmpBase = tempnam(sys_get_temp_dir(), 'poado_qr_svg_');
        if ($tmpBase === false) {
            throw new RuntimeException('Failed to allocate temporary QR SVG file.');
        }

        $tmpSvg = $tmpBase . '.svg';
        @unlink($tmpBase);

        if (file_put_contents($tmpSvg, $svg) === false) {
            throw new RuntimeException('Failed to write temporary QR SVG file.');
        }

        $commands = [
            'rsvg-convert -b white -w ' . (int)$targetSize . ' -h ' . (int)$targetSize
                . ' ' . escapeshellarg($tmpSvg)
                . ' -o ' . escapeshellarg($pngPath),
            'convert -background white -alpha remove -alpha off -filter point -resize '
                . (int)$targetSize . 'x' . (int)$targetSize
                . ' ' . escapeshellarg($tmpSvg)
                . ' ' . escapeshellarg($pngPath),
            'magick convert -background white -alpha remove -alpha off -filter point -resize '
                . (int)$targetSize . 'x' . (int)$targetSize
                . ' ' . escapeshellarg($tmpSvg)
                . ' ' . escapeshellarg($pngPath),
        ];

        $success = false;
        foreach ($commands as $cmd) {
            @exec($cmd . ' 2>/dev/null', $out, $code);
            if ($code === 0 && is_file($pngPath) && filesize($pngPath) > 0) {
                $success = true;
                break;
            }
        }

        @unlink($tmpSvg);

        if (!$success) {
            throw new RuntimeException(
                'Failed to convert QR SVG to PNG. Install Imagick or rsvg-convert/ImageMagick.'
            );
        }
    }
}

if (!function_exists('poado_cert_generate_image_normalize_qr_png')) {
    /**
     * Normalize QR PNG onto a square white canvas with no transparency drift.
     */
    function poado_cert_generate_image_normalize_qr_png(string $sourcePng, string $outputPng, int $size): void
    {
        $src = poado_cert_generate_image_load_png($sourcePng);

        $canvas = imagecreatetruecolor($size, $size);
        if (!$canvas instanceof GdImage) {
            imagedestroy($src);
            throw new RuntimeException('Failed to allocate QR normalization canvas.');
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $size, $size, $white);

        imagealphablending($canvas, true);
        imagesavealpha($canvas, false);

        imagecopyresampled(
            $canvas,
            $src,
            0,
            0,
            0,
            0,
            $size,
            $size,
            imagesx($src),
            imagesy($src)
        );

        poado_cert_generate_image_save_png($canvas, $outputPng);

        imagedestroy($src);
        imagedestroy($canvas);
    }
}

if (!function_exists('poado_cert_generate_image_make_verify_qr_png')) {
    /**
     * Generate final production verify QR PNG using canonical qr.php helper.
     *
     * @return array<string,mixed>
     */
    function poado_cert_generate_image_make_verify_qr_png(
        string $certUid,
        string $qrOutputPath,
        int $qrSize
    ): array {
        $certUid = trim($certUid);
        if ($certUid === '') {
            throw new InvalidArgumentException('CERT_UID is required.');
        }

        $verifyUrl = poado_cert_verify_url($certUid);
        $svg = poado_cert_generate_image_qr_svg_from_helper($verifyUrl);

        $tmpBase = tempnam(sys_get_temp_dir(), 'poado_qr_png_');
        if ($tmpBase === false) {
            throw new RuntimeException('Failed to allocate temporary QR PNG file.');
        }

        $tmpPng = $tmpBase . '.png';
        @unlink($tmpBase);

        poado_cert_generate_image_svg_to_png($svg, $tmpPng, $qrSize);
        poado_cert_generate_image_normalize_qr_png($tmpPng, $qrOutputPath, $qrSize);

        @unlink($tmpPng);

        return [
            'verify_url' => $verifyUrl,
            'qr_png_path' => $qrOutputPath,
            'size' => $qrSize,
        ];
    }
}

if (!function_exists('poado_cert_generate_image_composite')) {
    /**
     * Composite QR PNG into locked template at exact fixed pixel position.
     *
     * @return array<string,mixed>
     */
    function poado_cert_generate_image_composite(array $input): array
    {
        poado_cert_generate_image_require_gd();

        $certType = strtolower(trim((string)($input['cert_type'] ?? '')));
        $certUid = trim((string)($input['cert_uid'] ?? ''));
        $outputPath = trim((string)($input['output_path'] ?? ''));
        $qrOutputPath = trim((string)($input['qr_output_path'] ?? ''));

        if ($certType === '') {
            throw new InvalidArgumentException('cert_type is required.');
        }
        if ($certUid === '') {
            throw new InvalidArgumentException('cert_uid is required.');
        }
        if ($outputPath === '') {
            throw new InvalidArgumentException('output_path is required.');
        }

        $cfg = poado_cert_meta_image_config($certType);
        $templatePath = poado_cert_meta_template_path($certType);

        $qrX = (int)($cfg['qr']['x'] ?? 0);
        $qrY = (int)($cfg['qr']['y'] ?? 0);
        $qrSize = (int)($cfg['qr']['size'] ?? 0);

        if ($qrSize <= 0) {
            throw new RuntimeException('Invalid QR size for cert type: ' . $certType);
        }

        if ($qrOutputPath === '') {
            $qrOutputPath = preg_replace('#/+#', '/', dirname($outputPath) . '/../qr/verify-qr.png') ?: '';
            if ($qrOutputPath === '') {
                throw new RuntimeException('Unable to resolve qr_output_path.');
            }
        }

        poado_cert_generate_image_ensure_dir(dirname($outputPath));
        poado_cert_generate_image_ensure_dir(dirname($qrOutputPath));

        $qrInfo = poado_cert_generate_image_make_verify_qr_png($certUid, $qrOutputPath, $qrSize);

        $template = poado_cert_generate_image_load_png($templatePath);
        $qrImg = poado_cert_generate_image_load_png($qrOutputPath);

        $templateWidth = imagesx($template);
        $templateHeight = imagesy($template);

        if ($qrX < 0 || $qrY < 0 || ($qrX + $qrSize) > $templateWidth || ($qrY + $qrSize) > $templateHeight) {
            imagedestroy($template);
            imagedestroy($qrImg);
            throw new RuntimeException(
                'QR placement exceeds template bounds for cert type: ' . $certType
            );
        }

        imagealphablending($template, true);
        imagesavealpha($template, true);

        imagecopyresampled(
            $template,
            $qrImg,
            $qrX,
            $qrY,
            0,
            0,
            $qrSize,
            $qrSize,
            imagesx($qrImg),
            imagesy($qrImg)
        );

        poado_cert_generate_image_save_png($template, $outputPath);

        imagedestroy($template);
        imagedestroy($qrImg);

        return [
            'ok' => true,
            'cert_uid' => $certUid,
            'cert_type' => $certType,
            'rwa_key' => (string)$cfg['rwa_key'],
            'rwa_code' => (string)$cfg['rwa_code'],
            'family' => (string)$cfg['family'],
            'template_file' => (string)$cfg['file'],
            'template_path' => $templatePath,
            'verify_url' => (string)$qrInfo['verify_url'],
            'qr_output_path' => $qrOutputPath,
            'image_output_path' => $outputPath,
            'qr' => [
                'x' => $qrX,
                'y' => $qrY,
                'size' => $qrSize,
            ],
            'template_size' => [
                'width' => $templateWidth,
                'height' => $templateHeight,
            ],
        ];
    }
}

if (!function_exists('poado_cert_generate_cert_image')) {
    /**
     * Backward-compatible public wrapper.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    function poado_cert_generate_cert_image(array $input): array
    {
        return poado_cert_generate_image_composite($input);
    }
}
