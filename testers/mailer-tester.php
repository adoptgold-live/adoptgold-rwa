<?php
declare(strict_types=1);

/**
 * AdoptGold RWA
 * Mailer Tester
 * File: /var/www/html/public/rwa/testers/mailer-tester.php
 * Version: v1.0.20260315
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/mailer.php';

header('Content-Type: text/plain; charset=utf-8');

function envv(string $key, string $default = ''): string {
    $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($v === false || $v === null) return $default;
    return trim((string)$v);
}

try {
    echo "== RWA MAILER TEST ==\n";
    echo "autoload mailer loaded: OK\n";
    echo "SMTP_HOST=" . envv('SMTP_HOST', 'smtp.gmail.com') . "\n";
    echo "SMTP_PORT=" . envv('SMTP_PORT', '587') . "\n";
    echo "SMTP_USER=" . envv('SMTP_USER', '') . "\n";
    echo "MAIL_FROM_EMAIL=" . envv('MAIL_FROM_EMAIL', '') . "\n";
    echo "MAIL_FROM_NAME=" . envv('MAIL_FROM_NAME', '') . "\n";
    echo "APP_HEADER_TEXT=" . envv('APP_HEADER_TEXT', '') . "\n";
    echo "APP_FOOTER_TEXT=" . envv('APP_FOOTER_TEXT', '') . "\n";

    $to = isset($_GET['to']) ? trim((string)$_GET['to']) : '';
    if ($to === '') {
        echo "\nNo ?to=email@example.com provided.\n";
        exit;
    }

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Invalid ?to email address');
    }

    $subject = 'RWA Mailer Test';
    $html = '
        <h2 style="margin:0 0 12px 0;">Mailer Test OK</h2>
        <p style="margin:0 0 12px 0;">This is a direct test from <strong>/rwa/testers/mailer-tester.php</strong>.</p>
        <p style="margin:0;">If you received this, SMTP + Composer + mailer path are working.</p>
    ';

    mailer_send($to, $subject, $html);

    echo "\nSEND RESULT: OK\n";
    echo "Sent to: {$to}\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "\nSEND RESULT: FAIL\n";
    echo "ERROR: " . $e->getMessage() . "\n";
}