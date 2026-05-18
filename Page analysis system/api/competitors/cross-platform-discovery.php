<?php
/**
 * Cross-Platform Discovery for Competitors
 * يستخرج روابط FB/IG/TT/X من موقع المنافس عبر cURL محلي
 *
 * المنطق مشابه لـ runPageScan لكن بدون استدعاء Apify
 */

declare(strict_types=1);

require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/helpers.php';

/**
 * @param array $competitor المنافس من Discovery
 * @return array منافس مُحدَّث مع:
 *   - social.facebook (إن لم يكن موجود)
 *   - social.instagram
 *   - social.tiktok
 *   - social.twitter
 *   - website_html (cached)
 *   - has_ssl, has_pixel, has_ga, has_cta (سريع)
 */
function discoverCompetitorSocialLinks(array $competitor, array $cfg): array {
    // ── 1. لو الـ social موجود من Google Places، نُكمّل فقط الناقص ──
    $existingSocial = $competitor['social'] ?? [];

    // ── 2. اجلب website (لو موجود) ──
    $website = $competitor['website'] ?? '';
    if (empty($website)) {
        // محاولة استنباط الموقع من Facebook page (لو متوفر)
        $fbUrl = $existingSocial['facebook'] ?? '';
        if (empty($fbUrl)) {
            // لا موقع ولا FB — ما يمكننا فعل شيء
            return $competitor;
        }
        // سنحاول استخراج الموقع من FB في step منفصل (Sprint 4)
    }

    if (empty($website)) return $competitor;

    // ── 3. cURL سريع ──
    $html = _fetchHtmlForCompetitor($website);
    if (empty($html)) {
        $competitor['_warnings'][] = 'تعذر جلب موقع المنافس';
        return $competitor;
    }

    // ── 4. استخراج روابط social ──
    $extracted = _extractSocialFromHtml($html, $website);

    // دمج مع الموجود (existing له الأولوية لأنه أدق)
    $merged = $existingSocial;
    foreach ($extracted as $platform => $url) {
        if (empty($merged[$platform])) {
            $merged[$platform] = $url;
        }
    }
    $competitor['social'] = $merged;

    // ── 5. تحليلات سريعة (0 Apify) ──
    $competitor['quick_analysis'] = _quickAnalyzeWebsite($html, $website);

    // حفظ html مؤقتاً للـ enrichment لاحقاً
    $competitor['_html_size'] = strlen($html);

    return $competitor;
}

/**
 * cURL سريع مع timeout قصير
 */
function _fetchHtmlForCompetitor(string $url): ?string {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10, // أقصر من العميل لأننا نسحب 5 منافسين
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml',
            'Accept-Language: ar,en-US;q=0.9',
        ],
        CURLOPT_ENCODING       => '',
    ]);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$html || $code >= 400) return null;
    return $html;
}

/**
 * استخراج روابط FB/IG/TT/X من HTML
 */
function _extractSocialFromHtml(string $html, string $baseUrl): array {
    $social = [];

    // Facebook
    if (preg_match('/https?:\/\/(?:www\.)?facebook\.com\/([a-zA-Z0-9._\-]+)/i', $html, $m)) {
        $slug = $m[1];
        // استبعاد روابط عامة
        if (!in_array($slug, ['sharer','share','login','tr','plugins','dialog','pages'], true)) {
            $social['facebook'] = 'https://www.facebook.com/' . $slug;
        }
    }

    // Instagram
    if (preg_match('/https?:\/\/(?:www\.)?instagram\.com\/([a-zA-Z0-9._]+)/i', $html, $m)) {
        $slug = $m[1];
        if (!in_array($slug, ['p','explore','reel','tv','accounts'], true)) {
            $social['instagram'] = 'https://www.instagram.com/' . $slug . '/';
        }
    }

    // TikTok
    if (preg_match('/https?:\/\/(?:www\.)?tiktok\.com\/@([a-zA-Z0-9._]+)/i', $html, $m)) {
        $social['tiktok'] = 'https://www.tiktok.com/@' . $m[1];
    }

    // Twitter / X
    if (preg_match('/https?:\/\/(?:www\.)?(?:twitter|x)\.com\/([a-zA-Z0-9_]+)/i', $html, $m)) {
        $slug = $m[1];
        if (!in_array(mb_strtolower($slug), ['intent','share','home','login','signup'], true)) {
            $social['twitter'] = 'https://twitter.com/' . $slug;
        }
    }

    // YouTube
    if (preg_match('/https?:\/\/(?:www\.)?youtube\.com\/(?:c\/|channel\/|@)([a-zA-Z0-9_\-]+)/i', $html, $m)) {
        $social['youtube'] = $m[0];
    }

    // LinkedIn
    if (preg_match('/https?:\/\/(?:www\.)?linkedin\.com\/(?:company|in)\/([a-zA-Z0-9_\-]+)/i', $html, $m)) {
        $social['linkedin'] = $m[0];
    }

    return $social;
}

/**
 * تحليل سريع للموقع (0 Apify)
 */
function _quickAnalyzeWebsite(string $html, string $url): array {
    $isHttps   = str_starts_with($url, 'https://');
    $hasPixel  = (bool)preg_match('/connect\.facebook\.net|fbq\(/i', $html);
    $hasGA     = (bool)preg_match('/google-analytics|gtag\(|GoogleAnalyticsObject/i', $html);
    $hasGtm    = (bool)preg_match('/googletagmanager\.com/i', $html);
    $hasWhats  = (bool)preg_match('/wa\.me|api\.whatsapp\.com/i', $html);
    $hasSchema = (bool)preg_match('/application\/ld\+json/i', $html);
    $hasOpenGraph = (bool)preg_match('/og:title|og:image/i', $html);

    // CTA detection
    $ctaPatterns = ['اطلب','احجز','اشترك','تواصل','اتصل','جرب','انضم',
                    'order','book','subscribe','contact','call','try','join','buy'];
    $hasCta = false;
    foreach ($ctaPatterns as $cta) {
        if (preg_match('/\b' . preg_quote($cta, '/') . '\b/iu', $html)) {
            $hasCta = true;
            break;
        }
    }

    // Tech stack detection
    $techStack = [];
    if (preg_match('/wp-content|wordpress/i', $html))       $techStack[] = 'WordPress';
    if (preg_match('/cdn\.shopify\.com|shopify/i', $html))  $techStack[] = 'Shopify';
    if (preg_match('/wix\.com|wixstatic/i', $html))         $techStack[] = 'Wix';
    if (preg_match('/squarespace/i', $html))                $techStack[] = 'Squarespace';
    if (preg_match('/__next|_next\/static/i', $html))       $techStack[] = 'Next.js';
    if (preg_match('/tailwindcss|tailwind/i', $html))       $techStack[] = 'Tailwind';

    // عنوان وصف الصفحة
    $title = '';
    if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $m)) {
        $title = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
    }

    return [
        'has_ssl'        => $isHttps,
        'has_fb_pixel'   => $hasPixel,
        'has_ga'         => $hasGA,
        'has_gtm'        => $hasGtm,
        'has_whatsapp'   => $hasWhats,
        'has_schema'     => $hasSchema,
        'has_open_graph' => $hasOpenGraph,
        'has_cta'        => $hasCta,
        'tech_stack'     => $techStack,
        'title'          => mb_substr($title, 0, 200),
        'html_size_kb'   => round(strlen($html) / 1024, 1),
    ];
}
