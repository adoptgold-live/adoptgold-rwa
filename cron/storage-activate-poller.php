<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/storage/_bootstrap.php';

$pdo = storage_pdo();

$rows = $pdo->query("
    SELECT user_id, activation_ref
    FROM rwa_storage_cards
    WHERE COALESCE(is_active,0) = 0
      AND activation_ref IS NOT NULL
      AND activation_ref <> ''
    ORDER BY id DESC
    LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

$out = [];
foreach ($rows as $r) {
    try {
        $out[] = storage_verify_emx_activation_auto((int)$r['user_id'], (string)$r['activation_ref'], [
            'auto' => true,
        ]);
    } catch (Throwable $e) {
        $out[] = [
            'ok' => false,
            'code' => 'POLLER_ERROR',
            'activation_ref' => (string)$r['activation_ref'],
            'error' => $e->getMessage(),
        ];
    }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'checked' => count($out),
    'results' => $out,
], JSON_UNESCAPED_SLASHES);