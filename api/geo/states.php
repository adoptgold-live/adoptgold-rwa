<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/db.php';

if (!function_exists('rwa_geo_json_out')) {
    function rwa_geo_json_out(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('rwa_geo_lang')) {
    function rwa_geo_lang(): string
    {
        $lang = strtolower(trim((string)($_GET['lang'] ?? $_POST['lang'] ?? '')));
        return in_array($lang, ['zh', 'cn', 'zh-cn', 'zh_hans'], true) ? 'zh' : 'en';
    }
}

try {
    $pdo = rwa_db();
    $lang = rwa_geo_lang();

    $country = strtoupper(trim((string)(
        $_GET['country_iso2']
        ?? $_POST['country_iso2']
        ?? $_GET['country']
        ?? $_POST['country']
        ?? ''
    )));

    if ($country === '') {
        rwa_geo_json_out([
            'ok'      => false,
            'lang'    => $lang,
            'ts'      => gmdate('c'),
            'error'   => 'country_required',
            'country' => '',
            'items'   => [],
        ], 422);
    }

    $sql = "
        SELECT
            id,
            country_iso2,
            name_en,
            COALESCE(name_local, '') AS name_local,
            COALESCE(is_active, 1) AS is_active
        FROM poado_states
        WHERE UPPER(TRIM(country_iso2)) = :country
          AND COALESCE(is_active, 1) = 1
        ORDER BY
            CASE WHEN COALESCE(name_en, '') = '' THEN 1 ELSE 0 END,
            name_en ASC,
            name_local ASC,
            id ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':country' => $country]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $items = [];
    foreach ($rows as $row) {
        $nameEn = trim((string)($row['name_en'] ?? ''));
        $nameLocal = trim((string)($row['name_local'] ?? ''));

        if ($country === 'MY' && $nameLocal === '') {
            $nameLocal = $nameEn;
        }

        $items[] = [
            'id'           => (int)($row['id'] ?? 0),
            'country_iso2' => strtoupper(trim((string)($row['country_iso2'] ?? $country))),
            'name_en'      => $nameEn,
            'name_local'   => $nameLocal !== '' ? $nameLocal : $nameEn,
            'display_name' => $lang === 'zh'
                ? ($nameLocal !== '' ? $nameLocal : $nameEn)
                : $nameEn,
        ];
    }

    rwa_geo_json_out([
        'ok'      => true,
        'lang'    => $lang,
        'ts'      => gmdate('c'),
        'country' => $country,
        'items'   => $items,
    ]);
} catch (Throwable $e) {
    rwa_geo_json_out([
        'ok'      => false,
        'lang'    => rwa_geo_lang(),
        'ts'      => gmdate('c'),
        'error'   => 'states_fetch_failed',
        'country' => strtoupper(trim((string)(
            $_GET['country_iso2']
            ?? $_POST['country_iso2']
            ?? $_GET['country']
            ?? $_POST['country']
            ?? ''
        ))),
        'items'   => [],
    ], 500);
}