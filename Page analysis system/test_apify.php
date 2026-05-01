<?php
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/apify-scraper.php';

$cfg = require __DIR__ . '/api/config.php';
$token = getValidApifyToken($cfg);

$url = 'https://www.facebook.com/alabeer.marketing';
$actorId = 'KoJrdxJCTtpon81KY';
$input = json_encode([
    'startUrls'    => [['url' => $url]],
    'resultsLimit' => 2,
    'captionText'  => false,
]);

$runId = _apifyStartRun($actorId, $input, $token);
if ($runId) {
    echo "Run started: $runId\n";
    $result = _apifyWaitAndFetch($runId, $token, 120);
    file_put_contents(__DIR__ . '/test_apify_output.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "Done.\n";
} else {
    echo "Failed to start run.\n";
}
