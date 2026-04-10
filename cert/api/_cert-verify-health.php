<?php
declare(strict_types=1);

/**
 * VERIFY HEALTH ENGINE
 * - checks REQUIRED files only
 * - no UI logic
 * - no fallback assumption
 */

require_once __DIR__ . '/_cert-path.php';

function cert_health_check(array $row, int $version): array
{
    $vf = cert_path_version_files($row, $version);

    $checks = [
        'pdf'       => is_file($vf['pdf']),
        'metadata'  => is_file($vf['metadata_json']),
        'verify'    => is_file($vf['verify_json']),
        'qr'        => is_file($vf['qr_svg']),
        'payment'   => is_file($vf['payment_proof_json']),
    ];

    // mint proof optional
    $checks['mint'] = is_file($vf['mint_proof_json']);

    $ok = $checks['pdf']
        && $checks['metadata']
        && $checks['verify']
        && $checks['qr']
        && $checks['payment'];

    return [
        'ok' => $ok,
        'checks' => $checks,
        'root' => $vf['root'],
    ];
}

function cert_health_latest(array $row, int $maxScan = 5): array
{
    for ($v = $maxScan; $v >= 1; $v--) {
        $h = cert_health_check($row, $v);
        if ($h['ok']) {
            return [
                'ok' => true,
                'version' => $v,
                'health' => $h,
            ];
        }
    }

    return [
        'ok' => false,
        'version' => 0,
    ];
}
