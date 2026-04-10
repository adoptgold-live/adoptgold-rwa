<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/api/_metadata-cdn.php
 * Version: v2.0.20260410-commit-hash-authority
 *
 * Canonical rule:
 * - Public NFT metadata URLs must use commit-hash based jsDelivr URLs.
 * - Fallback order:
 *   1) RWA_METADATA_CDN_REF from env
 *   2) current HEAD of /var/www/metadata-repo
 *   3) "main" as last-resort fallback
 */

if (function_exists('cert_metadata_cdn_url_from_local')) {
    return;
}

function cert_metadata_cdn_repo(): string
{
    return trim((string)($_ENV['RWA_METADATA_CDN_REPO'] ?? 'adoptgold-live/adoptgold-rwa-metadata'));
}

function cert_metadata_repo_git_dir(): string
{
    return '/var/www/metadata-repo/.git';
}

function cert_metadata_repo_head_hash(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $gitDir = cert_metadata_repo_git_dir();
    if (!is_dir($gitDir)) {
        $cached = 'main';
        return $cached;
    }

    $cmd = 'git --git-dir=' . escapeshellarg($gitDir) . ' rev-parse HEAD 2>/dev/null';
    $out = trim((string)shell_exec($cmd));

    if (preg_match('/^[0-9a-f]{40}$/i', $out)) {
        $cached = $out;
        return $cached;
    }

    $cached = 'main';
    return $cached;
}

function cert_metadata_cdn_ref(): string
{
    $envRef = trim((string)($_ENV['RWA_METADATA_CDN_REF'] ?? ''));
    if ($envRef !== '') {
        return $envRef;
    }

    return cert_metadata_repo_head_hash();
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
            if (str_contains($candidate, 'cdn.jsdelivr.net/gh/')) {
                return $candidate;
            }
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

function cert_metadata_cdn_debug_state(): array
{
    return [
        'repo' => cert_metadata_cdn_repo(),
        'ref'  => cert_metadata_cdn_ref(),
        'base' => cert_metadata_cdn_base(),
    ];
}
