<?php
/**
 * CSP Override — auto-prepend via .user.ini
 *
 * Local by Flywheel injects a restrictive Content-Security-Policy
 * from the parent Apache/WordPress vhost config. The .htaccess
 * `Header always unset/set` approach does NOT work because the
 * parent config overrides it. But PHP header() runs after Apache
 * has set its headers, so we can REPLACE the CSP here.
 *
 * v2.0: Skips API endpoints to avoid interfering with JSON responses.
 * Only sets CSP for front-end PHP pages.
 *
 * NOTE: auto_prepend_file in .user.ini uses an ABSOLUTE path to
 * ensure this file is found regardless of which subdirectory the
 * PHP script is in (e.g., api/submit.php).
 */

// ── Skip entirely for API endpoints ──────────────────────────
// API files return JSON — CSP headers are harmless but we skip
// to be safe and avoid any potential output buffering issues.
$script = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
if (strpos($script, '/api/') !== false) {
    return;
}

// ── Also skip for non-HTML responses ────────────────────────
$uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
if (preg_match('/\.(json|xml|csv|txt|ico)(\?|$)/i', $uri)) {
    return;
}

// ── Set CSP header for front-end pages ─────────────────────
// This CSP allows external JS files (script-src 'self'),
// inline event handlers (unsafe-inline), eval (unsafe-eval),
// Google Fonts, and connections to external APIs.
header('Content-Security-Policy: default-src \'self\' \'unsafe-inline\' \'unsafe-eval\' data: blob: https:; img-src * data: blob: https: http:; style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com https:; font-src \'self\' https://fonts.googleapis.com https://fonts.gstatic.com; connect-src \'self\' https: http:; frame-src *; script-src \'self\' \'unsafe-inline\' \'unsafe-eval\' https: https://cdnjs.cloudflare.com;');
