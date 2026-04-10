<?php
/**
 * /rwa/inc/core/pdf.php
 * POAdo / AdoptGold — Global PDF Helper (Dompdf)
 * Version: v1.0.0-20260318
 *
 * Locked rules:
 * - canonical standalone path: /rwa/inc/core/pdf.php
 * - canonical Composer autoload: /var/www/html/public/rwa/vendor/autoload.php
 * - no "use" statements
 * - safe for repeated include/require across all modules
 * - suitable for RWA modules, testers, APIs, admin tools, and shared UI blocks
 * - inline stream by default unless attachment=true is passed
 */

declare(strict_types=1);

if (!defined('POADO_RWA_PDF_VERSION')) {
    define('POADO_RWA_PDF_VERSION', 'v1.0.0-20260318');
}

if (!function_exists('poado_rwa_pdf_autoload_path')) {
    function poado_rwa_pdf_autoload_path(): string
    {
        return '/var/www/html/public/rwa/vendor/autoload.php';
    }
}

if (!function_exists('poado_rwa_pdf_bootstrap')) {
    function poado_rwa_pdf_bootstrap(): void
    {
        if (class_exists('\\Dompdf\\Dompdf')) {
            return;
        }

        $autoload = poado_rwa_pdf_autoload_path();
        if (is_file($autoload)) {
            require_once $autoload;
        }
    }
}

poado_rwa_pdf_bootstrap();

if (!function_exists('poado_rwa_pdf_assert_ready')) {
    function poado_rwa_pdf_assert_ready(): void
    {
        if (!class_exists('\\Dompdf\\Dompdf')) {
            throw new \RuntimeException('Dompdf not loaded. Missing /rwa/vendor/autoload.php');
        }
        if (!class_exists('\\Dompdf\\Options')) {
            throw new \RuntimeException('Dompdf Options class not found.');
        }
    }
}

if (!function_exists('poado_rwa_pdf_options')) {
    /**
     * Build Dompdf options with safe defaults for global module usage.
     */
    function poado_rwa_pdf_options(array $opts = []): \Dompdf\Options
    {
        poado_rwa_pdf_bootstrap();
        poado_rwa_pdf_assert_ready();

        $options = new \Dompdf\Options();

        $options->set('isRemoteEnabled', (bool)($opts['is_remote_enabled'] ?? true));
        $options->set('isHtml5ParserEnabled', (bool)($opts['is_html5_parser_enabled'] ?? true));
        $options->set('defaultFont', (string)($opts['default_font'] ?? 'DejaVu Sans'));
        $options->set('isPhpEnabled', (bool)($opts['is_php_enabled'] ?? false));
        $options->set('isJavascriptEnabled', (bool)($opts['is_javascript_enabled'] ?? true));

        if (isset($opts['dpi'])) {
            $options->set('dpi', (int)$opts['dpi']);
        }

        if (isset($opts['default_media_type'])) {
            $options->set('defaultMediaType', (string)$opts['default_media_type']);
        }

        return $options;
    }
}

if (!function_exists('poado_rwa_pdf_instance')) {
    /**
     * Create a configured Dompdf instance.
     */
    function poado_rwa_pdf_instance(array $opts = []): \Dompdf\Dompdf
    {
        $options = poado_rwa_pdf_options($opts);
        return new \Dompdf\Dompdf($options);
    }
}

if (!function_exists('poado_rwa_pdf_render')) {
    /**
     * Render HTML into a Dompdf instance and return it.
     */
    function poado_rwa_pdf_render(string $html, array $opts = []): \Dompdf\Dompdf
    {
        $html = trim($html);
        if ($html === '') {
            throw new \InvalidArgumentException('PDF HTML cannot be empty.');
        }

        $paper = (string)($opts['paper'] ?? 'A4');
        $orientation = (string)($opts['orientation'] ?? 'portrait');

        $dompdf = poado_rwa_pdf_instance($opts);
        $dompdf->setPaper($paper, $orientation);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        return $dompdf;
    }
}

if (!function_exists('poado_rwa_pdf_output')) {
    /**
     * Return raw PDF binary string.
     */
    function poado_rwa_pdf_output(string $html, array $opts = []): string
    {
        $dompdf = poado_rwa_pdf_render($html, $opts);
        $output = $dompdf->output();

        if (!is_string($output) || $output === '') {
            throw new \RuntimeException('Failed to generate PDF output.');
        }

        return $output;
    }
}

if (!function_exists('poado_rwa_pdf_stream')) {
    /**
     * Stream PDF to browser.
     *
     * Options:
     * - paper: A4, letter, etc.
     * - orientation: portrait|landscape
     * - attachment: true=download, false=inline
     * - default_font
     * - is_remote_enabled
     * - is_html5_parser_enabled
     * - is_php_enabled
     * - is_javascript_enabled
     * - dpi
     * - default_media_type
     */
    function poado_rwa_pdf_stream(string $html, string $filename, array $opts = []): void
    {
        $filename = trim($filename);
        if ($filename === '') {
            $filename = 'document.pdf';
        }
        if (!preg_match('/\.pdf$/i', $filename)) {
            $filename .= '.pdf';
        }

        $attachment = (bool)($opts['attachment'] ?? false);

        $dompdf = poado_rwa_pdf_render($html, $opts);
        $dompdf->stream($filename, ['Attachment' => $attachment]);
        exit;
    }
}

if (!function_exists('poado_rwa_pdf_save')) {
    /**
     * Save rendered PDF to a file path.
     */
    function poado_rwa_pdf_save(string $html, string $absolutePath, array $opts = []): string
    {
        $absolutePath = trim($absolutePath);
        if ($absolutePath === '') {
            throw new \InvalidArgumentException('PDF save path cannot be empty.');
        }

        $dir = dirname($absolutePath);
        if (!is_dir($dir)) {
            throw new \RuntimeException('PDF save directory does not exist: ' . $dir);
        }

        $pdf = poado_rwa_pdf_output($html, $opts);
        $bytes = @file_put_contents($absolutePath, $pdf);

        if ($bytes === false) {
            throw new \RuntimeException('Failed to save PDF file: ' . $absolutePath);
        }

        return $absolutePath;
    }
}

if (!function_exists('poado_rwa_pdf_download_headers')) {
    /**
     * Emit download/inline headers manually when returning custom PDF output.
     */
    function poado_rwa_pdf_download_headers(string $filename, bool $attachment = false): void
    {
        $filename = trim($filename);
        if ($filename === '') {
            $filename = 'document.pdf';
        }
        if (!preg_match('/\.pdf$/i', $filename)) {
            $filename .= '.pdf';
        }

        if (!headers_sent()) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: ' . ($attachment ? 'attachment' : 'inline') . '; filename="' . str_replace('"', '', $filename) . '"');
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
        }
    }
}