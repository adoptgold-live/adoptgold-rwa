<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/inc/core/avatar.php
 *
 * Canonical standalone RWA avatar helper.
 *
 * Global rules:
 * - TON wallet avatar = identicon from canonical users.wallet_address
 * - Fallback avatar = nickname initial placeholder
 * - Output is safe data:image/svg+xml URL for direct <img src="">
 * - Light avatar background for dark RWA UI
 */

if (!function_exists('rwa_avatar_svg_data_url')) {
    function rwa_avatar_svg_data_url(string $svg): string
    {
        return 'data:image/svg+xml;charset=UTF-8,' . rawurlencode($svg);
    }
}

if (!function_exists('rwa_avatar_escape')) {
    function rwa_avatar_escape(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('rwa_avatar_initial')) {
    function rwa_avatar_initial(?string $nickname = null, string $fallback = 'U'): string
    {
        $s = trim((string)$nickname);
        if ($s === '') {
            return strtoupper(substr($fallback, 0, 1));
        }

        if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
            return mb_strtoupper(mb_substr($s, 0, 1, 'UTF-8'), 'UTF-8');
        }

        return strtoupper(substr($s, 0, 1));
    }
}

if (!function_exists('rwa_avatar_placeholder_svg')) {
    function rwa_avatar_placeholder_svg(?string $nickname = null): string
    {
        $letter = rwa_avatar_escape(rwa_avatar_initial($nickname, 'U'));

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 160 160">
  <defs>
    <linearGradient id="rwaAvatarPlaceholderG" x1="0" x2="1" y1="0" y2="1">
      <stop offset="0%" stop-color="#b06cff"/>
      <stop offset="100%" stop-color="#7d4dff"/>
    </linearGradient>
  </defs>
  <rect width="160" height="160" rx="34" fill="#f7f3ff"/>
  <rect x="8" y="8" width="144" height="144" rx="28" fill="url(#rwaAvatarPlaceholderG)" opacity=".22"/>
  <text x="50%" y="54%" text-anchor="middle" font-size="68" font-family="ui-monospace, Menlo, Consolas, monospace" fill="#4f2aa8">{$letter}</text>
</svg>
SVG;
    }
}

if (!function_exists('rwa_ton_identicon_svg')) {
    function rwa_ton_identicon_svg(?string $seed = null): string
    {
        $s = trim((string)$seed);
        if ($s === '') {
            return rwa_avatar_placeholder_svg('U');
        }

        $hash = 0;
        $len  = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $hash = (($hash << 5) - $hash + ord($s[$i])) & 0xFFFFFFFF;
        }

        if ($hash & 0x80000000) {
            $hash = -((~$hash & 0xFFFFFFFF) + 1);
        }

        $hue = abs($hash) % 360;
        $c1  = "hsl({$hue} 95% 68%)";
        $c2  = 'hsl(' . (($hue + 58) % 360) . ' 92% 62%)';
        $c3  = 'hsl(' . (($hue + 130) % 360) . ' 88% 58%)';

        $bytes = [];
        $x = abs($hash);
        if ($x === 0) {
            $x = 123456789;
        }

        for ($i = 0; $i < 25; $i++) {
            $x = (int) ((1103515245 * $x + 12345) & 0x7fffffff);
            $bytes[] = $x & 255;
        }

        $cells = [];
        $size = 24;
        $gap  = 4;

        for ($row = 0; $row < 5; $row++) {
            for ($col = 0; $col < 3; $col++) {
                $b = $bytes[$row * 5 + $col];
                if (($b % 2) === 0) {
                    continue;
                }

                $fill = [$c1, $c2, $c3][$b % 3];
                $x1   = 18 + $col * ($size + $gap);
                $y1   = 18 + $row * ($size + $gap);
                $mx   = 18 + (4 - $col) * ($size + $gap);

                $cells[] = '<rect x="' . $x1 . '" y="' . $y1 . '" width="' . $size . '" height="' . $size . '" rx="7" fill="' . $fill . '" />';
                if ($col !== 2) {
                    $cells[] = '<rect x="' . $mx . '" y="' . $y1 . '" width="' . $size . '" height="' . $size . '" rx="7" fill="' . $fill . '" />';
                }
            }
        }

        $cellsSvg = implode('', $cells);

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 160 160">
  <rect width="160" height="160" rx="34" fill="#f7f3ff"/>
  <rect x="8" y="8" width="144" height="144" rx="28" fill="none" stroke="rgba(124,77,255,.22)"/>
  {$cellsSvg}
</svg>
SVG;
    }
}

if (!function_exists('rwa_avatar_src')) {
    /**
     * Canonical avatar priority:
     * wallet_address -> nickname placeholder
     */
    function rwa_avatar_src(?string $walletAddress = null, ?string $nickname = null): string
    {
        $wallet = trim((string)$walletAddress);
        if ($wallet !== '') {
            return rwa_avatar_svg_data_url(rwa_ton_identicon_svg($wallet));
        }

        return rwa_avatar_svg_data_url(rwa_avatar_placeholder_svg($nickname));
    }
}

if (!function_exists('rwa_avatar_img')) {
    /**
     * Render ready-to-use avatar <img>.
     */
    function rwa_avatar_img(
        ?string $walletAddress = null,
        ?string $nickname = null,
        string $alt = 'avatar',
        string $class = '',
        array $attrs = []
    ): string {
        $src = rwa_avatar_src($walletAddress, $nickname);

        $htmlAttrs = '';
        foreach ($attrs as $k => $v) {
            $k = trim((string)$k);
            if ($k === '') {
                continue;
            }
            $htmlAttrs .= ' ' . rwa_avatar_escape($k) . '="' . rwa_avatar_escape((string)$v) . '"';
        }

        $classAttr = trim($class) !== '' ? ' class="' . rwa_avatar_escape($class) . '"' : '';

        return '<img src="' . rwa_avatar_escape($src) . '" alt="' . rwa_avatar_escape($alt) . '"' . $classAttr . $htmlAttrs . '>';
    }
}