<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/api/global/reference-curves.php
 *
 * Version: v1.0.20260329-dual-curve-api
 *
 * Changelog:
 * - Added canonical dual-curve public API
 * - Returns EMA$ + wEMS 10-year reference datasets
 * - EMA$ base reads from standalone helper when available
 * - wEMS locked reference curve = 0.001000 -> 1.000000 over 10 years
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/json.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/ema-price.php';

if (!function_exists('json_ok')) {
    header('Content-Type: application/json; charset=utf-8');
}

function ref_curve_build(float $start, float $end, int $years = 10, int $precision = 6): array
{
    $start = max($start, 0.000001);
    $end   = max($end, 0.000001);
    $years = max($years, 1);

    $factor = pow($end / $start, 1 / $years);

    $labels = [];
    $points = [];

    $price = $start;
    $labels[] = 'Year 0';
    $points[] = round($price, $precision);

    for ($year = 1; $year <= $years; $year++) {
        $price *= $factor;
        $labels[] = 'Year ' . $year;
        $points[] = round($price, $precision);
    }

    return [
        'labels' => $labels,
        'points' => $points,
        'start'  => round($start, $precision),
        'end'    => round($end, $precision),
        'years'  => $years,
    ];
}

function ref_curve_ema_base_price(): float
{
    // Preferred standalone helper patterns
    if (function_exists('ema_price_now')) {
        try {
            $v = (float) ema_price_now();
            if ($v > 0) {
                return $v;
            }
        } catch (Throwable $e) {
            // fall through
        }
    }

    if (function_exists('poado_ema_price_now')) {
        try {
            $v = (float) poado_ema_price_now();
            if ($v > 0) {
                return $v;
            }
        } catch (Throwable $e) {
            // fall through
        }
    }

    // Locked fallback baseline
    return 0.10;
}

try {
    $years = isset($_GET['years']) ? (int) $_GET['years'] : 10;
    $years = max(1, min(10, $years));

    $emaStart  = ref_curve_ema_base_price();
    $emaEnd    = 100.000000; // locked long-range target vision
    $wemsStart = 0.001000;   // locked public reference start
    $wemsEnd   = 1.000000;   // locked 10-year reference end

    $emaCurve  = ref_curve_build($emaStart, $emaEnd, $years, 6);
    $wemsCurve = ref_curve_build($wemsStart, $wemsEnd, $years, 6);

    $payload = [
        'ok' => true,
        'ts' => time(),
        'model' => [
            'type' => 'public_reference_curves',
            'scale' => 'log-friendly',
            'years' => $years,
            'wording' => 'launch-phase reference curve',
        ],
        'labels' => $emaCurve['labels'],
        'curves' => [
            'ema' => [
                'symbol' => 'EMA$',
                'name' => 'eMoney RWA Adoption Token',
                'start' => $emaCurve['start'],
                'end' => $emaCurve['end'],
                'points' => $emaCurve['points'],
                'note' => 'Reference projection model',
            ],
            'wems' => [
                'symbol' => 'wEMS',
                'name' => 'Web Gold',
                'start' => $wemsCurve['start'],
                'end' => $wemsCurve['end'],
                'points' => $wemsCurve['points'],
                'note' => '10-year Web Gold reference curve',
            ],
        ],
    ];

    if (function_exists('json_ok')) {
        json_ok($payload);
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    $err = [
        'ok' => false,
        'ts' => time(),
        'error' => 'reference_curve_api_failed',
        'message' => $e->getMessage(),
    ];

    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($err, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
