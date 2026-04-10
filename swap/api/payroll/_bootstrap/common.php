<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/swap/api/payroll/_bootstrap/common.php
 * Version: v1.0.0-20260409-swap-payroll-common
 */

if (defined('SWAP_PAYROLL_COMMON_BOOTSTRAPPED')) {
    return;
}
define('SWAP_PAYROLL_COMMON_BOOTSTRAPPED', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';

function swap_payroll_pdo(): PDO
{
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $GLOBALS['pdo'];
    }
    if (function_exists('db')) {
        $pdo = db();
        if ($pdo instanceof PDO) {
            return $pdo;
        }
    }
    throw new RuntimeException('SWAP_PAYROLL_PDO_UNAVAILABLE');
}

function swap_payroll_json_ok(array $data = [], int $status = 200): never
{
    if (function_exists('json_ok')) {
        json_ok($data, $status);
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => true, 'ts' => time()], $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function swap_payroll_json_error(string $error, int $status = 400, array $data = []): never
{
    if (function_exists('json_error')) {
        json_error($error, $status, $data);
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => false, 'error' => $error, 'ts' => time()], $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function swap_payroll_request_user_id(): int
{
    $candidates = [];

    if (!empty($_SESSION['rwa_user']) && is_array($_SESSION['rwa_user'])) {
        $candidates[] = $_SESSION['rwa_user']['id'] ?? null;
        $candidates[] = $_SESSION['rwa_user']['user_id'] ?? null;
    }

    if (!empty($_SESSION['user']) && is_array($_SESSION['user'])) {
        $candidates[] = $_SESSION['user']['id'] ?? null;
        $candidates[] = $_SESSION['user']['user_id'] ?? null;
    }

    foreach ($candidates as $v) {
        $id = (int)$v;
        if ($id > 0) {
            return $id;
        }
    }

    return 0;
}

function swap_payroll_require_post(): void
{
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'POST') {
        swap_payroll_json_error('METHOD_NOT_ALLOWED', 405);
    }
}

function swap_payroll_uploaded_file_or_fail(string $field = 'file'): array
{
    if (empty($_FILES[$field]) || !is_array($_FILES[$field])) {
        swap_payroll_json_error('PAYROLL_FILE_REQUIRED', 422);
    }

    $file = $_FILES[$field];
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        swap_payroll_json_error('PAYROLL_UPLOAD_FAILED', 422, ['upload_error' => $err]);
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    $name = (string)($file['name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        swap_payroll_json_error('PAYROLL_TMP_FILE_INVALID', 422);
    }

    return [
        'tmp_name' => $tmp,
        'name'     => $name,
        'size'     => (int)($file['size'] ?? 0),
        'type'     => (string)($file['type'] ?? ''),
    ];
}

function swap_payroll_normalize_decimal(mixed $value, int $scale = 2): string
{
    if ($value === null) {
        return number_format(0, $scale, '.', '');
    }

    $raw = trim((string)$value);
    if ($raw === '') {
        return number_format(0, $scale, '.', '');
    }

    $raw = str_replace([',', ' '], ['', ''], $raw);
    if (!is_numeric($raw)) {
        return number_format(0, $scale, '.', '');
    }

    return number_format((float)$raw, $scale, '.', '');
}

function swap_payroll_batch_ref(string $siteName, int $year, int $month): string
{
    $site = strtoupper(trim($siteName));
    $site = preg_replace('/[^A-Z0-9]+/', '-', $site);
    $site = trim((string)$site, '-');
    if ($site === '') {
        $site = 'UNKNOWN';
    }
    $site = substr($site, 0, 24);

    return sprintf(
        'PAYROLL-%04d%02d-%s-%s',
        $year,
        $month,
        $site,
        strtoupper(substr(sha1($siteName . '|' . $year . '|' . $month . '|' . microtime(true)), 0, 6))
    );
}

function swap_payroll_basis_ref(int $batchId, int $workerId): string
{
    return sprintf(
        'RHRD-BASIS-%06d-%06d-%s',
        $batchId,
        $workerId,
        strtoupper(substr(sha1((string)$batchId . '|' . (string)$workerId . '|' . microtime(true)), 0, 6))
    );
}

function swap_payroll_log(
    PDO $pdo,
    ?int $batchId,
    string $sourceFileName,
    string $actionName,
    string $logLevel,
    string $message,
    array $context = [],
    ?int $createdBy = null
): void {
    $sql = "
        INSERT INTO swap_payroll_import_logs
        (
            batch_id,
            source_file_name,
            action_name,
            log_level,
            message,
            context_json,
            created_by
        ) VALUES (
            :batch_id,
            :source_file_name,
            :action_name,
            :log_level,
            :message,
            :context_json,
            :created_by
        )
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':batch_id'         => $batchId,
        ':source_file_name' => $sourceFileName,
        ':action_name'      => $actionName,
        ':log_level'        => $logLevel,
        ':message'          => $message,
        ':context_json'     => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':created_by'       => $createdBy,
    ]);
}

function swap_payroll_insert_batch(PDO $pdo, array $payload): int
{
    $sql = "
        INSERT INTO swap_payroll_batches
        (
            batch_ref,
            claim_no,
            site_name,
            site_location,
            work_month,
            work_year,
            source_file_name,
            source_file_sha1,
            currency_code,
            total_workers,
            total_hours,
            total_gross_amount,
            import_status,
            review_status,
            created_by,
            meta_json
        ) VALUES (
            :batch_ref,
            :claim_no,
            :site_name,
            :site_location,
            :work_month,
            :work_year,
            :source_file_name,
            :source_file_sha1,
            :currency_code,
            :total_workers,
            :total_hours,
            :total_gross_amount,
            :import_status,
            :review_status,
            :created_by,
            :meta_json
        )
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':batch_ref'          => $payload['batch_ref'],
        ':claim_no'           => $payload['claim_no'],
        ':site_name'          => $payload['site_name'],
        ':site_location'      => $payload['site_location'],
        ':work_month'         => $payload['work_month'],
        ':work_year'          => $payload['work_year'],
        ':source_file_name'   => $payload['source_file_name'],
        ':source_file_sha1'   => $payload['source_file_sha1'],
        ':currency_code'      => $payload['currency_code'],
        ':total_workers'      => $payload['total_workers'],
        ':total_hours'        => $payload['total_hours'],
        ':total_gross_amount' => $payload['total_gross_amount'],
        ':import_status'      => $payload['import_status'],
        ':review_status'      => $payload['review_status'],
        ':created_by'         => $payload['created_by'],
        ':meta_json'          => json_encode($payload['meta_json'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    return (int)$pdo->lastInsertId();
}

function swap_payroll_insert_worker(PDO $pdo, int $batchId, array $row): int
{
    $sql = "
        INSERT INTO swap_payroll_workers
        (
            batch_id,
            line_no,
            worker_name,
            identity_no,
            nationality_code,
            nationality_name,
            mobile_e164,
            employer_name,
            site_name,
            total_hours,
            hourly_rate,
            gross_amount,
            valid_hours,
            rhrd_units,
            review_status,
            review_note,
            worker_user_id,
            meta_json
        ) VALUES (
            :batch_id,
            :line_no,
            :worker_name,
            :identity_no,
            :nationality_code,
            :nationality_name,
            :mobile_e164,
            :employer_name,
            :site_name,
            :total_hours,
            :hourly_rate,
            :gross_amount,
            :valid_hours,
            :rhrd_units,
            :review_status,
            :review_note,
            :worker_user_id,
            :meta_json
        )
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':batch_id'          => $batchId,
        ':line_no'           => (int)$row['line_no'],
        ':worker_name'       => (string)$row['worker_name'],
        ':identity_no'       => $row['identity_no'] ?? null,
        ':nationality_code'  => $row['nationality_code'] ?? null,
        ':nationality_name'  => $row['nationality_name'] ?? null,
        ':mobile_e164'       => $row['mobile_e164'] ?? null,
        ':employer_name'     => $row['employer_name'] ?? null,
        ':site_name'         => $row['site_name'] ?? null,
        ':total_hours'       => swap_payroll_normalize_decimal($row['total_hours'] ?? 0, 2),
        ':hourly_rate'       => swap_payroll_normalize_decimal($row['hourly_rate'] ?? 0, 2),
        ':gross_amount'      => swap_payroll_normalize_decimal($row['gross_amount'] ?? 0, 2),
        ':valid_hours'       => swap_payroll_normalize_decimal($row['valid_hours'] ?? 0, 2),
        ':rhrd_units'        => swap_payroll_normalize_decimal($row['rhrd_units'] ?? 0, 6),
        ':review_status'     => (string)($row['review_status'] ?? 'pending'),
        ':review_note'       => $row['review_note'] ?? null,
        ':worker_user_id'    => !empty($row['worker_user_id']) ? (int)$row['worker_user_id'] : null,
        ':meta_json'         => json_encode($row['meta_json'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    return (int)$pdo->lastInsertId();
}

function swap_payroll_insert_worker_day(PDO $pdo, int $workerId, array $day): void
{
    $sql = "
        INSERT INTO swap_payroll_worker_days
        (
            payroll_worker_id,
            work_date,
            hours_worked,
            day_type,
            source_cell_ref,
            meta_json
        ) VALUES (
            :payroll_worker_id,
            :work_date,
            :hours_worked,
            :day_type,
            :source_cell_ref,
            :meta_json
        )
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':payroll_worker_id' => $workerId,
        ':work_date'         => (string)$day['work_date'],
        ':hours_worked'      => swap_payroll_normalize_decimal($day['hours_worked'] ?? 0, 2),
        ':day_type'          => (string)($day['day_type'] ?? 'workday'),
        ':source_cell_ref'   => $day['source_cell_ref'] ?? null,
        ':meta_json'         => json_encode($day['meta_json'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function swap_payroll_compute_rhrd_units(string|float|int $validHours): string
{
    $hours = (float)swap_payroll_normalize_decimal($validHours, 2);
    return number_format($hours / 10, 6, '.', '');
}

function swap_payroll_validate_parsed_payload(array $parsed): array
{
    $errors = [];

    $siteName = trim((string)($parsed['site_name'] ?? ''));
    $workMonth = (int)($parsed['work_month'] ?? 0);
    $workYear = (int)($parsed['work_year'] ?? 0);
    $workers = $parsed['workers'] ?? null;

    if ($siteName === '') {
        $errors[] = 'SITE_NAME_REQUIRED';
    }

    if ($workMonth < 1 || $workMonth > 12) {
        $errors[] = 'WORK_MONTH_INVALID';
    }

    if ($workYear < 2000 || $workYear > 2100) {
        $errors[] = 'WORK_YEAR_INVALID';
    }

    if (!is_array($workers) || count($workers) === 0) {
        $errors[] = 'PAYROLL_WORKERS_EMPTY';
    }

    if ($errors) {
        swap_payroll_json_error('PAYROLL_PARSE_INVALID', 422, ['details' => $errors]);
    }

    return $parsed;
}
