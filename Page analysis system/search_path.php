<?php
$text = file_get_contents(__DIR__ . '/js/report-connect.js');
$lines = explode("\n", $text);
foreach ($lines as $i => $line) {
    if (strpos($line, 'path =') !== false || strpos($line, ' path') !== false) {
        if (preg_match('/(const|let|var)\s+path\s*=/', $line)) {
            echo "Line " . ($i+1) . ": " . trim($line) . "\n";
        }
    }
}
