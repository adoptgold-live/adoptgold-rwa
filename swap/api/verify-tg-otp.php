<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/json.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/error.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/swap/inc/swap-config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/swap/inc/swap-helpers.php';

function swap_verify_otp_fail(string $message, int $status = 400, array $extra = []): never
{
    json_error($message, $status, $extra);
}

function swap_verify_otp_read_json(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function swap_verify_digits(string $v): string
{
    return preg_replace('/\D+/', '', $v);
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        swap_verify_otp_fail('Method not allowed', 405);
    }

    $payload = swap_verify_otp_read_json();

    $mobileRaw = trim((string)($payload['mobile'] ?? ''));
    $otpCode   = trim((string)($payload['otp'] ?? ''));

    if ($mobileRaw === '' || $otpCode === '') {
        swap_verify_otp_fail('Mobile and OTP required', 422);
    }

    $digits = swap_verify_digits($mobileRaw);
    if ($digits === '') {
        swap_verify_otp_fail('Invalid mobile number', 422);
    }

    if (!preg_match('/^\d{6}$/', $otpCode)) {
        swap_verify_otp_fail('Invalid OTP format', 422);
    }

    $mobileE164 = '+' . $digits;

    $pdo = swap_db();

    $stmt = $pdo->prepare("
        SELECT id
        FROM rwa_hr_tg_otps
        WHERE mobile_e164 = :mobile
          AND otp_code = :otp_code
          AND purpose = 'status_search'
          AND used_at IS NULL
          AND expires_at >= NOW()
        ORDER BY id DESC
        LIMIT 1
    ");

    $stmt->execute([
        ':mobile'   => $mobileE164,
        ':otp_code' => $otpCode,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        swap_verify_otp_fail('Invalid or expired OTP', 403);
    }

    $otpId = (int)$row['id'];

    // mark as used
    $upd = $pdo->prepare("
        UPDATE rwa_hr_tg_otps
        SET used_at = NOW()
        WHERE id = :id
        LIMIT 1
    ");
    $upd->execute([':id' => $otpId]);

    // optional: create short session flag (not mandatory, but useful)
    $_SESSION['swap_status_otp_mobile'] = $mobileE164;
    $_SESSION['swap_status_otp_verified_at'] = time();

    json_ok([
        'verified' => true,
        'mobile_masked' => '+' . substr($digits, 0, 4) . str_repeat('*', max(0, strlen($digits) - 7)) . substr($digits, -3),
        'message' => 'OTP verified',
    ]);
} catch (Throwable $e) {
    if (function_exists('poado_error')) {
        try {
            poado_error('swap', '/rwa/swap/api/verify-tg-otp.php', 'SWAP_VERIFY_TG_OTP_FAIL', [
                'message' => $e->getMessage(),
            ]);
        } catch (Throwable $ignore) {
        }
    }
    swap_verify_otp_fail('Failed to verify OTP', 500);
}