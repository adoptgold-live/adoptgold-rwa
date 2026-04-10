<?php
// /var/www/html/public/rwa/auth/email/send-verify.php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

if (!function_exists('rwa_email_json_out')) {
    function rwa_email_json_out(array $data, int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('rwa_email_h')) {
    function rwa_email_h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('rwa_email_csrf_ok')) {
    function rwa_email_csrf_ok(string $token, string $scope = 'rwa_profile_save'): bool {
        if ($token === '') return false;

        if (function_exists('csrf_check')) {
            try {
                return (bool) csrf_check($scope, $token);
            } catch (Throwable $e) {
            }
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $sess = (string)($_SESSION['csrf_token_rwa_profile'] ?? '');
        return $sess !== '' && hash_equals($sess, $token);
    }
}

if (!function_exists('rwa_email_base_url')) {
    function rwa_email_base_url(): string {
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'adoptgold.app');
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'https';
        return $scheme . '://' . $host;
    }
}

if (!function_exists('rwa_email_make_token')) {
    function rwa_email_make_token(): string {
        return bin2hex(random_bytes(32));
    }
}

if (!function_exists('rwa_email_verify_link')) {
    function rwa_email_verify_link(string $token): string {
        return rwa_email_base_url() . '/rwa/auth/email/verify-token.php?token=' . urlencode($token);
    }
}

if (!function_exists('rwa_email_send_mail')) {
    function rwa_email_send_mail(string $to, string $subject, string $html, string $text = ''): bool {
        if (function_exists('poado_mail')) {
            return (bool) poado_mail($to, $subject, $html, $text);
        }
        if (function_exists('send_mail')) {
            return (bool) send_mail($to, $subject, $html);
        }
        if (function_exists('rwa_mail')) {
            return (bool) rwa_mail($to, $subject, $html, $text);
        }
        return false;
    }
}

if (!function_exists('rwa_email_issue_and_send')) {
    function rwa_email_issue_and_send(PDO $pdo, int $userId, string $email): array {
        $email = strtolower(trim($email));
        if ($userId <= 0) {
            return ['ok' => false, 'error' => 'Invalid user'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Valid email required'];
        }

        $token = rwa_email_make_token();

        $st = $pdo->prepare("
            UPDATE users
            SET email = :email,
                verify_token = :token,
                verify_sent_at = NOW(),
                email_verified_at = NULL,
                updated_at = NOW()
            WHERE id = :id
            LIMIT 1
        ");
        $st->execute([
            ':email' => $email,
            ':token' => $token,
            ':id'    => $userId,
        ]);

        $verifyUrl = rwa_email_verify_link($token);
        $subject = 'Verify Your RWA Profile Email';

        $html = '
        <div style="font-family:Arial,Helvetica,sans-serif;background:#0b0713;color:#f3ecff;padding:24px">
          <div style="max-width:640px;margin:0 auto;border:1px solid rgba(187,117,255,.24);border-radius:18px;padding:24px;background:#120723">
            <h2 style="margin:0 0 12px;color:#ffd86b">Verify Your Email</h2>
            <p style="margin:0 0 12px;line-height:1.6;color:#efe7ff">Please confirm your standalone RWA profile email address.</p>
            <p style="margin:0 0 20px;line-height:1.6;color:#cfc3ea">Click the button below to complete verification.</p>
            <p style="margin:0 0 18px">
              <a href="' . rwa_email_h($verifyUrl) . '" style="display:inline-block;padding:12px 18px;border-radius:12px;text-decoration:none;background:#1b102c;border:1px solid #ffd86b;color:#ffd86b;font-weight:700">
                Verify Email
              </a>
            </p>
            <p style="margin:0 0 10px;line-height:1.6;color:#cfc3ea">If the button does not work, open this link:</p>
            <p style="margin:0;word-break:break-all;color:#4cffb2">' . rwa_email_h($verifyUrl) . '</p>
          </div>
        </div>';

        $text = "Verify your email by opening this link:\n" . $verifyUrl;

        $sent = rwa_email_send_mail($email, $subject, $html, $text);

        return [
            'ok' => true,
            'sent' => $sent,
            'email' => $email,
            'verify_url' => $verifyUrl,
            'token' => $token,
        ];
    }
}

$pdo = function_exists('rwa_db') ? rwa_db() : ($GLOBALS['pdo'] ?? null);
if (!$pdo instanceof PDO) {
    rwa_email_json_out(['ok' => false, 'error' => 'DB not ready'], 500);
}

$user = function_exists('session_user') ? session_user() : [];
$userId = (int)($user['id'] ?? 0);
if ($userId <= 0) {
    rwa_email_json_out(['ok' => false, 'error' => 'Login required'], 401);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'POST') {
    rwa_email_json_out(['ok' => false, 'error' => 'POST required'], 405);
}

$csrf = trim((string)($_POST['csrf_token'] ?? ''));
if (!rwa_email_csrf_ok($csrf, 'rwa_profile_save')) {
    rwa_email_json_out(['ok' => false, 'error' => 'Invalid CSRF token'], 419);
}

$email = strtolower(trim((string)($_POST['email'] ?? ($user['email'] ?? ''))));
if ($email === '') {
    rwa_email_json_out(['ok' => false, 'error' => 'Email required'], 422);
}

try {
    $res = rwa_email_issue_and_send($pdo, $userId, $email);
    if (!$res['ok']) {
        rwa_email_json_out($res, 422);
    }
    rwa_email_json_out([
        'ok' => true,
        'msg' => !empty($res['sent']) ? 'Verification email sent' : 'Verification token issued. Mail sender unavailable.',
        'email' => $res['email'],
        'sent' => (bool)$res['sent'],
        'verify_url' => $res['verify_url'],
    ]);
} catch (Throwable $e) {
    rwa_email_json_out(['ok' => false, 'error' => 'Unable to send verification email'], 500);
}