<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/json.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/error.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/swap/inc/swap-config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/swap/inc/swap-helpers.php';

function swap_apply_json_fail(string $message, int $status = 400, array $extra = []): never
{
    json_error($message, $status, $extra);
}

function swap_apply_read_json(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function swap_apply_str(array $src, string $key, int $max = 255): string
{
    $v = trim((string)($src[$key] ?? ''));
    if ($max > 0 && mb_strlen($v) > $max) {
        $v = mb_substr($v, 0, $max);
    }
    return $v;
}

function swap_apply_upper(array $src, string $key, int $max = 255): string
{
    return strtoupper(swap_apply_str($src, $key, $max));
}

function swap_apply_int_str(array $src, string $key): string
{
    return preg_replace('/\D+/', '', (string)($src[$key] ?? ''));
}

function swap_apply_validate_csrf(array $payload): void
{
    $token = (string)($payload['csrf'] ?? '');
    $ok = false;

    if (function_exists('csrf_check')) {
        try {
            $r = csrf_check('swap_apply', $token);
            $ok = ($r !== false);
        } catch (Throwable $e) {
            $ok = false;
        }
    } else {
        // If csrf helper is unavailable in this environment, do soft fallback only.
        $ok = ($token !== '');
    }

    if (!$ok) {
        swap_apply_json_fail('Invalid request token', 403);
    }
}

function swap_apply_validate_required(array $payload): void
{
    $required = [
        'full_name'           => 'Full Name',
        'passport_no'         => 'Passport Number',
        'nationality'         => 'Nationality',
        'gender'              => 'Gender',
        'date_of_birth'       => 'Date of Birth',
        'mobile_country_code' => 'Mobile Prefix',
        'mobile_number'       => 'Mobile Number',
        'preferred_industry'  => 'Preferred Industry',
        'preferred_location'  => 'Preferred Location',
    ];

    foreach ($required as $key => $label) {
        $v = trim((string)($payload[$key] ?? ''));
        if ($v === '') {
            swap_apply_json_fail($label . ' is required', 422);
        }
    }

    if (($payload['nationality'] ?? '') === 'OTHER' && trim((string)($payload['nationality_other'] ?? '')) === '') {
        swap_apply_json_fail('Other Nationality is required', 422);
    }

    if (($payload['preferred_industry'] ?? '') === 'OTHER' && trim((string)($payload['industry_other'] ?? '')) === '') {
        swap_apply_json_fail('Other Industry is required', 422);
    }
}

function swap_apply_validate_enums(array $payload): void
{
    $genders = ['male', 'female', 'other'];
    if (!in_array((string)$payload['gender'], $genders, true)) {
        swap_apply_json_fail('Invalid gender', 422);
    }

    $nationalities = [
        'MYANMAR','BANGLADESH','INDONESIA','VIETNAM','NEPAL','INDIA',
        'CAMBODIA','PHILIPPINES','PAKISTAN','LAOS','THAILAND',
        'SRI_LANKA','CHINA','OTHER'
    ];
    if (!in_array((string)$payload['nationality'], $nationalities, true)) {
        swap_apply_json_fail('Invalid nationality', 422);
    }

    $industries = [
        'Construction','Domestic Maid','Manufacturing','Plantation','Services',
        'Logistics / Warehouse','Food & Beverage','Cleaning & Maintenance',
        'Security','Agriculture','OTHER'
    ];
    if (!in_array((string)$payload['preferred_industry'], $industries, true)) {
        swap_apply_json_fail('Invalid preferred industry', 422);
    }
}

function swap_apply_validate_dates(array $payload): void
{
    $dob = (string)($payload['date_of_birth'] ?? '');
    if ($dob === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
        swap_apply_json_fail('Invalid date of birth', 422);
    }

    try {
        $dt = new DateTimeImmutable($dob);
        $now = new DateTimeImmutable('now');
        if ($dt > $now) {
            swap_apply_json_fail('Date of birth cannot be in the future', 422);
        }
    } catch (Throwable $e) {
        swap_apply_json_fail('Invalid date of birth', 422);
    }
}

function swap_apply_validate_mobile(array &$payload): void
{
    $prefix = preg_replace('/\D+/', '', (string)($payload['mobile_country_code'] ?? ''));
    $mobile = preg_replace('/\D+/', '', (string)($payload['mobile_number'] ?? ''));

    if ($prefix === '' || $mobile === '') {
        swap_apply_json_fail('Invalid mobile number', 422);
    }

    $e164 = '+' . $prefix . $mobile;
    $wa = 'https://wa.me/' . $prefix . $mobile;

    $payload['mobile_country_code'] = $prefix;
    $payload['mobile_number'] = $mobile;
    $payload['mobile_e164'] = $e164;
    $payload['whatsapp_url'] = $wa;
}

function swap_apply_maybe_logged_in_wallet(): string
{
    try {
        if (function_exists('session_user')) {
            $u = session_user();
            if (is_array($u) && !empty($u)) {
                return trim((string)($u['wallet_address'] ?? $u['wallet'] ?? ''));
            }
        }
    } catch (Throwable $e) {
    }

    try {
        if (function_exists('rwa_session_user')) {
            $u = rwa_session_user();
            if (is_array($u) && !empty($u)) {
                return trim((string)($u['wallet_address'] ?? $u['wallet'] ?? ''));
            }
        }
    } catch (Throwable $e) {
    }

    return '';
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        swap_apply_json_fail('Method not allowed', 405);
    }

    $payload = swap_apply_read_json();
    if (!$payload) {
        swap_apply_json_fail('Invalid request payload', 400);
    }

    swap_apply_validate_csrf($payload);
    swap_apply_validate_required($payload);
    swap_apply_validate_enums($payload);
    swap_apply_validate_dates($payload);
    swap_apply_validate_mobile($payload);

    $pdo = swap_db();

    $fullName           = swap_apply_str($payload, 'full_name', 120);
    $passportNo         = swap_apply_upper($payload, 'passport_no', 50);
    $nationality        = swap_apply_upper($payload, 'nationality', 50);
    $nationalityOther   = swap_apply_str($payload, 'nationality_other', 80);
    $gender             = strtolower(swap_apply_str($payload, 'gender', 10));
    $dateOfBirth        = swap_apply_str($payload, 'date_of_birth', 10);

    $mobilePrefix       = swap_apply_str($payload, 'mobile_country_code', 8);
    $mobileNumber       = swap_apply_str($payload, 'mobile_number', 20);
    $mobileE164         = swap_apply_str($payload, 'mobile_e164', 20);
    $whatsappUrl        = swap_apply_str($payload, 'whatsapp_url', 255);

    $tgUsername         = swap_apply_str($payload, 'tg_username', 120);
    $preferredIndustry  = swap_apply_str($payload, 'preferred_industry', 50);
    $industryOther      = swap_apply_str($payload, 'industry_other', 100);
    $preferredLocation  = swap_apply_str($payload, 'preferred_location', 120);
    $experienceYearsRaw = trim((string)($payload['experience_years'] ?? ''));
    $experienceYears    = ($experienceYearsRaw === '') ? null : max(0, min(50, (int)$experienceYearsRaw));
    $skillNotes         = swap_apply_str($payload, 'skill_notes', 5000);
    $workedBefore       = ((string)($payload['worked_in_malaysia_before'] ?? '0') === '1') ? 1 : 0;

    $jobUid             = swap_apply_str($payload, 'job_uid', 60);
    $projectKey         = swap_apply_str($payload, 'project_key', 120);
    $source             = swap_apply_str($payload, 'source', 50);
    if ($source === '') {
        $source = 'swap_dashboard';
    }

    $walletAddress = swap_apply_str($payload, 'wallet_address', 255);
    if ($walletAddress === '') {
        $walletAddress = swap_apply_maybe_logged_in_wallet();
    }

    // Optional duplicate soft-check:
    // Avoid creating many identical fresh pending applications too quickly.
    $dupStmt = $pdo->prepare("
        SELECT request_uid
        FROM rwa_hr_job_requests
        WHERE passport_no = :passport_no
          AND application_status IN ('pending','shortlisted','approved','assigned')
        ORDER BY id DESC
        LIMIT 1
    ");
    $dupStmt->execute([':passport_no' => $passportNo]);
    $existing = $dupStmt->fetch(PDO::FETCH_ASSOC);

    if (is_array($existing) && !empty($existing['request_uid'])) {
        swap_apply_json_fail('An active application already exists for this passport', 409, [
            'request_uid' => (string)$existing['request_uid'],
        ]);
    }

    $requestUid = swap_uid('REQ');

    $sql = "
        INSERT INTO rwa_hr_job_requests (
            request_uid,
            job_uid,
            project_key,
            full_name,
            passport_no,
            nationality,
            nationality_other,
            gender,
            date_of_birth,
            mobile_country_code,
            mobile_number,
            mobile_e164,
            whatsapp_url,
            tg_username,
            preferred_industry,
            industry_other,
            preferred_location,
            experience_years,
            skill_notes,
            worked_in_malaysia_before,
            application_status,
            status_stage,
            next_action,
            source
        ) VALUES (
            :request_uid,
            :job_uid,
            :project_key,
            :full_name,
            :passport_no,
            :nationality,
            :nationality_other,
            :gender,
            :date_of_birth,
            :mobile_country_code,
            :mobile_number,
            :mobile_e164,
            :whatsapp_url,
            :tg_username,
            :preferred_industry,
            :industry_other,
            :preferred_location,
            :experience_years,
            :skill_notes,
            :worked_in_malaysia_before,
            :application_status,
            :status_stage,
            :next_action,
            :source
        )
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':request_uid', $requestUid, PDO::PARAM_STR);
    $stmt->bindValue(':job_uid', $jobUid !== '' ? $jobUid : null, $jobUid !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':project_key', $projectKey !== '' ? $projectKey : null, $projectKey !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':full_name', $fullName, PDO::PARAM_STR);
    $stmt->bindValue(':passport_no', $passportNo, PDO::PARAM_STR);
    $stmt->bindValue(':nationality', $nationality, PDO::PARAM_STR);
    $stmt->bindValue(':nationality_other', $nationalityOther !== '' ? $nationalityOther : null, $nationalityOther !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':gender', $gender, PDO::PARAM_STR);
    $stmt->bindValue(':date_of_birth', $dateOfBirth, PDO::PARAM_STR);
    $stmt->bindValue(':mobile_country_code', $mobilePrefix, PDO::PARAM_STR);
    $stmt->bindValue(':mobile_number', $mobileNumber, PDO::PARAM_STR);
    $stmt->bindValue(':mobile_e164', $mobileE164, PDO::PARAM_STR);
    $stmt->bindValue(':whatsapp_url', $whatsappUrl !== '' ? $whatsappUrl : null, $whatsappUrl !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':tg_username', $tgUsername !== '' ? $tgUsername : null, $tgUsername !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':preferred_industry', $preferredIndustry, PDO::PARAM_STR);
    $stmt->bindValue(':industry_other', $industryOther !== '' ? $industryOther : null, $industryOther !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':preferred_location', $preferredLocation, PDO::PARAM_STR);

    if ($experienceYears === null) {
        $stmt->bindValue(':experience_years', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':experience_years', $experienceYears, PDO::PARAM_INT);
    }

    $stmt->bindValue(':skill_notes', $skillNotes !== '' ? $skillNotes : null, $skillNotes !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':worked_in_malaysia_before', $workedBefore, PDO::PARAM_INT);
    $stmt->bindValue(':application_status', 'pending', PDO::PARAM_STR);
    $stmt->bindValue(':status_stage', 'pending_docs', PDO::PARAM_STR);
    $stmt->bindValue(':next_action', 'Awaiting admin review', PDO::PARAM_STR);
    $stmt->bindValue(':source', $source, PDO::PARAM_STR);
    $stmt->execute();

    json_ok([
        'request_uid' => $requestUid,
        'message' => 'Application submitted successfully',
        'wallet_address' => $walletAddress,
    ]);
} catch (Throwable $e) {
    if (function_exists('poado_error')) {
        try {
            poado_error('swap', '/rwa/swap/api/apply.php', 'SWAP_APPLY_FAIL', [
                'message' => $e->getMessage(),
            ]);
        } catch (Throwable $ignore) {
        }
    }
    swap_apply_json_fail('Failed to submit application', 500);
}