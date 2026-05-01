<?php
header('Content-Type: application/json; charset=utf-8');
$content = file_get_contents(__DIR__ . '/ai-analyze.php');
if ($content === false) {
    echo json_encode(['error' => 'Failed to read file']);
    exit;
}

$lines = explode("\n", $content);
$openBraces = 0;
$mismatchLine = -1;
$stack = [];

for ($i = 0; $i < count($lines); $i++) {
    $line = $lines[$i];
    $lineNum = $i + 1;
    
    // Very basic brace counting (ignoring strings/comments for a rough estimate)
    // Actually, let's just use token_get_all to properly parse the PHP file
    // and find where the braces don't match.
}

$tokens = token_get_all($content);
$braces = [];
$lastTokenLine = 0;
foreach ($tokens as $token) {
    if (is_array($token)) {
        $lastTokenLine = $token[2];
    }
    if ($token === '{' || (is_array($token) && $token[0] == T_CURLY_OPEN)) {
        $braces[] = $lastTokenLine;
    } elseif ($token === '}') {
        array_pop($braces);
    }
}

echo json_encode([
    'unclosed_braces_lines' => $braces,
    'total_lines' => count($lines)
]);
