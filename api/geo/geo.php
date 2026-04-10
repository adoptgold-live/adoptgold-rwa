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

if (!function_exists('rwa_geo_country_name_local')) {
    function rwa_geo_country_name_local(string $iso2, array $row): string
    {
        $iso2 = strtoupper(trim($iso2));
        if ($iso2 === 'MY') return 'Malaysia';
        if ($iso2 === 'CN') return '中国';

        $nameLocal = trim((string)($row['name_zh'] ?? $row['name_local'] ?? ''));
        $nameEn = trim((string)($row['name_en'] ?? ''));
        return $nameLocal !== '' ? $nameLocal : $nameEn;
    }
}

if (!function_exists('rwa_geo_country_tier')) {
    function rwa_geo_country_tier(string $iso2): int
    {
        $tier1 = ['MY', 'CN'];
        $tier2 = ['SG', 'TH', 'ID', 'PH', 'VN', 'BN', 'KH', 'LA', 'MM', 'TL'];
        $tier3 = ['HK', 'TW', 'JP', 'KR', 'IN'];

        $iso2 = strtoupper(trim($iso2));
        if (in_array($iso2, $tier1, true)) return 1;
        if (in_array($iso2, $tier2, true)) return 2;
        if (in_array($iso2, $tier3, true)) return 3;
        return 4;
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

    $stmtCountries = $pdo->query("
        SELECT
            iso2,
            name_en,
            COALESCE(name_zh, '') AS name_zh,
            COALESCE(calling_code, '') AS calling_code,
            COALESCE(flag_png, '') AS flag_png
        FROM countries
        WHERE COALESCE(is_enabled, 1) = 1
          AND UPPER(TRIM(iso2)) <> 'IL'
    ");
    $countryRows = $stmtCountries ? $stmtCountries->fetchAll(PDO::FETCH_ASSOC) : [];

    $countries = [];
    $prefixes = [];

    foreach ($countryRows as $row) {
        $iso2 = strtoupper(trim((string)($row['iso2'] ?? '')));
        if ($iso2 === '') continue;

        $nameEn = trim((string)($row['name_en'] ?? ''));
        $nameLocal = rwa_geo_country_name_local($iso2, $row);
        $callingCode = preg_replace('/\D+/', '', (string)($row['calling_code'] ?? ''));
        $flagPng = trim((string)($row['flag_png'] ?? ''));
        if ($flagPng === '') {
            $flagPng = '/rwa/assets/flags/' . strtolower($iso2) . '.png';
        }

        $tier = rwa_geo_country_tier($iso2);

        $countries[] = [
            'iso2'         => $iso2,
            'name_en'      => $nameEn,
            'name_local'   => $nameLocal,
            'display_name' => $lang === 'zh' ? $nameLocal : $nameEn,
            'calling_code' => $callingCode,
            'flag_png'     => $flagPng,
            'sort_tier'    => $tier,
        ];

        if ($callingCode !== '') {
            $prefixes[] = [
                'iso2'         => $iso2,
                'calling_code' => $callingCode,
                'prefix_label' => '+' . $callingCode,
                'name_en'      => $nameEn,
                'name_local'   => $nameLocal,
                'display_name' => $lang === 'zh' ? $nameLocal : $nameEn,
                'flag_png'     => $flagPng,
                'sort_tier'    => $tier,
            ];
        }
    }

    usort($countries, static function (array $a, array $b): int {
        $tierCmp = ($a['sort_tier'] <=> $b['sort_tier']);
        if ($tierCmp !== 0) return $tierCmp;
        if ($a['sort_tier'] === 1) {
            $order = ['MY' => 1, 'CN' => 2];
            return ($order[$a['iso2']] ?? 99) <=> ($order[$b['iso2']] ?? 99);
        }
        return strcasecmp((string)$a['name_en'], (string)$b['name_en']);
    });

    usort($prefixes, static function (array $a, array $b): int {
        $tierCmp = ($a['sort_tier'] <=> $b['sort_tier']);
        if ($tierCmp !== 0) return $tierCmp;
        if ($a['sort_tier'] === 1) {
            $order = ['MY' => 1, 'CN' => 2];
            return ($order[$a['iso2']] ?? 99) <=> ($order[$b['iso2']] ?? 99);
        }
        return strcasecmp((string)$a['name_en'], (string)$b['name_en']);
    });

    $states = [];
    if ($country !== '') {
        $stmtStates = $pdo->prepare("
            SELECT
                id,
                country_iso2,
                name_en,
                COALESCE(name_local, '') AS name_local
            FROM poado_states
            WHERE UPPER(TRIM(country_iso2)) = :country
              AND COALESCE(is_active, 1) = 1
            ORDER BY name_en ASC, name_local ASC, id ASC
        ");
        $stmtStates->execute([':country' => $country]);
        $stateRows = $stmtStates->fetchAll(PDO::FETCH_ASSOC);

        foreach ($stateRows as $row) {
            $nameEn = trim((string)($row['name_en'] ?? ''));
            $nameLocal = trim((string)($row['name_local'] ?? ''));
            if ($country === 'MY' && $nameLocal === '') {
                $nameLocal = $nameEn;
            }

            $states[] = [
                'id'           => (int)($row['id'] ?? 0),
                'country_iso2' => strtoupper(trim((string)($row['country_iso2'] ?? $country))),
                'name_en'      => $nameEn,
                'name_local'   => $nameLocal !== '' ? $nameLocal : $nameEn,
                'display_name' => $lang === 'zh'
                    ? ($nameLocal !== '' ? $nameLocal : $nameEn)
                    : $nameEn,
            ];
        }
    }

    $areas = [];
    if ($country !== '' && $stateId > 0) {
        $stmtAreas = $pdo->prepare("
            SELECT
                a.id,
                a.state_id,
                COALESCE(a.name_en, '') AS name_en,
                COALESCE(a.name_local, '') AS name_local
            FROM poado_areas a
            INNER JOIN poado_states s ON s.id = a.state_id
            WHERE a.state_id = :state_id
              AND UPPER(TRIM(s.country_iso2)) = :country
              AND COALESCE(a.is_active, 1) = 1
              AND COALESCE(s.is_active, 1) = 1
            ORDER BY a.name_en ASC, a.name_local ASC, a.id ASC
        ");
        $stmtAreas->execute([
            ':state_id' => $stateId,
            ':country'  => $country,
        ]);
        $areaRows = $stmtAreas->fetchAll(PDO::FETCH_ASSOC);

        foreach ($areaRows as $row) {
            $nameEn = trim((string)($row['name_en'] ?? ''));
            $nameLocal = trim((string)($row['name_local'] ?? ''));
            if ($country === 'MY' && $nameLocal === '') {
                $nameLocal = $nameEn;
            }

            $areas[] = [
                'id'           => (int)($row['id'] ?? 0),
                'state_id'     => (int)($row['state_id'] ?? 0),
                'name_en'      => $nameEn,
                'name_local'   => $nameLocal !== '' ? $nameLocal : $nameEn,
                'display_name' => $lang === 'zh'
                    ? ($nameLocal !== '' ? $nameLocal : $nameEn)
                    : $nameEn,
            ];
        }
    }

    rwa_geo_json_out([
        'ok'        => true,
        'lang'      => $lang,
        'ts'        => gmdate('c'),
        'country'   => $country,
        'state_id'  => $stateId,
        'countries' => array_values($countries),
        'prefixes'  => array_values($prefixes),
        'states'    => array_values($states),
        'areas'     => array_values($areas),
    ]);
} catch (Throwable $e) {
    rwa_geo_json_out([
        'ok'        => false,
        'lang'      => rwa_geo_lang(),
        'ts'        => gmdate('c'),
        'error'     => 'geo_fetch_failed',
        'country'   => strtoupper(trim((string)(
            $_GET['country_iso2']
            ?? $_POST['country_iso2']
            ?? $_GET['country']
            ?? $_POST['country']
            ?? ''
        ))),
        'state_id'  => (int)($_GET['state_id'] ?? $_POST['state_id'] ?? 0),
        'countries' => [],
        'prefixes'  => [],
        'states'    => [],
        'areas'     => [],
    ], 500);
}