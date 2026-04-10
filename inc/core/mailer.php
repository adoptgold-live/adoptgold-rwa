<?php
declare(strict_types=1);

/**
 * AdoptGold RWA
 * Shared Mailer
 * File: /var/www/html/public/rwa/inc/core/mailer.php
 * Version: v1.0.20260315c
 */

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Resolve Composer autoload for both:
 * - web requests
 * - CLI/php -r tests
 */
$__rwaDocRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$__rwaAutoloadCandidates = [];

if ($__rwaDocRoot !== '') {
    $__rwaAutoloadCandidates[] = $__rwaDocRoot . '/rwa/vendor/autoload.php';
}

/* mailer.php is /var/www/html/public/rwa/inc/core/mailer.php
   so ../../vendor/autoload.php from this file is the canonical fallback */
$__rwaAutoloadCandidates[] = dirname(__DIR__, 2) . '/vendor/autoload.php';

$__rwaAutoload = null;
foreach ($__rwaAutoloadCandidates as $__candidate) {
    if (is_file($__candidate)) {
        $__rwaAutoload = $__candidate;
        break;
    }
}

if ($__rwaAutoload === null) {
    throw new RuntimeException(
        'Composer autoload not found. Tried: ' . implode(' | ', $__rwaAutoloadCandidates)
    );
}

require_once $__rwaAutoload;

unset($__rwaDocRoot, $__rwaAutoloadCandidates, $__candidate, $__rwaAutoload);

if (!function_exists('rwa_mail_env')) {
    function rwa_mail_env(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return $default;
        }
        $value = trim((string)$value);
        return $value === '' ? $default : $value;
    }
}

if (!function_exists('rwa_mail_bool')) {
    function rwa_mail_bool(string $key, bool $default = false): bool
    {
        $raw = rwa_mail_env($key);
        if ($raw === null) {
            return $default;
        }
        return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
    }
}

if (!function_exists('rwa_mail_log')) {
    function rwa_mail_log(string $message, array $context = []): void
    {
        $line = '[RWA_MAILER] ' . $message;
        if ($context) {
            $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($json) && $json !== '') {
                $line .= ' ' . $json;
            }
        }
        error_log($line);
    }
}

if (!function_exists('rwa_mail_cfg')) {
    function rwa_mail_cfg(): array
    {
        $smtpHost = rwa_mail_env('SMTP_HOST', 'smtp.gmail.com');
        $smtpPort = (int)(rwa_mail_env('SMTP_PORT', '587') ?? '587');
        $smtpUser = rwa_mail_env('SMTP_USER', '');
        $smtpPass = rwa_mail_env('SMTP_PASS', '');
        $smtpSecureRaw = strtolower((string)rwa_mail_env('SMTP_SECURE', 'tls'));

        $fromEmail = rwa_mail_env('MAIL_FROM_EMAIL', $smtpUser !== '' ? $smtpUser : 'rwa@adoptgold.app');
        $fromName = rwa_mail_env('MAIL_FROM_NAME', rwa_mail_env('APP_HEADER_TEXT', 'WEB3 Gold Mining Reward = POAdo Dashboard v1.0'));
        $headerText = rwa_mail_env('APP_HEADER_TEXT', 'WEB3 Gold Mining Reward = POAdo Dashboard v1.0');
        $footerText = rwa_mail_env('APP_FOOTER_TEXT', '© 2025 Blockchain Group Ltd. (Hong Kong) · RWA Standard Organisation (RSO). All rights reserved.');

        $secure = PHPMailer::ENCRYPTION_STARTTLS;
        if (in_array($smtpSecureRaw, ['ssl', 'smtps'], true)) {
            $secure = PHPMailer::ENCRYPTION_SMTPS;
        }

        return [
            'smtp_host' => $smtpHost,
            'smtp_port' => $smtpPort > 0 ? $smtpPort : 587,
            'smtp_user' => $smtpUser,
            'smtp_pass' => $smtpPass,
            'smtp_secure' => $secure,
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'header_text' => $headerText,
            'footer_text' => $footerText,
            'debug' => rwa_mail_bool('SMTP_DEBUG', false),
        ];
    }
}

if (!function_exists('rwa_mail_html_layout')) {
    function rwa_mail_html_layout(string $subject, string $innerHtml): string
    {
        $cfg = rwa_mail_cfg();

        return '<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</title>
</head>
<body style="margin:0;padding:0;background:#0d0816;color:#f6f1ff;font-family:Arial,Helvetica,sans-serif;">
  <div style="max-width:680px;margin:0 auto;padding:24px 14px;">
    <div style="border:1px solid rgba(178,108,255,.28);border-radius:18px;overflow:hidden;background:linear-gradient(180deg,#1b122a,#24173b);box-shadow:0 18px 40px rgba(0,0,0,.28);">
      <div style="padding:16px 18px;border-bottom:1px solid rgba(255,255,255,.08);background:linear-gradient(180deg,rgba(255,255,255,.03),rgba(255,255,255,.01));">
        <div style="font-size:14px;line-height:1.5;color:#f5d97b;font-weight:700;">' . htmlspecialchars($cfg['header_text'], ENT_QUOTES, 'UTF-8') . '</div>
      </div>
      <div style="padding:22px 18px;font-size:15px;line-height:1.7;color:#f6f1ff;">
        ' . $innerHtml . '
      </div>
      <div style="padding:14px 18px;border-top:1px solid rgba(255,255,255,.08);font-size:12px;line-height:1.6;color:#c9bcdf;background:rgba(0,0,0,.16);">
        ' . htmlspecialchars($cfg['footer_text'], ENT_QUOTES, 'UTF-8') . '
      </div>
    </div>
  </div>
</body>
</html>';
    }
}

if (!function_exists('rwa_mail_text_from_html')) {
    function rwa_mail_text_from_html(string $html): string
    {
        $text = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
        $text = preg_replace('/<\/p>/i', "\n\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return trim($text);
    }
}

if (!function_exists('rwa_mailer_send')) {
    function rwa_mailer_send(string $to, string $subject, string $htmlBody, array $options = []): void
    {
        $to = trim($to);
        $subject = trim($subject);

        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid recipient email.');
        }

        if ($subject === '') {
            throw new InvalidArgumentException('Email subject is required.');
        }

        $cfg = rwa_mail_cfg();

        if ($cfg['smtp_user'] === '' || $cfg['smtp_pass'] === '') {
            throw new RuntimeException('SMTP credentials missing.');
        }

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = $cfg['smtp_host'];
            $mail->Port = (int)$cfg['smtp_port'];
            $mail->SMTPAuth = true;
            $mail->Username = $cfg['smtp_user'];
            $mail->Password = $cfg['smtp_pass'];
            $mail->SMTPSecure = $cfg['smtp_secure'];
            $mail->CharSet = 'UTF-8';
            $mail->Timeout = 20;

            if ($cfg['debug']) {
                $mail->SMTPDebug = 2;
            }

            $mail->setFrom($cfg['from_email'], $cfg['from_name']);
            $mail->addAddress($to);

            $replyToEmail = trim((string)($options['reply_to_email'] ?? ''));
            $replyToName = trim((string)($options['reply_to_name'] ?? ''));
            if ($replyToEmail !== '' && filter_var($replyToEmail, FILTER_VALIDATE_EMAIL)) {
                $mail->addReplyTo($replyToEmail, $replyToName);
            }

            $isFullHtml = stripos($htmlBody, '<html') !== false && stripos($htmlBody, '<body') !== false;
            $finalHtml = $isFullHtml ? $htmlBody : rwa_mail_html_layout($subject, $htmlBody);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $finalHtml;
            $mail->AltBody = trim((string)($options['alt_body'] ?? '')) ?: rwa_mail_text_from_html($htmlBody);

            $mail->send();

            rwa_mail_log('send_ok', [
                'to' => $to,
                'subject' => $subject,
                'host' => $cfg['smtp_host'],
                'port' => $cfg['smtp_port'],
            ]);
        } catch (PHPMailerException $e) {
            rwa_mail_log('send_fail_phpmailer', [
                'to' => $to,
                'subject' => $subject,
                'error' => $mail->ErrorInfo ?: $e->getMessage(),
            ]);
            throw new RuntimeException('Mail send failed: ' . ($mail->ErrorInfo ?: $e->getMessage()));
        } catch (Throwable $e) {
            rwa_mail_log('send_fail_runtime', [
                'to' => $to,
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('Mail send failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('sendMail')) {
    function sendMail(string $to, string $subject, string $html): void
    {
        rwa_mailer_send($to, $subject, $html);
    }
}

if (!function_exists('mailer_send')) {
    function mailer_send(string $to, string $subject, string $html, array $options = []): void
    {
        rwa_mailer_send($to, $subject, $html, $options);
    }
}