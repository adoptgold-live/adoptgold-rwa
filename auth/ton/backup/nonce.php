<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function json_out(array $a, int $code=200): void {
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function load_env_file(string $file): void {
    if (!is_file($file)) return;
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$k,$v] = explode('=', $line, 2);
        $k = trim($k); $v = trim($v);
        if ($k === '') continue;
        if ((str_starts_with($v,'"') && str_ends_with($v,'"')) || (str_starts_with($v,"'") && str_ends_with($v,"'"))) $v = substr($v,1,-1);
        putenv($k.'='.$v); $_ENV[$k]=$v; $_SERVER[$k]=$v;
    }
}
function envv(string $k, string $d=''): string {
    $v = getenv($k);
    if ($v !== false && $v !== '') return (string)$v;
    if (!empty($_ENV[$k])) return (string)$_ENV[$k];
    if (!empty($_SERVER[$k])) return (string)$_SERVER[$k];
    return $d;
}

load_env_file('/var/www/secure/.env');

$dbHost = envv('DB_HOST', '127.0.0.1');
$dbPort = envv('DB_PORT', '3306');
$dbName = envv('DB_NAME', 'wems_db');
$dbUser = envv('DB_USER', 'root');
$dbPass = envv('DB_PASS', '');

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    json_out(['ok'=>false,'message'=>'DB unavailable'], 500);
}

$payload = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
$expiresSec = 180;
$expiresAt = date('Y-m-d H:i:s', time() + $expiresSec);
$ip = $_SERVER['REMOTE_ADDR'] ?? null;
$ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

try {
    $st = $pdo->prepare("
      INSERT INTO poado_ton_nonces (payload, purpose, issued_ip, issued_ua, issued_at, expires_at)
      VALUES (:p, 'login', :ip, :ua, NOW(), :exp)
    ");
    $st->execute([':p'=>$payload, ':ip'=>$ip, ':ua'=>$ua, ':exp'=>$expiresAt]);
} catch (Throwable $e) {
    json_out(['ok'=>false,'message'=>'DB insert failed'], 500);
}

json_out(['ok'=>true,'payload'=>$payload,'expires_in'=>$expiresSec]);
