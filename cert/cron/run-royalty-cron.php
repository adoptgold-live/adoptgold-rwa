<?php
declare(strict_types=1);

/**
 * POAdo RWA Cert Engine
 * File: /rwa/cert/cron/run-royalty-cron.php
 *
 * Purpose:
 * - Canonical cron orchestrator for the full royalty automation chain
 * - Runs, in order:
 *     1) fetch-getgems-sales.php
 *     2) scan-getgems-sales.php
 *     3) run-royalty-pipeline.php
 *
 * Important:
 * - CLI only
 * - No browser/session dependency
 * - This is the cron-safe automation entrypoint
 *
 * Suggested cron:
 *   php /var/www/html/public/rwa/cert/cron/run-royalty-cron.php
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/bootstrap.php';

if (!function_exists('poado_rc_out')) {
    function poado_rc_out(array $payload, int $status = 200): void
    {
        if (PHP_SAPI !== 'cli') {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
        }
        exit;
    }
}

if (!function_exists('poado_rc_include_json_file')) {
    function poado_rc_include_json_file(string $path): array
    {
        if (!is_file($path)) {
            return [
                'ok' => false,
                'error' => 'missing_file',
                'message' => 'File not found.',
                'file' => $path,
            ];
        }

        ob_start();
        try {
            include $path;
            $raw = (string)ob_get_clean();
        } catch (Throwable $e) {
            ob_end_clean();
            return [
                'ok' => false,
                'error' => 'execution_failed',
                'message' => $e->getMessage(),
                'file' => $path,
            ];
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return [
                'ok' => false,
                'error' => 'invalid_json_response',
                'message' => 'Included file did not return valid JSON.',
                'file' => $path,
                'raw' => $raw,
            ];
        }

        return $json;
    }
}

if (!function_exists('poado_rc_run_cli_script')) {
    function poado_rc_run_cli_script(string $path, array $args = []): array
    {
        if (!is_file($path)) {
            return [
                'ok' => false,
                'error' => 'missing_file',
                'message' => 'Script not found.',
                'file' => $path,
            ];
        }

        $cmd = array_merge(['php', $path], $args);
        $escaped = array_map('escapeshellarg', $cmd);
        $command = implode(' ', $escaped) . ' 2>&1';

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        $raw = trim(implode("\n", $output));
        $json = json_decode($raw, true);

        if (is_array($json)) {
            $json['_exit_code'] = $exitCode;
            $json['_command'] = $command;
            return $json;
        }

        return [
            'ok' => $exitCode === 0,
            'error' => $exitCode === 0 ? null : 'script_failed',
            'message' => $exitCode === 0 ? 'Script executed.' : 'Script execution failed.',
            'file' => $path,
            '_exit_code' => $exitCode,
            '_command' => $command,
            'raw' => $raw,
        ];
    }
}

try {
    if (PHP_SAPI !== 'cli') {
        poado_rc_out([
            'ok' => false,
            'error' => 'cli_only',
            'message' => 'This cron orchestrator is CLI-only.',
        ], 403);
    }

    $root = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    $cronDir = $root . '/rwa/cert/cron';
    $apiDir = $root . '/rwa/cert/api';

    $fetchScript = $cronDir . '/fetch-getgems-sales.php';
    $scanScript = $cronDir . '/scan-getgems-sales.php';
    $pipelineScript = $apiDir . '/run-royalty-pipeline.php';

    $startedAt = gmdate('Y-m-d H:i:s');
    $steps = [];

    /**
     * Step 1: fetch upstream sales payload
     */
    $steps['fetch_getgems_sales'] = poado_rc_run_cli_script($fetchScript);

    /**
     * Step 2: scan payload into poado_rwa_royalty_events_v2
     */
    $steps['scan_getgems_sales'] = poado_rc_run_cli_script($scanScript);

    /**
     * Step 3: run allocation pipeline
     *
     * run-royalty-pipeline.php was originally browser/admin-session oriented.
     * For cron safety, this orchestrator tries to include it and expects JSON.
     *
     * Recommended future improvement:
     * - create a cron-safe variant that does not require session/CSRF.
     */
    $steps['run_royalty_pipeline'] = [
        'ok' => false,
        'error' => 'cron_unsafe_endpoint',
        'message' => 'run-royalty-pipeline.php currently depends on session/CSRF and should be converted into a cron-safe service runner.',
        'file' => $pipelineScript,
    ];

    $allOk = true;
    foreach ($steps as $step) {
        if (empty($step['ok'])) {
            $allOk = false;
            break;
        }
    }

    poado_rc_out([
        'ok' => $allOk,
        'message' => $allOk ? 'Royalty cron chain completed successfully.' : 'Royalty cron chain completed with warnings/errors.',
        'started_at' => $startedAt,
        'finished_at' => gmdate('Y-m-d H:i:s'),
        'steps' => $steps,
        'next_hardening_note' => 'Create a cron-safe royalty pipeline runner without session/CSRF so allocation build steps can run fully unattended.',
    ], $allOk ? 200 : 207);

} catch (Throwable $e) {
    poado_rc_out([
        'ok' => false,
        'error' => 'server_error',
        'message' => 'Failed to run royalty cron chain.',
        'details' => $e->getMessage(),
    ], 500);
}