<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=utf-8");

function exec_safe(string $cmd): string {
    $out = shell_exec($cmd . " 2>&1");
    return trim((string)($out ?? ""));
}

function bytes_fmt(float|int $bytes): string {
    $units = ["B", "KB", "MB", "GB", "TB"];
    $i = 0;
    $bytes = (float)$bytes;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . " " . $units[$i];
}

function cpu_usage(): float {
    $a = @file("/proc/stat");
    usleep(200000);
    $b = @file("/proc/stat");

    if (!$a || !$b || empty($a[0]) || empty($b[0])) {
        return 0.0;
    }

    $a0 = preg_split("/\s+/", trim($a[0]));
    $b0 = preg_split("/\s+/", trim($b[0]));

    $idle1 = (float)($a0[4] ?? 0);
    $idle2 = (float)($b0[4] ?? 0);

    $total1 = 0.0;
    foreach (array_slice($a0, 1) as $v) { $total1 += (float)$v; }

    $total2 = 0.0;
    foreach (array_slice($b0, 1) as $v) { $total2 += (float)$v; }

    $total = $total2 - $total1;
    $idle  = $idle2 - $idle1;

    if ($total <= 0) {
        return 0.0;
    }

    return round((1 - ($idle / $total)) * 100, 2);
}

$load = function_exists("sys_getloadavg") ? sys_getloadavg() : [0, 0, 0];

$mem = [];
$meminfo = @file("/proc/meminfo") ?: [];
foreach ($meminfo as $line) {
    if (strpos($line, ":") !== false) {
        [$k, $v] = explode(":", $line, 2);
        $mem[trim($k)] = (int)filter_var($v, FILTER_SANITIZE_NUMBER_INT) * 1024;
    }
}

$memTotal = (float)($mem["MemTotal"] ?? 0);
$memAvail = (float)($mem["MemAvailable"] ?? ($mem["MemFree"] ?? 0));
$memUsed  = max(0, $memTotal - $memAvail);
$memPercent = $memTotal > 0 ? round(($memUsed / $memTotal) * 100, 2) : 0.0;

$diskTotal = @disk_total_space("/") ?: 0;
$diskFree  = @disk_free_space("/") ?: 0;
$diskUsed  = max(0, $diskTotal - $diskFree);
$diskPercent = $diskTotal > 0 ? round(($diskUsed / $diskTotal) * 100, 2) : 0.0;

$services = [
    "nginx"    => exec_safe("systemctl is-active nginx || true"),
    "php_fpm"  => exec_safe("systemctl is-active php8.4-fpm || systemctl is-active php8.3-fpm || systemctl is-active php8.2-fpm || true"),
    "mysql"    => exec_safe("systemctl is-active mysql || systemctl is-active mariadb || true"),
    "cron"     => exec_safe("systemctl is-active cron || true"),
    "fail2ban" => exec_safe("systemctl is-active fail2ban || true"),
    "ufw"      => exec_safe("systemctl is-active ufw || true"),
];

$status = "ok";
if (cpu_usage() >= 85 || $memPercent >= 90 || $diskPercent >= 92) {
    $status = "warning";
}
foreach (["nginx", "php_fpm", "cron"] as $svc) {
    if (($services[$svc] ?? "") !== "active") {
        $status = "critical";
        break;
    }
}

echo json_encode([
    "ok" => true,
    "status" => $status,
    "time" => date("c"),
    "system" => [
        "hostname" => gethostname(),
        "php" => PHP_VERSION,
        "kernel" => php_uname("r"),
        "uptime" => exec_safe("uptime -p || true"),
        "load" => $load,
    ],
    "cpu" => [
        "usage_percent" => cpu_usage(),
    ],
    "memory" => [
        "used" => bytes_fmt($memUsed),
        "total" => bytes_fmt($memTotal),
        "percent" => $memPercent,
    ],
    "disk" => [
        "used" => bytes_fmt($diskUsed),
        "total" => bytes_fmt($diskTotal),
        "percent" => $diskPercent,
    ],
    "services" => $services,
    "web3" => [
        "ton_binary" => exec_safe("which tonlib-cli || which lite-client || true"),
        "node" => exec_safe("node -v || true"),
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
