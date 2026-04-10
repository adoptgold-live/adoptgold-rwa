<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/api/_vault-uploader.php
 *
 * Final Google Drive vault uploader for RWA Cert artifacts.
 *
 * Notes:
 * - Uses locked production vault path structure
 * - Assumes shared core drive/vault helpers already exist
 * - Keeps uploader logic isolated so mint.php can call one function
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/drive.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/vault.php';
require_once __DIR__ . '/_vault-path.php';

if (!function_exists('poado_cert_vault_json')) {
    function poado_cert_vault_json(array $data): string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new RuntimeException('Failed to encode JSON payload for vault upload');
        }
        return $json;
    }
}

if (!function_exists('poado_cert_vault_require_local_file')) {
    function poado_cert_vault_require_local_file(string $absPath): void
    {
        if ($absPath === '' || !is_file($absPath)) {
            throw new RuntimeException('Required local file not found: ' . $absPath);
        }
    }
}

if (!function_exists('poado_cert_vault_mkdirs')) {
    function poado_cert_vault_mkdirs(array $row): array
    {
        $paths = poado_cert_vault_required_paths($row);
        $folderIds = [];

        foreach ($paths as $key => $vaultPath) {
            if (function_exists('poado_vault_ensure_folder')) {
                $folderIds[$key] = poado_vault_ensure_folder($vaultPath);
            } elseif (function_exists('poado_drive_ensure_folder_path')) {
                $folderIds[$key] = poado_drive_ensure_folder_path($vaultPath);
            } else {
                throw new RuntimeException('No folder creation helper available in /rwa/inc/core/drive.php or vault.php');
            }
        }

        return $folderIds;
    }
}

if (!function_exists('poado_cert_vault_upload_bytes')) {
    function poado_cert_vault_upload_bytes(string $vaultFilePath, string $bytes, string $mimeType): array
    {
        if (function_exists('poado_vault_put_contents')) {
            return (array)poado_vault_put_contents($vaultFilePath, $bytes, $mimeType);
        }

        if (function_exists('poado_drive_upload_bytes_to_path')) {
            return (array)poado_drive_upload_bytes_to_path($vaultFilePath, $bytes, $mimeType);
        }

        throw new RuntimeException('No bytes-upload helper available in /rwa/inc/core/drive.php or vault.php');
    }
}

if (!function_exists('poado_cert_vault_upload_file')) {
    function poado_cert_vault_upload_file(string $vaultFilePath, string $localAbsPath, string $mimeType): array
    {
        poado_cert_vault_require_local_file($localAbsPath);

        if (function_exists('poado_vault_upload_file')) {
            return (array)poado_vault_upload_file($vaultFilePath, $localAbsPath, $mimeType);
        }

        if (function_exists('poado_drive_upload_file_to_path')) {
            return (array)poado_drive_upload_file_to_path($vaultFilePath, $localAbsPath, $mimeType);
        }

        $bytes = file_get_contents($localAbsPath);
        if ($bytes === false) {
            throw new RuntimeException('Failed to read local file: ' . $localAbsPath);
        }

        return poado_cert_vault_upload_bytes($vaultFilePath, $bytes, $mimeType);
    }
}

if (!function_exists('poado_cert_vault_upload_all')) {
    /**
     * Upload all finalized RWA cert artifacts into the locked Google Vault structure.
     *
     * Expected $artifacts:
     * [
     *   'certificate_pdf_abs' => '/abs/path/to/certificate.pdf',            // required
     *   'nft_image_abs'       => '/abs/path/to/image.png',                  // required
     *   'verify_qr_abs'       => '/abs/path/to/verify-qr.png',              // optional
     *   'payment_confirm'     => array|string,                              // optional
     *   'mint_result'         => array|string,                              // optional
     *   'lifecycle'           => array|string,                              // optional
     *   'metadata'            => array|string,                              // required
     * ]
     */
    function poado_cert_vault_upload_all(array $row, array $artifacts): array
    {
        $standard = poado_cert_vault_standard_files($row);
        $basePath = poado_cert_vault_base_path($row);

        poado_cert_vault_mkdirs($row);

        if (empty($artifacts['certificate_pdf_abs'])) {
            throw new RuntimeException('Missing certificate_pdf_abs artifact');
        }
        if (empty($artifacts['nft_image_abs'])) {
            throw new RuntimeException('Missing nft_image_abs artifact');
        }
        if (!array_key_exists('metadata', $artifacts)) {
            throw new RuntimeException('Missing metadata artifact');
        }

        $results = [
            'base_path' => $basePath,
            'files' => [],
        ];

        $results['files']['certificate_pdf'] = poado_cert_vault_upload_file(
            $standard['certificate_pdf'],
            (string)$artifacts['certificate_pdf_abs'],
            'application/pdf'
        );

        $results['files']['nft_image'] = poado_cert_vault_upload_file(
            $standard['nft_image'],
            (string)$artifacts['nft_image_abs'],
            'image/png'
        );

        if (!empty($artifacts['verify_qr_abs'])) {
            $results['files']['verify_qr'] = poado_cert_vault_upload_file(
                $standard['verify_qr'],
                (string)$artifacts['verify_qr_abs'],
                'image/png'
            );
        }

        $metadataBytes = is_string($artifacts['metadata'])
            ? (string)$artifacts['metadata']
            : poado_cert_vault_json((array)$artifacts['metadata']);

        $results['files']['nft_metadata'] = poado_cert_vault_upload_bytes(
            $standard['nft_metadata'],
            $metadataBytes,
            'application/json'
        );

        if (array_key_exists('payment_confirm', $artifacts)) {
            $payload = is_string($artifacts['payment_confirm'])
                ? (string)$artifacts['payment_confirm']
                : poado_cert_vault_json((array)$artifacts['payment_confirm']);

            $results['files']['payment_confirm'] = poado_cert_vault_upload_bytes(
                $standard['payment_confirm'],
                $payload,
                'application/json'
            );
        }

        if (array_key_exists('mint_result', $artifacts)) {
            $payload = is_string($artifacts['mint_result'])
                ? (string)$artifacts['mint_result']
                : poado_cert_vault_json((array)$artifacts['mint_result']);

            $results['files']['mint_result'] = poado_cert_vault_upload_bytes(
                $standard['mint_result'],
                $payload,
                'application/json'
            );
        }

        if (array_key_exists('lifecycle', $artifacts)) {
            $payload = is_string($artifacts['lifecycle'])
                ? (string)$artifacts['lifecycle']
                : poado_cert_vault_json((array)$artifacts['lifecycle']);

            $results['files']['lifecycle_audit'] = poado_cert_vault_upload_bytes(
                $standard['lifecycle_audit'],
                $payload,
                'application/json'
            );
        }

        return $results;
    }
}

if (!function_exists('poado_cert_vault_result_map')) {
    function poado_cert_vault_result_map(array $uploadResults): array
    {
        $out = [
            'base_path' => (string)($uploadResults['base_path'] ?? ''),
            'files' => [],
        ];

        foreach ((array)($uploadResults['files'] ?? []) as $key => $info) {
            $out['files'][$key] = [
                'id' => $info['id'] ?? null,
                'name' => $info['name'] ?? null,
                'path' => $info['path'] ?? null,
                'url' => $info['url'] ?? ($info['webViewLink'] ?? null),
                'download_url' => $info['download_url'] ?? ($info['webContentLink'] ?? null),
                'mime_type' => $info['mime_type'] ?? ($info['mimeType'] ?? null),
            ];
        }

        return $out;
    }
}
