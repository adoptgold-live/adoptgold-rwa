<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/api/_cert-manifest.php
 * Version: v1.0.0-20260404-cert-manifest-builder
 *
 * LOCK
 * - writes manifest.json into version folder only
 * - current/ stays lightweight and does not require manifest
 */

require_once __DIR__ . '/_cert-path.php';
require_once __DIR__ . '/_cert-atomic.php';

function cert_manifest_file_map(array $row, int $version): array
{
    $vf = cert_path_version_files($row, $version);

    return [
        'pdf' => [
            'exists' => is_file($vf['pdf']),
            'path'   => 'pdf/' . basename($vf['pdf']),
        ],
        'metadata' => [
            'exists' => is_file($vf['metadata_json']),
            'path'   => 'meta/metadata.json',
        ],
        'verify' => [
            'exists' => is_file($vf['verify_json']),
            'path'   => 'verify/verify.json',
        ],
        'qr' => [
            'exists' => is_file($vf['qr_svg']),
            'path'   => 'qr/verify.svg',
        ],
        'payment_proof' => [
            'exists' => is_file($vf['payment_proof_json']),
            'path'   => 'proof/payment-proof.json',
        ],
        'mint_proof' => [
            'exists' => is_file($vf['mint_proof_json']),
            'path'   => 'proof/mint-proof.json',
        ],
    ];
}

function cert_manifest_payload(array $row, int $version): array
{
    $uid      = (string)($row['cert_uid'] ?? '');
    $rwaCode  = cert_path_detect_rwa_code($row);
    $category = cert_path_normalize_category((string)($row['family'] ?? ''), $rwaCode);
    $chain    = cert_path_chain($row);
    $bucket   = cert_path_user_bucket($row);
    $relRoot  = cert_path_rel_root($row);

    $meta = $row['meta_json_decoded'] ?? [];

    return [
        'cert_uid'      => $uid,
        'user_id'       => (int)($row['owner_user_id'] ?? 0),
        'user_bucket'   => $bucket,
        'rwa_category'  => $category,
        'rwa_code'      => $rwaCode,
        'chain'         => $chain,
        'version'       => max(1, $version),
        'status'        => (string)($row['status'] ?? 'issued'),
        'issued_at'     => (string)($row['issued_at'] ?? ''),
        'minted_at'     => (string)($row['minted_at'] ?? ''),
        'local_root'    => cert_path_local_root($row),
        'drive_root'    => cert_path_drive_root($row),
        'relative_root' => 'RWA_CERT/' . $relRoot,
        'files'         => cert_manifest_file_map($row, $version),
        'generated_at'  => date('c'),
        'generator'     => 'cert-manifest-builder@v1.0.0-20260404',
        'meta' => [
            'family'          => (string)($row['family'] ?? ''),
            'rwa_type'        => (string)($row['rwa_type'] ?? ''),
            'price_wems'      => (string)($row['price_wems'] ?? ''),
            'fingerprint_hash'=> (string)($row['fingerprint_hash'] ?? ''),
            'router_tx_hash'  => (string)($row['router_tx_hash'] ?? ''),
            'nft_item_address'=> (string)($row['nft_item_address'] ?? ''),
            'nft_minted'      => (int)($row['nft_minted'] ?? 0),
            'source_meta_keys'=> array_keys(is_array($meta) ? $meta : []),
        ],
    ];
}

function cert_manifest_write(array $row, int $version): array
{
    $vf = cert_path_version_files($row, $version);
    $root = $vf['root'];

    cert_path_mkdirs($root);

    $payload = cert_manifest_payload($row, $version);
    cert_atomic_write_json($vf['manifest_json'], $payload);

    return [
        'ok' => true,
        'path' => $vf['manifest_json'],
        'payload' => $payload,
    ];
}
