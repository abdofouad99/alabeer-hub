<?php
$text = file_get_contents(__DIR__ . '/js/report-connect.js');
$lines = explode("\n", $text);
foreach ($lines as $i => $line) {
    if (strpos($line, 'renderAdsSection') !== false) {
        echo "Line " . ($i+1) . ": " . trim($line) . "\n";
    }
}
