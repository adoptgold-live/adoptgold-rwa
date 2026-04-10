<?php
declare(strict_types=1);

/**
 * /rwa/inc/core/validators.php
 * Standalone RWA Validators
 * Version: v1.0.20260314.1
 */

if (!function_exists('v_trim')) {
    function v_trim(?string $s): string
    {
        return trim((string)$s);
    }
}

if (!function_exists('v_lower')) {
    function v_lower(?string $s): string
    {
        return strtolower(v_trim($s));
    }
}

if (!function_exists('v_upper')) {
    function v_upper(?string $s): string
    {
        return strtoupper(v_trim($s));
    }
}

if (!function_exists('v_nonempty')) {
    function v_nonempty($v): bool
    {
        if (is_string($v)) {
            return trim($v) !== '';
        }
        return !empty($v);
    }
}

if (!function_exists('v_len_between')) {
    function v_len_between(?string $s, int $min, int $max): bool
    {
        $s = v_trim($s);
        $len = function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
        return $len >= $min && $len <= $max;
    }
}

if (!function_exists('v_email')) {
    function v_email(string $s): bool
    {
        return (bool)filter_var(v_trim($s), FILTER_VALIDATE_EMAIL);
    }
}

if (!function_exists('v_iso2')) {
    function v_iso2(string $s): bool
    {
        return (bool)preg_match('/^[A-Z]{2}$/', v_upper($s));
    }
}

if (!function_exists('v_phone_digits')) {
    function v_phone_digits(string $s): bool
    {
        return (bool)preg_match('/^[0-9]{1,15}$/', v_trim($s));
    }
}

if (!function_exists('v_phone_e164')) {
    function v_phone_e164(string $s): bool
    {
        return (bool)preg_match('/^\+[1-9][0-9]{1,14}$/', v_trim($s));
    }
}

if (!function_exists('v_calling_code')) {
    function v_calling_code(string $s): bool
    {
        return (bool)preg_match('/^[0-9]{1,4}$/', v_trim($s));
    }
}

if (!function_exists('v_date_ddmmyyyy')) {
    function v_date_ddmmyyyy(string $s): bool
    {
        if (!preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', v_trim($s), $m)) {
            return false;
        }

        $d  = (int)$m[1];
        $mo = (int)$m[2];
        $y  = (int)$m[3];

        return checkdate($mo, $d, $y);
    }
}

if (!function_exists('v_date_ymd')) {
    function v_date_ymd(string $s): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', v_trim($s), $m)) {
            return false;
        }

        $y  = (int)$m[1];
        $mo = (int)$m[2];
        $d  = (int)$m[3];

        return checkdate($mo, $d, $y);
    }
}

if (!function_exists('v_ddmmyyyy_to_ymd')) {
    function v_ddmmyyyy_to_ymd(string $s): ?string
    {
        if (!v_date_ddmmyyyy($s)) {
            return null;
        }

        preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', v_trim($s), $m);
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }
}

if (!function_exists('v_ymd_to_ddmmyyyy')) {
    function v_ymd_to_ddmmyyyy(string $s): ?string
    {
        if (!v_date_ymd($s)) {
            return null;
        }

        preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', v_trim($s), $m);
        return sprintf('%02d/%02d/%04d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }
}

if (!function_exists('v_time_hhmm')) {
    function v_time_hhmm(string $s): bool
    {
        if (!preg_match('/^(\d{2}):(\d{2})$/', v_trim($s), $m)) {
            return false;
        }

        $h  = (int)$m[1];
        $mi = (int)$m[2];

        if ($h < 0 || $h > 23 || $mi < 0 || $mi > 59) {
            return false;
        }

        // Booking rule 10:00–22:00 inclusive
        $t = ($h * 60) + $mi;
        return ($t >= 10 * 60 && $t <= 22 * 60);
    }
}

if (!function_exists('v_time_hhmm_any')) {
    function v_time_hhmm_any(string $s): bool
    {
        if (!preg_match('/^(\d{2}):(\d{2})$/', v_trim($s), $m)) {
            return false;
        }

        $h  = (int)$m[1];
        $mi = (int)$m[2];

        return !($h < 0 || $h > 23 || $mi < 0 || $mi > 59);
    }
}

if (!function_exists('v_time_minutes')) {
    function v_time_minutes(string $s): ?int
    {
        if (!v_time_hhmm_any($s)) {
            return null;
        }

        [$h, $m] = array_map('intval', explode(':', v_trim($s)));
        return ($h * 60) + $m;
    }
}

if (!function_exists('v_wallet_evm')) {
    function v_wallet_evm(string $s): bool
    {
        return (bool)preg_match('/^0x[a-fA-F0-9]{40}$/', v_trim($s));
    }
}

if (!function_exists('v_wallet_ton_raw')) {
    function v_wallet_ton_raw(string $s): bool
    {
        return (bool)preg_match('/^[0-9a-fA-F]{64}$/', v_trim($s));
    }
}

if (!function_exists('v_wallet_ton_friendly')) {
    function v_wallet_ton_friendly(string $s): bool
    {
        $s = v_trim($s);

        return (bool)preg_match('/^[UEk0][A-Za-z0-9_-]{47,55}$/', $s)
            || (bool)preg_match('/^[A-Za-z0-9_-]{48,60}$/', $s);
    }
}

if (!function_exists('v_wallet_ton')) {
    function v_wallet_ton(string $s): bool
    {
        return v_wallet_ton_raw($s) || v_wallet_ton_friendly($s);
    }
}

if (!function_exists('v_wallet_any')) {
    function v_wallet_any(string $s): bool
    {
        return v_wallet_evm($s) || v_wallet_ton($s);
    }
}

if (!function_exists('v_uid_hex8')) {
    function v_uid_hex8(string $s): bool
    {
        return (bool)preg_match('/^[A-Fa-f0-9]{8}$/', v_trim($s));
    }
}

if (!function_exists('v_cert_uid')) {
    function v_cert_uid(string $s): bool
    {
        return (bool)preg_match('/^[A-Z0-9-]+-\d{8}-[A-Fa-f0-9]{8}$/', v_trim($s));
    }
}

if (!function_exists('v_booking_uid')) {
    function v_booking_uid(string $s): bool
    {
        return v_len_between($s, 6, 120);
    }
}

if (!function_exists('v_deal_uid')) {
    function v_deal_uid(string $s): bool
    {
        return v_len_between($s, 6, 120);
    }
}

if (!function_exists('v_role_core')) {
    function v_role_core(string $s): bool
    {
        $s = v_lower($s);
        return in_array($s, ['adoptee', 'adopter', 'ace'], true);
    }
}

if (!function_exists('v_meeting_mode')) {
    function v_meeting_mode(string $s): bool
    {
        $s = v_lower($s);
        return in_array($s, ['online', 'offline'], true);
    }
}

if (!function_exists('v_url_http')) {
    function v_url_http(string $s): bool
    {
        $s = v_trim($s);
        if ($s === '') {
            return false;
        }

        if (!filter_var($s, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = strtolower((string)parse_url($s, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true);
    }
}

if (!function_exists('v_numeric_amount')) {
    function v_numeric_amount($v, int $maxDp = 18): bool
    {
        $s = trim((string)$v);
        if ($s === '') {
            return false;
        }

        return (bool)preg_match('/^\d+(\.\d{1,' . max(0, $maxDp) . '})?$/', $s);
    }
}

if (!function_exists('v_int_id')) {
    function v_int_id($v): bool
    {
        return is_numeric($v) && (int)$v > 0;
    }
}

if (!function_exists('v_enum')) {
    function v_enum(string $value, array $allowed, bool $strict = true): bool
    {
        return in_array($value, $allowed, $strict);
    }
}