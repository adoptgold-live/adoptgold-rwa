<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/json.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/error.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/swap/inc/swap-config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/swap/inc/swap-helpers.php';

function swap_status_fail(string $message, int $status = 400, array $extra = []): never
{
    json_error($message, $status, $extra);
}

function swap_status_read_json(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function swap_status_str(array $src, string $key, int $max = 255): string
{
    $v = trim((string)($src[$key] ?? ''));
    if ($max > 0 && mb_strlen($v) > $max) {
        $v = mb_substr($v, 0, $max);
    }
    return $v;
}

function swap_status_digits(string $v): string
{
    return preg_replace('/\D+/', '', $v);
}

function swap_status_detect_type(string $input): string
{
    $v = trim($input);
    if ($v === '') {
        return 'unknown';
    }

    if (str_starts_with($v, '+') || preg_match('/^\d{7,}$/', $v)) {
        return 'mobile';
    }

    if (preg_match('/^(REQ|APP)-?/i', $v)) {
        return 'request_uid';
    }

    return 'passport';
}

function swap_status_validate_mobile_otp(PDO $pdo, string $mobile, bool $otpVerified): void
{
    if (!$otpVerified) {
        swap_status_fail('Telegram OTP verification required', 403, [
            'require_otp' => true,
        ]);
    }

    $digits = swap_status_digits($mobile);
    if ($digits === '') {
        swap_status_fail('Invalid mobile number', 422);
    }

    $mobileE164 = '+' . $digits;

    $stmt = $pdo->prepare("
        SELECT 1
        FROM rwa_hr_tg_otps
        WHERE mobile_e164 = :mobile
          AND purpose = 'status_search'
          AND used_at IS NOT NULL
          AND created_at >= (NOW() - INTERVAL 15 MINUTE)
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([':mobile' => $mobileE164]);

    if (!(bool)$stmt->fetchColumn()) {
        swap_status_fail('Telegram OTP session not found or expired', 403, [
            'require_otp' => true,
        ]);
    }
}

function swap_status_project_short(?string $projectKey, ?string $location = null): string
{
    $projectKey = trim((string)$projectKey);
    if ($projectKey !== '') {
        if (function_exists('swap_project_short')) {
            return swap_project_short($projectKey);
        }
        $parts = array_values(array_filter(explode('-', $projectKey)));
        if (count($parts) >= 5) {
            return $parts[2] . '-' . $parts[4];
        }
        return $projectKey;
    }
    return trim((string)$location) !== '' ? trim((string)$location) : '-';
}

function swap_status_stage_label(string $stage): string
{
    $map = [
        'pending_docs'    => 'Document Review',
        'pending_fomema'  => 'Medical Check Required',
        'pending_permit'  => 'Permit Processing',
        'pending_hostel'  => 'Accommodation Pending',
        'ready'           => 'Ready for Arrival',
        'arrived'         => 'Arrived',
        'started'         => 'Work Started',
        'active'          => 'Active Employment',
        'under_review'    => 'Under Review',
        'shortlisted'     => 'Shortlisted',
        'approved'        => 'Approved',
        'assigned'        => 'Job Assigned',
        'rejected'        => 'Not Approved',
        'non_compliant'   => 'Action Required',
    ];

    return $map[$stage] ?? ($stage !== '' ? ucwords(str_replace('_', ' ', $stage)) : '-');
}

function swap_status_label(string $status): string
{
    $map = [
        'pending'        => 'Pending Approval',
        'shortlisted'    => 'Shortlisted',
        'approved'       => 'Approved',
        'assigned'       => 'Job Assigned',
        'active'         => 'Working',
        'non_compliant'  => 'Action Required',
        'rejected'       => 'Not Approved',
        'pending_docs'   => 'Pending Approval',
        'pending_fomema' => 'Pending Approval',
        'pending_permit' => 'Pending Approval',
        'pending_hostel' => 'Pending Approval',
        'ready'          => 'Ready',
        'arrived'        => 'Arrived',
        'started'        => 'Started',
    ];

    return $map[$status] ?? ($status !== '' ? ucwords(str_replace('_', ' ', $status)) : '-');
}

function swap_status_mask_mobile(?string $mobileE164): string
{
    $m = trim((string)$mobileE164);
    if ($m === '') {
        return '-';
    }

    $digits = swap_status_digits($m);
    if ($digits === '') {
        return '-';
    }

    if (strlen($digits) <= 6) {
        return '+' . $digits;
    }

    $head = substr($digits, 0, 4);
    $tail = substr($digits, -3);
    $mask = str_repeat('*', max(0, strlen($digits) - 7));

    return '+' . $head . $mask . $tail;
}

function swap_status_build_item(array $row, string $source): array
{
    $passport = (string)($row['passport_no'] ?? '');
    $projectKey = (string)($row['project_key'] ?? '');
    $location = (string)($row['preferred_location'] ?? $row['site_name'] ?? '');
    $industry = (string)($row['preferred_industry'] ?? $row['sector'] ?? '');
    if ($industry === 'OTHER') {
        $industry = (string)($row['industry_other'] ?? '');
    }

    $rawStatus = (string)($row['application_status'] ?? $row['worker_status'] ?? '');
    $rawStage  = (string)($row['status_stage'] ?? '');
    if ($rawStage === '') {
        $rawStage = $rawStatus;
    }

    $updatedAt = (string)($row['updated_at'] ?? $row['created_at'] ?? '');

    return [
        'source'           => $source,
        'passport_masked'  => function_exists('swap_mask_passport') ? swap_mask_passport($passport) : $passport,
        'mobile_masked'    => swap_status_mask_mobile((string)($row['mobile_e164'] ?? '')),
        'status'           => swap_status_label($rawStatus),
        'stage'            => swap_status_stage_label($rawStage),
        'industry'         => $industry !== '' ? $industry : '-',
        'location'         => $location !== '' ? $location : '-',
        'project_short'    => swap_status_project_short($projectKey, $location),
        'next_action'      => (string)($row['next_action'] ?? '-') ?: '-',
        'updated_at'       => $updatedAt !== '' ? $updatedAt : '-',
    ];
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        swap_status_fail('Method not allowed', 405);
    }

    $payload = swap_status_read_json();
    if (!$payload) {
        swap_status_fail('Invalid request payload', 400);
    }

    $pdo = swap_db();

    $q = swap_status_str($payload, 'q', 255);
    $mobile = swap_status_str($payload, 'mobile', 40);
    $otpVerified = ((int)($payload['otp_verified'] ?? 0) === 1);

    $type = swap_status_str($payload, 'type', 32);
    if ($type === '') {
        $type = ($mobile !== '') ? 'mobile' : swap_status_detect_type($q);
    }

    if ($type === 'unknown') {
        swap_status_fail('Search input required', 422);
    }

    if ($type === 'mobile') {
        $searchMobile = $mobile !== '' ? $mobile : $q;
        swap_status_validate_mobile_otp($pdo, $searchMobile, $otpVerified);

        $mobileE164 = '+' . swap_status_digits($searchMobile);

        $stmt = $pdo->prepare("
            SELECT
                request_uid,
                passport_no,
                mobile_e164,
                preferred_industry,
                industry_other,
                preferred_location,
                project_key,
                application_status,
                status_stage,
                next_action,
                updated_at,
                created_at
            FROM rwa_hr_job_requests
            WHERE mobile_e164 = :mobile
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([':mobile' => $mobileE164]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $stmt2 = $pdo->prepare("
                SELECT
                    worker_uid,
                    passport_no,
                    mobile_e164,
                    sector,
                    nationality,
                    site_name,
                    project_key,
                    worker_status,
                    status_stage,
                    next_action,
                    updated_at,
                    created_at
                FROM rwa_hr_workers
                WHERE mobile_e164 = :mobile
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt2->execute([':mobile' => $mobileE164]);
            $row2 = $stmt2->fetch(PDO::FETCH_ASSOC);

            if (!$row2) {
                json_ok([
                    'found' => false,
                    'message' => 'No record found',
                ]);
            }

            json_ok([
                'found' => true,
                'item'  => swap_status_build_item($row2, 'worker'),
            ]);
        }

        json_ok([
            'found' => true,
            'item'  => swap_status_build_item($row, 'application'),
        ]);
    }

    if ($type === 'request_uid') {
        $requestUid = strtoupper(trim($q));

        $stmt = $pdo->prepare("
            SELECT
                request_uid,
                passport_no,
                mobile_e164,
                preferred_industry,
                industry_other,
                preferred_location,
                project_key,
                application_status,
                status_stage,
                next_action,
                updated_at,
                created_at
            FROM rwa_hr_job_requests
            WHERE request_uid = :request_uid
            LIMIT 1
        ");
        $stmt->execute([':request_uid' => $requestUid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            json_ok([
                'found' => false,
                'message' => 'No record found',
            ]);
        }

        json_ok([
            'found' => true,
            'item'  => swap_status_build_item($row, 'application'),
        ]);
    }

    // default = passport
    $passport = strtoupper(trim($q));
    $passport = preg_replace('/\s+/', '', $passport);

    $stmt = $pdo->prepare("
        SELECT
            request_uid,
            passport_no,
            mobile_e164,
            preferred_industry,
            industry_other,
            preferred_location,
            project_key,
            application_status,
            status_stage,
            next_action,
            updated_at,
            created_at
        FROM rwa_hr_job_requests
        WHERE passport_no = :passport_no
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([':passport_no' => $passport]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $stmt2 = $pdo->prepare("
            SELECT
                worker_uid,
                passport_no,
                mobile_e164,
                sector,
                nationality,
                site_name,
                project_key,
                worker_status,
                status_stage,
                next_action,
                updated_at,
                created_at
            FROM rwa_hr_workers
            WHERE passport_no = :passport_no
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt2->execute([':passport_no' => $passport]);
        $row2 = $stmt2->fetch(PDO::FETCH_ASSOC);

        if (!$row2) {
            json_ok([
                'found' => false,
                'message' => 'No record found',
            ]);
        }

        json_ok([
            'found' => true,
            'item'  => swap_status_build_item($row2, 'worker'),
        ]);
    }

    json_ok([
        'found' => true,
        'item'  => swap_status_build_item($row, 'application'),
    ]);
} catch (Throwable $e) {
    if (function_exists('poado_error')) {
        try {
            poado_error('swap', '/rwa/swap/api/status-search.php', 'SWAP_STATUS_SEARCH_FAIL', [
                'message' => $e->getMessage(),
            ]);
        } catch (Throwable $ignore) {
        }
    }
    swap_status_fail('Failed to search status', 500);
}