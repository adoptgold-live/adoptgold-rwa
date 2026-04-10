<?php
declare(strict_types=1);

/**
 * POAdo RWA Cert Engine
 * File: /rwa/cert/cron/run-royalty-master.php
 *
 * Purpose:
 * - Master royalty cron runner
 * - Runs in order:
 *   1) fetch-getgems-sales.php
 *   2) scan-getgems-sales.php
 *   3) run-royalty-pipeline-cron.php
 *
 * Notes:
 * - CLI safe
 * - Does NOT use exec()
 * - Includes child scripts directly
 */

$root = dirname(__DIR__, 3);
require_once $root . '/dashboard/inc/bootstrap.php';

if (!function_exists('poado_master_out')) {
    function poado_master_out(array $payload, int $status = 200): void
    {
        if (PHP_SAPI !== 'cli') {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }

        echo json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        ) . PHP_EOL;
        exit;
    }
}

if (!function_exists('poado_master_run_php_file')) {
    function poado_master_run_php_file(string $file): array
    {
        if (!is_file($file)) {
            return [
                'ok' => false,
                'error' => 'missing_file',
                'message' => 'Script file not found.',
                'file' => $file,
            ];
        }

        ob_start();
        try {
            include $file;
            $raw = trim((string)ob_get_clean());
        } catch (Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            return [
                'ok' => false,
                'error' => 'include_failed',
                'message' => $e->getMessage(),
                'file' => $file,
            ];
        }

        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            return [
                'ok' => (bool)($decoded['ok'] ?? false),
                'file' => $file,
                'parsed' => $decoded,
                'raw_output' => $raw,
            ];
        }

        return [
            'ok' => false,
            'error' => 'invalid_json_output',
            'message' => 'Included script did not return valid JSON.',
            'file' => $file,
            'raw_output' => $raw,
        ];
    }
}

try {
    if (PHP_SAPI !== 'cli') {
        poado_master_out([
            'ok' => false,
            'error' => 'cli_only',
            'message' => 'run-royalty-master.php is CLI-only.',
        ], 403);
    }

    $steps = [
        'fetch_getgems_sales' => __DIR__ . '/fetch-getgems-sales.php',
        'scan_getgems_sales' => __DIR__ . '/scan-getgems-sales.php',
        'run_royalty_pipeline' => __DIR__ . '/run-royalty-pipeline-cron.php',
    ];

    $startedAt = gmdate('Y-m-d H:i:s');
    $results = [];
    $allOk = true;

    foreach ($steps as $name => $file) {
        $result = poado_master_run_php_file($file);
        $results[$name] = $result;

        if (empty($result['ok'])) {
            $allOk = false;
        }
    }

    poado_master_out([
        'ok' => $allOk,
        'pipeline' => 'rwa-royalty-master',
        'started_at' => $startedAt,
        'finished_at' => gmdate('Y-m-d H:i:s'),
        'steps' => $results,
    ], $allOk ? 200 : 207);

} catch (Throwable $e) {
    poado_master_out([
        'ok' => false,
        'error' => 'server_error',
        'message' => 'Failed to run royalty master cron.',
        'details' => $e->getMessage(),
    ], 500);
}