<?php
declare(strict_types=1);

/**
 * AdoptGold RWA
 * Profile API
 * File: /var/www/html/public/rwa/api/profile/send-verify-email.php
 * Version: v1.0.20260315c
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/mailer.php';

header('Content-Type: application/json; charset=utf-8');

function json_exit(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$userId = (int) session_user_id();
if ($userId <= 0) {
    json_exit([
        'ok' => false,
        'error' => 'AUTH_REQUIRED',
        'message' => 'Please sign in first.'
    ], 401);
}

try {
    if (!function_exists('db')) {
        throw new RuntimeException('db() not available from bootstrap.');
    }

    $db = db();
    if (!$db instanceof PDO) {
        throw new RuntimeException('Database connection unavailable.');
    }

    $stmt = $db->prepare("
        SELECT id, email, email_verified_at
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        json_exit([
            'ok' => false,
            'error' => 'USER_NOT_FOUND',
            'message' => 'User not found.'
        ], 404);
    }

    $email = trim((string)($user['email'] ?? ''));
    if ($email === '') {
        json_exit([
            'ok' => false,
            'error' => 'EMAIL_EMPTY',
            'message' => 'Email is empty.'
        ], 422);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_exit([
            'ok' => false,
            'error' => 'EMAIL_INVALID',
            'message' => 'Email format is invalid.'
        ], 422);
    }

    if (!empty($user['email_verified_at'])) {
        json_exit([
            'ok' => false,
            'error' => 'EMAIL_ALREADY_VERIFIED',
            'message' => 'Email already verified.'
        ], 409);
    }

    $token = bin2hex(random_bytes(32));

    $stmt = $db->prepare("
        UPDATE users
        SET
            verify_token = ?,
            verify_sent_at = NOW(),
            updated_at = NOW()
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$token, $userId]);

    $verifyLink = 'https://adoptgold.app/rwa/verify-email.php?token=' . urlencode($token);

    $subject = 'Verify your AdoptGold account';

    $html = '
      <h2 style="margin:0 0 12px 0;color:#f5d97b;">Verify your email</h2>
      <p style="margin:0 0 14px 0;">Please confirm your email address for your AdoptGold RWA account.</p>
      <p style="margin:0 0 18px 0;">
        <a href="' . htmlspecialchars($verifyLink, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:12px 18px;border-radius:10px;background:#6f42ff;color:#ffffff;text-decoration:none;font-weight:700;">
          Verify Email
        </a>
      </p>
      <p style="margin:0 0 12px 0;color:#bfb5dd;">If you did not request this, you can ignore this email.</p>
      <p style="margin:0;color:#8f86aa;font-size:12px;">Link: ' . htmlspecialchars($verifyLink, ENT_QUOTES, 'UTF-8') . '</p>
    ';

    mailer_send($email, $subject, $html);

    json_exit([
        'ok' => true,
        'message' => 'Verification email sent.'
    ]);
} catch (Throwable $e) {
    json_exit([
        'ok' => false,
        'error' => 'VERIFY_EMAIL_FAILED',
        'message' => $e->getMessage()
    ], 500);
}