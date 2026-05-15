# برومبت إصلاح أدوات السحب — Page Analysis System

## المستودع: `abdofouad99/alabeer-hub`
## المسار: `Page analysis system/`

---

## تعليمات عامة للمبرمج:

1. افحص كل ملف مذكور بالسطر المحدد قبل التعديل
2. لا تمسح أي functionality موجودة — فقط أصلح/أضف
3. اعمل branch جديد: `fix/scraper-comprehensive-bugs`
4. كل إصلاح = commit منفصل برسالة واضحة
5. بعد الانتهاء اعمل PR → main

---

# ═══════════════════════════════════════════════════════════
# 📘 المجموعة 1: Facebook (3 مشاكل)
# ═══════════════════════════════════════════════════════════

## BUG-FB-1: Actor ثانٍ لا يُستدعى إذا followers موجود لكن reviews/services فارغة

**الملف:** `api/apify-scraper.php`
**السطر:** 541
**الكود الحالي:**
```php
if (empty($followers) && empty($email) && empty($phone) && empty($website)) {
```

**المشكلة:** الشرط يشترط أن كل الأربعة فارغة. لو `followers` موجود لكن `email` و `phone` فارغين — لا يُستدعى Actor الثاني وتبقى التقييمات (`reviews`) والخدمات (`services`) والساعات (`hours`) فارغة.

**الإصلاح المطلوب:** غيّر الشرط ليستدعي Actor الثاني أيضاً عند غياب reviews/services:
```php
if ((empty($followers) && empty($email) && empty($phone) && empty($website))
    || (empty($reviews) && empty($services) && empty($hours))) {
```

---

## BUG-FB-2: `_extractReviews` لا تعالج recommendation من نوع boolean

**الملف:** `api/apify-scraper.php`
**الدالة:** `_extractReviews()` (ابحث عنها بـ `function _extractReviews`)
**السطر التقريبي:** بعد استخراج `$rating`

**الكود الحالي:**
```php
$rating = $r['rating'] ?? $r['stars'] ?? $r['recommendation'] ?? null;
// ...
if (is_numeric($rating)) {
    $normalizedRating = (float)$rating;
} elseif (is_string($rating)) {
    // ...
}
```

**المشكلة:** لو Apify أرجع `"recommendation": true` (boolean) — لا يُعالَج لأنه ليس numeric ولا string.

**الإصلاح المطلوب:** أضف شرط `is_bool` قبل `is_string`:
```php
if (is_numeric($rating)) {
    $normalizedRating = (float)$rating;
} elseif (is_bool($rating)) {
    $normalizedRating = $rating ? 5.0 : 1.0;
} elseif (is_string($rating)) {
    $low = strtolower($rating);
    if (in_array($low, ['positive','recommends','recommend'], true)) $normalizedRating = 5.0;
    elseif (in_array($low, ['negative','doesnt-recommend','dont-recommend'], true)) $normalizedRating = 1.0;
}
```

---

## BUG-FB-3: waitTime 150 ثانية يتسبب بـ PHP timeout

**الملف:** `api/apify-scraper.php`
**السطر:** ~480 (بحث عن `_apifyWaitAndFetch($runId, $token, 150)`)

**المشكلة:** لو `max_execution_time` في PHP = 120 ثانية، السكربت يموت قبل الحصول على النتيجة.

**الإصلاح المطلوب:** في ملف `api/analyze.php` و `api/run.php` — أضف في البداية (بعد `<?php`):
```php
set_time_limit(300); // 5 دقائق لضمان اكتمال جميع Apify actors
```

---


# ═══════════════════════════════════════════════════════════
# 📸 المجموعة 2: Instagram (2 مشاكل)
# ═══════════════════════════════════════════════════════════

## BUG-IG-1: `resultsType: 'details'` يُرجع 12-24 منشور فقط بدلاً من 100

**الملف:** `api/apify-scraper.php`
**السطر:** 1067-1072

**الكود الحالي:**
```php
$isOfficial = (str_contains($actorId, 'apify/instagram-scraper') || $actorId === 'shu8hvrXbJbY3Eb9W');
if (str_contains($actorId, 'instagram-profile-scraper')) {
    $inputData = ['usernames' => [$username], 'resultsLimit' => 100];
} elseif ($isOfficial) {
    $inputData = [
        'directUrls'    => [$profileUrl],
        'resultsType'   => 'details',   // ← هذا يُرجع profile + latestPosts محدودة!
        'resultsLimit'  => 100,
        'addParentData' => true,
        'searchLimit'   => 1,
    ];
}
```

**المشكلة:** `resultsType: 'details'` في `apify/instagram-scraper` يُرجع profile مع `latestPosts[]` محدود (12-24 منشور). الحقل `resultsLimit: 100` يتجاهله الـ Actor في وضع details.

**الإصلاح المطلوب:** غيّر `resultsType` إلى `'posts'` واحتفظ بـ `addParentData: true` لجلب بيانات البروفايل مع المنشورات:
```php
} elseif ($isOfficial) {
    $inputData = [
        'directUrls'    => [$profileUrl],
        'resultsType'   => 'posts',      // ✅ يجلب 100 منشور فعلاً
        'resultsLimit'  => 100,
        'addParentData' => true,          // يُضمّن بيانات البروفايل مع كل منشور
        'searchLimit'   => 1,
    ];
}
```

---

## BUG-IG-2: `calcPostsPerWeek` يُعطي نتائج خاطئة للحسابات القديمة

**الملف:** `api/apify-scraper.php`
**السطر:** 2230-2237

**الكود الحالي:**
```php
function calcPostsPerWeek(array $posts): float {
    if (count($posts) < 2) return 0;
    $timestamps = array_filter(array_map(fn($p) => strtotime($p['timestamp'] ?? $p['takenAt'] ?? ''), $posts));
    if (count($timestamps) < 2) return 0;
    $range = (max($timestamps) - min($timestamps));
    if ($range <= 0) return 0;
    return round(count($timestamps) / ($range / 604800), 1);
}
```

**المشكلة:** لو سحبت 100 منشور وأقدمها من 3 سنوات، يُحسب: `100 / 156 أسبوع = 0.6` — وهو غير دقيق لأنك تنظر لعينة فقط وليس كل المنشورات.

**الإصلاح المطلوب:** استخدم آخر 30 يوماً فقط لحساب المعدل الحالي:
```php
function calcPostsPerWeek(array $posts): float {
    if (count($posts) < 2) return 0;
    $timestamps = array_filter(array_map(fn($p) => strtotime($p['timestamp'] ?? $p['takenAt'] ?? ''), $posts));
    if (count($timestamps) < 2) return 0;
    
    // استخدم آخر 30 يوم فقط لحساب المعدل الحالي (أدق من range كامل)
    $now = time();
    $thirtyDaysAgo = $now - (30 * 86400);
    $recentPosts = array_filter($timestamps, fn($ts) => $ts >= $thirtyDaysAgo);
    
    if (count($recentPosts) >= 2) {
        // معدل بناءً على آخر 30 يوم
        return round(count($recentPosts) / (30 / 7), 1);
    }
    
    // fallback: لو أقل من 2 منشور في 30 يوم، استخدم range كامل
    $range = (max($timestamps) - min($timestamps));
    if ($range <= 0) return 0;
    return round(count($timestamps) / ($range / 604800), 1);
}
```

**ملاحظة:** هذه الدالة تُستخدم في Facebook, Instagram, TikTok, Twitter — الإصلاح يُحسّن الجميع.

---


# ═══════════════════════════════════════════════════════════
# 🎵 المجموعة 3: TikTok (2 مشاكل)
# ═══════════════════════════════════════════════════════════

## BUG-TT-1: Multi-actor fallback بدون blacklist = وقت ضائع

**الملف:** `api/apify-scraper.php`
**السطر:** 1392-1398

**الكود الحالي:**
```php
$primaryActor = $cfg['apis']['apify_actor_tiktok'] ?? 'clockworks/tiktok-scraper';
$candidates = array_values(array_unique(array_filter([
    $primaryActor,
    'clockworks/tiktok-scraper',
    'clockworks/free-tiktok-scraper',
    '0FXVyOXXEmdGcV88a',
])));
```

**المشكلة:** لو Actor معلّق أو محذوف، `_apifyStartRun` تنتظر 30 ثانية timeout ثم تفشل. إذا فشلت 3 actors = 90 ثانية ضائعة + Compute Units محروقة.

**الإصلاح المطلوب:** أضف blacklist مؤقت (file-based) للـ actors الفاشلة:
```php
// أضف هذه الدالة المساعدة (يمكن وضعها قبل scrapeTikTok أو في ملف helpers)
function _isActorBlacklisted(string $actorId): bool {
    $file = sys_get_temp_dir() . '/apify_blacklist_' . md5($actorId);
    if (!file_exists($file)) return false;
    // blacklist لمدة ساعة واحدة
    return (time() - filemtime($file)) < 3600;
}

function _blacklistActor(string $actorId): void {
    $file = sys_get_temp_dir() . '/apify_blacklist_' . md5($actorId);
    file_put_contents($file, time());
}
```

ثم في loop الـ candidates (TikTok سطر ~1410 و Twitter سطر ~1762):
```php
foreach ($candidates as $actorId) {
    if (_isActorBlacklisted($actorId)) continue; // ✅ تخطي actors فاشلة
    
    // ... الكود الحالي ...
    
    $runId = _apifyStartRun($actorId, $input, $token);
    if (!$runId) {
        _blacklistActor($actorId); // ✅ سجّل الفشل
        continue;
    }
    $result = _apifyWaitAndFetch($runId, $token, ...);
    if (!$result) {
        _blacklistActor($actorId); // ✅ سجّل الفشل
        continue;
    }
    // ... معالجة النتيجة ...
}
```

---

## BUG-TT-2: لا يوجد Comments Sentiment لتيك توك (موجود لـ FB و IG)

**الملف:** `api/apify-scraper.php`
**المكان:** داخل `scrapeTikTok()` — قبل `return [...]` (سطر ~1620)

**المشكلة:** في Facebook: `analyzeFBCommentsSentiment()` يُستدعى تلقائياً. في Instagram: `analyzeIGCommentsSentiment()` يُستدعى تلقائياً. في TikTok: **لا شيء** — رغم أن `scrapePostComments()` تدعم `platform === 'tiktok'`.

**الإصلاح المطلوب:** أضف قبل `return [...]` في `scrapeTikTok()`:
```php
// Comments Sentiment (اختياري — مكافئ لـ FB/IG)
$sentiment = null;
if (!empty($cfg['analysis']['enable_tt_comments'] ?? true)) {
    try {
        // سحب تعليقات أفضل 3 فيديوهات
        $topForComments = array_slice($topVideos, 0, 3);
        $allComments = [];
        foreach ($topForComments as $tv) {
            $tvUrl = $tv['url'] ?? '';
            if (!$tvUrl) continue;
            $cResult = scrapePostComments($tvUrl, 'tiktok', $token, 30);
            if ($cResult['success'] ?? false) {
                $allComments = array_merge($allComments, $cResult['sample_phrases'] ?? []);
            }
        }
        if (count($allComments) >= 5) {
            $sentiment = [
                'success' => true,
                'total_comments' => count($allComments),
                'samples' => array_slice($allComments, 0, 15),
            ];
        }
    } catch (\Throwable $e) {
        logError('TikTok comments failed', ['err' => $e->getMessage()]);
    }
}
```

ثم أضف في مصفوفة الـ return:
```php
'comments_sentiment' => $sentiment,
```

وأضف في `.env.example`:
```
ENABLE_TT_COMMENTS=true
```

وفي `config.example.php` تحت analysis:
```php
'enable_tt_comments' => filter_var($get('ENABLE_TT_COMMENTS', 'true'), FILTER_VALIDATE_BOOLEAN),
```

---


# ═══════════════════════════════════════════════════════════
# 𝕏 المجموعة 4: Twitter/X (4 مشاكل)
# ═══════════════════════════════════════════════════════════

## BUG-TW-1: 7 actors = حتى 17.5 دقيقة timeout + استهلاك مفرط

**الملف:** `api/apify-scraper.php`
**السطر:** 1723-1731

**الكود الحالي:**
```php
$candidates = array_values(array_unique(array_filter([
    $primaryActor,
    'apidojo/tweet-scraper',
    'kaitoeasyapi/twitter-x-data-tweet-scraper-pay-per-result-cheapest',
    'apidojo/twitter-scraper-lite',
    'kaitoeasyapi/twitter-x-profile-scraper',
    'shanes/twitter-profile-scraper',
    'u6ppkMWAx2E2MpEuF',
])));
```

**الإصلاح المطلوب:**
1. قلّل إلى 3 actors max (الأكثر استقراراً)
2. قلّل timeout من 150 إلى 90 ثانية
3. أضف blacklist (نفس BUG-TT-1)

```php
$candidates = array_values(array_unique(array_filter([
    $primaryActor,
    'apidojo/tweet-scraper',
    'kaitoeasyapi/twitter-x-data-tweet-scraper-pay-per-result-cheapest',
])));
// حد أقصى 3 actors لتجنب timeout مفرط
$candidates = array_slice($candidates, 0, 3);
```

وغيّر timeout من 150 إلى 90:
```php
$result = _apifyWaitAndFetch($runId, $token, 90, $maxTweets + 5); // كان 150
```

---

## BUG-TW-2: Schema موحّد يُرسل حقول غير مدعومة لبعض Actors

**الملف:** `api/apify-scraper.php`
**السطر:** ~1762-1785

**المشكلة:** بعض Actors تفشل (400 Bad Request) عند تلقي حقول غير معروفة مثل `twitterHandles` أو `searchTerms`.

**الإصلاح المطلوب:** أنشئ input مخصص لكل actor بدلاً من schema موحّد:
```php
// بناء input حسب الـ actor المحدد
if (str_contains($actorId, 'tweet-scraper') || str_contains($actorId, 'apidojo')) {
    $input = json_encode([
        'startUrls' => [$profileUrlTwitter, $profileUrlX],
        'maxItems'  => $maxTweets,
        'addUserInfo' => true,
        'sort' => 'Latest',
    ]);
} elseif (str_contains($actorId, 'profile-scraper') || str_contains($actorId, 'kaitoeasyapi')) {
    $input = json_encode([
        'handles' => [$username],
        'tweetsDesired' => $maxTweets,
        'getAbout' => true,
    ]);
} else {
    // fallback عام
    $input = json_encode([
        'startUrls' => [$profileUrlTwitter],
        'twitterHandles' => [$username],
        'maxItems' => $maxTweets,
        'addUserInfo' => true,
    ]);
}
```

---

## BUG-TW-3: profile fallback يأخذ أول تغريدة بدلاً من بروفايل حقيقي

**الملف:** `api/apify-scraper.php`
**السطر:** ~1850

**الكود الحالي:**
```php
if (!$profile && !empty($result[0])) {
    $profile = $result[0]; // ← قد يكون تغريدة!
}
```

**الإصلاح المطلوب:**
```php
if (!$profile && !empty($result[0])) {
    // تأكد أنه بروفايل فعلاً (يحتوي followers أو screen_name)
    $candidate = $result[0];
    if (isset($candidate['followers']) || isset($candidate['followersCount']) 
        || isset($candidate['followers_count']) || isset($candidate['screenName'])
        || isset($candidate['screen_name'])) {
        $profile = $candidate;
    }
}
```

---

## BUG-TW-4: لا يوجد Health Score أو Deep Analysis مخصص لتويتر

**الملف:** `api/apify-scraper.php`
**المكان:** قبل `return` في `scrapeTwitter()` (سطر ~1935)

**المشكلة:** Twitter يستخدم `analyzeDeepContent()` العام (المصمم لـ FB/IG) — لا يفهم threads, spaces, أو tweet-specific metrics.

**الإصلاح المطلوب (اختياري — أولوية منخفضة):** أضف دالة `calcTwitterHealthScore()` بسيطة:
```php
function calcTwitterHealthScore(array $data): array {
    $score = 0; $issues = []; $strengths = [];
    $followers = (int)($data['followers'] ?? 0);
    $eng = (float)($data['engagement_rate'] ?? 0);
    $postsPerWeek = (float)($data['posts_per_week'] ?? 0);
    $verified = (bool)($data['is_verified'] ?? false);
    
    if ($postsPerWeek >= 5) { $score += 25; $strengths[] = 'نشاط ممتاز'; }
    elseif ($postsPerWeek >= 2) { $score += 15; }
    elseif ($postsPerWeek > 0) { $score += 5; $issues[] = 'نشر متباعد'; }
    else $issues[] = 'لا نشاط';
    
    if ($eng >= 3) { $score += 25; $strengths[] = 'تفاعل ممتاز'; }
    elseif ($eng >= 1) { $score += 15; }
    elseif ($eng > 0) { $score += 5; }
    else $issues[] = 'تفاعل ضعيف';
    
    if ($followers >= 100000) $score += 20;
    elseif ($followers >= 10000) $score += 15;
    elseif ($followers >= 1000) $score += 10;
    else $issues[] = 'جمهور صغير';
    
    if ($verified) { $score += 15; $strengths[] = 'حساب موثّق'; }
    if (!empty($data['website'])) { $score += 5; }
    if (!empty($data['bio']) && mb_strlen($data['bio']) >= 50) { $score += 10; $strengths[] = 'Bio محسّن'; }
    else $issues[] = 'Bio قصير أو فارغ';
    
    $score = min(100, $score);
    $grade = $score >= 80 ? 'A' : ($score >= 65 ? 'B' : ($score >= 50 ? 'C' : ($score >= 35 ? 'D' : 'F')));
    return ['score' => $score, 'grade' => $grade, 'strengths' => $strengths, 'issues' => $issues];
}
```

---


# ═══════════════════════════════════════════════════════════
# 🌐 المجموعة 5: الموقع الإلكتروني (5 مشاكل — الأضعف)
# ═══════════════════════════════════════════════════════════

## BUG-WEB-1: صفحة واحدة فقط (homepage) — لا multi-page crawl

**الملف:** `api/page-scan.php`
**الدالة:** `scanWebsiteHTML()` (سطر 796)

**المشكلة:** يفحص URL واحد فقط. لا يتبع أي روابط داخلية. 90% من محتوى الموقع مخفي.

**الإصلاح المطلوب:** أضف دالة جديدة `scanWebsiteMultiPage()` تُكمّل بيانات `scanWebsiteHTML()`:
```php
function scanWebsiteMultiPage(string $baseUrl, string $html, array $cfg): array {
    $extra = ['pages_crawled' => 1, 'all_services' => [], 'all_products' => [], 'tech_stack' => []];
    
    // 1) استخراج الروابط الداخلية من الصفحة الرئيسية
    $baseDomain = parse_url($baseUrl, PHP_URL_HOST);
    preg_match_all('/href=["\']([^"\']+)["\']/', $html, $links);
    $internalLinks = [];
    foreach (($links[1] ?? []) as $link) {
        $link = trim($link);
        if (str_starts_with($link, '/') && !str_starts_with($link, '//')) {
            $link = rtrim($baseUrl, '/') . $link;
        }
        if (!filter_var($link, FILTER_VALIDATE_URL)) continue;
        $linkHost = parse_url($link, PHP_URL_HOST);
        if ($linkHost !== $baseDomain) continue;
        $internalLinks[] = $link;
    }
    $internalLinks = array_unique($internalLinks);
    
    // 2) فحص أهم 4 صفحات فقط (لتجنب الثقل)
    $priorityPaths = ['/about', '/services', '/products', '/contact', '/pricing', '/من-نحن', '/خدماتنا'];
    $toScan = [];
    foreach ($internalLinks as $link) {
        $path = strtolower(parse_url($link, PHP_URL_PATH) ?? '');
        foreach ($priorityPaths as $pp) {
            if (str_contains($path, $pp)) { $toScan[] = $link; break; }
        }
        if (count($toScan) >= 4) break;
    }
    
    // 3) سحب كل صفحة واستخراج محتوى إضافي
    foreach ($toScan as $pageUrl) {
        $pageHtml = fetchHtml($pageUrl, 10);
        if (!$pageHtml) continue;
        $extra['pages_crawled']++;
        
        // استخراج خدمات/منتجات من H2/H3
        preg_match_all('/<h[23][^>]*>(.*?)<\/h[23]>/is', $pageHtml, $hm);
        foreach (($hm[1] ?? []) as $h) {
            $t = trim(strip_tags($h));
            if (mb_strlen($t) > 3 && mb_strlen($t) < 100) $extra['all_services'][] = $t;
        }
    }
    $extra['all_services'] = array_slice(array_unique($extra['all_services']), 0, 25);
    $extra['internal_links_count'] = count($internalLinks);
    
    return $extra;
}
```

ثم في `_fetchAndScanWebsite()` (أو في `runPageScan` عند مسار website):
```php
$ws = scanWebsiteHTML($url, $cfg);
// ✅ إضافة multi-page crawl
if (!empty($ws) && !isset($ws['error'])) {
    $html = fetchHtml($url, 12); // أعد جلب HTML (أو خزّنه من scanWebsiteHTML)
    if ($html) {
        $multiPage = scanWebsiteMultiPage($url, $html, $cfg);
        $ws = array_merge($ws, $multiPage);
    }
}
```

---

## BUG-WEB-2: `services_list` تلتقط أي `<li>` (navigation + footer)

**الملف:** `api/page-scan.php`
**السطر:** ~869-876 (داخل `scanWebsiteHTML()`)

**الكود الحالي:**
```php
preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $html, $liMatches);
foreach (($liMatches[1] ?? []) as $li) {
    $text = trim(strip_tags($li));
    if (mb_strlen($text) > 5 && mb_strlen($text) < 100 && !preg_match('/^\d+$/', $text)) {
        $services[] = $text;
    }
}
```

**المشكلة:** يأخذ عناصر navigation, footer links, sidebar كـ "خدمات".

**الإصلاح المطلوب:** استبعد عناصر `<nav>`, `<header>`, `<footer>`:
```php
// إزالة nav, header, footer قبل البحث عن services
$cleanHtml = preg_replace('/<nav[^>]*>.*?<\/nav>/is', '', $html);
$cleanHtml = preg_replace('/<header[^>]*>.*?<\/header>/is', '', $cleanHtml);
$cleanHtml = preg_replace('/<footer[^>]*>.*?<\/footer>/is', '', $cleanHtml);

preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $cleanHtml, $liMatches);
foreach (($liMatches[1] ?? []) as $li) {
    $text = trim(strip_tags($li));
    // استبعاد العناصر القصيرة جداً أو التي تبدو روابط تنقل
    if (mb_strlen($text) > 10 && mb_strlen($text) < 100 
        && !preg_match('/^\d+$/', $text)
        && !preg_match('/^(home|about|contact|الرئيسية|من نحن|اتصل|تسجيل|دخول)$/iu', $text)) {
        $services[] = $text;
    }
}
```

---

## BUG-WEB-3: Apify Puppeteer لا يقدّم قيمة إضافية (3.5s wait + HTML فقط)

**الملف:** `api/apify-scraper.php`
**الدالة:** `scrapeWebsiteApify()` (سطر ~2374)

**الكود الحالي:**
```php
$pageFunction = "async function pageFunction(context) {
    await new Promise(r => setTimeout(r, 3500));
    return { html: await context.page.content() };
}";
```

**الإصلاح المطلوب:** أضف screenshot + console errors + performance metrics:
```php
$pageFunction = "async function pageFunction(context) {
    const page = context.page;
    
    // انتظار تحميل الشبكة
    await page.waitForNetworkIdle({timeout: 8000}).catch(() => {});
    
    // Scroll لتحميل lazy content
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight / 2));
    await new Promise(r => setTimeout(r, 1500));
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await new Promise(r => setTimeout(r, 1500));
    
    // جمع البيانات
    const html = await page.content();
    const metrics = await page.metrics();
    const consoleErrors = [];
    page.on('console', msg => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });
    
    // Performance timing
    const perf = await page.evaluate(() => {
        const t = performance.timing;
        return {
            dom_load: t.domContentLoadedEventEnd - t.navigationStart,
            full_load: t.loadEventEnd - t.navigationStart,
        };
    });
    
    return { html, metrics, perf, console_errors: consoleErrors.slice(0, 10) };
}";
```

---

## BUG-WEB-4: `speed_rating` مبني على cURL time (مضلّل)

**الملف:** `api/page-scan.php`
**السطر:** ~890 (داخل return array)

**الكود الحالي:**
```php
'speed_rating' => $loadTime < 2 ? 'ممتاز' : ($loadTime < 4 ? 'جيد' : 'بطيء'),
```

**المشكلة:** cURL يقيس وقت تحميل HTML فقط — بدون CSS, JS, images. موقع بـ 15MB صور يظهر "ممتاز".

**الإصلاح المطلوب:** أضف حقل `speed_note` يوضّح أن القياس أولي:
```php
'speed_rating'    => $loadTime < 2 ? 'ممتاز' : ($loadTime < 4 ? 'جيد' : 'بطيء'),
'speed_note'      => 'قياس أوّلي (HTML فقط) — استخدم PageSpeed لقياس شامل',
'html_size_kb'    => round(strlen($html) / 1024, 1),
```

والحل الأفضل: **فعّل PageSpeed افتراضياً** (بدون key يعمل بحد 25 طلب/يوم):
في `config.example.php` سطر ~75:
```php
'enable_pagespeed' => filter_var($get('ENABLE_PAGESPEED', 'true'), FILTER_VALIDATE_BOOLEAN), // كان false
```

---

## BUG-WEB-5: لا يوجد Tech Stack Detection

**الملف:** `api/page-scan.php`
**المكان:** داخل `scanWebsiteHTML()` — قبل الـ return (سطر ~883)

**الإصلاح المطلوب:** أضف:
```php
// Tech Stack Detection
$techStack = [];
if (preg_match('/wp-content|wp-includes|wordpress/i', $html)) $techStack[] = 'WordPress';
if (preg_match('/cdn\.shopify\.com|shopify/i', $html)) $techStack[] = 'Shopify';
if (preg_match('/wix\.com|wixstatic/i', $html)) $techStack[] = 'Wix';
if (preg_match('/squarespace/i', $html)) $techStack[] = 'Squarespace';
if (preg_match('/__next|_next\/static/i', $html)) $techStack[] = 'Next.js';
if (preg_match('/react|reactDOM/i', $html)) $techStack[] = 'React';
if (preg_match('/vue\.js|vuejs\.org|v-bind|v-if/i', $html)) $techStack[] = 'Vue.js';
if (preg_match('/angular\.io|ng-version/i', $html)) $techStack[] = 'Angular';
if (preg_match('/bootstrap/i', $html)) $techStack[] = 'Bootstrap';
if (preg_match('/tailwindcss|tailwind/i', $html)) $techStack[] = 'Tailwind CSS';
if (preg_match('/jquery/i', $html)) $techStack[] = 'jQuery';
if (preg_match('/laravel|csrf-token.*content/i', $html)) $techStack[] = 'Laravel';
if (preg_match('/generator.*content=.*joomla/i', $html)) $techStack[] = 'Joomla';
if (preg_match('/generator.*content=.*drupal/i', $html)) $techStack[] = 'Drupal';
```

ثم في return array:
```php
'tech_stack' => $techStack,
```

---


# ═══════════════════════════════════════════════════════════
# 📊 المجموعة 6: Ads Library (1 مشكلة)
# ═══════════════════════════════════════════════════════════

## BUG-ADS-1: `is_active` غير دقيق لإعلانات بدون status واضح

**الملف:** `api/apify-scraper.php`
**السطر:** 190

**الكود الحالي:**
```php
'is_active' => ($ad['isActive'] ?? $ad['is_active'] ?? false) || $status === 'active',
```

**المشكلة:** بعض Actors يُرجعون `ad_delivery_status: ""` (فارغ) — فيُعتبر غير نشط حتى لو بدأ أمس.

**الإصلاح المطلوب:**
```php
'is_active' => ($ad['isActive'] ?? $ad['is_active'] ?? false) 
            || $status === 'active'
            || ($status === '' && !empty($ad['startDate'] ?? $ad['start_date'] ?? $ad['ad_creation_time'])),
```

---

# ═══════════════════════════════════════════════════════════
# 🏆 المجموعة 7: المنافسين (2 مشاكل)
# ═══════════════════════════════════════════════════════════

## BUG-COMP-1: نتائج Google قد تحتوي الموقع الأصلي نفسه

**الملف:** `api/apify-scraper.php`
**الدالة:** `scrapeCompetitorsViaGoogle()` (سطر ~240)

**الكود الحالي:**
```php
$competitors[] = [
    'name' => $title,
    'url' => $url,
    'description' => $desc
];
```

**الإصلاح المطلوب:** أضف filter يستبعد الموقع الأصلي + مواقع الأخبار العامة:
```php
// تمرير URL الأصلي كمعامل إضافي للدالة
function scrapeCompetitorsViaGoogle(string $companyName, string $targetAudience, string $token, string $originalUrl = ''): array {
    // ...
    $excludeDomains = ['youtube.com','wikipedia.org','linkedin.com','twitter.com','x.com','instagram.com','facebook.com','tiktok.com'];
    $originalDomain = parse_url($originalUrl, PHP_URL_HOST) ?? '';
    
    foreach ($result as $item) {
        $url = $item['url'] ?? '';
        $domain = parse_url($url, PHP_URL_HOST) ?? '';
        
        // استبعاد الموقع الأصلي
        if ($originalDomain && str_contains($domain, str_replace('www.', '', $originalDomain))) continue;
        // استبعاد مواقع عامة
        $skip = false;
        foreach ($excludeDomains as $ed) { if (str_contains($domain, $ed)) { $skip = true; break; } }
        if ($skip) continue;
        
        $competitors[] = ['name' => $title, 'url' => $url, 'description' => $desc];
    }
}
```

---

## BUG-COMP-2: `enrichCompetitorsData` معطّل = بيانات المقارنة فارغة

**الملف:** `api/config.example.php`

**الحالة الحالية:** `enable_competitor_enrich` = `false` — يعني تحصل فقط على اسم + رابط بدون followers/engagement/ads.

**الإصلاح المقترح (ليس bug بل تحسين):** بدلاً من `runPageScan` كامل لكل منافس (×6 actors)، أنشئ `lightScanCompetitor()` سريع:
```php
function lightScanCompetitor(string $url, array $cfg): array {
    // فحص OG Tags فقط (بدون Apify) — سريع ومجاني
    $og = scanOGTags($url);
    $ws = scanWebsiteHTML($url, $cfg);
    return [
        'title'     => $og['title'] ?? '',
        'followers' => $og['followers'] ?? null,
        'has_ssl'   => $ws['has_ssl'] ?? false,
        'has_pixel' => $ws['has_fb_pixel'] ?? false,
        'has_ga'    => $ws['has_ga'] ?? false,
        'has_cta'   => $ws['has_cta'] ?? false,
    ];
}
```

---

# ═══════════════════════════════════════════════════════════
# 💬 المجموعة 8: التعليقات/Sentiment (1 مشكلة)
# ═══════════════════════════════════════════════════════════

## BUG-SENT-1: Heuristic sentiment بدائي (كلمة "لا" = سلبي!)

**الملف:** `api/facebook-deep.php`
**الدالة:** `_fbHeuristicSentiment()` (سطر 609)
**وأيضاً:** نفس المنطق في `instagram-deep.php`

**المشكلة:** كلمة "لا" وحدها = سلبي! لكن "لا يوجد أفضل منكم" = إيجابي. كلمة "غالي" = سلبي لكن "الجودة تستاهل ولو غالي" = إيجابي.

**الإصلاح المطلوب:** 
1. أزل "لا" من `$negWords` (كلمة عامة جداً)
2. أضف فحص سياقي بسيط:

```php
$negWords = ['غالي','مكلف','سيء','وحش','رديء','ضعيف','فاشل','تأخر','تعطل','مزعج','خايس','خداع','نصب','احتيال','bad','worst','expensive','scam','poor','rude','slow','terrible'];
// ✅ أزلنا "لا" — كلمة عامة جداً تسبب false positives

// فحص سياقي: لو "غالي" مع كلمة إيجابية = neutral وليس negative
$negExceptions = ['يستاهل','يستحق','بس','لكن الجودة','worth'];
```

وعدّل loop الفحص:
```php
$isNeg = false;
foreach ($negWords as $w) {
    if (mb_stripos($t, $w) !== false) {
        // فحص سياقي: هل توجد كلمة استثناء بالقرب؟
        $hasException = false;
        foreach ($negExceptions as $ex) {
            if (mb_stripos($t, $ex) !== false) { $hasException = true; break; }
        }
        if (!$hasException) { $isNeg = true; break; }
    }
}
```

---

# ═══════════════════════════════════════════════════════════
# 📍 المجموعة 9: Google Maps (1 مشكلة)
# ═══════════════════════════════════════════════════════════

## BUG-MAPS-1: لا يُكتشف Google Maps URL تلقائياً من HTML الموقع

**الملف:** `api/analyze.php`
**السطر:** 812-818

**الكود الحالي:**
```php
if (!$mapsUrl) {
    $wsLinks = $scanResult['website_scan']['links'] ?? [];
    foreach ((array)$wsLinks as $link) {
        if (is_string($link) && str_contains($link, 'google.com/maps')) {
            $mapsUrl = $link; break;
        }
    }
}
```

**المشكلة:** `$scanResult['website_scan']['links']` **غير موجود**! `scanWebsiteHTML()` لا تُرجع حقل `links`. لذلك هذا الكود لا يعمل أبداً.

**الإصلاح المطلوب:** في `scanWebsiteHTML()` (ملف `page-scan.php`)، أضف استخراج Google Maps URL:
```php
// قبل الـ return في scanWebsiteHTML:
$googleMapsUrl = null;
if (preg_match('/https?:\/\/(?:maps\.google\.com|www\.google\.com\/maps|goo\.gl\/maps)[^\s"\'<>]+/i', $html, $mMaps)) {
    $googleMapsUrl = $mMaps[0];
}
// أيضاً iframe embedded maps
if (!$googleMapsUrl && preg_match('/src=["\']([^"\']*google\.com\/maps\/embed[^"\']*)/i', $html, $mEmbed)) {
    $googleMapsUrl = $mEmbed[1];
}
```

ثم في return array:
```php
'google_maps_url' => $googleMapsUrl,
```

وفي `analyze.php` سطر 812، عدّل:
```php
if (!$mapsUrl) {
    // ✅ جلب من website_scan مباشرة (الحقل الجديد)
    $mapsUrl = $scanResult['website_scan']['google_maps_url'] ?? '';
}
if (!$mapsUrl) {
    // fallback: بحث في HTML خام عن أي رابط maps
    $wsHtml = fetchHtml($scanResult['website'] ?? '', 8);
    if ($wsHtml && preg_match('/https?:\/\/(?:maps\.google|goo\.gl\/maps|google\.com\/maps)[^\s"\'<>]+/i', $wsHtml, $mM)) {
        $mapsUrl = $mM[0];
    }
}
```

---

# ═══════════════════════════════════════════════════════════
# ⚠️ المجموعة 10: مشاكل عامة (تؤثر على كل المنصات)
# ═══════════════════════════════════════════════════════════

## BUG-GLOBAL-1: CORS مفتوح بالكامل في scan.php

**الملف:** `api/scan.php`
**السطر:** 11

**الكود الحالي:**
```php
header('Access-Control-Allow-Origin: *');
```

**الإصلاح المطلوب:** حدّد الـ origins المسموحة:
```php
$allowedOrigins = [
    'https://yourdomain.com',
    'https://www.yourdomain.com',
    'http://localhost',
    'http://localhost:3000',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: https://yourdomain.com');
}
```

**أو** على الأقل أضف Rate Limiting:
```php
// بسيط: حد 10 طلبات لكل IP في الدقيقة
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$rateFile = sys_get_temp_dir() . '/rate_' . md5($ip) . '_' . date('YmdHi');
$count = file_exists($rateFile) ? (int)file_get_contents($rateFile) : 0;
if ($count >= 10) {
    http_response_code(429);
    echo json_encode(['error' => 'تم تجاوز الحد المسموح. حاول بعد دقيقة.']);
    exit;
}
file_put_contents($rateFile, $count + 1);
```

---

## BUG-GLOBAL-2: `getValidApifyToken()` يتحقق كل PHP process (5 ثوانٍ ضائعة)

**الملف:** `api/apify-scraper.php`
**السطر:** 30-73

**المشكلة:** `static $cachedToken` يُخزَّن فقط داخل العملية الحالية. كل طلب HTTP جديد = validation جديد = 5 ثوانٍ ضائعة.

**الإصلاح المطلوب:** خزّن Token الصالح في file cache:
```php
function getValidApifyToken(array $cfg): string {
    $tokens = $cfg['apis']['apify_tokens'] ?? [];
    if (empty($tokens)) return '';

    static $cachedToken = null;
    if ($cachedToken !== null) return $cachedToken;

    // ✅ تحقق من file cache أولاً (يدوم 15 دقيقة)
    $cacheFile = sys_get_temp_dir() . '/apify_valid_token_' . md5(implode(',', $tokens));
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 900) {
        $saved = trim(file_get_contents($cacheFile));
        if ($saved !== '' && in_array($saved, $tokens)) {
            $cachedToken = $saved;
            return $cachedToken;
        }
    }

    // ... الكود الحالي للـ validation ...
    
    // ✅ بعد إيجاد token صالح، خزّنه في file
    file_put_contents($cacheFile, $cachedToken);
    return $cachedToken;
}
```

---

## BUG-GLOBAL-3: لا يوجد Cache لنتائج الفحص (نفس الرابط = فحص كامل كل مرة)

**الملف:** `api/page-scan.php`
**الدالة:** `runPageScan()` (سطر 16)

**الإصلاح المطلوب:** أضف في بداية `runPageScan()`:
```php
function runPageScan(string $rawUrl, array $cfg): array {
    $url = normalizeUrl($rawUrl);
    if (!$url) return ['success' => false, 'error' => 'الرابط غير صالح'];
    
    // ✅ Cache: لو نفس الرابط فُحص خلال آخر ساعة، أرجع النتيجة المخزنة
    $cacheKey = md5($url);
    $cacheFile = (__DIR__ . '/../cache/scan_' . $cacheKey . '.json');
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached && ($cached['success'] ?? false)) {
            $cached['from_cache'] = true;
            return $cached;
        }
    }
    
    // ... باقي الكود الحالي ...
    
    // ✅ قبل الـ return الأخير، خزّن النتيجة:
    $cacheDir = __DIR__ . '/../cache';
    if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);
    file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE));
    
    return $result;
}
```

---

# ═══════════════════════════════════════════════════════════
# 📝 ملخص الإصلاحات المطلوبة
# ═══════════════════════════════════════════════════════════

| # | المنصة | الملف | النوع | الأولوية |
|---|---|---|---|---|
| FB-1 | Facebook | apify-scraper.php:541 | شرط ناقص | 🔴 عالي |
| FB-2 | Facebook | apify-scraper.php (_extractReviews) | boolean handling | 🟡 متوسط |
| FB-3 | Facebook | analyze.php + run.php | timeout | 🔴 عالي |
| IG-1 | Instagram | apify-scraper.php:1069 | resultsType خاطئ | 🔴 عالي |
| IG-2 | Instagram | apify-scraper.php:2230 | حساب خاطئ | 🟠 مهم |
| TT-1 | TikTok | apify-scraper.php:1392 | blacklist actors | 🟠 مهم |
| TT-2 | TikTok | apify-scraper.php (~1620) | sentiment مفقود | 🟡 متوسط |
| TW-1 | Twitter | apify-scraper.php:1723 | 7 actors = بطيء | 🔴 عالي |
| TW-2 | Twitter | apify-scraper.php:~1762 | schema غلط | 🟠 مهم |
| TW-3 | Twitter | apify-scraper.php:~1850 | profile fallback | 🟡 متوسط |
| TW-4 | Twitter | (ملف جديد أو نفسه) | health score | 🟢 منخفض |
| WEB-1 | Website | page-scan.php:796 | multi-page | 🟠 مهم |
| WEB-2 | Website | page-scan.php:~869 | services خاطئة | 🔴 عالي |
| WEB-3 | Website | apify-scraper.php:~2374 | puppeteer سطحي | 🟡 متوسط |
| WEB-4 | Website | page-scan.php:~890 | speed مضلّل | 🟡 متوسط |
| WEB-5 | Website | page-scan.php (جديد) | tech stack | 🟡 متوسط |
| ADS-1 | Ads | apify-scraper.php:190 | is_active خاطئ | 🟠 مهم |
| COMP-1 | المنافسين | apify-scraper.php:~240 | self-inclusion | 🟠 مهم |
| COMP-2 | المنافسين | config.example.php | enrich معطّل | 🟢 منخفض |
| SENT-1 | Sentiment | facebook-deep.php:609 | false positives | 🟠 مهم |
| MAPS-1 | Maps | analyze.php:812 + page-scan.php | detection مكسور | 🔴 عالي |
| GLB-1 | عام | scan.php:11 | CORS مفتوح | 🔴 عالي |
| GLB-2 | عام | apify-scraper.php:30 | token cache | 🟠 مهم |
| GLB-3 | عام | page-scan.php:16 | scan cache | 🟠 مهم |

---

## ترتيب التنفيذ المقترح:

### المرحلة 1 (عاجل — يمنع هدر الموارد):
1. GLB-1 (CORS)
2. GLB-2 (Token cache)
3. GLB-3 (Scan cache)
4. FB-3 (timeout)
5. TW-1 (7 actors)

### المرحلة 2 (مهم — يُحسّن دقة النتائج):
6. IG-1 (resultsType)
7. WEB-2 (services)
8. MAPS-1 (Maps detection)
9. FB-1 (reviews/services)
10. ADS-1 (is_active)
11. IG-2 (posts/week)
12. COMP-1 (self-exclusion)
13. SENT-1 (false positives)
14. TT-1 (blacklist)
15. TW-2 (schema)

### المرحلة 3 (تحسينات):
16. WEB-1 (multi-page)
17. WEB-5 (tech stack)
18. FB-2 (boolean review)
19. TT-2 (TT comments)
20. TW-3 (profile fallback)
21. WEB-3 (puppeteer)
22. WEB-4 (speed note)
23. TW-4 (health score)
24. COMP-2 (light enrich)
