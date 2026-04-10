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
    $stateId = (int)($_GET['state_id'] ?? $_POST['state_id'] ?? 0);

    if ($stateId <= 0) {
        rwa_geo_json_out([
            'ok'       => true,
            'lang'     => $lang,
            'ts'       => gmdate('c'),
            'country'  => $country,
            'state_id' => 0,
            'items'    => [],
        ]);
    }

    // If country not supplied, derive it from poado_states by state_id
    if ($country === '') {
        $stmtCountry = $pdo->prepare("
            SELECT UPPER(TRIM(country_iso2)) AS country_iso2
            FROM poado_states
            WHERE id = :state_id
              AND COALESCE(is_active,1)=1
            LIMIT 1
        ");
        $stmtCountry->execute([':state_id' => $stateId]);
        $countryRow = $stmtCountry->fetch(PDO::FETCH_ASSOC);
        $country = strtoupper(trim((string)($countryRow['country_iso2'] ?? '')));
    }

    if ($country === '') {
        rwa_geo_json_out([
            'ok'       => false,
            'lang'     => $lang,
            'ts'       => gmdate('c'),
            'error'    => 'country_required',
            'country'  => '',
            'state_id' => $stateId,
            'items'    => [],
        ], 422);
    }

    $sql = "
        SELECT
            a.id,
            a.state_id,
            COALESCE(a.name_en, '') AS name_en,
            COALESCE(a.name_local, '') AS name_local
        FROM poado_areas a
        INNER JOIN poado_states s
            ON s.id = a.state_id
        WHERE a.state_id = :state_id
          AND UPPER(TRIM(s.country_iso2)) = :country
          AND COALESCE(a.is_active, 1) = 1
          AND COALESCE(s.is_active, 1) = 1
        ORDER BY
            CASE WHEN COALESCE(a.name_en, '') = '' THEN 1 ELSE 0 END,
            a.name_en ASC,
            a.name_local ASC,
            a.id ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':state_id' => $stateId,
        ':country'  => $country,
    ]);

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
            'state_id'     => (int)($row['state_id'] ?? 0),
            'name_en'      => $nameEn,
            'name_local'   => $nameLocal !== '' ? $nameLocal : $nameEn,
            'display_name' => $lang === 'zh'
                ? ($nameLocal !== '' ? $nameLocal : $nameEn)
                : $nameEn,
        ];
    }

    rwa_geo_json_out([
        'ok'       => true,
        'lang'     => $lang,
        'ts'       => gmdate('c'),
        'country'  => $country,
        'state_id' => $stateId,
        'items'    => $items,
    ]);
} catch (Throwable $e) {
    rwa_geo_json_out([
        'ok'       => false,
        'lang'     => rwa_geo_lang(),
        'ts'       => gmdate('c'),
        'error'    => 'areas_fetch_failed',
        'country'  => strtoupper(trim((string)(
            $_GET['country_iso2']
            ?? $_POST['country_iso2']
            ?? $_GET['country']
            ?? $_POST['country']
            ?? ''
        ))),
        'state_id' => (int)($_GET['state_id'] ?? $_POST['state_id'] ?? 0),
        'items'    => [],
    ], 500);
}