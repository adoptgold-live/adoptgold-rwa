<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/api/global/ema-price.php
 * AdoptGold / POAdo — Global EMA Price Formula
 * Version: v1.0.0-locked-20260318
 *
 * Global master lock:
 * - canonical EMA price formula path is /rwa/api/global/ema-price.php
 * - start date: 2026-01-01 00:00:00 UTC
 * - start price: 0.100000
 * - end date: 2036-01-01 00:00:00 UTC
 * - end price: 100.000000
 * - deterministic formula only
 * - no DB override
 * - no external source override
 */

if (!function_exists('poado_ema_price_config')) {
    function poado_ema_price_config(): array
    {
        return [
            'version' => 'v1.0.0-locked-20260318',
            'timezone' => 'UTC',
            'start_iso' => '2026-01-01T00:00:00Z',
            'end_iso' => '2036-01-01T00:00:00Z',
            'start_price' => '0.100000',
            'end_price' => '100.000000',
            'decimals' => 6,
        ];
    }
}

if (!function_exists('poado_ema_price_start_ts')) {
    function poado_ema_price_start_ts(): int
    {
        return strtotime('2026-01-01 00:00:00 UTC');
    }
}

if (!function_exists('poado_ema_price_end_ts')) {
    function poado_ema_price_end_ts(): int
    {
        return strtotime('2036-01-01 00:00:00 UTC');
    }
}

if (!function_exists('poado_ema_price_total_days')) {
    function poado_ema_price_total_days(): int
    {
        $days = (int) floor((poado_ema_price_end_ts() - poado_ema_price_start_ts()) / 86400);
        return $days > 0 ? $days : 3652;
    }
}

if (!function_exists('poado_ema_price_daily_growth')) {
    function poado_ema_price_daily_growth(): float
    {
        $cfg = poado_ema_price_config();
        $start = (float) $cfg['start_price'];
        $end = (float) $cfg['end_price'];
        $days = poado_ema_price_total_days();

        return pow(($end / $start), 1 / $days);
    }
}

if (!function_exists('poado_ema_price_days_elapsed')) {
    function poado_ema_price_days_elapsed(?int $ts = null): int
    {
        $ts = $ts ?? time();
        $startTs = poado_ema_price_start_ts();
        $endTs = poado_ema_price_end_ts();

        if ($ts <= $startTs) {
            return 0;
        }

        if ($ts >= $endTs) {
            return poado_ema_price_total_days();
        }

        return (int) floor(($ts - $startTs) / 86400);
    }
}

if (!function_exists('poado_ema_price_raw')) {
    function poado_ema_price_raw(?int $ts = null): float
    {
        $cfg = poado_ema_price_config();
        $startPrice = (float) $cfg['start_price'];
        $endPrice = (float) $cfg['end_price'];

        $ts = $ts ?? time();
        $startTs = poado_ema_price_start_ts();
        $endTs = poado_ema_price_end_ts();

        if ($ts <= $startTs) {
            return $startPrice;
        }

        if ($ts >= $endTs) {
            return $endPrice;
        }

        $daysElapsed = poado_ema_price_days_elapsed($ts);
        $dailyGrowth = poado_ema_price_daily_growth();

        return $startPrice * pow($dailyGrowth, $daysElapsed);
    }
}

if (!function_exists('poado_ema_price')) {
    function poado_ema_price(?int $ts = null): string
    {
        $cfg = poado_ema_price_config();
        $decimals = (int) ($cfg['decimals'] ?? 6);
        $price = poado_ema_price_raw($ts);

        return number_format($price, $decimals, '.', '');
    }
}

if (!function_exists('poado_ema_price_now')) {
    function poado_ema_price_now(): string
    {
        return poado_ema_price(time());
    }
}

if (!function_exists('poado_ema_price_meta')) {
    function poado_ema_price_meta(?int $ts = null): array
    {
        $cfg = poado_ema_price_config();
        $ts = $ts ?? time();
        $startTs = poado_ema_price_start_ts();
        $endTs = poado_ema_price_end_ts();
        $daysElapsed = poado_ema_price_days_elapsed($ts);
        $daysTotal = poado_ema_price_total_days();

        if ($ts <= $startTs) {
            $phase = 'prestart';
        } elseif ($ts >= $endTs) {
            $phase = 'capped';
        } else {
            $phase = 'active';
        }

        return [
            'version' => $cfg['version'],
            'timezone' => $cfg['timezone'],
            'start_iso' => $cfg['start_iso'],
            'end_iso' => $cfg['end_iso'],
            'start_ts' => $startTs,
            'end_ts' => $endTs,
            'query_ts' => $ts,
            'query_iso' => gmdate('c', $ts),
            'days_elapsed' => $daysElapsed,
            'days_total' => $daysTotal,
            'daily_growth' => number_format(poado_ema_price_daily_growth(), 12, '.', ''),
            'phase' => $phase,
            'price' => poado_ema_price($ts),
        ];
    }
}