<?php
// Temporary diagnostic page — delete after use.
// Shows PHP paths and current CSP headers for debugging.
header('Content-Type: text/html; charset=utf-8');
echo '<html><body style="font-family:monospace;direction:ltr;white-space:pre;padding:40px;">';
echo "DOCUMENT_ROOT: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'N/A') . "\n\n";
echo "__DIR__ (this file): " . __DIR__ . "\n\n";
echo "SCRIPT_FILENAME: " . ($_SERVER['SCRIPT_FILENAME'] ?? 'N/A') . "\n\n";
echo "csp-override.php absolute path: " . __DIR__ . "/csp-override.php\n";
echo "File exists: " . (file_exists(__DIR__ . "/csp-override.php") ? 'YES' : 'NO') . "\n\n";

echo "Current response headers:\n";
foreach (headers_list() as $h) {
    if (stripos($h, 'content-security') !== false) {
        echo "  >>> $h\n";
    }
}
echo "\nRequest headers received:\n";
foreach ($_SERVER as $k => $v) {
    if (strpos($k, 'HTTP_') === 0) {
        $name = str_replace('_', '-', substr($k, 5));
        if (stripos($name, 'content-security') !== false) {
            echo "  >>> $name: $v\n";
        }
    }
}
echo '</body></html>';
