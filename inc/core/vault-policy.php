<?php
declare(strict_types=1);

/**
 * Path: /var/www/html/public/rwa/inc/core/vault-policy.php
 * Version: v1.0.0-20260329-rwa-standalone-vault-policy
 */

if (defined('RWA_CORE_VAULT_POLICY_LOADED')) {
    return;
}
define('RWA_CORE_VAULT_POLICY_LOADED', true);

function rwa_vault_policy_normalize_relpath(string $relPath): string
{
    $relPath = trim(str_replace('\\', '/', $relPath));
    $relPath = preg_replace('~/+~', '/', $relPath);
    $relPath = ltrim((string)$relPath, '/');

    if ($relPath === '') {
        throw new RuntimeException('EMPTY_VAULT_PATH');
    }

    $parts = explode('/', $relPath);
    $safeParts = [];

    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '' || $part === '.' || $part === '..') {
            throw new RuntimeException('INVALID_VAULT_PATH_PART');
        }

        $part = preg_replace('~[^A-Za-z0-9._-]+~', '_', $part);
        $part = trim((string)$part, '_');

        if ($part === '') {
            throw new RuntimeException('EMPTY_VAULT_PATH_PART');
        }

        $safeParts[] = $part;
    }

    return implode('/', $safeParts);
}

function rwa_vault_policy_assert_relpath(string $relPath): string
{
    $safe = rwa_vault_policy_normalize_relpath($relPath);

    if (!preg_match('~^RWA_CERT/~', $safe)) {
        throw new RuntimeException('VAULT_PATH_MUST_START_WITH_RWA_CERT');
    }

    return $safe;
}

function rwa_vault_policy_filename(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        throw new RuntimeException('EMPTY_FILENAME');
    }

    $name = preg_replace('~[^A-Za-z0-9._-]+~', '_', $name);
    $name = trim((string)$name, '_');

    if ($name === '' || $name === '.' || $name === '..') {
        throw new RuntimeException('INVALID_FILENAME');
    }

    return $name;
}
