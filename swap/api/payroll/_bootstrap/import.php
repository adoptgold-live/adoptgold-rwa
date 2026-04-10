<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/swap/api/payroll/_bootstrap/import.php
 * Version: v1.0.0-20260409-swap-payroll-import-core
 *
 * Flow
 * - POST only
 * - multipart/form-data file field = file
 * - mode=preview  => parse only, no DB write
 * - mode=commit   => parse + insert batch/workers/days + import log
 */

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/parser.php';

function swap_payroll_import_handler(): void
{
    swap_payroll_require_post();

    $pdo = swap_payroll_pdo();
    $userId = swap_payroll_request_user_id();
    $mode = strtolower(trim((string)($_POST['mode'] ?? 'preview')));
    if (!in_array($mode, ['preview', 'commit'], true)) {
        $mode = 'preview';
    }

    $upload = swap_payroll_uploaded_file_or_fail('file');
    $tmpFile = $upload['tmp_name'];
    $fileName = $upload['name'];
    $fileSha1 = sha1_file($tmpFile) ?: '';

    try {
        $parsed = swap_payroll_parser_parse_file($tmpFile, $fileName);
        $parsed = swap_payroll_validate_parsed_payload($parsed);
    } catch (Throwable $e) {
        swap_payroll_log(
            $pdo,
            null,
            $fileName,
            'parse',
            'error',
            'Payroll parse failed',
            [
                'exception' => $e->getMessage(),
                'file_name' => $fileName,
            ],
            $userId > 0 ? $userId : null
        );

        swap_payroll_json_error('PAYROLL_PARSE_FAILED', 422, [
            'message' => $e->getMessage(),
            'file_name' => $fileName,
        ]);
    }

    $workers = is_array($parsed['workers'] ?? null) ? $parsed['workers'] : [];
    $summary = swap_payroll_import_build_summary($workers);

    if ($mode === 'preview') {
        swap_payroll_json_ok([
            'mode' => 'preview',
            'file_name' => $fileName,
            'site_name' => (string)$parsed['site_name'],
            'site_location' => (string)($parsed['site_location'] ?? ''),
            'claim_no' => (string)($parsed['claim_no'] ?? ''),
            'work_month' => (int)$parsed['work_month'],
            'work_year' => (int)$parsed['work_year'],
            'currency_code' => (string)($parsed['currency_code'] ?? 'MYR'),
            'summary' => $summary,
            'workers' => $workers,
        ]);
    }

    $batchRef = trim((string)($_POST['batch_ref'] ?? ''));
    if ($batchRef === '') {
        $batchRef = swap_payroll_batch_ref(
            (string)$parsed['site_name'],
            (int)$parsed['work_year'],
            (int)$parsed['work_month']
        );
    }

    try {
        $pdo->beginTransaction();

        $batchId = swap_payroll_insert_batch($pdo, [
            'batch_ref'          => $batchRef,
            'claim_no'           => (string)($parsed['claim_no'] ?? ''),
            'site_name'          => (string)$parsed['site_name'],
            'site_location'      => (string)($parsed['site_location'] ?? ''),
            'work_month'         => (int)$parsed['work_month'],
            'work_year'          => (int)$parsed['work_year'],
            'source_file_name'   => $fileName,
            'source_file_sha1'   => $fileSha1,
            'currency_code'      => (string)($parsed['currency_code'] ?? 'MYR'),
            'total_workers'      => (int)$summary['total_workers'],
            'total_hours'        => (string)$summary['total_hours'],
            'total_gross_amount' => (string)$summary['total_gross_amount'],
            'import_status'      => 'parsed',
            'review_status'      => 'pending',
            'created_by'         => $userId > 0 ? $userId : null,
            'meta_json'          => [
                'source' => 'swap_payroll_import_handler',
                'parser_version' => 'v1',
                'original_file_name' => $fileName,
            ],
        ]);

        $insertedWorkers = 0;
        $insertedDays = 0;

        foreach ($workers as $worker) {
            $validHours = swap_payroll_normalize_decimal($worker['valid_hours'] ?? $worker['total_hours'] ?? 0, 2);
            $rhrdUnits = swap_payroll_compute_rhrd_units($validHours);

            $workerId = swap_payroll_insert_worker($pdo, $batchId, [
                'line_no'          => (int)($worker['line_no'] ?? 0),
                'worker_name'      => (string)($worker['worker_name'] ?? ''),
                'identity_no'      => $worker['identity_no'] ?? null,
                'nationality_code' => $worker['nationality_code'] ?? null,
                'nationality_name' => $worker['nationality_name'] ?? null,
                'mobile_e164'      => $worker['mobile_e164'] ?? null,
                'employer_name'    => $worker['employer_name'] ?? null,
                'site_name'        => (string)($worker['site_name'] ?? $parsed['site_name']),
                'total_hours'      => $worker['total_hours'] ?? 0,
                'hourly_rate'      => $worker['hourly_rate'] ?? 0,
                'gross_amount'     => $worker['gross_amount'] ?? 0,
                'valid_hours'      => $validHours,
                'rhrd_units'       => $rhrdUnits,
                'review_status'    => 'pending',
                'review_note'      => null,
                'worker_user_id'   => !empty($worker['worker_user_id']) ? (int)$worker['worker_user_id'] : null,
                'meta_json'        => [
                    'imported_from' => $fileName,
                    'source_line'   => (int)($worker['line_no'] ?? 0),
                ],
            ]);
            $insertedWorkers++;

            $days = is_array($worker['days'] ?? null) ? $worker['days'] : [];
            foreach ($days as $day) {
                $hoursWorked = (float)swap_payroll_normalize_decimal($day['hours_worked'] ?? 0, 2);
                if ($hoursWorked <= 0) {
                    continue;
                }
                swap_payroll_insert_worker_day($pdo, $workerId, [
                    'work_date'       => (string)$day['work_date'],
                    'hours_worked'    => $day['hours_worked'] ?? 0,
                    'day_type'        => (string)($day['day_type'] ?? 'workday'),
                    'source_cell_ref' => $day['source_cell_ref'] ?? null,
                    'meta_json'       => [
                        'imported_from' => $fileName,
                    ],
                ]);
                $insertedDays++;
            }
        }

        swap_payroll_log(
            $pdo,
            $batchId,
            $fileName,
            'import',
            'info',
            'Payroll import success',
            [
                'batch_ref' => $batchRef,
                'workers'   => $insertedWorkers,
                'days'      => $insertedDays,
            ],
            $userId > 0 ? $userId : null
        );

        $pdo->commit();

        swap_payroll_json_ok([
            'mode' => 'commit',
            'batch_id' => $batchId,
            'batch_ref' => $batchRef,
            'file_name' => $fileName,
            'summary' => [
                'total_workers' => $insertedWorkers,
                'total_days' => $insertedDays,
                'total_hours' => $summary['total_hours'],
                'total_gross_amount' => $summary['total_gross_amount'],
            ],
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        swap_payroll_log(
            $pdo,
            null,
            $fileName,
            'import',
            'error',
            'Payroll import transaction failed',
            [
                'exception' => $e->getMessage(),
                'batch_ref' => $batchRef,
            ],
            $userId > 0 ? $userId : null
        );

        swap_payroll_json_error('PAYROLL_IMPORT_FAILED', 500, [
            'message' => $e->getMessage(),
            'file_name' => $fileName,
        ]);
    }
}

function swap_payroll_import_build_summary(array $workers): array
{
    $totalWorkers = 0;
    $totalHours = 0.0;
    $totalGross = 0.0;

    foreach ($workers as $worker) {
        $totalWorkers++;
        $totalHours += (float)swap_payroll_normalize_decimal($worker['total_hours'] ?? 0, 2);
        $totalGross += (float)swap_payroll_normalize_decimal($worker['gross_amount'] ?? 0, 2);
    }

    return [
        'total_workers' => $totalWorkers,
        'total_hours' => number_format($totalHours, 2, '.', ''),
        'total_gross_amount' => number_format($totalGross, 2, '.', ''),
    ];
}
