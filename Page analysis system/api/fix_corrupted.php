<?php
$path = __DIR__ . '/ai-analyze.php';
$content = file_get_contents($path);
$lines = explode("\n", $content);

// Fix line 1626 (index 1625) and 1627 (index 1626)
$fixed = 0;

// Find and fix the lines containing $score = $data[...] and $type = $data[...]
// inside fallbackAnalysis function
$inFallback = false;
for ($i = 0; $i < count($lines); $i++) {
    if (strpos($lines[$i], 'function fallbackAnalysis') !== false) {
        $inFallback = true;
    }
    if ($inFallback) {
        // Fix the $score line - replace any quote style around 'score'
        if (preg_match('/\$score\s*=\s*\$data/', $lines[$i])) {
            $lines[$i] = '    $score = $data["score"] ?? 50;';
            $fixed++;
        }
        // Fix the $type line - replace any quote style around 'type'
        if (preg_match('/\$type\s*=\s*\$data/', $lines[$i])) {
            $lines[$i] = '    $type  = $data["type"] ?? "general";';
            $fixed++;
        }
        // Stop after fixing both lines (once we find them after fallbackAnalysis)
        if ($fixed >= 2) break;
    }
}

$newContent = implode("\n", $lines);
if (file_put_contents($path, $newContent) !== false) {
    echo "<strong style='color:green'>SUCCESS! Fixed $fixed lines.</strong><br>";
    echo "<pre>";
    // Show lines around the fix
    $l = explode("\n", $newContent);
    for ($i = 1619; $i <= 1632 && $i < count($l); $i++) {
        echo ($i+1).": ".htmlspecialchars($l[$i])."\n";
    }
    echo "</pre>";
} else {
    echo "<strong style='color:red'>FAILED to write!</strong>";
}
