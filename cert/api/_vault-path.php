<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/api/_vault-path.php
 *
 * Final Google Vault path builder for RWA Cert artifacts.
 *
 * Locked production structure:
 * RWA_CERT/{FAMILY}/{RWA_CODE}/TON/{YYYY}/{MM}/U{USER_ID}/{CERT_UID}/
 *
 * Required subfolders:
 * nft/
 * cert/
 * qr/
 * payment/
 * mint/
 * verify/
 * royalty/
 * audit/
 * attachments/
 */

require_once __DIR__ . '/_pdf-template-map.php';

if (!function_exists('poado_cert_vault_family_from_theme')) {
    function poado_cert_vault_family_from_theme(array $theme): string
    {
        $family = strtoupper(trim((string)($theme['family'] ?? 'GENESIS')));
        return in_array($family, ['GENESIS', 'SECONDARY', 'TERTIARY'], true) ? $family : 'GENESIS';
    }
}

if (!function_exists('poado_cert_vault_year_month')) {
    function poado_cert_vault_year_month(array $row): array
    {
        $raw = trim((string)($row['issued_at'] ?? ''));
        $ts = $raw !== '' ? strtotime($raw) : false;
        if (!$ts) {
            $ts = time();
        }
        return [gmdate('Y', $ts), gmdate('m', $ts)];
    }
}

if (!function_exists('poado_cert_vault_base_parts')) {
    function poado_cert_vault_base_parts(array $row): array
    {
        $theme = poado_cert_pdf_theme($row);
        $family = poado_cert_vault_family_from_theme($theme);
        $rwaCode = strtoupper(trim((string)($theme['rwa_code'] ?? ($row['rwa_type'] ?? 'RWA-EMA'))));
        [$year, $month] = poado_cert_vault_year_month($row);
        $userId = (int)($row['owner_user_id'] ?? 0);
        $certUid = trim((string)($row['cert_uid'] ?? ''));

        if ($certUid === '') {
            throw new RuntimeException('Missing cert_uid for vault path generation');
        }
        if ($userId <= 0) {
            throw new RuntimeException('Missing owner_user_id for vault path generation');
        }

        return [
            'RWA_CERT',
            $family,
            $rwaCode,
            'TON',
            $year,
            $month,
            'U' . $userId,
            $certUid,
        ];
    }
}

if (!function_exists('poado_cert_vault_base_path')) {
    function poado_cert_vault_base_path(array $row): string
    {
        return implode('/', poado_cert_vault_base_parts($row)) . '/';
    }
}

if (!function_exists('poado_cert_vault_required_subfolders')) {
    function poado_cert_vault_required_subfolders(): array
    {
        return [
            'nft',
            'cert',
            'qr',
            'payment',
            'mint',
            'verify',
            'royalty',
            'audit',
            'attachments',
        ];
    }
}

if (!function_exists('poado_cert_vault_required_paths')) {
    function poado_cert_vault_required_paths(array $row): array
    {
        $base = poado_cert_vault_base_path($row);
        $paths = [];
        foreach (poado_cert_vault_required_subfolders() as $folder) {
            $paths[$folder] = $base . $folder . '/';
        }
        return $paths;
    }
}

if (!function_exists('poado_cert_vault_standard_files')) {
    function poado_cert_vault_standard_files(array $row): array
    {
        $paths = poado_cert_vault_required_paths($row);

        return [
            'nft_image'        => $paths['nft'] . 'image.png',
            'nft_metadata'     => $paths['nft'] . 'metadata.json',
            'certificate_pdf'  => $paths['cert'] . 'certificate.pdf',
            'verify_qr'        => $paths['qr'] . 'verify-qr.png',
            'payment_confirm'  => $paths['payment'] . 'payment-confirm.json',
            'mint_result'      => $paths['mint'] . 'mint-result.json',
            'lifecycle_audit'  => $paths['audit'] . 'lifecycle.json',
        ];
    }
}
