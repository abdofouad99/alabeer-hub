<?php
$text = file_get_contents(__DIR__ . '/js/report-connect.js');
$lines = explode("\n", $text);

$stack = [];
foreach ($lines as $i => $line) {
    $lineNo = $i + 1;
    // VERY simple regex matching, assuming no strings/comments mess it up for now
    // Just count { and } roughly. Better yet, let's strip single line comments and strings.
    $clean = preg_replace('#//.*$#', '', $line);
    $clean = preg_replace('#/\*.*?\*/#s', '', $clean);
    $clean = preg_replace("/'.*?'/", '', $clean);
    $clean = preg_replace('/".*?"/', '', $clean);
    $clean = preg_replace('/`.*?`/s', '', $clean); // multi-line strings will break this logic, but let's try
    
    $openCount = substr_count($clean, '{');
    $closeCount = substr_count($clean, '}');
    
    for($j=0; $j<$openCount; $j++) $stack[] = $lineNo;
    for($j=0; $j<$closeCount; $j++) {
        if(count($stack) > 0) array_pop($stack);
    }
    
    if ($lineNo >= 490 && $lineNo <= 5365) {
        if (strpos($line, '} catch') !== false) {
            echo "CATCH FOUND at $lineNo. Stack depth: " . count($stack) . "\n";
        }
    }
}
echo "Final Stack depth: " . count($stack) . "\n";
if (count($stack) > 0) {
    echo "Unclosed from lines: " . implode(', ', $stack) . "\n";
}
