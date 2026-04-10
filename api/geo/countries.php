<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/json.php';

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
        if (in_array($lang, ['zh', 'cn', 'zh-cn', 'zh_hans'], true)) {
            return 'zh';
        }
        return 'en';
    }
}

if (!function_exists('rwa_geo_country_name_local')) {
    function rwa_geo_country_name_local(string $iso2, array $row): string
    {
        $iso2 = strtoupper(trim($iso2));
        if ($iso2 === 'MY') {
            return 'Malaysia';
        }
        if ($iso2 === 'CN') {
            return '中国';
        }

        $nameLocal = trim((string)($row['name_zh'] ?? $row['name_local'] ?? ''));
        $nameEn    = trim((string)($row['name_en'] ?? ''));
        return $nameLocal !== '' ? $nameLocal : $nameEn;
    }
}

if (!function_exists('rwa_geo_country_tier')) {
    function rwa_geo_country_tier(string $iso2): int
    {
        $iso2 = strtoupper(trim($iso2));

        $tier1 = ['MY', 'CN'];
        $tier2 = ['SG', 'TH', 'ID', 'PH', 'VN', 'BN', 'KH', 'LA', 'MM', 'TL'];
        $tier3 = ['HK', 'TW', 'JP', 'KR', 'IN'];

        if (in_array($iso2, $tier1, true)) return 1;
        if (in_array($iso2, $tier2, true)) return 2;
        if (in_array($iso2, $tier3, true)) return 3;

        return 4;
    }
}

try {
    $pdo = rwa_db(); // expected from /rwa/inc/core/db.php
    $lang = rwa_geo_lang();

    $sql = "
        SELECT
            iso2,
            name_en,
            COALESCE(name_zh, '') AS name_zh,
            COALESCE(calling_code, '') AS calling_code,
            COALESCE(flag_png, '') AS flag_png,
            COALESCE(is_enabled, 1) AS is_enabled
        FROM countries
        WHERE COALESCE(is_enabled, 1) = 1
          AND UPPER(TRIM(iso2)) <> 'IL'
    ";

    $stmt = $pdo->query($sql);
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $items = [];
    foreach ($rows as $row) {
        $iso2 = strtoupper(trim((string)($row['iso2'] ?? '')));
        if ($iso2 === '') {
            continue;
        }

        $nameEn = trim((string)($row['name_en'] ?? ''));
        $nameLocal = rwa_geo_country_name_local($iso2, $row);
        $callingCode = preg_replace('/\D+/', '', (string)($row['calling_code'] ?? ''));
        $flagPng = trim((string)($row['flag_png'] ?? ''));

        if ($flagPng === '') {
            $flagPng = '/rwa/assets/flags/' . strtolower($iso2) . '.png';
        }

        $items[] = [
            'iso2'         => $iso2,
            'name_en'      => $nameEn,
            'name_local'   => $nameLocal,
            'display_name' => $lang === 'zh' ? $nameLocal : $nameEn,
            'calling_code' => $callingCode,
            'flag_png'     => $flagPng,
            'sort_tier'    => rwa_geo_country_tier($iso2),
        ];
    }

    usort($items, static function (array $a, array $b): int {
        $tierCmp = ($a['sort_tier'] <=> $b['sort_tier']);
        if ($tierCmp !== 0) {
            return $tierCmp;
        }

        if ($a['sort_tier'] === 1) {
            $order = ['MY' => 1, 'CN' => 2];
            return ($order[$a['iso2']] ?? 99) <=> ($order[$b['iso2']] ?? 99);
        }

        return strcasecmp((string)$a['name_en'], (string)$b['name_en']);
    });

    rwa_geo_json_out([
        'ok'    => true,
        'lang'  => $lang,
        'ts'    => gmdate('c'),
        'items' => array_values($items),
    ]);
} catch (Throwable $e) {
    rwa_geo_json_out([
        'ok'    => false,
        'lang'  => rwa_geo_lang(),
        'ts'    => gmdate('c'),
        'error' => 'countries_fetch_failed',
        'items' => [],
    ], 500);
}