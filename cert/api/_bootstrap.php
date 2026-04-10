<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';

if (!isset($GLOBALS['pdo']) && isset($pdo)) {
    $GLOBALS['pdo'] = $pdo;
}

function cert_api_ok(array $data = [], int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(array_merge(['ok'=>true,'ts'=>gmdate('c')], $data), JSON_UNESCAPED_SLASHES);
    exit;
}

function cert_api_fail(string $err, array $extra = [], int $code = 400): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(array_merge(['ok'=>false,'error'=>$err,'ts'=>gmdate('c')], $extra), JSON_UNESCAPED_SLASHES);
    exit;
}
