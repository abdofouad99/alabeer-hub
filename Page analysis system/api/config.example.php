<?php
// ============================================================
// api/config.example.php — Production-Ready Config Loader
// انسخ إلى api/config.php (gitignored) واترك القيم الحساسة في .env.
// يقرأ من .env إذا كان موجوداً، ثم getenv()، ثم القيم الافتراضية.
// ============================================================

// ── 1) محاولة تحميل .env (parse_ini_file آمن، لا يحتاج dotenv lib) ──
$envPath = __DIR__ . '/../.env';
$env     = file_exists($envPath) ? (parse_ini_file($envPath, false, INI_SCANNER_RAW) ?: []) : [];

// helper: env file > env var > default (returns string)
$get = function (string $key, string $default = '') use ($env): string {
    if (isset($env[$key]) && $env[$key] !== '') return (string)$env[$key];
    $sysVal = getenv($key);
    if ($sysVal !== false && $sysVal !== '') return (string)$sysVal;
    return $default;
};

// helper: قائمة CSV → array (filtered, trimmed, non-empty)
$csv = function (string $key) use ($get): array {
    $raw = $get($key, '');
    if ($raw === '') return [];
    return array_values(array_filter(array_map('trim', explode(',', $raw)), fn($v) => $v !== ''));
};

// helper: aggregate Apify tokens. يجمع APIFY_TOKENS (CSV) مع APIFY_TOKEN
// و APIFY_TOKEN_1..APIFY_TOKEN_9 للتوافق مع تركيبات .env المختلفة.
$apifyTokensList = function () use ($get, $csv): array {
    $list = $csv('APIFY_TOKENS');
    $single = $get('APIFY_TOKEN', '');
    if ($single !== '') $list[] = $single;
    for ($i = 1; $i <= 9; $i++) {
        $v = $get('APIFY_TOKEN_' . $i, '');
        if ($v !== '') $list[] = $v;
    }
    return array_values(array_unique(array_filter(array_map('trim', $list), fn($v) => $v !== '')));
};

// ── 2) تكوين قاعدة البيانات ──────────────────────────────────
$dbHost = $get('DB_HOST', '127.0.0.1');
$dbPort = $get('DB_PORT', '3306');

return [
    'db' => [
        // db.php سيُحلّل host:port تلقائياً، نمررها مدمجة للحفاظ على التوافق
        'host'    => $dbHost . ':' . $dbPort,
        'name'    => $get('DB_NAME', 'growth_lab'),
        'user'    => $get('DB_USER', 'growth'),
        'pass'    => $get('DB_PASS', ''),
        'charset' => $get('DB_CHARSET', 'utf8mb4'),
    ],

    // ── 3) أولوية AI providers (Fallback chain) ──────────────
    // الترتيب: gemini أولاً (مجاني وسريع)، ثم groq (مجاني)، ثم openai (مدفوع
    // كاحتياط نهائي لو الاثنان معطّلان أو مستهلك كان كامل)، وأخيراً pekpik.
    // إن استُهلك Gemini بـ 429 فالنظام يقفز إلى Groq ثم OpenAI تلقائياً.
    'analysis' => [
        'ai_priority'        => ['openai'],
        'enable_openai'      => filter_var($get('ENABLE_OPENAI', 'true'), FILTER_VALIDATE_BOOLEAN),
        'enable_gemini'      => false,
        'enable_groq'        => false,
        // ── علامات تشغيل Apify scrapers ────────────────────────
        // الإعلانات والمنافسين مُفعَّلان افتراضياً (true). ضع ENABLE_APIFY=false
        // أو ENABLE_ADS_LIBRARY=false في .env لتعطيلها (تقليل التكلفة/الحصة).
        // analyze.php و page-scan.php يفحصان هاتين القيمتين قبل أي استدعاء
        // لـ Apify scrapers.
        // ⚠️ ملاحظة هامة: لو نشرت بدون ملف .env فستعمل الـ scrapers افتراضياً
        // وقد تستهلك حصة Apify بدون قصد. اضبط false صراحةً للحسابات الإنتاجية
        // المُحدّدة الميزانية.
        'enable_apify'       => filter_var($get('ENABLE_APIFY',       'true'),  FILTER_VALIDATE_BOOLEAN),
        'enable_ads_library' => filter_var($get('ENABLE_ADS_LIBRARY', 'true'),  FILTER_VALIDATE_BOOLEAN),
        'enable_pagespeed'   => filter_var($get('ENABLE_PAGESPEED',   'true'), FILTER_VALIDATE_BOOLEAN), // WEB-4 FIX: مفعّل افتراضياً (حد 25 طلب/يوم بدون key)
        // enable_competitor_enrich مُعطَّل افتراضياً لأنه يضاعف استهلاك Apify ×6
        // لكل منافس (يُستدعى runPageScan الكامل لكل صفحة منافس).
        'enable_competitor_enrich' => filter_var($get('ENABLE_COMPETITOR_ENRICH', 'false'), FILTER_VALIDATE_BOOLEAN),
        // TT-2 FIX: تفعيل Comments Sentiment لتيك توك
        'enable_tt_comments' => filter_var($get('ENABLE_TT_COMMENTS', 'true'), FILTER_VALIDATE_BOOLEAN),

        // ── Instagram Deep Scan switches (مهمة لتقرير IG شامل) ─────
        // enable_ig_comments: استدعاء Comments Actor لأفضل 5 منشورات → Sentiment + Objections
        // enable_ig_vision:   تحليل أفضل 5 صور عبر OpenAI Vision (gpt-4o-mini) → OCR + content tags
        // enable_ig_stories:  محاولة سحب Stories + Highlights (Actor مخصص)
        // enable_ig_sentiment_ai: استخدام OpenAI لتحليل المشاعر بدلاً من heuristic بسيط
        'enable_ig_comments'    => filter_var($get('ENABLE_IG_COMMENTS',    'true'),  FILTER_VALIDATE_BOOLEAN),
        'enable_ig_vision'      => filter_var($get('ENABLE_IG_VISION',      'false'), FILTER_VALIDATE_BOOLEAN),
        'enable_ig_stories'     => filter_var($get('ENABLE_IG_STORIES',     'false'), FILTER_VALIDATE_BOOLEAN),
        'enable_ig_sentiment_ai'=> filter_var($get('ENABLE_IG_SENTIMENT_AI','true'),  FILTER_VALIDATE_BOOLEAN),
        // عدد المنشورات/الصور التي يُجرى عليها تحليل المشاعر/الرؤية (تكلفة)
        'ig_comments_top_posts' => (int)$get('IG_COMMENTS_TOP_POSTS', '5'),
        'ig_vision_top_images'  => (int)$get('IG_VISION_TOP_IMAGES',  '5'),

        // ── Facebook Deep Scan switches (V3) ───────────────────────
        // enable_fb_comments:     استدعاء FB Comments Actor لأفضل 5 منشورات → Sentiment
        // enable_fb_vision:       تحليل أفضل 5 صور Facebook عبر OpenAI Vision (مكلف)
        // enable_fb_sentiment_ai: استخدام OpenAI لتلخيص مشاعر التعليقات (دقة أعلى)
        'enable_fb_comments'    => filter_var($get('ENABLE_FB_COMMENTS',    'true'),  FILTER_VALIDATE_BOOLEAN),
        'enable_fb_vision'      => filter_var($get('ENABLE_FB_VISION',      'false'), FILTER_VALIDATE_BOOLEAN),
        'enable_fb_sentiment_ai'=> filter_var($get('ENABLE_FB_SENTIMENT_AI','true'),  FILTER_VALIDATE_BOOLEAN),
        'fb_comments_top_posts' => (int)$get('FB_COMMENTS_TOP_POSTS', '5'),
        'fb_vision_top_images'  => (int)$get('FB_VISION_TOP_IMAGES',  '5'),
        // عدد المنشورات الافتراضي عند سحب Facebook (50 = تحليل أعمق)
        'fb_max_posts'          => (int)$get('FB_MAX_POSTS', '50'),

        // ── Competitors v2 ──
        'competitor_discovery_mode'     => $get('COMPETITOR_DISCOVERY_MODE', 'auto'),
        'competitor_max_candidates'     => (int)$get('COMPETITOR_MAX_CANDIDATES', '30'),
        'competitor_top_n'              => (int)$get('COMPETITOR_TOP_N', '5'),
        'competitor_min_validation_score' => (int)$get('COMPETITOR_MIN_VALIDATION_SCORE', '40'),
        'competitor_retry_if_less_than'   => (int)$get('COMPETITOR_RETRY_IF_LESS_THAN', '3'),

        // ── Competitors v2 / Sprint 3 (Enrichment) ──
        'competitor_enrich_tier'          => (int)$get('COMPETITOR_ENRICH_TIER', '3'),
        'competitor_include_reviews'      => filter_var($get('COMPETITOR_INCLUDE_REVIEWS', 'true'), FILTER_VALIDATE_BOOLEAN),
        'competitor_parallel_enrich'      => filter_var($get('COMPETITOR_PARALLEL_ENRICH', 'true'), FILTER_VALIDATE_BOOLEAN),
        'competitor_cache_hours'          => (int)$get('COMPETITOR_CACHE_HOURS', '6'),
        'competitor_max_reviews_per_comp' => (int)$get('COMPETITOR_MAX_REVIEWS_PER_COMP', '20'),

        // ── Competitors v2 / Sprint 4 (AI Analysis) ──
        'competitor_ai_strict_mode' => filter_var($get('COMPETITOR_AI_STRICT_MODE', 'true'), FILTER_VALIDATE_BOOLEAN),
        'competitor_ai_provider'    => $get('COMPETITOR_AI_PROVIDER', 'openai'),
        'competitor_ai_model'       => $get('COMPETITOR_AI_MODEL', 'gpt-4o-mini'),
        'competitor_ai_temperature' => (float)$get('COMPETITOR_AI_TEMPERATURE', '0.2'),
    ],

    // ── 4) مفاتيح APIs ───────────────────────────────────────
    'apis' => [
        // Gemini (Google) — الأساسي
        'gemini_keys'  => $csv('GEMINI_KEYS'),
        'gemini_key'   => $get('GEMINI_KEY', ''),  // backward-compat
        'gemini_model' => $get('GEMINI_MODEL', 'gemini-1.5-flash'),

        // Groq (fallback أول)
        'groq_key'     => $get('GROQ_KEY', ''),

        // Pekpik (fallback مقدّم — متعدد المسارات)
        'pekpik_base_url'      => $get('PEKPIK_BASE_URL', 'https://aiapiv2.pekpik.com/v1'),
        'pekpik_flagship_keys' => $csv('PEKPIK_FLAGSHIP_KEYS'),
        'pekpik_pro_keys'      => $csv('PEKPIK_PRO_KEYS'),
        'pekpik_gemini_keys'   => $csv('PEKPIK_GEMINI_KEYS'),
        'pekpik_keys'          => $csv('PEKPIK_KEYS'),

        // Fallbacks إضافية
        'deepseek_key'   => $get('DEEPSEEK_KEY', ''),
        'deepseek_model' => $get('DEEPSEEK_MODEL', 'deepseek-chat'),
        'openai_key'              => $get('OPENAI_KEY', ''),
        'openai_model'            => $get('OPENAI_MODEL', 'gpt-4o-mini'),
        'openai_timeout'          => (int)$get('OPENAI_TIMEOUT', '120'),
        'openai_connect_timeout'  => (int)$get('OPENAI_CONNECT_TIMEOUT', '15'),
        'openai_prompt_max_chars' => (int)$get('OPENAI_PROMPT_MAX_CHARS', '30000'),
        'openai_max_tokens'       => (int)$get('OPENAI_MAX_TOKENS', '3500'),
        'nvidia_keys'    => $csv('NVIDIA_KEYS'),

        // Apify — Tokens + Actor IDs
        // يدعم APIFY_TOKENS (CSV) و APIFY_TOKEN / APIFY_TOKEN_1..9 (مفرد).
        'apify_tokens'        => $apifyTokensList(),
        'apify_actor_ig'      => $get('APIFY_ACTOR_IG', 'apify/instagram-scraper'),
        'apify_actor_ig_comments'  => $get('APIFY_ACTOR_IG_COMMENTS',  'SbK00X0JYCPblD2wp'),
        'apify_actor_ig_stories'   => $get('APIFY_ACTOR_IG_STORIES',   'apify/instagram-stories-scraper'),
        'apify_actor_fb'      => $get('APIFY_ACTOR_FB', 'apify/facebook-pages-scraper'),
        'apify_actor_fb_comments' => $get('APIFY_ACTOR_FB_COMMENTS', 'us5srxAYnsrkgUv2v'),
        'apify_actor_tiktok'  => $get('APIFY_ACTOR_TIKTOK', 'clockworks/free-tiktok-scraper'),
        'apify_actor_twitter' => $get('APIFY_ACTOR_TWITTER', 'nfp1fpt5gUlBwPcor'),
        'apify_actor_website' => $get('APIFY_ACTOR_WEBSITE', 'apify/website-content-crawler'),
        'apify_actor_ads_fb'  => $get('APIFY_ACTOR_ADS_FB', 'JJghSZmShuco4j9gJ'),
        'ads_default_country' => $get('ADS_DEFAULT_COUNTRY', 'SA'),

        // عدد العناصر للسحب لكل منصة
        'tiktok_videos_limit'  => (int)$get('TIKTOK_VIDEOS_LIMIT', '200'),
        'twitter_tweets_limit' => (int)$get('TWITTER_TWEETS_LIMIT', '100'),

        // Meta / Facebook
        'facebook_access_token' => $get('FACEBOOK_ACCESS_TOKEN', ''),
        'meta_ads_token'        => $get('META_ADS_TOKEN', ''),

        // Google PageSpeed Insights
        'google_pagespeed_key'  => $get('GOOGLE_PAGESPEED_KEY', ''),

        // ── Competitors v2 ──
        'apify_actor_google_places'           => $get('APIFY_ACTOR_GOOGLE_PLACES', 'LmLOOMYKuCUrYsda2'),
        'apify_actor_google_search'           => $get('APIFY_ACTOR_GOOGLE_SEARCH', 'YNcgn7yiLc72ayYeB'),
        'apify_actor_google_search_fallback'  => $get('APIFY_ACTOR_GOOGLE_SEARCH_FALLBACK', 'V8SFJw3gKgULelpok'),
        'apify_actor_fb_pages_search'         => $get('APIFY_ACTOR_FB_PAGES_SEARCH', 'YAg3YuPbbASz7JzWG'),
        'apify_actor_fb_pages_search_fallback'=> $get('APIFY_ACTOR_FB_PAGES_SEARCH_FALLBACK', 'HBdQuY0Qwd2bDGM4a'),
        'apify_actor_google_maps_reviews'     => $get('APIFY_ACTOR_GOOGLE_MAPS_REVIEWS', 'Xb8osYTtOjlsgI6k9'),
    ],

    // ── 5) إعدادات التطبيق ───────────────────────────────────
    'app' => [
        'env'   => $get('APP_ENV', 'production'),
        'debug' => filter_var($get('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN),
    ],

    // ── 6) إعدادات التسجيل (Logging) ─────────────────────────
    'logging' => [
        'enabled'       => true,
        'level'         => $get('LOG_LEVEL', 'INFO'),
        'file_path'     => __DIR__ . '/../logs/app.log',
        'max_file_size' => 10 * 1024 * 1024, // 10MB
        'max_files'     => 5,
    ],

    // ── 7) إعدادات الكاش (Cache) ─────────────────────────────
    'cache' => [
        'enabled' => filter_var($get('CACHE_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN),
        'driver'  => $get('CACHE_DRIVER', 'file'), // file | redis | apcu
        'ttl'     => (int)$get('CACHE_TTL', '3600'),
        'file'    => [
            'path' => __DIR__ . '/../cache',
        ],
        'redis'   => [
            'url'   => $get('REDIS_URL', ''),
            'token' => $get('REDIS_TOKEN', ''),
        ],
    ],
];
