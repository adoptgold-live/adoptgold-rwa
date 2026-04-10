<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/api/_metadata-cdn.php
 * Version: v1.0.20260410-cdn-metadata-helper
 *
 * Purpose:
 * - Convert local metadata file paths under /var/www/html/public/rwa/metadata
 *   into public GitHub CDN URLs.
 * - Default delivery uses jsDelivr over the metadata repo.
 *
 * Canonical repo:
 *   adoptgold-live/adoptgold-rwa-metadata
 *
 * Default mutable channel:
 *   @main
 *
 * Optional immutable mode:
 *   set env RWA_METADATA_CDN_REF=<commit-hash-or-tag>
 */

if (function_exists('cert_metadata_cdn_url_from_local')) {
    return;
}

function cert_metadata_cdn_repo(): string
{
    return trim((string)($_ENV['RWA_METADATA_CDN_REPO'] ?? 'adoptgold-live/adoptgold-rwa-metadata'));
}

function cert_metadata_cdn_ref(): string
{
    $ref = trim((string)($_ENV['RWA_METADATA_CDN_REF'] ?? 'main'));
    return $ref !== '' ? $ref : 'main';
}

function cert_metadata_cdn_base(): string
{
    $custom = trim((string)($_ENV['RWA_METADATA_CDN_BASE'] ?? ''));
    if ($custom !== '') {
        return rtrim($custom, '/');
    }

    return 'https://cdn.jsdelivr.net/gh/' . cert_metadata_cdn_repo() . '@' . cert_metadata_cdn_ref();
}

function cert_metadata_local_root(): string
{
    return '/var/www/html/public/rwa/metadata';
}

function cert_metadata_cdn_url_from_relative(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    return cert_metadata_cdn_base() . '/' . $relativePath;
}

function cert_metadata_cdn_url_from_local(string $absolutePath): string
{
    $absolutePath = str_replace('\\', '/', $absolutePath);
    $root = rtrim(str_replace('\\', '/', cert_metadata_local_root()), '/');

    if (!str_starts_with($absolutePath, $root . '/')) {
        throw new RuntimeException('METADATA_PATH_OUTSIDE_LOCAL_ROOT');
    }

    $relative = ltrim(substr($absolutePath, strlen($root)), '/');
    if ($relative === '') {
        throw new RuntimeException('METADATA_RELATIVE_PATH_EMPTY');
    }

    return cert_metadata_cdn_url_from_relative($relative);
}

function cert_metadata_try_public_url(array $row = [], array $meta = []): string
{
    $candidates = [
        $meta['metadata_url'] ?? null,
        $meta['metadata_public_url'] ?? null,
        $row['metadata_url'] ?? null,
        $row['metadata_public_url'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate !== '' && preg_match('~^https?://~i', $candidate)) {
            return $candidate;
        }
    }

    $pathCandidates = [
        $meta['metadata_path'] ?? null,
        $row['metadata_path'] ?? null,
    ];

    foreach ($pathCandidates as $path) {
        $path = trim((string)$path);
        if ($path === '') {
            continue;
        }

        if (preg_match('~^https?://~i', $path)) {
            return $path;
        }

        if (str_starts_with($path, '/')) {
            return cert_metadata_cdn_url_from_local($path);
        }

        return cert_metadata_cdn_url_from_relative($path);
    }

    throw new RuntimeException('METADATA_PUBLIC_URL_NOT_RESOLVED');
}
