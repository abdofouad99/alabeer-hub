<?php
$content = file_get_contents(__DIR__ . '/ai-analyze.php');
$lines = explode("\n", $content);
$output = [];
for ($i = max(0, count($lines) - 200); $i < count($lines); $i++) {
    $output[$i + 1] = $lines[$i];
}
file_put_contents(__DIR__ . '/ai-analyze-tail.json', json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
