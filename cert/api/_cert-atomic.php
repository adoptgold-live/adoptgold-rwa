<?php
declare(strict_types=1);

function cert_atomic_mkdir(string $dir, int $mode = 0755): void
{
    if ($dir === '') {
        throw new RuntimeException('ATOMIC_MKDIR_EMPTY_PATH');
    }
    if (is_dir($dir)) {
        return;
    }
    if (!mkdir($dir, $mode, true) && !is_dir($dir)) {
        throw new RuntimeException('ATOMIC_MKDIR_FAILED:' . $dir);
    }
}

function cert_atomic_write_string(string $path, string $content): void
{
    $dir = dirname($path);
    cert_atomic_mkdir($dir);

    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $content) === false) {
        throw new RuntimeException('ATOMIC_WRITE_FAILED:' . $tmp);
    }

    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('ATOMIC_RENAME_FAILED:' . $path);
    }
}

function cert_atomic_write_json(string $path, array $payload): void
{
    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
    );

    if (!is_string($json)) {
        throw new RuntimeException('ATOMIC_JSON_ENCODE_FAILED:' . $path);
    }

    cert_atomic_write_string($path, $json . PHP_EOL);
}

function cert_atomic_copy_file(string $src, string $dst): void
{
    if (!is_file($src)) {
        throw new RuntimeException('ATOMIC_COPY_SOURCE_MISSING:' . $src);
    }

    $dir = dirname($dst);
    cert_atomic_mkdir($dir);

    $tmp = $dst . '.tmp';
    if (!copy($src, $tmp)) {
        throw new RuntimeException('ATOMIC_COPY_FAILED:' . $src . '=>' . $tmp);
    }

    if (!rename($tmp, $dst)) {
        @unlink($tmp);
        throw new RuntimeException('ATOMIC_COPY_RENAME_FAILED:' . $dst);
    }
}
