<?php
if (defined('ANALYZE_LOADED')) return;
define('ANALYZE_LOADED', true);

// ============================================================
// api/analyze.php — محرك التحليل v4.0 (Auto-Detection — لا استبيان)
// الدرجة 100% مبنية على بيانات حقيقية من Apify + فحص HTML
// ============================================================

require_once __DIR__ . '/init.php';

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
              : (!empty($scan['tiktok']['followers']) ? $scan['tiktok']
              : (!empty($scan['twitter']['followers']) ? $scan['twitter']
              : ($scan['social'] ?? []))));
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

function genDetailedBreakdown(array $scores, array $scan): array {
    $mapping = [
        'brand'      => 'الهوية والبراندينج',
        'content'    => 'المحتوى والاستقطاب',
        'presence'   => 'التواجد الرقمي (Presence)',
        'ads'        => 'الإعلانات والانتشار الممول',
        'conversion' => 'رحلة العميل والتحويل',
        'analytics'  => 'البيانات والتتبع (Analytics)',
    ];

    $reasons = [
        'brand' => [
            'high' => 'هوية بصرية واحترافية عالية جداً. التناسق بين الشعارات والألوان والرسالة التسويقية يبني ثقة فورية لدى الزائر ويجعلك تبدو كعلامة تجارية رائدة.',
            'med'  => 'توجد أساسيات الهوية لكنها تحتاج لمسة احترافية لتوحيد (Tone of Voice). عدم وضوح الرسالة التسويقية أحياناً يشتت العميل المحتمل.',
            'low'  => 'هناك فجوة كبيرة في الهوية البصرية. غياب الشعار الواضح أو وصف النشاط يجعل الحساب يبدو غير موثوق، مما يرفع معدل الهروب (Bounce Rate) فور دخول الزائر.'
        ],
        'content' => [
            'high' => 'استراتيجية محتوى ذكية جداً مع تفاعل ممتاز. استمرارك في النشر وبناء علاقة مع الجمهور يجعل خوارزميات المنصات تمنحك وصولاً عضوياً (Reach) مجانياً.',
            'med'  => 'المحتوى موجود ولكنه روتيني ويفتقر للابتكار. تحتاج للتركيز أكثر على فيديوهات (Reels/UGC) لأنها هي المحرك الحقيقي للنمو في 2026.',
            'low'  => 'المحتوى فقير جداً أو غير موجود. حساب بدون محتوى متجدد هو حساب ميت في نظر العملاء والمنصات. أنت تخسر فرصة بناء جمهور دائم.'
        ],
        'presence' => [
            'high' => 'تواجد رقمي قوي ومسيطر على المنصات الصحيحة. الربط بين موقعك وحساباتك الاجتماعية يجعل رحلة العميل متكاملة واحترافية.',
            'med'  => 'أنت موجود في بعض المنصات ولكنك تغيب عن أخرى هامة لجمهورك. التواجد المشتت بدون استراتيجية ربط يقلل من تأثير علامتك التجارية.',
            'low'  => 'ضعف شديد في التواجد الرقمي. عدم وجود روابط للموقع أو غيابك عن المنصات الأساسية يجعل المنافسين يلتهمون حصتك السوقية بسهولة.'
        ],
        'ads' => [
            'high' => 'نشاط إعلاني احترافي مع تنوع في الحملات. استغلالك لمكتبة الإعلانات يظهر أنك تستثمر بذكاء للوصول لشرائح جديدة باستمرار.',
            'med'  => 'توجد محاولات إعلانية لكنها غير محسنة. الاعتماد على الصور الثابتة فقط أو غياب التتبع يجعل تكلفة الاستحواذ (CPA) مرتفعة عليك.',
            'low'  => 'غياب كامل للإعلانات الممولة أو هدر مالي كبير. أنت تعتمد فقط على الصدفة والانتشار العضوي، وهذا لا يبني بزنس مستدام وقابل للتوسع.'
        ],
        'conversion' => [
            'high' => 'رحلة تحويل مثالية! سهولة التواصل عبر الواتساب ووضوح العرض في الموقع يغلق الصفقات بسرعة وبأقل جهد بيعي.',
            'med'  => 'العميل يصل إليك ولكنه يتردد في الخطوة الأخيرة. قد يكون السبب تعقيد صفحة الدفع أو غياب ضمانات واضحة تكسر حاجز الخوف لديه.',
            'low'  => 'نزيف مبيعات حاد! لا يوجد زر تواصل واضح، والعميل يتوه داخل موقعك أو حسابك دون معرفة الخطوة التالية للشراء.'
        ],
        'analytics' => [
            'high' => 'أنت تملك السيطرة الكاملة على بياناتك. استخدام البيكسل والتتبع يجعلك تتخذ قرارات مبنية على أرقام وليس مجرد تخمينات.',
            'med'  => 'توجد بعض أدوات التتبع ولكنك لا تستغل البيانات الناتجة عنها. أنت ترى الزيارات ولكنك لا تعرف بدقة من أين تأتي أرباحك الحقيقية.',
            'low'  => 'قيادة معصوب العينين! غياب البيكسل والتتبع يعني أنك لا تعرف ماذا يحدث لمالك. أنت تخسر بيانات العملاء الذين دفعوا لك مسبقاً.'
        ]
    ];

    $detailed = [];
    foreach ($mapping as $key => $axis) {
        $score = $scores[$key] ?? 0;
        $max = ($key === 'analytics') ? 10 : (($key === 'brand' || $key === 'presence') ? 15 : 20);
        $percent = ($score / $max) * 100;

        $tier = ($percent >= 80) ? 'high' : (($percent >= 40) ? 'med' : 'low');

        $detailed[] = [
            'axis'   => $axis,
            'score'  => (int)$percent,
            'reason' => $reasons[$key][$tier] ?? 'تحليل البيانات قيد المعالجة لرفع دقة التوصية.'
        ];
    }
    return $detailed;
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
    $actionWeek = buildActionWeek($score, $scanResult, $map);

    $detailedBreakdown = genDetailedBreakdown($breakdown, $scanResult ?? []);

    return compact('summary', 'strengths', 'weaknesses', 'actionWeek', 'detailedBreakdown') + ['recommendations' => array_values($recs)];
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
    logInfo('Starting analysis run', ['assessment_id' => $assessmentId]);

    ini_set('max_execution_time', 0); // ✅ Unlimited — server hard limit is the actual cap
    set_time_limit(0);
    $analysisStartTime = microtime(true);
    $maxAnalysisTime = 900; // ✅ Effectively unlimited — server hard limit applies, register_shutdown_function catches fatal errors
    $db  = getDB();

    // ✅ Register shutdown handler to catch fatal errors and update status
    $GLOBALS['_analysis_id'] = $assessmentId;
    $GLOBALS['_analysis_db'] = $db;
    register_shutdown_function(function() {
        $id = $GLOBALS['_analysis_id'] ?? null;
        $db = $GLOBALS['_analysis_db'] ?? null;
        if (!$id || !$db) return;

        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            logError('Fatal shutdown in runAnalysis', ['assessment_id' => $id, 'error' => $error]);
            try {
                $db->prepare("UPDATE assessments SET status='failed', scan_error=? WHERE id=?")
                   ->execute(['Fatal error: ' . $error['message'], $id]);
            } catch (\Throwable $e) {
                logError('Failed to update status on shutdown', ['error' => $e->getMessage()]);
            }
        }
    });
    $cfg = require __DIR__ . '/config.php';

    // ── DB Migration: معالج مركزياً في migrate.php ─────────────
    // (يشتغل تلقائياً مرة واحدة عبر init.php)

    // ─── Helper: تحديث scan_step في DB أثناء التحليل (للـ Polling) ──
    $updateStep = function(int $step) use ($db, $assessmentId) {
        try {
            $db->prepare("UPDATE assessments SET scan_step=? WHERE id=?")
               ->execute([$step, $assessmentId]);
        } catch (\Throwable $e) {}
    };

    // ─── Helper: حفظ مرحلي لبيانات Apify فور وصولها ─────────
    // يضمن عدم ضياع أي بيانات حتى لو انقطع الاتصال في المنتصف
    $saveScanProgress = function(string $key, $value) use ($db, $assessmentId) {
        try {
            $db->prepare(
                "UPDATE assessments SET scan_result = JSON_SET(COALESCE(scan_result,'{}'), ?, CAST(? AS JSON)) WHERE id=?"
            )->execute(['$.' . $key, json_encode($value, JSON_UNESCAPED_UNICODE), $assessmentId]);
        } catch (\Throwable $e) {
            logError('saveScanProgress failed', [
                'key'           => $key,
                'assessment_id' => $assessmentId,
                'error'         => $e->getMessage(),
            ]);
        }
    };

    // ─── 1) جلب بيانات العميل ────────────────────────────────
    $stmt = $db->prepare('SELECT l.*, a.id as assessment_id
        FROM assessments a LEFT JOIN leads l ON a.lead_id=l.id WHERE a.id=?');
    $stmt->execute([$assessmentId]);
    $row = $stmt->fetch();
    $leadData = $row; // P0-1: تعريف $leadData لضمان عمل genRecommendations و رادار المنافسين

    $fbUrl  = trim($row['facebook_url']  ?? '');
    $igUrl  = trim($row['instagram_url'] ?? '');
    $webUrl = trim($row['website_url']   ?? '');
    $tkUrl  = trim($row['tiktok_url']   ?? '');
    $twUrl  = trim($row['twitter_url']  ?? '');

    // الرابط الأساسي المُدخل من المستخدم (أيها أول — الخمس منصات)
    $primaryUrl = $webUrl ?: $fbUrl ?: $igUrl ?: $tkUrl ?: $twUrl;
    if (!$primaryUrl) {
        return ['ok' => false, 'error' => 'لا يوجد أي رابط للفحص'];
    }

    require_once __DIR__ . '/page-scan.php';
    require_once __DIR__ . '/apify-scraper.php';
    $updateStep(1); // step 1: بدء الفحص الأساسي

    // ─── 2) الفحص الأساسي الذكي ─────────────────────────────
    $cacheKey = 'scan_' . md5($primaryUrl);
    $scanResult = cacheGet($cacheKey);

    // لا نستخدم الكاش إذا كانت النتيجة فاشلة أو غير موجودة
    if (!$scanResult || !($scanResult['success'] ?? false)) {
        logInfo('Starting page scan', ['url' => $primaryUrl, 'assessment_id' => $assessmentId]);

        try {
            // تحديث حالة assessment الى running
            $db->prepare("UPDATE assessments SET status='running' WHERE id=?")->execute([$assessmentId]);

            $scanResult = runPageScan($primaryUrl, $cfg);
            $updateStep(2); // step 2: اكتمل فحص الموقع واكتُشفت المنصات

            logInfo('Page scan completed', ['url' => $primaryUrl, 'success' => $scanResult['success'] ?? false]);

            // ✅ نخزّن في الكاش فقط إذا نجح الفحص — لا نُخزّن الفشل
            if ($scanResult['success'] ?? false) {
                cacheSet($cacheKey, $scanResult, 6 * 3600);
            }
        } catch (\Throwable $e) {
            logError('Page scan failed', ['url' => $primaryUrl, 'error' => $e->getMessage()]);
            $scanResult = ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ─── 3) استخدم نتائج محرك الاكتشاف مباشرة ──────────────
    // runPageScan v5 يكتشف FB/IG ويشغّل Apify داخلياً
    // الأولوية: Apify (عميق) → runPageScan's Cross-Platform Discovery
    // إذا المستخدم أدخل FB/IG يدوياً ولم يُكتشفا من الموقع → اكملها
    $detectedFbUrl = $fbUrl ?: ($scanResult['facebook']['url'] ?? '');
    $igUsername    = $scanResult['instagram']['username'] ?? '';
    $detectedIgUrl = $igUrl ?: ($igUsername ? 'https://www.instagram.com/' . $igUsername : '');

    // ── تخمين هجومي (Aggressive Guessing) ──
    if (!$detectedFbUrl && !empty($igUsername)) {
        $detectedFbUrl = 'https://www.facebook.com/' . $igUsername;
    } elseif (!$detectedFbUrl && $detectedIgUrl) {
        preg_match('/instagram\.com\/([^\/\?#]+)/i', $detectedIgUrl, $m);
        if (!empty($m[1])) {
            $detectedFbUrl = 'https://www.facebook.com/' . trim($m[1]);
        }
    }


    // تخمين إنستقرام من الفيسبوك إذا لم نكتشفه بعد
    if (!$detectedIgUrl && $detectedFbUrl) {
        preg_match('/facebook\.com\/([^\/\?#]+)/i', $detectedFbUrl, $m);
        if (!empty($m[1]) && !in_array($m[1], ['profile.php', 'pages'])) {
            $detectedIgUrl = 'https://www.instagram.com/' . trim($m[1]);
        }
    }

    // ─── 4) Apify Facebook — فقط إذا لم تُجلب بعد ────────────
    // ⛔ Time-budget skip disabled: restored timeouts so analysis runs fully
    // if ((microtime(true) - $analysisStartTime) > $maxAnalysisTime) { goto skip_apify; }

    $apifyFb = null;
    // نعتبر أن لدينا بيانات حقيقية وعميقة فقط إذا كان لدينا عدد المنشورات
    $hasFbData = !empty($scanResult['facebook']['followers']) && isset($scanResult['facebook']['posts_count']) && $scanResult['facebook']['posts_count'] !== null;
    $updateStep(3); // step 3: يسحب بيانات Facebook

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
                    $saveScanProgress('facebook', $scanResult['facebook']); // ✅ حفظ فوري

                    // اكتشاف روابط انستقرام والموقع من داخل بيانات فيسبوك (مهم إذا كان الإدخال فيسبوك فقط)
                    if (empty($detectedIgUrl) && !empty($apifyFb['instagram'])) {
                        $detectedIgUrl = $apifyFb['instagram'];
                    }
                    if (empty($scanResult['website_scan']) && !empty($apifyFb['website'])) {
                        $newWebUrl = $apifyFb['website'];
                        // قم بفحصه فوراً لأننا لم نفحصه في الخطوة الأولى
                        $ws = _fetchAndScanWebsite($newWebUrl, $cfg);
                        $scanResult['website'] = $newWebUrl;
                        $scanResult['website_scan'] = $ws;

                        // محاولة اكتشاف انستقرام من الموقع إذا لم يكن موجوداً في صفحة الفيسبوك مباشرة
                        if (empty($detectedIgUrl) && !empty($ws['instagram_url'])) {
                            $detectedIgUrl = $ws['instagram_url'];
                        }
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
    $updateStep(4); // step 4: يسحب بيانات Instagram

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
                    $saveScanProgress('instagram', $scanResult['instagram']); // ✅ حفظ فوري
                }
            }
        } catch (\Throwable $e) {}
    } else {
        $apifyIg = $scanResult['instagram'] ?? null;
    }

    // ─── 5b) TikTok — من رابط مُدخل يدوياً أو مُكتشف تلقائياً ────
    // ⛔ Time-budget skip disabled: restored timeouts so analysis runs fully
    // if ((microtime(true) - $analysisStartTime) > $maxAnalysisTime) { goto skip_tiktok; }

    $tkUrlFinal = $tkUrl ?: ($scanResult['tiktok']['url'] ?? '');
    $hasTkData  = !empty($scanResult['tiktok']['followers']);
    if ($tkUrlFinal && !$hasTkData) {
        try {
            // نستخدم scanTikTokPublic التي تتضمن Apify + Public fallback
            $r = scanTikTokPublic($tkUrlFinal, $cfg);
            if ($r['success'] ?? false) {
                $scanResult['tiktok'] = $r;
                $saveScanProgress('tiktok', $r); // ✅ حفظ فوري
                // اكتشاف الموقع من تيك توك إذا لم نكن قد فحصناه
                if (empty($scanResult['website_scan']) && !empty($r['website'])) {
                    $newWebUrl = $r['website'];
                    $ws = _fetchAndScanWebsite($newWebUrl, $cfg);
                    $scanResult['website'] = $newWebUrl;
                    $scanResult['website_scan'] = $ws;
                }
            } else {
                // احفظ البيانات مع عنوان URL حتى لو فشل
                $scanResult['tiktok'] = array_merge($r, ['url' => $tkUrlFinal]);
                $saveScanProgress('tiktok', $scanResult['tiktok']);
                logInfo('TikTok scan returned partial/failed', ['url' => $tkUrlFinal, 'error' => $r['error'] ?? 'unknown']);
            }
        } catch (\Throwable $e) {
            logError('TikTok scrape failed', ['url' => $tkUrlFinal, 'error' => $e->getMessage()]);
            $scanResult['tiktok'] = [
                'success'  => false,
                'platform' => 'tiktok',
                'url'      => $tkUrlFinal,
                'error'    => 'تعذّر جلب بيانات تيك توك',
            ];
            $saveScanProgress('tiktok', $scanResult['tiktok']);
        }
    }

    skip_tiktok:

    // ─── 5d) تعليقات أفضل منشور (Facebook + Instagram) ──────────
    // تُغذّي الذكاء الاصطناعي بتحليل المشاعر والاعتراضات الحقيقية
    if (($cfg['analysis']['enable_apify'] ?? false)) {
        try {
            $token = getValidApifyToken($cfg);
            if ($token) {
                // Facebook: أفضل منشور
                $fbTopPost = $scanResult['facebook']['top_post'] ?? null;
                $fbTopUrl  = $fbTopPost['url'] ?? $fbTopPost['postUrl'] ?? '';
                if ($fbTopUrl) {
                    $fbComments = scrapePostComments($fbTopUrl, 'facebook', $token, 50);
                    if ($fbComments['success'] ?? false) {
                        $scanResult['facebook']['top_post_comments'] = $fbComments;
                        $saveScanProgress('facebook.top_post_comments', $fbComments);
                        logInfo('FB top post comments scraped', ['total' => $fbComments['total_comments']]);
                    }
                }
                // Instagram: أفضل منشور
                $igTopPost = $scanResult['instagram']['top_post'] ?? null;
                $igTopUrl  = $igTopPost['url'] ?? $igTopPost['postUrl'] ?? '';
                if ($igTopUrl) {
                    $igComments = scrapePostComments($igTopUrl, 'instagram', $token, 50);
                    if ($igComments['success'] ?? false) {
                        $scanResult['instagram']['top_post_comments'] = $igComments;
                        $saveScanProgress('instagram.top_post_comments', $igComments);
                        logInfo('IG top post comments scraped', ['total' => $igComments['total_comments']]);
                    }
                }
            }
        } catch (\Throwable $e) {
            logError('Comments scrape failed', ['error' => $e->getMessage()]);
        }
    }

    // ─── 5e) Google Maps Reviews — إذا وُجد رابط Maps ────────────
    if (($cfg['analysis']['enable_apify'] ?? false)) {
        $mapsUrl = $row['maps_url'] ?? '';
        // MAPS-1 FIX: جلب من website_scan مباشرة (الحقل الجديد)
        if (!$mapsUrl) {
            $mapsUrl = $scanResult['website_scan']['google_maps_url'] ?? '';
        }
        if (!$mapsUrl) {
            // fallback: بحث في links القديمة (للتوافق الخلفي)
            $wsLinks = $scanResult['website_scan']['links'] ?? [];
            foreach ((array)$wsLinks as $link) {
                if (is_string($link) && str_contains($link, 'google.com/maps')) {
                    $mapsUrl = $link; break;
                }
            }
        }
        if ($mapsUrl) {
            try {
                $token = getValidApifyToken($cfg);
                if ($token) {
                    $mapsResult = scrapeGoogleMapsReviews($mapsUrl, $token, 50);
                    if ($mapsResult['success'] ?? false) {
                        $scanResult['google_maps'] = $mapsResult;
                        $saveScanProgress('google_maps', $mapsResult);
                        logInfo('Google Maps reviews scraped', ['total' => $mapsResult['total_reviews'], 'rating' => $mapsResult['avg_rating']]);
                    }
                }
            } catch (\Throwable $e) {
                logError('Google Maps scrape failed', ['error' => $e->getMessage()]);
            }
        }
    }

    // ─── 5f) Cloud Video Intelligence — تحليل أفضل فيديو/رييل ───
    $gcpCredsFile = __DIR__ . '/gcp-credentials.json';
    if (file_exists($gcpCredsFile)) {
        require_once __DIR__ . '/gcp-video-analyzer.php';
        $topVideoUrl = '';
        foreach ((array)($scanResult['instagram']['latest_posts'] ?? $scanResult['instagram']['posts'] ?? []) as $p) {
            $u = $p['videoUrl'] ?? $p['video_url'] ?? $p['videoPlayUrl'] ?? '';
            if ($u && str_starts_with($u, 'http')) { $topVideoUrl = $u; break; }
        }
        if (!$topVideoUrl) {
            foreach ((array)($scanResult['tiktok']['latest_posts'] ?? $scanResult['tiktok']['posts'] ?? []) as $p) {
                $u = $p['videoUrl'] ?? $p['video_url'] ?? $p['playAddr'] ?? '';
                if ($u && str_starts_with($u, 'http')) { $topVideoUrl = $u; break; }
            }
        }
        if ($topVideoUrl) {
            try {
                logInfo('GCP Video analysis started', ['url' => substr($topVideoUrl, 0, 80)]);
                $vidResult = analyzeVideoContent($topVideoUrl, $gcpCredsFile, 'alabeer-hub-video-analysis');
                if ($vidResult['analyzed']) {
                    $scanResult['video_intelligence'] = $vidResult;
                    $saveScanProgress('video_intelligence', $vidResult);
                    logInfo('GCP Video done', ['hook' => mb_substr($vidResult['hook_text'], 0, 60)]);
                } else {
                    logError('GCP Video failed', ['error' => $vidResult['error']]);
                }
            } catch (\Throwable $e) {
                logError('GCP Video exception', ['error' => $e->getMessage()]);
            }
        }
    }

    // ─── 5c) Twitter — من رابط مُدخل يدوياً أو مُكتشف تلقائياً ──
    // ⛔ Time-budget skip disabled: restored timeouts so analysis runs fully
    // if ((microtime(true) - $analysisStartTime) > $maxAnalysisTime) { goto skip_twitter; }

    $twUrlFinal = $twUrl ?: ($scanResult['twitter']['url'] ?? '');
    $hasTwData  = !empty($scanResult['twitter']['followers']);
    if ($twUrlFinal && !$hasTwData) {
        try {
            // نستخدم scanTwitterPublic التي تتضمن Apify + Public fallback
            $r = scanTwitterPublic($twUrlFinal, $cfg);
            if ($r['success'] ?? false) {
                $scanResult['twitter'] = $r;
                $saveScanProgress('twitter', $r); // ✅ حفظ فوري
                // اكتشاف الموقع من تويتر إذا لم نكن قد فحصناه
                if (empty($scanResult['website_scan']) && !empty($r['website'])) {
                    $newWebUrl = $r['website'];
                    $ws = _fetchAndScanWebsite($newWebUrl, $cfg);
                    $scanResult['website'] = $newWebUrl;
                    $scanResult['website_scan'] = $ws;
                }
            } else {
                // احفظ الفشل أيضاً ليعرض الفرونت "تعذّر جلب بيانات تويتر"
                $scanResult['twitter'] = array_merge($r, ['url' => $twUrlFinal]);
                $saveScanProgress('twitter', $scanResult['twitter']);
                logInfo('Twitter scan returned partial/failed', ['url' => $twUrlFinal, 'error' => $r['error'] ?? 'unknown']);
            }
        } catch (\Throwable $e) {
            $scanResult['twitter'] = [
                'success'  => false,
                'platform' => 'twitter',
                'url'      => $twUrlFinal,
                'error'    => 'تعذّر جلب بيانات تويتر',
            ];
            $saveScanProgress('twitter', $scanResult['twitter']);
            logError('Twitter scrape exception', ['url' => $twUrlFinal, 'error' => $e->getMessage()]);
        }
    }

    skip_twitter:

    // ─── 6) الإعلانات ─────────────────────────────────────────
    // ⛔ Time-budget skip disabled: restored timeouts so analysis runs fully
    // if ((microtime(true) - $analysisStartTime) > $maxAnalysisTime) { goto skip_ads; }

    // استخدم بيانات runPageScan + Apify إذا لم تكن كافية
    $adsRaw  = $scanResult['ads_library'] ?? null;
    // ✅ الإصلاح: لا نتخطى Apify إلا إذا كان total_ads > 0 — البيانات الفارغة تعني أننا لم نسحب بعد
    $adsHasRealData = !empty($adsRaw) && (($adsRaw['total_ads'] ?? 0) > 0 || ($adsRaw['active_ads'] ?? 0) > 0);
    $adsData = $adsHasRealData ? $adsRaw : null;
    $updateStep(5); // step 5: يسحب بيانات الإعلانات

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

        // الأولوية 3: اسم الشركة أو النطاق
        if (!$adsQuery && !$adsPageId) {
            $adsQuery = $leadData['company_name'] ?? '';
            if (!$adsQuery && $primaryUrl) {
                $host = parse_url($primaryUrl, PHP_URL_HOST);
                $adsQuery = str_replace('www.', '', $host ?? '');
            }
        }

        if (($adsQuery || $adsPageId) && ($cfg['analysis']['enable_apify'] ?? false)) {
            try {
                $token = getValidApifyToken($cfg);
                if ($token) {
                    $searchParam = $adsPageId ? "ID:{$adsPageId}" : $adsQuery;
                    logInfo('Starting Ads Library scrape', ['query' => $searchParam]);
                    // نمرر بيانات Facebook إذا توفرت لاستخدام pageAdLibrary مباشرة
                    $r = scrapeAdsLibrary($searchParam, $token, $cfg, $cfg['apis']['ads_default_country'] ?? 'SA', $apifyFb ?? []);
                    if ($r['success'] ?? false) {
                        logInfo('Ads Library scrape successful', ['query' => $searchParam, 'total_ads' => $r['total_ads'] ?? 0]);
                        $adsData = $r;
                        $saveScanProgress('ads_library', $r); // ✅ حفظ فوري
                    } else {
                        logError('Ads Library scrape failed', ['query' => $searchParam, 'error' => $r['error'] ?? 'Unknown error']);
                    }
                }
            } catch (\Throwable $e) {
                logError('Ads Library scrape exception', ['query' => $searchParam, 'error' => $e->getMessage()]);
            }
        }
        // Fallback: Meta Graph API
        if (!$adsData && $adsQuery) {
            $rawAds = fetchAdsLibrary($adsQuery, $cfg['apis']['meta_ads_token'] ?? '');
            if ($rawAds && !isset($rawAds['error'])) $adsData = $rawAds;
        }
    }

    skip_ads:

    // ─── 7) رادار المنافسين v2 ─────────────────────────────────
    require_once __DIR__ . '/competitors/orchestrator.php';

    $compRadar = null;
    if (($cfg['analysis']['enable_apify'] ?? false)) {
        try {
            $compResult = runCompetitorDiscovery($scanResult, $cfg);
            if ($compResult['success'] ?? false) {
                $saveScanProgress('competitor_discovery', $compResult);
                // الـ enrichment يأتي في Sprint 3 — حالياً نحفظ Discovery فقط
                $compRadar = $compResult['top_competitors'];
            } else {
                logError('Competitor discovery failed', [
                    'error' => $compResult['error'] ?? 'Unknown',
                    'diagnostics' => $compResult['diagnostics'] ?? [],
                ]);
            }
        } catch (\Throwable $e) {
            logError('Competitor discovery exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    // ─── 8) دمج الكل في scanResult موحّد ─────────────────────
    // الأولوية: Apify (عميق) → runPageScan's Cross-Platform Discovery

    // Facebook: Apify فقط إذا نجح، وإلا من Cross-Platform Discovery
    $scanResult['facebook']  = $apifyFb  ?? $scanResult['facebook']  ?? null;

    // Instagram: Apify فقط إذا نجح، وإلا من Cross-Platform Discovery
    $scanResult['instagram'] = $apifyIg  ?? $scanResult['instagram'] ?? null;

    // TikTok & Twitter: من Cross-Platform Discovery (page-scan.php)
    $scanResult['tiktok']  = $scanResult['tiktok']  ?? null;
    $scanResult['twitter'] = $scanResult['twitter'] ?? null;

    // ── P1-1: تحديد الاسم النهائي بعد اكتمال كل البيانات (مصدر واحد) ──────
    // الأولوية: عنوان الموقع > OG Title > اسم صفحة FB (Apify) > اسم IG الكامل > username IG > إدخال المستخدم
    $finalName = $scanResult['website_scan']['title']          // 1. عنوان الموقع (الأكثر رسمية)
              ?? $scanResult['og']['title']                    // 2. OG meta title
              ?? $scanResult['facebook']['page_name']          // 3. اسم صفحة Facebook من Apify
              ?? $scanResult['instagram']['full_name']         // 4. الاسم الكامل من Instagram
              ?? $scanResult['instagram']['username']          // 5. اسم المستخدم IG
              ?? $row['company_name']                          // 6. اسم الشركة المُدخَل
              ?? $row['full_name']                             // 7. اسم المستخدم المُدخَل (الأخير)
              ?? '';
    if (!empty($finalName)) {
        $db->prepare("UPDATE leads SET full_name=? WHERE id=(SELECT lead_id FROM assessments WHERE id=?)")
           ->execute([$finalName, $assessmentId]);
        $row['full_name'] = $finalName;
    }

    // إضافة معلومات العميل والنموذج
    $scanResult['lead_objective']   = $leadData['objective']       ?? '';
    $scanResult['lead_audience']    = $leadData['target_audience'] ?? '';
    $scanResult['lead_budget']      = $leadData['ad_budget']       ?? '';
    // BUG-COMP-B2: لا تدوس على نتيجة page-scan بـ null
    if (!empty($compRadar)) {
        $scanResult['competitor_radar'] = $compRadar;
    }
    if (!empty($compResult['market_summary'])) {
        $scanResult['market_summary'] = $compResult['market_summary'];
    }
    // ابقِ القيمة الموجودة من runPageScan إن نجحت

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
    // hasSSL: من فحص الموقع أو من الـ URL مباشرة (https = SSL)
    $urlHasSSL = str_starts_with(strtolower($primaryUrl), 'https://');
    $scanResult['hasSSL']      = $ws['has_ssl']          ?? ($urlHasSSL ?: null);
    $scanResult['hasPixel']    = $ws['has_fb_pixel']      ?? $scanResult['has_fb_pixel'] ?? null;
    $scanResult['hasGA']       = $ws['has_ga']            ?? $scanResult['has_ga']        ?? null;
    $scanResult['hasTikTok']   = $ws['has_tiktok']        ?? $scanResult['has_tiktok']    ?? null;
    $scanResult['hasSnapchat'] = $ws['has_snapchat']      ?? null;
    $scanResult['hasWhatsApp'] = $ws['has_whatsapp']                           // ✅ الموقع
                               ?? ($scanResult['facebook']['has_whatsapp'] ?? null) // ✅ فيسبوك
                               ?? (!empty($scanResult['facebook']['whatsapp']) ?: null) // ✅ Apify whatsapp field
                               ?? ($scanResult['social']['has_whatsapp'] ?? null)   // ✅ Social عام
                               ?? $scanResult['has_whatsapp']                       // ✅ legacy
                               ?? null;

    if (empty($scanResult['facebook']['whatsapp']) && !empty($scanResult['hasWhatsApp'])) {
        $scanResult['facebook']['whatsapp'] = $scanResult['facebook']['phone'] ?? $ws['phone'] ?? '';
    }
    $scanResult['hasCTA']      = $ws['has_cta']           ?? null;
    $scanResult['hasSchema']   = $ws['has_schema']        ?? null;
    $scanResult['hasOGTags']   = !empty($scanResult['og']['title'])
                               || ($ws['has_og_tags'] ?? false);
    $scanResult['title']        = $ws['title']             ?? $scanResult['og']['title'] ?? '';
    $scanResult['description']  = $ws['description']       ?? $scanResult['og']['description'] ?? '';
    $scanResult['h1']           = $ws['h1']                ?? '';

    // ✅ Label for time budget skip
    skip_apify:

    if (function_exists('normalizeScanResult')) {
        $scanResult = normalizeScanResult($scanResult);
    }

    // ── المعلومات المجمّعة من كل المنصات ──────────────────────
    $fbContact = $scanResult['facebook']['has_contact'] ?? false;
    $igContact = !empty($scanResult['instagram']['website']);
    $scanResult['has_any_contact'] = $fbContact || $igContact
                                  || ($ws['has_phone'] ?? false)
                                  || ($ws['has_contact_form'] ?? false);



    skip_competitors:

    // ─── 8) حساب الدرجة ──────────────────────────────────────
    ['score' => $finalScore, 'breakdown' => $breakdown] = scoreFromScanData($scanResult);

    // ─── 9) التوصيات + النظرة التسويقية ───────────────────────
    $tier     = tierFromScore($finalScore);
    $gen      = genRecommendations($finalScore, $breakdown, $scanResult, []);
    $insights = genInsights($breakdown, [], $scanResult);

    // ─── 10) OpenAI analysis ───────────────────────────────────
    $aiResult = null;
    $updateStep(6); // step 6: يكتب التقرير بالذكاء الاصطناعي

    // ✅ Check time budget - skip AI if running low on time (use fallback instead)
    $skipAiDueToTimeout = false;
    if ((microtime(true) - $analysisStartTime) > ($maxAnalysisTime - 30)) { // Need 30s for fallback
        logInfo('Skipping AI analysis due to time budget, using fallback', ['elapsed' => round(microtime(true) - $analysisStartTime, 2)]);
        $skipAiDueToTimeout = true;
    }

    $openAiEnabled = $cfg['analysis']['enable_openai'] ?? ($cfg['analysis']['enable_gemini'] ?? false);
    if (!$skipAiDueToTimeout && $openAiEnabled) {
        try {
            require_once __DIR__ . '/ai-analyze.php';
            $aiResult = runGeminiAnalysis([
                'id'              => $assessmentId,
                'score'           => $finalScore,
                'breakdown'       => $breakdown,
                'scan_result'     => $scanResult,
                // ── بيانات العميل الكاملة للـ AI ──────────────────
                'full_name'       => $row['full_name']        ?? '',
                'company_name'    => $row['company_name']     ?? '',
                'project_type'    => $row['project_type']     ?? '',
                'country'         => $row['country']          ?? '',
                'platform'        => $row['platform']         ?? '',
                'objective'       => $row['objective']        ?? '',
                'target_audience' => $row['target_audience']  ?? '',
                'ad_budget'       => $row['ad_budget']        ?? '',
            ], $cfg);
            // ── AI يحل محل المحلي — لا دمج (مصدران مختلفان) ──
            // التوصيات: AI أولاً وتحل محل المحلية كاملاً (schema مختلف)
            if (!empty($aiResult['recommendations'])) {
                $gen['recommendations'] = $aiResult['recommendations'];
            }
            // الملخص
            if (!empty($aiResult['summary'])) {
                $gen['summary'] = $aiResult['summary'];
            }
            // القوة والضعف: AI أولاً، المحلي fallback إذا AI أعاد أقل من 3 نقاط
            if (!empty($aiResult['strengths']) && count($aiResult['strengths']) >= 3) {
                $insights['strengths']  = $aiResult['strengths'];
            }
            if (!empty($aiResult['weaknesses']) && count($aiResult['weaknesses']) >= 3) {
                $insights['weaknesses'] = $aiResult['weaknesses'];
            }
            if (!empty($aiResult['action_week'])) {
                $gen['actionWeek'] = $aiResult['action_week'];
            }
        } catch (\Throwable $e) {
            logError('AI analysis failed', ['assessment_id' => $assessmentId, 'error' => $e->getMessage()]);
        }
    }

    // ملاحظة: المهاجرات تُدار مركزياً في api/migrate.php (يستدعى عبر init.php).
    // كان هنا auto-migration مكرر يُنفّذ على كل تحليل ويُعيد تعريف status إلى
    // DEFAULT 'pending' (NULL مسموح) — متعارض مع schema_mysql.sql ومع migrate.php.
    // أُزيل لأن migrate.php هو المصدر الوحيد للمهاجرات.

    // ─── 11) حفظ النتيجة ─────────────────────────────────────
    try {
        $db->prepare("UPDATE assessments SET
            status='analyzed', score=?, tier=?, breakdown=?, summary=?,
            recommendations=?, strengths=?, weaknesses=?, next_steps=?,
            scan_result=?, scan_status=?, scan_error=?, ai_report=?
            WHERE id=?")->execute([
            $finalScore, $tier,
            json_encode($gen['detailedBreakdown'] ?? $breakdown, JSON_UNESCAPED_UNICODE),
            $gen['summary'] ?? '',
            json_encode($gen['recommendations'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($insights['strengths']  ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($insights['weaknesses'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($gen['actionWeek']      ?? $aiResult['action_week'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($scanResult,                   JSON_UNESCAPED_UNICODE),
            'success', null,
            json_encode($aiResult,                     JSON_UNESCAPED_UNICODE),
            $assessmentId,
        ]);
    } catch (\Throwable $e) {
        logError('DB update failed in runAnalysis', [
            'assessment_id' => $assessmentId,
            'error' => $e->getMessage()
        ]);
        $db->prepare("UPDATE assessments SET status='analyzed', score=?, tier=? WHERE id=?")
           ->execute([$finalScore, $tier, $assessmentId]);
    }

    logInfo('Analysis run completed', [
        'assessment_id' => $assessmentId,
        'score' => $finalScore,
        'tier' => $tier
    ]);

    return [
        'ok'              => true,
        'score'           => $finalScore,
        'tier'            => $tier,
        'breakdown'       => $breakdown,
        'summary'         => $gen['summary'] ?? '',
        'recommendations' => $gen['recommendations'] ?? [],
        'strengths'       => $insights['strengths']  ?? [],
        'weaknesses'      => $insights['weaknesses'] ?? [],
        'action_week'     => $gen['actionWeek']      ?? $aiResult['action_week'] ?? [],
        'scan_result'     => $scanResult,
        'ai_report'       => $aiResult,
    ];
}
