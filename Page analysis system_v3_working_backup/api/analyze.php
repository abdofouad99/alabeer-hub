<?php
// ============================================================
// api/analyze.php — محرك التحليل v4.0 (Auto-Detection — لا استبيان)
// الدرجة 100% مبنية على بيانات حقيقية من Apify + فحص HTML
// ============================================================

function clamp(float $n, float $min, float $max): float {
    return max($min, min($max, $n));
}

function tierFromScore(int $score): string {
    if ($score < 40) return 'red';
    if ($score < 70) return 'yellow';
    return 'green';
}

// ============================================================
// 🆕 التسجيل الجديد — 100% من بيانات الفحص الحقيقية
// ============================================================
function scoreFromScanData(array $scan): array {
    // الأولوية: facebook → social → instagram (أيها أغنى)
    $fbData  = $scan['facebook']    ?? [];
    $igData  = $scan['instagram']   ?? [];
    $social  = !empty($fbData['followers']) ? $fbData
              : (!empty($igData['followers']) ? $igData
              : ($scan['social'] ?? []));
    $ads     = $scan['ads_library'] ?? [];
    $urlType = $scan['url_type']    ?? 'website';
    $isSocial = in_array($urlType, ['facebook', 'instagram']);

    // ── Brand (15) — هوية وجودة الصفحة ───────────────────────
    $brand = 0;
    if (!empty($social['description'])
     || !empty($scan['og']['description'])) $brand += 5; // وصف/رسالة
    if (!empty($scan['og']['image']))       $brand += 5; // صورة/شعار
    if (!empty($social['phone'])
     || ($social['has_contact'] ?? false))   $brand += 3; // بيانات اتصال
    if (!empty($social['website'])
     || ($social['has_website'] ?? false))   $brand += 2; // موقع مرتبط

    // ── Content (20) — لا يعتمد على Apify فقط ───────────────
    $content = 0;
    $followers = (int)($social['followers'] ?? 0);
    $posts = (int)($social['posts_count']     // Apify
          ?? $social['posts']                 // Instagram public
          ?? $igData['posts_count']           // IG Apify
          ?? 0);
    $eng   = (float)($social['avg_engagement'] ?? $igData['avg_engagement'] ?? 0);
    $likes = (int)($social['page_likes'] ?? $social['likes'] ?? 0);

    // المتابعون كمؤشر على حجم المحتوى
    if ($followers >= 50000)    $content += 5;
    elseif ($followers >= 10000) $content += 4;
    elseif ($followers >= 1000)  $content += 3;
    elseif ($followers >= 100)   $content += 1;

    // منشورات (Apify إذا متاحة)
    if ($posts >= 50)       $content += 4;
    elseif ($posts >= 20)   $content += 3;
    elseif ($posts >= 5)    $content += 2;
    elseif ($posts > 0)     $content += 1;

    // تفاعل
    if ($eng >= 2000)       $content += 4;
    elseif ($eng >= 500)    $content += 3;
    elseif ($eng >= 100)    $content += 2;
    elseif ($likes > 0 && $followers > 0) $content += 2; // fallback: likes ≈ تفاعل

    // آخر منشور
    $lastPost = $social['top_post']['date'] ?? $social['creation_date'] ?? null;
    if ($lastPost) {
        $days = (int)((time() - strtotime($lastPost)) / 86400);
        if ($days <= 7)       $content += 4;
        elseif ($days <= 30)  $content += 3;
        elseif ($days <= 90)  $content += 1;
    } elseif ($followers > 0) {
        // لا تاريخ منشور لكن لدينا متابعون = الصفحة نشطة
        $content += 2;
    }

    // بونص: انستقرام موجود يعني تنوع محتوى
    if (!empty($igData['followers']) || !empty($igData['username'])) $content += 2;

    // ── Presence (15) — حضور وقوة ─────────────────────────────
    $presence = 0;
    $followers = (int)($social['followers'] ?? 0);

    if ($followers >= 100000) $presence += 7;
    elseif ($followers >= 10000) $presence += 5;
    elseif ($followers >= 1000)  $presence += 3;
    elseif ($followers >= 100)   $presence += 1;

    if ($social['has_website'] ?? false) $presence += 3;    // موقع مرتبط
    if ($scan['hasOGTags']  ?? false)    $presence += 2;    // OG Tags
    if (!empty($scan['og']['title']))    $presence += 1;    // عنوان
    if (!empty($scan['description']))    $presence += 1;    // وصف
    if (!empty($social['category']))     $presence += 1;    // تصنيف

    // ── Ads (20) — الإعلانات ──────────────────────────────────
    $adsScore = 0;
    $totalAds  = (int)($ads['total_ads']  ?? 0);
    $activeAds = (int)($ads['active_ads'] ?? 0);
    $hasPixel  = $scan['hasPixel'] ?? null; // null = غير فحصنا موقع

    if ($totalAds > 0)       $adsScore += 6;   // يُعلن
    if ($activeAds > 0)      $adsScore += 4;   // إعلانات نشطة
    if ($activeAds > 5)      $adsScore += 2;   // حجم إعلاني جيد
    if ($activeAds > 20)     $adsScore += 2;   // حجم إعلاني ممتاز

    // Pixel (للمواقع فقط)
    if ($hasPixel === true)  $adsScore += 4;
    elseif ($hasPixel === false) ; // لا خصم — لكن لا بونص

    // Spend detected
    $adsItems = $ads['ads'] ?? [];
    $hasSpend = array_filter($adsItems, fn($a) => !empty($a['spend']));
    if ($hasSpend)           $adsScore += 2;   // إنفاق مرصود

    // ── Conversion (20) — التحويل ─────────────────────────────
    $conversion = 0;
    $rating = (float)($social['rating'] ?? 0);

    if ($social['has_website'] ?? false) $conversion += 5;  // موقع
    if ($scan['hasWhatsApp'] ?? null)    $conversion += 4;  // واتساب
    if ($scan['hasCTA'] ?? null)         $conversion += 3;  // CTA
    if ($social['has_contact'] ?? false) $conversion += 3;  // اتصال مباشر
    if ($rating >= 4.5)                  $conversion += 3;  // تقييم ممتاز
    elseif ($rating >= 3.5)              $conversion += 2;  // تقييم جيد
    if (!empty($social['phone']))        $conversion += 2;  // هاتف مباشر
    // SSL للمواقع
    if ($scan['hasSSL'] ?? null)         $conversion += 0;  // معلومة إضافية (لا تُحسب)

    // ── Analytics (10) — قياس وتتبع ──────────────────────────
    $analytics = 0;
    if ($scan['hasPixel'] === true)  $analytics += 4;
    if ($scan['hasGA']    === true)  $analytics += 4;
    if ($scan['hasTikTok']?? null)   $analytics += 2;

    // للصفحات الاجتماعية: بونص لوجود بيانات تقييم كدليل على المتابعة
    if ($isSocial) {
        if ($rating > 0)             $analytics += 3;
        // بونص: بيانات Apify موجودة يعني الصفحة منشطة وقابلة للتتبع
        if ((int)($social['ratings_count'] ?? 0) > 10) $analytics += 3;
    }

    $breakdown = [
        'brand'      => (int)clamp($brand,      0, 15),
        'content'    => (int)clamp($content,    0, 20),
        'presence'   => (int)clamp($presence,   0, 15),
        'ads'        => (int)clamp($adsScore,   0, 20),
        'conversion' => (int)clamp($conversion, 0, 20),
        'analytics'  => (int)clamp($analytics,  0, 10),
    ];

    $score = (int)clamp(array_sum($breakdown), 0, 100);
    return compact('score', 'breakdown');
}

// للتوافق مع الكود القديم (إذا كانت هناك إجابات)
function scoreFromAnswers(array $map): array {
    // إذا كان الـ map فارغاً (نظام جديد) → ابدأ بأصفار
    return ['score' => 0, 'breakdown' => ['brand'=>0,'content'=>0,'presence'=>0,'ads'=>0,'conversion'=>0,'analytics'=>0]];
}

// ============================================================
// فحص الموقع / الصفحة
// ============================================================
function scanUrl(string $url): array {
    require_once __DIR__ . '/page-scan.php';
    $cfg = require __DIR__ . '/config.php';
    return runPageScan($url, $cfg);
}

// ── Legacy website scan ────────────────────────────────────────
function scanWebsite(string $url): array {
    $result  = scanUrl($url);
    $urlType = $result['type'] ?? 'website';
    $isSocial = in_array($urlType, ['facebook', 'instagram']);

    $ws = ($isSocial)
        ? ($result['website_scan'] ?? [])
        : ($result['website_scan'] ?? $result['website'] ?? []);

    $og = $result['og'] ?? [];
    $webOnly = !$isSocial || !empty($ws);

    return [
        'success'        => $result['success'] ?? false,
        'error'          => $result['error']   ?? null,
        'finalUrl'       => $ws['final_url']   ?? $url,
        'httpCode'       => $ws['http_code']   ?? 200,
        'loadTime'       => $ws['load_time_s'] ?? null,
        'speedRating'    => $ws['speed_rating'] ?? null,
        'title'          => $ws['title']        ?? $og['title']       ?? '',
        'titleLength'    => mb_strlen($ws['title'] ?? $og['title'] ?? ''),
        'description'    => $ws['description']  ?? $og['description'] ?? '',
        'descLength'     => mb_strlen($ws['description'] ?? $og['description'] ?? ''),
        'h1'             => $webOnly ? ($ws['h1']           ?? '') : null,
        'h2Count'        => $ws['h2_count']     ?? 0,
        'hasOGTags'      => $og['has_og_tags']  ?? ($ws['has_og_tags'] ?? false),
        'hasSchema'      => $webOnly ? ($ws['has_schema']   ?? false) : null,
        'hasSSL'         => $webOnly ? ($ws['has_ssl']      ?? false) : null,
        'hasPixel'       => $webOnly ? ($ws['has_fb_pixel'] ?? false) : null,
        'hasGA'          => $webOnly ? ($ws['has_ga']       ?? false) : null,
        'hasTikTok'      => $webOnly ? ($ws['has_tiktok']   ?? false) : null,
        'hasSnapchat'    => $webOnly ? ($ws['has_snapchat'] ?? false) : null,
        'hasWhatsApp'    => $webOnly ? ($ws['has_whatsapp'] ?? false) : null,
        'hasLiveChat'    => $webOnly ? ($ws['has_live_chat']?? false) : null,
        'hasContactForm' => $webOnly ? ($ws['has_contact_form'] ?? false) : null,
        'hasPhoneNumber' => $webOnly ? ($ws['has_phone']    ?? false) : null,
        'hasCTA'         => $webOnly ? ($ws['has_cta']      ?? false) : null,
        'social'         => $result['social']      ?? null,
        'pagespeed'      => $result['pagespeed']   ?? null,
        'ads_library'    => $result['ads_library'] ?? null,
        'scan_score'     => $result['scan_score']  ?? null,
        'url_type'       => $urlType,
        'og'             => $og,
    ];
}

// ============================================================
// تطبيق نتائج الفحص على الدرجة (للنظام القديم — deprecated)
// ============================================================
function applyScanBoosts(array &$breakdown, array $scanResult): void {
    // لا شيء — الدرجة الآن تأتي كاملة من scoreFromScanData
}

// ============================================================
// Insights: نقاط القوة والضعف — مبنية على بيانات الفحص
// ============================================================
function genInsights(array $breakdown, array $map, array $scanResult = []): array {
    $strengths = $weaknesses = [];
    $urlType = $scanResult['url_type']    ?? 'website';
    
    // استخدم بيانات المنصة المحددة إذا توفرت، وإلا استخدم social العام
    $social = [];
    if (in_array($urlType, ['facebook', 'instagram'])) {
        $social = $scanResult[$urlType] ?? $scanResult['social'] ?? [];
    } else {
        $social = $scanResult['facebook'] ?? $scanResult['instagram'] ?? $scanResult['social'] ?? [];
    }
    
    $ads     = $scanResult['ads_library'] ?? [];
    $isSocial = in_array($urlType, ['facebook', 'instagram']);

    // Brand
    if (!empty($social['description']) || !empty($scanResult['og']['description']))
        $strengths[] = 'صفحة بوصف واضح ورسالة جلية — تُقنع الزوار بالبقاء.';
    else
        $weaknesses[] = 'غياب وصف الصفحة — الزوار لا يعرفون ما تقدّمه.';

    // Content
    $posts = (int)($social['posts_count'] ?? 0);
    $eng   = (float)($social['avg_engagement'] ?? 0);
    if ($posts >= 20)
        $strengths[] = "محتوى وفير ({$posts} منشور) — يبني ثقة ورصيد جمهور.";
    elseif ($posts > 0 && $posts < 5)
        $weaknesses[] = "نشاط منشورات منخفض ({$posts} فقط) — الخوارزمية تحتاج 4+ أسبوعياً.";

    if ($eng >= 500)
        $strengths[] = 'تفاعل عالٍ على المحتوى — إشارة قوية للخوارزمية والإعلانات.';
    elseif ($eng > 0 && $eng < 100)
        $weaknesses[] = 'معدل تفاعل منخفض — المحتوى لا يُحرّك الجمهور بما يكفي.';

    // Followers
    $followers = (int)($social['followers'] ?? 0);
    if ($followers >= 10000)
        $strengths[] = "جمهور ضخم ({$followers} متابع) — أصل تسويقي قوي.";
    elseif ($followers < 500 && $followers > 0)
        $weaknesses[] = "جمهور صغير ({$followers}) — يحتاج حملة نمو موجّهة.";

    // Ads
    $totalAds  = (int)($ads['total_ads']  ?? 0);
    $activeAds = (int)($ads['active_ads'] ?? 0);
    if ($totalAds > 0)
        $strengths[] = "نشاط إعلاني مرصود ({$totalAds} إعلان) — استثمار في الوصول.";
    else
        $weaknesses[] = 'لا إعلانات في مكتبة Meta — فرصة ضائعة للوصول للعملاء الجدد.';

    if ($activeAds > 5)
        $strengths[] = "{$activeAds} إعلان نشط — حملة إعلانية فعّالة ومستمرة.";

    // Technical
    if ($scanResult['hasPixel'] === true)
        $strengths[] = 'Meta Pixel مثبت — بيانات جاهزة للاستهداف الذكي.';
    elseif ($scanResult['hasPixel'] === false)
        $weaknesses[] = 'لا Meta Pixel على الموقع — الإعلانات تعمل بدون تتبع!';

    if ($scanResult['hasGA'] === true)
        $strengths[] = 'Google Analytics نشط — رؤية كاملة لسلوك الزوار.';

    if ($scanResult['hasWhatsApp'])
        $strengths[] = 'زر واتساب على الموقع — قناة تحويل مباشرة وفعّالة.';
    elseif ($scanResult['hasWhatsApp'] === false)
        $weaknesses[] = 'لا زر واتساب — إضافته ترفع التحويل 30% فورياً.';

    // Contact — يجمع من كل المنصات المكتشفة
    $hasContact = ($scanResult['has_any_contact'] ?? false)
               || ($social['has_contact'] ?? false)
               || ($social['has_phone']   ?? false)
               || ($social['has_whatsapp'] ?? false)
               || !empty($social['phone'])
               || ($scanResult['facebook']['has_contact'] ?? false)
               || ($scanResult['hasWhatsApp'] === true);
    if ($hasContact)
        $strengths[] = 'معلومات الاتصال مكتملة — سهولة للعملاء في التواصل.';
    else
        $weaknesses[] = 'معلومات الاتصال مفقودة — العملاء المحتملون يتساءلون كيف يصلون إليك.';

    // Rating
    $rating = (float)($social['rating'] ?? 0);
    if ($rating >= 4.5)
        $strengths[] = "سمعة ممتازة ({$rating}/5) — أقوى أداة إقناع مجانية.";
    elseif ($rating > 0 && $rating < 3.5)
        $weaknesses[] = "تقييمات منخفضة ({$rating}/5) — تُقلل الثقة وتبعد العملاء.";

    if (!$strengths) $strengths = ['نشاط تجاري بإمكانات واضحة قابلة للتطوير.', 'الرغبة في التحسين هي أولى خطوات النمو.'];
    if (!$weaknesses) $weaknesses = ['تعزيز الحضور الإعلاني لمواكبة المنافسين.', 'الاستمرار في تطوير المحتوى وتنويعه.'];

    return [
        'strengths'  => array_slice($strengths,  0, 5),
        'weaknesses' => array_slice($weaknesses, 0, 5),
    ];
}

// ============================================================
// توليد التوصيات — مبنية على البيانات الحقيقية
// ============================================================
function genRecommendations(int $score, array $breakdown, ?array $scanResult, array $map): array {
    $sorted = $breakdown;
    asort($sorted);
    $weakest = array_slice(array_keys($sorted), 0, 3);

    $recMap = [
        'brand'      => ['title'=>'🎨 ثبّت هوية احترافية',       'priority'=>'عاجل', 'bullets'=>['صفحة موثّقة + شعار واضح + وصف قوي','رسالة موحدة: ماذا تقدم؟ لمن؟','صورة غلاف تعكس هوية النشاط']],
        'content'    => ['title'=>'📅 محتوى يوقف التمرير',        'priority'=>'عاجل', 'bullets'=>['4+ منشورات أسبوعياً (Reels + Carousel + Story)','قاعدة 80/20: 80% قيمة، 20% بيع','استخدم تعليقات الجمهور لإنتاج محتوى أجدد']],
        'presence'   => ['title'=>'🌐 احتل المنصات الصحيحة',      'priority'=>'مهم',  'bullets'=>['ركّز على منصة واحدة 90 يوماً وسيّد فيها','Bio: الخدمة + المدينة + CTA واضح','رابط Linktree يجمع كل قنواتك']],
        'ads'        => ['title'=>'💸 إعلانات بـ ROI حقيقي',       'priority'=>'عاجل', 'bullets'=>['Pixel أولاً قبل أي ريال إعلاني','ابدأ بـ Retargeting زوار الصفحة الحاليين','اختبر 3 إعلانات أسبوعياً، وسّع الفائز']],
        'conversion' => ['title'=>'🎯 حوّل الزوار لعملاء',        'priority'=>'مهم',  'bullets'=>['أضف زر واتساب ورقم هاتف في Bio','Landing Page مخصصة لكل خدمة','عرض بوقت محدود يرفع الإلحاح']],
        'analytics'  => ['title'=>'📊 قس كل شيء',                'priority'=>'مهم',  'bullets'=>['GA4 + Meta Pixel + TikTok Pixel','تتبع: تكلفة العميل، التحويل، ROAS','تقرير أسبوعي 10 دقائق يوجّه قراراتك']],
    ];

    $recs = [];

    // ── تقرير الفحص التقني أولاً ─────────────────────────────
    if ($scanResult) {
        $bullets  = [];
        $social   = $scanResult['social']      ?? [];
        $ads      = $scanResult['ads_library'] ?? [];
        $urlType  = $scanResult['url_type']    ?? 'website';
        $isSocial = in_array($urlType, ['facebook','instagram']);

        // إحصائيات الصفحة
        $followers = (int)($social['followers'] ?? 0);
        if ($followers > 0) {
            $f = number_format($followers);
            $bullets[] = "👥 المتابعون: {$f}";
        }
        if (!empty($social['avg_engagement']))
            $bullets[] = "📊 متوسط التفاعل: " . number_format($social['avg_engagement']) . " / منشور";
        if (!empty($social['posts_count']))
            $bullets[] = "📝 إجمالي المنشورات: {$social['posts_count']}";

        // إعلانات
        $totalAds  = (int)($ads['total_ads']  ?? 0);
        $activeAds = (int)($ads['active_ads'] ?? 0);
        if ($totalAds > 0)
            $bullets[] = "📢 {$totalAds} إعلان في المكتبة ({$activeAds} نشط حالياً)";
        else
            $bullets[] = '⚠️ لا إعلانات في مكتبة Meta — ابدأ بـ 50 ريال/يوم';

        // موقع إلكتروني
        if (!$isSocial || !empty($scanResult['hasSSL'])) {
            if ($scanResult['hasSSL'] === false)  $bullets[] = '🔴 الموقع بلا HTTPS — فقد يخيف الزوار';
            if ($scanResult['hasPixel'] === false) $bullets[] = '🔴 لا Meta Pixel — الإعلانات تعمل بلا تتبع!';
            if ($scanResult['hasPixel'] === true)  $bullets[] = '✅ Meta Pixel مثبت';
            if ($scanResult['hasGA'] === true)     $bullets[] = '✅ Google Analytics نشط';
            if ($scanResult['hasGA'] === false)    $bullets[] = '⚠️ لا Google Analytics';
            if ($scanResult['hasWhatsApp'])        $bullets[] = '✅ زر واتساب موجود';
            elseif ($scanResult['hasWhatsApp'] === false) $bullets[] = '⚠️ أضف زر واتساب — يرفع التحويل 30%';
        }

        // اتصال
        if (!($social['has_contact'] ?? false))
            $bullets[] = '⚠️ معلومات الاتصال مفقودة — يُصعّب على العملاء التواصل';

        // تقييم
        $rating = (float)($social['rating'] ?? 0);
        if ($rating > 0) $bullets[] = "⭐ التقييم: {$rating}/5 (" . ($social['ratings_count'] ?? '?') . " تقييم)";

        // PageSpeed
        $ps = $scanResult['pagespeed'] ?? [];
        if (!empty($ps['performance']))
            $bullets[] = "⚡ PageSpeed: {$ps['performance']}/100 — " . ($ps['performance'] >= 90 ? 'ممتاز' : ($ps['performance'] >= 70 ? 'جيد' : 'يحتاج تحسين'));

        $recs[] = ['title' => '🔍 نتائج الفحص الشامل الفوري', 'priority' => 'عاجل', 'bullets' => $bullets];
    }

    // ── التوصيات من المحاور الأضعف ───────────────────────────
    foreach ($weakest as $key) {
        if (isset($recMap[$key])) $recs[] = $recMap[$key];
    }

    // ── الملخص التنفيذي ───────────────────────────────────────
    $summary = $score < 40
        ? 'نشاطك بحاجة لإنقاذ تسويقي سريع — كل يوم تأخير يكلّفك عملاء يذهبون للمنافس.'
        : ($score < 70
            ? 'لديك أساس جيد، لكن 3 نقاط تمنعك من مضاعفة نتائجك — قابلة للإصلاح خلال أسبوعين.'
            : 'وضعك التسويقي قوي — التحسينات الآن تُضاعف عائد استثمارك الإعلاني بشكل ملموس.');

    ['strengths' => $strengths, 'weaknesses' => $weaknesses] = genInsights($breakdown, $map, $scanResult ?? []);
    $actionWeek = buildActionWeek($score, $scanResult, $scanResult ?? []);

    return compact('summary', 'strengths', 'weaknesses', 'actionWeek') + ['recommendations' => array_values($recs)];
}

// ============================================================
// خطة الأسبوع الأول — مبنية على الفحص الحقيقي
// ============================================================
function buildActionWeek(int $score, ?array $scan, array $map): array {
    $tasks    = [];
    $social   = $scan['social']      ?? [];
    $ads      = $scan['ads_library'] ?? [];
    $urlType  = $scan['url_type']    ?? 'website';
    $isSocial = in_array($urlType, ['facebook','instagram']);

    // أولويات بناءً على ما يفتقده النشاط
    if ($scan && $scan['hasPixel'] === false)
        $tasks[] = 'تركيب Meta Pixel على الموقع (15 دقيقة) — الأهم على الإطلاق.';
    if ($scan && $scan['hasGA'] === false)
        $tasks[] = 'إعداد Google Analytics 4 وإضافة الكود على الموقع.';
    if ($scan && $scan['hasSSL'] === false)
        $tasks[] = 'تفعيل SSL/HTTPS من لوحة الاستضافة — مجاناً مع Let\'s Encrypt.';
    if ($scan && $scan['hasWhatsApp'] === false)
        $tasks[] = 'إضافة زر واتساب للموقع — Widget مجاني يرفع التحويل فوراً.';

    if (!($social['is_verified'] ?? false))
        $tasks[] = 'تقديم طلب توثيق الصفحة عبر مساعد Meta وتجهيز المستندات.';
    if (($social['posts_count'] ?? 0) < 5)
        $tasks[] = 'تصوير 5 Reels قصيرة جاهزة للنشر هذا الأسبوع.';
    if (($ads['total_ads'] ?? 0) == 0)
        $tasks[] = 'إنشاء أول حملة إعلانية بـ 50 ريال/يوم لقياس جمهورك المستهدف.';
    if (!($social['has_contact'] ?? false))
        $tasks[] = 'إضافة رقم الهاتف والبريد الإلكتروني في معلومات الصفحة.';

    if (empty($tasks)) {
        $tasks = [
            'اختبار A/B لإعلانين مختلفين في نفس الوقت.',
            'توسيع جمهور الإعلانات لشريحة Lookalike جديدة.',
            'جمع 10 تقييمات جديدة من عملاء سابقين.',
            'إنشاء Highlight Story لكل قسم من خدماتك.',
        ];
    }

    return array_slice($tasks, 0, 5);
}

// ============================================================
// الدالة الرئيسية: تشغيل التحليل الكامل v6.0
// يستخدم runPageScan أولاً (يستخرج FB/IG من الموقع تلقائياً)
// ثم يُغني بـ Apify للبيانات العميقة
// ============================================================
function runAnalysis(int $assessmentId): array {
    ini_set('max_execution_time', 300);
    $db  = getDB();
    $cfg = require __DIR__ . '/config.php';

    // ─── 1) جلب بيانات العميل ────────────────────────────────
    $stmt = $db->prepare('SELECT l.website_url, l.facebook_url, l.instagram_url, l.company_name, l.full_name
        FROM assessments a LEFT JOIN leads l ON a.lead_id=l.id WHERE a.id=?');
    $stmt->execute([$assessmentId]);
    $row = $stmt->fetch();

    $fbUrl  = trim($row['facebook_url']  ?? '');
    $igUrl  = trim($row['instagram_url'] ?? '');
    $webUrl = trim($row['website_url']   ?? '');

    // الرابط الأساسي المُدخل من المستخدم (أيها أول)
    // الأولوية: الموقع أولاً إذا موجود، ثم FB، ثم IG
    $primaryUrl = $webUrl ?: $fbUrl ?: $igUrl;
    if (!$primaryUrl) {
        return ['ok' => false, 'error' => 'لا يوجد أي رابط للفحص'];
    }

    require_once __DIR__ . '/page-scan.php';
    require_once __DIR__ . '/apify-scraper.php';

    // ─── 2) الفحص الأساسي الذكي ─────────────────────────────
    $scanResult = null;
    try {
        $scanResult = runPageScan($primaryUrl, $cfg);
    } catch (\Throwable $e) {
        $scanResult = ['success' => false, 'error' => $e->getMessage()];
    }

    // ─── 3) استخدم نتائج محرك الاكتشاف مباشرة ──────────────
    // runPageScan v5 يكتشف FB/IG ويشغّل Apify داخلياً
    // الأولوية: Apify (عميق) → runPageScan's Cross-Platform Discovery
    // إذا المستخدم أدخل FB/IG يدوياً ولم يُكتشفا من الموقع → اكملها
    $detectedFbUrl = $fbUrl ?: ($scanResult['facebook']['url'] ?? '');
    $detectedIgUrl = $igUrl ?: ($scanResult['instagram']['username']
        ? 'https://www.instagram.com/' . $scanResult['instagram']['username']
        : '');

    // ── تخمين هجومي (Aggressive Guessing) ──
    // إذا كان لدينا إنستقرام ولم نستطع إيجاد رابط فيسبوك بالبحث، نخمن أن المعرّف متطابق!
    if (!$detectedFbUrl && !empty($scanResult['instagram']['username'])) {
        $detectedFbUrl = 'https://www.facebook.com/' . $scanResult['instagram']['username'];
    } elseif (!$detectedFbUrl && $detectedIgUrl) {
        preg_match('/instagram\.com\/([^\/\?#]+)/i', $detectedIgUrl, $m);
        if (!empty($m[1])) {
            $detectedFbUrl = 'https://www.facebook.com/' . trim($m[1]);
        }
    }

    // ─── 4) Apify Facebook — فقط إذا لم تُجلب بعد ────────────
    $apifyFb = null;
    // نعتبر أن لدينا بيانات حقيقية فقط إذا كان لدينا المتابعين (وليس مجرد page id من فحص HTML المبدئي)
    $hasFbData = !empty($scanResult['facebook']['followers']);

    if ($detectedFbUrl && !$hasFbData && ($cfg['analysis']['enable_apify'] ?? false)) {
        try {
            $token = getValidApifyToken($cfg);
            if ($token) {
                $r = scrapeFacebook($detectedFbUrl, $token, $cfg);
                if ($r['success'] ?? false) {
                    $apifyFb = $r;
                    // ادمج البيانات العميقة من Apify لتحل محل البيانات المبدئية
                    if (is_array($scanResult['facebook'] ?? null)) {
                        $scanResult['facebook'] = array_merge($scanResult['facebook'], $apifyFb);
                    } else {
                        $scanResult['facebook'] = $apifyFb;
                    }
                }
            }
        } catch (\Throwable $e) {}
    } else {
        $apifyFb = $scanResult['facebook'] ?? null;
    }

    // ─── 5) Apify Instagram — فقط إذا لم تُجلب بعد ───────────
    $apifyIg = null;
    $hasIgData = !empty($scanResult['instagram']['followers']);

    if ($detectedIgUrl && !$hasIgData && ($cfg['analysis']['enable_apify'] ?? false)) {
        try {
            $token = getValidApifyToken($cfg);
            if ($token) {
                $r = scrapeInstagram($detectedIgUrl, $token, $cfg);
                if ($r['success'] ?? false) {
                    $apifyIg = $r;
                    if (is_array($scanResult['instagram'] ?? null)) {
                        $scanResult['instagram'] = array_merge($scanResult['instagram'], $apifyIg);
                    } else {
                        $scanResult['instagram'] = $apifyIg;
                    }
                }
            }
        } catch (\Throwable $e) {}
    } else {
        $apifyIg = $scanResult['instagram'] ?? null;
    }


    // ─── 6) الإعلانات ─────────────────────────────────────────
    // استخدم بيانات runPageScan + Apify إذا لم تكن كافية
    $adsData = $scanResult['ads_library'] ?? null;

    if (!$adsData && ($cfg['analysis']['enable_ads_library'] ?? false)) {
        // الأولوية 1: Page ID (الأدق)
        $adsPageId = $apifyFb['page_id'] ?? $scanResult['social']['page_id'] ?? $scanResult['og']['page_id'] ?? '';
        // الأولوية 2: Page Name
        $adsQuery = $apifyFb['page_name'] ?? $scanResult['social']['page_name'] ?? '';
        
        if (!$adsQuery && !$adsPageId) {
            if ($detectedFbUrl) {
                preg_match('/facebook\.com\/([^\/\?#]+)/i', $detectedFbUrl, $m);
                $adsQuery = $m[1] ?? '';
            } elseif ($detectedIgUrl) {
                preg_match('/instagram\.com\/([^\/\?#]+)/i', $detectedIgUrl, $m);
                $adsQuery = $m[1] ?? '';
            }
        }

        if (($adsQuery || $adsPageId) && ($cfg['analysis']['enable_apify'] ?? false)) {
            try {
                $token = getValidApifyToken($cfg);
                if ($token) {
                    $searchParam = $adsPageId ? "ID:{$adsPageId}" : $adsQuery;
                    // نمرر بيانات Facebook إذا توفرت لاستخدام pageAdLibrary مباشرة
                    $r = scrapeAdsLibrary($searchParam, $token, $cfg, 'ALL', $apifyFb ?? []);
                    if ($r['success'] ?? false) $adsData = $r;
                }
            } catch (\Throwable $e) {}
        }
        // Fallback: Meta Graph API
        if (!$adsData && $adsQuery) {
            $rawAds = fetchAdsLibrary($adsQuery, $cfg['apis']['meta_ads_token'] ?? '');
            if ($rawAds && !isset($rawAds['error'])) $adsData = $rawAds;
        }
    }

    // ─── 7) دمج الكل في scanResult موحّد ─────────────────────
    // الأولوية: Apify (عميق) → runPageScan's Cross-Platform Discovery

    // Facebook: Apify فقط إذا نجح، وإلا من Cross-Platform Discovery
    $scanResult['facebook']  = $apifyFb
        ?? $scanResult['facebook']   // من محرك الاكتشاف الجديد
        ?? null;

    // Instagram: Apify فقط إذا نجح، وإلا من Cross-Platform Discovery
    $scanResult['instagram'] = $apifyIg
        ?? $scanResult['instagram']  // من محرك الاكتشاف الجديد
        ?? null;

    // الـ Social الأساسي للتسجيل: أغنى البيانات
    $scanResult['social'] = $scanResult['facebook'] ?? $scanResult['instagram'] ?? $scanResult['social'] ?? null;

    // الإعلانات
    $scanResult['ads_library'] = $adsData ?? $scanResult['ads_library'] ?? null;
    $scanResult['url_type']    = $scanResult['type'] ?? 'website';

    // ── تطبيق Flatten لتوحيد حقول الموقع ───────────────────────
    // المصدر الأول: website_scan من محرك الاكتشاف
    // المصدر الثاني: website_scan المباشر إذا كان FB/IG المدخل
    $ws = $scanResult['website_scan'] ?? [];
    if (!is_array($ws) || empty($ws)) {
        // إذا كانت $scanResult['website'] هي URL نظيف وليس array → نتجاهله
        $wsAlt = $scanResult['website'] ?? [];
        $ws = is_array($wsAlt) && !empty($wsAlt['has_ssl']) ? $wsAlt : [];
    }

    // ── Flat fields (توحيد المسميات مرة واحدة هنا فقط) ─────────
    $scanResult['hasSSL']      = $ws['has_ssl']          ?? null;
    $scanResult['hasPixel']    = $ws['has_fb_pixel']      ?? null;
    $scanResult['hasGA']       = $ws['has_ga']            ?? null;
    $scanResult['hasTikTok']   = $ws['has_tiktok']        ?? null;
    $scanResult['hasSnapchat'] = $ws['has_snapchat']      ?? null;
    $scanResult['hasWhatsApp'] = $ws['has_whatsapp']      ?? null;
    $scanResult['hasCTA']      = $ws['has_cta']           ?? null;
    $scanResult['hasSchema']   = $ws['has_schema']        ?? null;
    $scanResult['hasOGTags']   = !empty($scanResult['og']['title'])
                               || ($ws['has_og_tags'] ?? false);
    $scanResult['title']        = $ws['title']             ?? $scanResult['og']['title'] ?? '';
    $scanResult['description']  = $ws['description']       ?? $scanResult['og']['description'] ?? '';
    $scanResult['h1']           = $ws['h1']                ?? '';

    // ── المعلومات المجمّعة من كل المنصات ──────────────────────
    $fbContact = $scanResult['facebook']['has_contact'] ?? false;
    $igContact = !empty($scanResult['instagram']['website']);
    $scanResult['has_any_contact'] = $fbContact || $igContact
                                  || ($ws['has_phone'] ?? false)
                                  || ($ws['has_contact_form'] ?? false);



    // ─── 8) حساب الدرجة ──────────────────────────────────────
    ['score' => $finalScore, 'breakdown' => $breakdown] = scoreFromScanData($scanResult);

    // ─── 9) التوصيات + النظرة التسويقية ───────────────────────
    $tier     = tierFromScore($finalScore);
    $gen      = genRecommendations($finalScore, $breakdown, $scanResult, []);
    $insights = genInsights($breakdown, [], $scanResult);

    // ─── 10) Gemini AI ────────────────────────────────────────
    $aiResult = null;
    if ($cfg['analysis']['enable_gemini'] ?? false) {
        try {
            require_once __DIR__ . '/ai-analyze.php';
            $aiResult = runGeminiAnalysis([
                'score'       => $finalScore,
                'breakdown'   => $breakdown,
                'scan_result' => $scanResult,
                'company'     => $row['company_name'] ?? '',
            ], $cfg);
            if (!empty($aiResult['recommendations']))
                $gen['recommendations'] = array_merge($gen['recommendations'], $aiResult['recommendations']);
            if (!empty($aiResult['summary']))    $gen['summary']         = $aiResult['summary'];
            if (!empty($aiResult['strengths']))  $insights['strengths']  = array_merge($insights['strengths'],  $aiResult['strengths']);
            if (!empty($aiResult['weaknesses'])) $insights['weaknesses'] = array_merge($insights['weaknesses'], $aiResult['weaknesses']);
        } catch (\Throwable $e) {}
    }

    // ─── 11) auto-migration ───────────────────────────────────
    try {
        $existing = $db->query("SHOW COLUMNS FROM assessments")->fetchAll(\PDO::FETCH_COLUMN);
        $needed   = [
            'breakdown' => 'JSON', 'summary' => 'TEXT', 'recommendations' => 'JSON',
            'strengths' => 'JSON', 'weaknesses' => 'JSON', 'next_steps' => 'JSON',
            'scan_result' => 'JSON', 'scan_status' => 'VARCHAR(20)', 'scan_error' => 'TEXT',
            'report_token' => 'VARCHAR(64)', 'tier' => "ENUM('red','yellow','green')",
        ];
        foreach ($needed as $col => $def) {
            if (!in_array($col, $existing))
                try { $db->exec("ALTER TABLE assessments ADD COLUMN `{$col}` {$def} NULL"); } catch (\Throwable $e) {}
        }
    } catch (\Throwable $e) {}

    // ─── 12) حفظ النتيجة ─────────────────────────────────────
    try {
        $db->prepare("UPDATE assessments SET
            status='analyzed', score=?, tier=?, breakdown=?, summary=?,
            recommendations=?, strengths=?, weaknesses=?,
            scan_result=?, scan_status=?, scan_error=?
            WHERE id=?")->execute([
            $finalScore, $tier,
            json_encode($breakdown,                    JSON_UNESCAPED_UNICODE),
            $gen['summary'] ?? '',
            json_encode($gen['recommendations'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($insights['strengths']  ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($insights['weaknesses'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($scanResult,                   JSON_UNESCAPED_UNICODE),
            'success', null,
            $assessmentId,
        ]);
    } catch (\Throwable $e) {
        $db->prepare("UPDATE assessments SET status='analyzed', score=?, tier=? WHERE id=?")
           ->execute([$finalScore, $tier, $assessmentId]);
    }

    return [
        'ok'              => true,
        'score'           => $finalScore,
        'tier'            => $tier,
        'breakdown'       => $breakdown,
        'summary'         => $gen['summary'] ?? '',
        'recommendations' => $gen['recommendations'] ?? [],
        'strengths'       => $insights['strengths']  ?? [],
        'weaknesses'      => $insights['weaknesses'] ?? [],
        'action_week'     => $gen['actionWeek']      ?? [],
        'scan_result'     => $scanResult,
    ];
}

