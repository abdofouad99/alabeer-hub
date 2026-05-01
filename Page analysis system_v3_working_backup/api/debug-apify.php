<?php
/**
 * debug-apify.php – اختبار نهائي للتحقق من صحة Instagram + Facebook + Ads
 */
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(300);

$cfg   = require __DIR__ . '/config.php';
$token = $cfg['apis']['apify_tokens'][0];  // أول توكن (الجديد)

echo "=== APIFY FINAL TEST ===\n";
echo "Token: ..." . substr($token, -8) . "\n\n";

function runActor(string $label, string $actorId, array $input, string $token, int $wait = 90): void {
    echo "── {$label} ──────────────────────────────────\n";
    echo "Actor : {$actorId}\n";

    $ch = curl_init("https://api.apify.com/v2/acts/{$actorId}/runs?token={$token}");
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($input),
    ]);
    $res  = json_decode(curl_exec($ch), true);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http !== 201 || empty($res['data']['id'])) {
        echo "❌ HTTP {$http}: " . ($res['error']['message'] ?? 'خطأ غير معروف') . "\n\n";
        return;
    }

    $runId = $res['data']['id'];
    echo "✅ Run ID: {$runId}\n⏳ انتظار النتائج...\n";

    $start = time();
    while (time() - $start < $wait) {
        sleep(5);
        $ch2 = curl_init("https://api.apify.com/v2/actor-runs/{$runId}?token={$token}");
        curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false]);
        $s = json_decode(curl_exec($ch2), true); curl_close($ch2);
        $status = $s['data']['status'] ?? '?';
        echo "   [" . (time()-$start) . "s] {$status}\n"; ob_flush(); flush();

        if (in_array($status, ['SUCCEEDED', 'FINISHED'])) {
            $dsId = $s['data']['defaultDatasetId'] ?? '';
            if ($dsId) {
                $ch3 = curl_init("https://api.apify.com/v2/datasets/{$dsId}/items?token={$token}&limit=1");
                curl_setopt_array($ch3, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false]);
                $items = json_decode(curl_exec($ch3), true); curl_close($ch3);
                echo "   عناصر: " . count($items ?? []) . "\n";
                if (!empty($items[0])) {
                    echo "   مفاتيح: " . implode(', ', array_keys($items[0])) . "\n";
                    // عرض بعض القيم المهمة
                    foreach (['username','fullName','followersCount','title','followers','likes','website','ad_count','ads'] as $k) {
                        if (isset($items[0][$k])) echo "   {$k}: " . json_encode($items[0][$k], JSON_UNESCAPED_UNICODE) . "\n";
                    }
                }
            }
            echo "✅ نجح\n\n"; return;
        }
        if (in_array($status, ['FAILED','ABORTED','TIMED-OUT'])) {
            echo "❌ فشل: {$status}\n\n"; return;
        }
    }
    echo "⌛ انتهت المهلة\n\n";
}

// 1. Instagram
runActor(
    'Instagram Profile Scraper',
    $cfg['apis']['apify_actor_ig'],
    ['usernames' => ['helmehoney']],
    $token
);

// 2. Facebook
runActor(
    'Facebook Pages Scraper',
    $cfg['apis']['apify_actor_fb'],
    ['startUrls' => [['url' => 'https://www.facebook.com/helmehoney']], 'maxPosts' => 5, 'scrapeAbout' => true],
    $token
);

// 3. Meta Ads Library (Actor الجديد الصحيح)
runActor(
    'Meta Ads Library (apify~facebook-ads-library-scraper)',
    $cfg['apis']['apify_actor_ads_fb'],
    ['searchPageOrURL' => 'helmehoney', 'country' => 'ALL'],
    $token
);

echo "=== DONE ===\n";
