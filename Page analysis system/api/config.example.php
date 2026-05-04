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
        'ai_priority' => $csv('AI_PRIORITY') ?: ['gemini', 'groq', 'openai', 'pekpik'],
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
        'openai_key'     => $get('OPENAI_KEY', ''),
        'openai_model'   => $get('OPENAI_MODEL', 'gpt-4o-mini'),
        'nvidia_keys'    => $csv('NVIDIA_KEYS'),

        // Apify — Tokens + Actor IDs
        'apify_tokens'        => $csv('APIFY_TOKENS'),
        'apify_actor_ig'      => $get('APIFY_ACTOR_IG', 'apify/instagram-scraper'),
        'apify_actor_fb'      => $get('APIFY_ACTOR_FB', 'apify/facebook-pages-scraper'),
        'apify_actor_tiktok'  => $get('APIFY_ACTOR_TIKTOK', 'clockworks/free-tiktok-scraper'),
        'apify_actor_twitter' => $get('APIFY_ACTOR_TWITTER', 'u6ppkMWAx2E2MpEuF'),
        'apify_actor_website' => $get('APIFY_ACTOR_WEBSITE', 'apify/website-content-crawler'),
        'apify_actor_ads_fb'  => $get('APIFY_ACTOR_ADS_FB', 'curious_coder/facebook-ads-library-scraper'),

        // Meta / Facebook
        'facebook_access_token' => $get('FACEBOOK_ACCESS_TOKEN', ''),
        'meta_ads_token'        => $get('META_ADS_TOKEN', ''),
    ],

    // ── 5) إعدادات التطبيق ───────────────────────────────────
    'app' => [
        'env'   => $get('APP_ENV', 'production'),
        'debug' => filter_var($get('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN),
    ],
];
