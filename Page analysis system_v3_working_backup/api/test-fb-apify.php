<?php
require __DIR__ . '/config.php';
require __DIR__ . '/apify-scraper.php';

$cfg = require __DIR__ . '/config.php';
$token = getValidApifyToken($cfg);

echo "Token: $token\n";
echo "Actor: " . $cfg['apis']['apify_actor_fb'] . "\n";

$url = 'https://www.facebook.com/alabeer.marketing';
$r = scrapeFacebook($url, $token, $cfg);

print_r($r);
