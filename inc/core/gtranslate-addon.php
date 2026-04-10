<?php
/**
 * GTranslate Addon (popup.js) — Stable
 * - Uses popup.js + your locked settings (flag_size 24, flag_style 3d)
 * - 4 positions via env.php: bl | br | tl | tr
 * - Fix: popup container becomes scrollable (horizontal + vertical) to avoid truncated names
 * - Mobile safe-area aware
 * - Dedupe-safe (prevents double initialization)
 *
 * Usage (on every new page):
 *   require __DIR__ . '/inc/bootstrap.php';
 *   require __DIR__ . '/inc/gtranslate-addon.php';
 *   echo gtranslate_render_addon();
 */

if (!isset($ENV) || !is_array($ENV)) {
    $ENV = [];
}

/* ---------- helpers ---------- */
function gt_env(string $key, $default = null) {
    global $ENV;
    if (isset($ENV[$key]) && $ENV[$key] !== '') return $ENV[$key];
    $v = getenv($key);
    if ($v !== false && $v !== '') return $v;
    return $default;
}

function gt_bool($v): bool {
    if (is_bool($v)) return $v;
    return in_array(strtolower(trim((string)$v)), ['1','true','yes','on'], true);
}

function gt_pos_class(string $pos): string {
    return match (strtolower(trim($pos))) {
        'bl' => 'gt-pos-bl',
        'br' => 'gt-pos-br',
        'tl' => 'gt-pos-tl',
        'tr' => 'gt-pos-tr',
        default => 'gt-pos-br',
    };
}

/* ---------- render ---------- */
function gtranslate_render_addon(): string {

    if (!gt_bool(gt_env('GTRANSLATE_ENABLED', '1'))) {
        return '';
    }

    // locked script (popup.js)
    $script = (string) gt_env('GTRANSLATE_SCRIPT', 'https://cdn.gtranslate.net/widgets/latest/popup.js');

    // 4-position control
    $posClass = gt_pos_class((string) gt_env('GTRANSLATE_POSITION', 'bl'));

    // exact locked settings
    $settings = [
        "default_language"         => (string) gt_env('GTRANSLATE_DEFAULT_LANG', 'en'),
        "detect_browser_language" => gt_bool(gt_env('GTRANSLATE_DETECT_BROWSER', '1')),
        "wrapper_selector"        => ".gtranslate_wrapper",
        "flag_size"               => (int) gt_env('GTRANSLATE_FLAG_SIZE', '24'),
        "flag_style"              => (string) gt_env('GTRANSLATE_FLAG_STYLE', '3d'),
    ];

    $json = json_encode($settings, JSON_UNESCAPED_SLASHES);

    // Dedupe: If a page already injected GT, don't re-init settings, but CSS/anchor can exist safely.
    // popup.js may still load twice if a page hardcoded it; CSS fixes still help.
    return <<<HTML
<!-- ================= GTranslate Addon (popup.js) ================= -->
<style id="gt-addon-style">
/* anchor container for the trigger/widget */
.gt-anchor{
  position:fixed;
  z-index:99999;
  pointer-events:none;
}
.gt-anchor .gtranslate_wrapper{ pointer-events:auto; }

/* 4 positions (safe-area aware) */
.gt-pos-bl{ left:max(10px, env(safe-area-inset-left));  bottom:max(10px, env(safe-area-inset-bottom)); }
.gt-pos-br{ right:max(10px, env(safe-area-inset-right)); bottom:max(10px, env(safe-area-inset-bottom)); }
.gt-pos-tl{ left:max(10px, env(safe-area-inset-left));  top:max(10px, env(safe-area-inset-top)); }
.gt-pos-tr{ right:max(10px, env(safe-area-inset-right)); top:max(10px, env(safe-area-inset-top)); }

/* ==========================================================
   FIX: popup scrollable (left/right + vertical)
   Works even if popup is injected into <body> (not wrapper)
   ========================================================== */

/* Common popup containers across popup.js builds */
.gtranslate_popup,
.gt-popup,
[data-gtranslate-popup],
.gtranslate_wrapper_popup,
.switcher .dropdown-menu,
.switcher .dropdown{
  max-width: min(92vw, 720px) !important;
  max-height: min(70vh, 520px) !important;
  overflow-x: auto !important;
  overflow-y: auto !important;
  -webkit-overflow-scrolling: touch;
  box-sizing: border-box !important;
}

/* Keep entries in a single line so horizontal scroll works */
.gtranslate_popup *,
.gt-popup *,
[data-gtranslate-popup] *,
.gtranslate_wrapper_popup *,
.switcher .dropdown-menu *{
  white-space: nowrap !important;
}

/* Keep popup inside viewport on right edge */
.gtranslate_popup,
.gt-popup,
[data-gtranslate-popup],
.gtranslate_wrapper_popup{
  right: max(10px, env(safe-area-inset-right)) !important;
  left: auto !important;
}

/* Optional: visible horizontal scrollbar height (webkit browsers) */
.gtranslate_popup::-webkit-scrollbar,
.gt-popup::-webkit-scrollbar,
[data-gtranslate-popup]::-webkit-scrollbar{
  height: 10px;
  width: 10px;
}
</style>

<div id="gt-addon-anchor" class="gt-anchor {$posClass}">
  <div class="gtranslate_wrapper"></div>
</div>

<script id="gt-addon-settings">
if (!window.__GT_ADDON_SETTINGS_SET__) {
  window.__GT_ADDON_SETTINGS_SET__ = true;
  window.gtranslateSettings = {$json};
}
</script>

<script id="gt-addon-script" src="{$script}" defer></script>
<!-- ================= /GTranslate Addon ================= -->
HTML;
}
