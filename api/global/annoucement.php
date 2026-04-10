<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60, s-maxage=60');

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';

try {
    if (function_exists('db_connect')) {
        db_connect();
    }

    $rows = [
        [
            'id' => 'sys-portal',
            'title' => 'Public ecosystem portal online',
            'message' => 'Use explorer and verify tools to review public ecosystem indicators.',
            'level' => 'info',
            'sort_order' => 10,
        ],
        [
            'id' => 'ema-model',
            'title' => 'EMA reference projection visible',
            'message' => 'EMA display follows the launch-phase reference curve and ecosystem planning model.',
            'level' => 'notice',
            'sort_order' => 20,
        ],
        [
            'id' => 'rwa-entry',
            'title' => 'Standalone RWA entry active',
            'message' => 'Account access continues at /rwa/ through the standalone login flow.',
            'level' => 'success',
            'sort_order' => 30,
        ],
    ];

    echo json_encode([
        'ok' => true,
        'items' => $rows,
        'updated_at' => gmdate('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode([
        'ok' => false,
        'items' => [],
        'error' => 'announcement_unavailable',
        'updated_at' => gmdate('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}