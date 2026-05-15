<?php
if (defined('PAGE_SCAN_LOADED')) return;
define('PAGE_SCAN_LOADED', true);

// ============================================================
// api/page-scan.php — مكتبة دوال الفحص (لا تُستدعى مباشرة)
// استخدم api/scan.php كنقطة الدخول HTTP
// ============================================================
require_once __DIR__ . '/db.php';// ============================================================
// محرك الاكتشاف المتبادل v5.0
// أي رابط واحد → تقرير كامل لجميع المنصات
// ============================================================
function runPageScan(string $rawUrl, array $cfg): array {
    $url  = normalizeUrl($rawUrl);
    if (!$url) return ['success' => false, 'error' => 'الرابط غير صالح'];

    $type = detectUrlType($url);

    $result = [
        'success'      => true,
        'url'          => $url,
        'type'         => $type,
        'scanned_at'   => date('Y-m-d H:i:s'),
        'og'           => [],
        'pagespeed'    => null,
        'ads_library'  => null,
        'social'       => null,
        'website'      => null,
        'website_scan' => null,
        'facebook'     => null,
        'instagram'    => null,
        'tiktok'       => null,
        'twitter'      => null,
    ];

    // ── Step 1: OG Tags (يعمل مع أي URL) ──────────────────────
    $result['og'] = scanOGTags($url);

    // ── Step 2: فحص يعتمد على نوع الرابط ──────────────────────
    $detectedWebsiteUrl = '';
    $detectedFbUrl      = '';
    $detectedIgUrl      = '';

    if ($type === 'facebook') {
        // ── مسار الفيسبوك ──
        $fbData = scanFacebookPublic($url, $cfg);
        $result['social']   = $fbData;
        $result['facebook'] = $fbData;

        // اكتشاف الموقع من FB Bio
        $detectedWebsiteUrl = $fbData['website'] ?? extractWebsiteFromFB($result['og']) ?? '';

        // اكتشاف Instagram من الموقع المرتبط
        if ($detectedWebsiteUrl) {
            $ws = _fetchAndScanWebsite($detectedWebsiteUrl, $cfg);
            $result['website']      = $detectedWebsiteUrl;
            $result['website_scan'] = $ws;
            $detectedIgUrl = $ws['instagram_url'] ?? '';
        }

        // إذا اكتشفنا IG → افحصه
        if ($detectedIgUrl && ($cfg['analysis']['enable_instagram'] ?? true)) {
            $igData = scanInstagramPublic($detectedIgUrl, $cfg);
            $result['instagram'] = $igData;
            if (!$detectedWebsiteUrl) {
                $detectedWebsiteUrl = $igData['website'] ?? '';
            }
        }

        // استخدم FB URL للإعلانات (الأكثر دقة)
        $adsSourceUrl = $url;

    } elseif ($type === 'instagram') {
        // ── مسار الإنستقرام ──
        $igData = scanInstagramPublic($url, $cfg);
        $result['social']    = $igData;
        $result['instagram'] = $igData;

        // اكتشاف الموقع من IG Bio
        $detectedWebsiteUrl = $igData['website'] ?? '';

        // افحص الموقع + اكتشف FB منه
        if ($detectedWebsiteUrl) {
            $ws = _fetchAndScanWebsite($detectedWebsiteUrl, $cfg);
            $result['website']      = $detectedWebsiteUrl;
            $result['website_scan'] = $ws;
            $detectedFbUrl = $ws['facebook_url'] ?? '';
        }

        // إذا اكتشفنا FB → افحصه
        if ($detectedFbUrl) {
            $fbData = scanFacebookPublic($detectedFbUrl, $cfg);
            $result['facebook'] = $fbData;
            // الـ social يُحدَّث بأغنى البيانات
            if (!empty($fbData['followers'])) {
                $result['social'] = $igData; // keep IG as primary
            }
        }

        // الإعلانات: FB أفضل مصدر؛ إذا لا FB → استخدم IG
        $adsSourceUrl = $detectedFbUrl ?: $url;

    } elseif ($type === 'tiktok') {
        // ── مسار تيك توك المباشر ──
        $ttData = scanTikTokPublic($url, $cfg);
        $result['tiktok'] = $ttData;
        $result['social']  = $ttData;

        // اكتشاف الموقع من تيك توك ومسحه فوراً
        $detectedUrl = $ttData['website'] ?? '';
        if ($detectedUrl) {
            $ws = _fetchAndScanWebsite($detectedUrl, $cfg);
            $result['website']      = $detectedUrl;
            $result['website_scan'] = $ws;
        }
        $adsSourceUrl = '';

    } elseif ($type === 'twitter') {
        // ── مسار تويتر المباشر ──
        $twData = scanTwitterPublic($url, $cfg);
        $result['twitter'] = $twData;
        $result['social']  = $twData;

        // اكتشاف الموقع من تويتر ومسحه فوراً
        $detectedUrl = $twData['website'] ?? '';
        if ($detectedUrl) {
            $ws = _fetchAndScanWebsite($detectedUrl, $cfg);
            $result['website']      = $detectedUrl;
            $result['website_scan'] = $ws;
        }
        $adsSourceUrl = '';

    } else {
        // ── مسار الموقع (website) ──
        $ws = _fetchAndScanWebsite($url, $cfg);
        $result['website']      = $url;
        $result['website_scan'] = $ws;

        // اكتشاف روابط السوشيال من 3 مصادر
        $detectedFbUrl = $ws['facebook_url'] ?? '';
        $detectedIgUrl = $ws['instagram_url'] ?? '';

        // المصدر الثاني: OG Tags
        if (!$detectedFbUrl && !empty($result['og']['page_id'])) {
            $detectedFbUrl = 'https://www.facebook.com/profile.php?id=' . $result['og']['page_id'];
        }

        // المصدر الثالث: Sitemap.xml
        if (!$detectedFbUrl && !$detectedIgUrl) {
            [$detectedFbUrl, $detectedIgUrl] = _extractSocialFromSitemap($url);
        }

        // افحص FB إذا اكتشفناه
        if ($detectedFbUrl) {
            $fbData = scanFacebookPublic($detectedFbUrl, $cfg);
            $result['social']   = $fbData;
            $result['facebook'] = $fbData;
            if (!$detectedIgUrl) {
                $detectedIgUrl = $fbData['instagram_url'] ?? '';
            }
        }

        // افحص IG إذا اكتشفناه
        if ($detectedIgUrl) {
            $igData = scanInstagramPublic($detectedIgUrl, $cfg);
            $result['instagram'] = $igData;
            if (!$result['social']) $result['social'] = $igData;
        }

        // اكتشاف وفحص TikTok من داخل الموقع
        $detectedTikTokUrl = $ws['tiktok_url'] ?? '';
        if ($detectedTikTokUrl && ($cfg['analysis']['enable_apify'] ?? false)) {
            $result['tiktok'] = scanTikTokPublic($detectedTikTokUrl, $cfg);
        }

        // اكتشاف وفحص Twitter من داخل الموقع
        $detectedTwitterUrl = $ws['twitter_url'] ?? '';
        if ($detectedTwitterUrl && ($cfg['analysis']['enable_apify'] ?? false)) {
            $result['twitter'] = scanTwitterPublic($detectedTwitterUrl, $cfg);
        }

        // الإعلانات: FB أفضل مصدر؛ IG إذا لا FB
        $adsSourceUrl = $detectedFbUrl ?: $detectedIgUrl ?: '';

        // PageSpeed للمواقع فقط
        if ($cfg['analysis']['enable_pagespeed'] ?? false) {
            $result['pagespeed'] = fetchPageSpeed($url, $cfg['apis']['google_pagespeed_key'] ?? '');
        }
    }

    // ── Step 3: Ads Library ─────────────────────────────────────
    if (!empty($adsSourceUrl) && ($cfg['analysis']['enable_ads_library'] ?? false)) {
        // محاولة 1: استخدام Page ID (الأدق)
        $fbPageId   = $result['facebook']['page_id'] ?? $result['og']['page_id'] ?? '';
        $fbPageName = $result['facebook']['page_name'] ?? '';
        $pageName   = $result['social']['page_name'] ?? '';

        $adsParam = $fbPageId   ? "ID:$fbPageId"
                  : ($fbPageName ? $fbPageName
                  : ($pageName   ? $pageName
                  : extractPageIdentifier($adsSourceUrl)));

        if ($adsParam) {
            $adsResult = null;
            if ($cfg['analysis']['enable_apify'] ?? false) {
                try {
                    require_once __DIR__ . '/apify-scraper.php';
                    if (function_exists('getValidApifyToken') && function_exists('scrapeAdsLibrary')) {
                        $token = getValidApifyToken($cfg);
                        if ($token) {
                            $adsResult = scrapeAdsLibrary($adsParam, $token, $cfg);
                            if (!($adsResult['success'] ?? false)) $adsResult = null;
                        }
                    }
                } catch (\Throwable $e) { $adsResult = null; }
            }
            // Fallback: Meta Graph API
            if (!$adsResult) {
                $cleanName = str_starts_with($adsParam, 'ID:') ? '' : $adsParam;
                if ($cleanName) {
                    $raw = fetchAdsLibrary($cleanName, $cfg['apis']['meta_ads_token'] ?? '');
                    if ($raw && !isset($raw['error'])) $adsResult = $raw;
                }
            }
            $result['ads_library'] = $adsResult;
        }
    }

    // ── Step 4: تحليل المنافسين (كمّي) ─────────────────────────
    if ($cfg['analysis']['enable_apify'] ?? false) {
        try {
            require_once __DIR__ . '/apify-scraper.php';
            if (function_exists('getValidApifyToken') && function_exists('scrapeCompetitorsViaGoogle')) {
                $compToken   = getValidApifyToken($cfg);
                $companyName = $result['social']['page_name']
                            ?? $result['facebook']['page_name']
                            ?? $result['instagram']['username']
                            ?? '';
                if ($compToken && $companyName) {
                    $competitorsResult = scrapeCompetitorsViaGoogle($companyName, '', $compToken);
                    if (!empty($competitorsResult['success']) && !empty($competitorsResult['competitors'])) {
                        // enrichCompetitorsData يستدعي runPageScan لكل منافس →
                        // ×6 actors لكل منافس. عطّل افتراضياً لتفادي استنفاد
                        // حصة Apify الشهرية، وفعّل عبر ENABLE_COMPETITOR_ENRICH=true.
                        $enrich = ($cfg['analysis']['enable_competitor_enrich'] ?? false)
                                  && function_exists('enrichCompetitorsData');
                        $competitors = $enrich
                            ? enrichCompetitorsData($competitorsResult['competitors'], $cfg)
                            : $competitorsResult['competitors'];
                        $result['competitors']      = $competitors;
                        $result['competitor_radar'] = $competitors;
                    } else {
                        if (function_exists('logError')) {
                            logError('Page-scan competitor scrape failed', [
                                'company' => $companyName,
                                'error'   => $competitorsResult['error'] ?? 'Unknown',
                            ]);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            if (function_exists('logError')) {
                logError('Page-scan competitor scrape exception', ['error' => $e->getMessage()]);
            }
        }
    }

    // ── Step 5: حساب درجة الفحص الجزئية ───────────────────────
    $result['scan_score'] = computeScanScore($result);

    return $result;
}

// ── مساعد: فحص الموقع وإرجاع نتيجة منظمة ────────────────────
function _fetchAndScanWebsite(string $url, array $cfg): array {
    return scanWebsiteHTML($url, $cfg) ?: [];
}

// ── مساعد: استخراج روابط سوشيال من Sitemap.xml ───────────────
function _extractSocialFromSitemap(string $siteUrl): array {
    $fbUrl = $igUrl = '';
    try {
        $sitemapUrl = rtrim($siteUrl, '/') . '/sitemap.xml';
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $sitemapUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; Googlebot/2.1)',
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $html = curl_exec($ch);
        curl_close($ch);
        if ($html) {
            preg_match('/https?:\/\/(?:www\.)?facebook\.com\/[\w.\-]+/i', $html, $mFb);
            preg_match('/https?:\/\/(?:www\.)?instagram\.com\/[\w.\-]+/i', $html, $mIg);
            $fbUrl = $mFb[0] ?? '';
            $igUrl = $mIg[0] ?? '';
        }
    } catch (\Throwable $e) {}
    return [$fbUrl, $igUrl];
}

// ============================================================
// OG Tags Scanner
// ============================================================
function scanOGTags(string $url): array {
    $html = fetchHtml($url, 12);
    if (!$html) return ['error' => 'فشل جلب الصفحة'];

    $og = [];

    // استخراج OG
    preg_match_all('/<meta[^>]+property=["\']og:([^"\']+)["\'][^>]+content=["\']([^"\']*)["\'][^>]*>/i', $html, $m);
    for ($i = 0; $i < count($m[1]); $i++) {
        $og[$m[1][$i]] = html_entity_decode($m[2][$i], ENT_QUOTES, 'UTF-8');
    }
    // الترتيب المعكوس أحياناً
    preg_match_all('/<meta[^>]+content=["\']([^"\']*)["\'][^>]+property=["\']og:([^"\']+)["\'][^>]*>/i', $html, $m2);
    for ($i = 0; $i < count($m2[2]); $i++) {
        if (!isset($og[$m2[2][$i]])) {
            $og[$m2[2][$i]] = html_entity_decode($m2[1][$i], ENT_QUOTES, 'UTF-8');
        }
    }

    // استخراج FB-specific
    preg_match('/"pageID":"(\d+)"/', $html, $mPageId);
    preg_match('/"followers_count":(\d+)/', $html, $mFollowers);
    preg_match('/"fan_count":(\d+)/', $html, $mFans);
    preg_match('/"rating_count":(\d+)/', $html, $mRatings);
    preg_match('/"overall_star_rating":([\d.]+)/', $html, $mRating);
    preg_match('/"is_verified":(true|false)/', $html, $mVerified);
    preg_match('/"page_created_time":"([^"]+)"/', $html, $mCreated);
    preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $mTitle);

    return [
        'title'            => $og['title'] ?? trim(strip_tags($mTitle[1] ?? '')),
        'description'      => $og['description'] ?? '',
        'image'            => $og['image'] ?? '',
        'url'              => $og['url'] ?? $url,
        'type'             => $og['type'] ?? '',
        'site_name'        => $og['site_name'] ?? '',
        'page_id'          => $mPageId[1] ?? null,
        'followers'        => !empty($mFollowers[1]) ? (int)$mFollowers[1] : null,
        'fans'             => !empty($mFans[1]) ? (int)$mFans[1] : null,
        'ratings_count'    => !empty($mRatings[1]) ? (int)$mRatings[1] : null,
        'star_rating'      => !empty($mRating[1]) ? (float)$mRating[1] : null,
        'is_verified'      => isset($mVerified[1]) ? $mVerified[1] === 'true' : null,
        'created_time'     => $mCreated[1] ?? null,
        'has_og_tags'      => !empty($og['title']),
    ];
}

// ============================================================
// Google PageSpeed Insights
// ============================================================
function fetchPageSpeed(string $url, string $apiKey): array {
    if (!$apiKey || str_contains($apiKey, 'YOUR')) {
        // وضع تجريبي بدون key (محدود)
        $apiUrl = "https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=" . urlencode($url) . "&strategy=mobile";
    } else {
        $apiUrl = "https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=" . urlencode($url)
                . "&strategy=mobile&key=" . urlencode($apiKey)
                . "&category=performance&category=seo&category=best-practices&category=accessibility";
    }

    $response = fetchJson($apiUrl, 30);
    if (!$response || isset($response['error'])) {
        return ['error' => $response['error']['message'] ?? 'فشل استدعاء PageSpeed API'];
    }

    $cats = $response['lighthouseResult']['categories'] ?? [];
    $audits = $response['lighthouseResult']['audits'] ?? [];

    return [
        'performance'        => isset($cats['performance']['score'])    ? (int)round($cats['performance']['score'] * 100) : null,
        'seo'                => isset($cats['seo']['score'])            ? (int)round($cats['seo']['score'] * 100) : null,
        'best_practices'     => isset($cats['best-practices']['score']) ? (int)round($cats['best-practices']['score'] * 100) : null,
        'accessibility'      => isset($cats['accessibility']['score'])  ? (int)round($cats['accessibility']['score'] * 100) : null,
        'fcp_ms'             => (int)($audits['first-contentful-paint']['numericValue'] ?? 0),
        'lcp_ms'             => (int)($audits['largest-contentful-paint']['numericValue'] ?? 0),
        'cls'                => round((float)($audits['cumulative-layout-shift']['numericValue'] ?? 0), 3),
        'tbt_ms'             => (int)($audits['total-blocking-time']['numericValue'] ?? 0),
        'is_mobile_friendly' => true, // إذا وصل الجلب نجح
        'rating'             => scoreToRating(isset($cats['performance']['score']) ? (int)round($cats['performance']['score'] * 100) : 0),
    ];
}

// ============================================================
// TikTok Public Scanner
// ============================================================
function scanTikTokPublic(string $url, array $cfg = []): array {
    if ($cfg['analysis']['enable_apify'] ?? false) {
        try {
            require_once __DIR__ . '/apify-scraper.php';
            $token = getValidApifyToken($cfg);
            if ($token) {
                return scrapeTikTok($url, $token, $cfg);
            }
        } catch (\Throwable $e) {}
    }
    return ['success' => false, 'platform' => 'tiktok', 'url' => $url];
}

// ============================================================
// Twitter Public Scanner
// ============================================================
function scanTwitterPublic(string $url, array $cfg = []): array {
    if ($cfg['analysis']['enable_apify'] ?? false) {
        try {
            require_once __DIR__ . '/apify-scraper.php';
            $token = getValidApifyToken($cfg);
            if ($token) {
                return scrapeTwitter($url, $token, $cfg);
            }
        } catch (\Throwable $e) {}
    }
    return ['success' => false, 'platform' => 'twitter', 'url' => $url];
}

// ============================================================
// Facebook Scanner (يدمج Apify + Graph API + Public Scraping)
// ============================================================
function scanFacebookPublic(string $url, array $cfg = []): array {
    $data = [
        'platform'        => 'facebook',
        'page_url'        => $url,
        'accessible'      => false,
        'followers'       => null,
        'likes'           => null,
        'category'        => null,
        'is_verified'     => false,
        'has_contact'     => false,
        'has_website'     => false,
        'has_phone'       => false,
        'has_email'       => false,
        'has_whatsapp'    => false,
        'has_cta_button'  => false,
        'has_shop'        => false,
        'website'         => null,
        'website_url'     => null,
        'phone'           => null,
        'rating'          => null,
        'response_time'   => null,
        'posts_count'     => null,
        'avg_engagement'  => null,
        'creation_date'   => null,
        'cover_photo'     => null,
        'profile_photo'   => null,
    ];

    // ── 1) Apify Scraper (الأقوى والأدق — يجلب Posts, Engagement, Ads) ──
    if ($cfg['analysis']['enable_apify'] ?? false) {
        try {
            require_once __DIR__ . '/apify-scraper.php';
            if (function_exists('getValidApifyToken') && function_exists('scrapeFacebook')) {
                $token = getValidApifyToken($cfg);
                if ($token) {
                    $apifyResult = scrapeFacebook($url, $token, $cfg);
                    if ($apifyResult['success'] ?? false) {
                        $apifyResult['accessible'] = true;

                        // محاولة جلب النواقص عبر Public Scraping السريع
                        if (!($apifyResult['has_whatsapp'] ?? false) || empty($apifyResult['whatsapp']) || empty($apifyResult['posts_count'])) {
                            $mobileUrl = str_replace(['www.facebook.com', 'facebook.com'], 'm.facebook.com', $url);
                            $html = fetchHtml($mobileUrl, 8); // مهلة قصيرة
                            if ($html) {
                                // فحص الواتساب في كود الصفحة
                                if (!($apifyResult['has_whatsapp'] ?? false)) {
                                    $waRegex = '/(?:wa\.me|api\.whatsapp\.com|whatsapp:\/\/send|whatsapp\.com\/send|واتساب|واتس\s*اب|whatsapp)/i';
                                    if (preg_match($waRegex, $html)) {
                                        $apifyResult['has_whatsapp'] = true;
                                    }
                                }
                                // فحص رقم الهاتف في كود الصفحة إذا كان مفقوداً
                                if (empty($apifyResult['phone']) && preg_match('/\+?\d[\d\s\-]{8,}/u', $html, $mPh)) {
                                    $apifyResult['phone'] = $mPh[0];
                                    $apifyResult['has_phone'] = true;
                                }
                            }
                        }

                        // دمج حقول الـ fallback الغائبة واستنتاج البوليانات
                        $apifyResult['has_whatsapp'] = $apifyResult['has_whatsapp'] ?? !empty($apifyResult['whatsapp']);
                        $apifyResult['has_phone']    = $apifyResult['has_phone']    ?? !empty($apifyResult['phone']);
                        $apifyResult['has_email']    = $apifyResult['has_email']    ?? !empty($apifyResult['email']);
                        $apifyResult['has_website']  = $apifyResult['has_website']  ?? !empty($apifyResult['website']);
                        $apifyResult['has_contact']  = $apifyResult['has_contact']  ?? ($apifyResult['has_phone'] || $apifyResult['has_email'] || $apifyResult['has_whatsapp']);
                        $apifyResult['has_shop']     = $apifyResult['has_shop'] ?? false;
                        $apifyResult['website']      = $apifyResult['website'] ?? '';
                        $apifyResult['website_url']  = $apifyResult['website_url'] ?? $apifyResult['website'];

                        // دمج نتائج Apify مع البيانات الأساسية (نستخدم array_filter لتجنب مسح القيم ببيانات فارغة)
                        $data = array_merge($data, array_filter($apifyResult, fn($v) => $v !== null && $v !== ''));
                    }
                }
            }
        } catch (\Throwable $e) {}
    }

    // ── 2) Facebook Graph API ────────────────────────────────────
    $fbToken = $cfg['apis']['facebook_access_token'] ?? '';
    if ($fbToken) {
        preg_match('/facebook\.com\/([^\/?#]+)/i', $url, $m);
        $pageId = $m[1] ?? '';
        if ($pageId && !in_array($pageId, ['pages', 'groups', 'events', 'watch', 'marketplace'])) {
            $fields   = 'id,name,fan_count,followers_count,category,verification_status,website,phone,emails,about,rating_count,overall_star_rating,cover,picture';
            $graphUrl = "https://graph.facebook.com/v19.0/{$pageId}?fields={$fields}&access_token={$fbToken}";
            $graphData = fetchJson($graphUrl, 12);

            if ($graphData && !isset($graphData['error'])) {
                $data['accessible']    = true;
                $data['source']        = 'graph_api';
                $data['page_id']       = $graphData['id']                  ?? null;
                $data['page_name']     = $graphData['name']                ?? '';
                $data['followers']     = $graphData['followers_count']     ?? $graphData['fan_count'] ?? null;
                $data['likes']         = $graphData['fan_count']           ?? null;
                $data['category']      = $graphData['category']            ?? '';
                $data['is_verified']   = ($graphData['verification_status'] ?? '') === 'blue_verified';
                $data['website_url']   = $graphData['website']             ?? '';
                $data['website']       = $graphData['website']             ?? '';
                $data['phone']         = $graphData['phone']               ?? '';
                $data['rating']        = $graphData['overall_star_rating'] ?? null;
                $data['ratings_count'] = $graphData['rating_count']        ?? null;
                $data['has_phone']     = !empty($graphData['phone']);
                $data['has_email']     = !empty($graphData['emails']);
                $data['has_website']   = !empty($graphData['website']);
                $data['has_contact']   = $data['has_phone'] || $data['has_email'];
                $data['cover_photo']   = $graphData['cover']['source']        ?? '';
                $data['profile_photo'] = $graphData['picture']['data']['url'] ?? '';
                return $data;
            }
        }
    }

    // ── 3) Public Scraping (no API) ─────────────────────────────────
    $mobileUrl = str_replace(['www.facebook.com', 'facebook.com'], 'm.facebook.com', $url);
    $html = fetchHtml($mobileUrl, 15);
    if (!$html) return $data;

    $data['accessible'] = true;
    $data['source']     = 'public_scrape';

    // دعم K/M: 10K, 1.5M, 500,000, 500.2K, عربي وإنجليزي
    if (preg_match('/([\d.]+\s*[KkMm]?)[\s\x{00A0}]*(متابع|‎متابع|followers|Followers)/ui', $html, $mFol)) {
        $data['followers'] = function_exists('parseFollowerCount')
            ? (parseFollowerCount($mFol[1]) ?? (int)str_replace(',', '', $mFol[1]))
            : (int)str_replace(',', '', $mFol[1]);
    }
    if (preg_match('/([\d.]+\s*[KkMm]?)[\s\x{00A0}]*(إعجاب|‎إعجاب|likes|Likes)/ui', $html, $mLikes)) {
        $data['likes'] = function_exists('parseFollowerCount')
            ? (parseFollowerCount($mLikes[1]) ?? (int)str_replace(',', '', $mLikes[1]))
            : (int)str_replace(',', '', $mLikes[1]);
    }

    // استخراج رابط الموقع من صفحة الفيسبوك
    if (preg_match('/https?:\/\/(?!(?:www\.)?(?:facebook|instagram|m\.facebook|twitter|wa\.me))[^\s"\'<>]+\.[a-z]{2,6}(?:\/[^\s"\'<>]*)?/i', $html, $mWS)) {
        $data['website']     = $mWS[0];
        $data['website_url'] = $mWS[0];
        $data['has_website'] = true;
    } else {
        $data['has_website'] = (bool)preg_match('/website|موقع/i', $html);
    }

    $data['is_verified']   = (bool)preg_match('/verified_badge|isVerified":true/i', $html);
    $data['has_phone']     = (bool)preg_match('/\+?\d[\d\s\-]{8,}/u', $html);
    $data['has_email']     = (bool)preg_match('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', $html);
    $data['has_whatsapp']  = (bool)preg_match('/wa\.me|whatsapp/i', $html);
    $data['has_contact']   = $data['has_phone'] || $data['has_email'] || $data['has_whatsapp'];
    $data['has_cta_button']= (bool)preg_match('/احجز|اشترك|تواصل|تسوق|متجر|book|shop now|send message|contact/i', $html);
    $data['has_shop']      = (bool)preg_match('/Shop|متجر|store/i', $html);

    preg_match('/(\d[\.,]\d)\s*(?:نجم|star|rating)/i', $html, $mRat);
    if ($mRat) $data['rating'] = (float)str_replace(',', '.', $mRat[1]);

    if (preg_match('/(?:يرد عادةً|responds|typically responds).{0,60}/isu', $html, $mResp)) {
        $data['response_time'] = trim(strip_tags($mResp[0]));
    }

    return $data;
}



// ============================================================
// Instagram Public Scanner
// ------------------------------------------------------------
// يحاول بالترتيب:
//   1) Apify scraper (apify/instagram-scraper) — أعمق وأشمل
//   2) Public web_profile_info API (بدون توكن)
//   3) HTML regex
//
// (1) إن نجح → يُرجع النموذج الكامل من scrapeInstagram (V3) ويتضمن:
//     hashtags_analysis, mentions_analysis, content_distribution,
//     posting_heatmap, bio_optimization, account_health,
//     comments_sentiment, vision_analysis, stories_data, related_profiles…
// (2) و(3) → يرجعان نموذجاً مبسّطاً موحداً مع نفس مفاتيح Apify
//          لتسهيل عرض الواجهة بدون شروط معقدة.
// ============================================================
function scanInstagramPublic(string $url, array $cfg = []): array {
    // ── 1) محاولة Apify Scraper (الأقوى) ─────────────────────
    // ⚠️ افتراضياً enable_apify = true في config.example.php لكن نقرأ true كذلك
    //    هنا في حال لم يضبط المستخدم config صراحة.
    if ($cfg['analysis']['enable_apify'] ?? true) {
        try {
            require_once __DIR__ . '/apify-scraper.php';
            if (function_exists('getValidApifyToken') && function_exists('scrapeInstagram')) {
                $token = getValidApifyToken($cfg);
                if ($token) {
                    $apifyResult = scrapeInstagram($url, $token, $cfg);
                    if ($apifyResult['success'] ?? false) {
                        $apifyResult['accessible'] = true;
                        return $apifyResult;
                    }
                }
            }
        } catch (\Throwable $e) {
            if (function_exists('logError')) {
                logError('IG Apify path failed, falling back to public', ['err' => $e->getMessage()]);
            }
        }
    }

    // استخراج username
    preg_match('/instagram\.com\/([^\/\?]+)/i', $url, $mUser);
    $username = $mUser[1] ?? '';

    // النموذج الموحد — كل المفاتيح الأساسية حتى لا تفشل الواجهة
    $data = [
        'success'              => false,
        'source'               => 'public',
        'platform'             => 'instagram',
        'username'             => $username,
        'profile_url'          => $username ? "https://www.instagram.com/{$username}/" : '',
        'full_name'            => '',
        'bio'                  => '',
        'bio_length'           => 0,
        'website'              => '',
        'has_link'              => false,
        'followers'            => null,
        'following'            => null,
        'posts_count'          => null,
        'highlights'           => 0,
        'highlight_reel_count' => 0,
        'is_verified'          => false,
        'is_business'          => false,
        'business_category'    => '',
        'private'              => false,
        'has_reels'            => false,
        'profile_pic'          => '',
        'engagement_rate'      => 0,
        'avg_likes'            => 0,
        'avg_comments'         => 0,
        'avg_saves'            => 0,
        'reels_count'          => 0,
        'posts_per_week'       => 0,
        'last_post_days'       => null,
        'top_5_posts'          => [],
        'latest_posts'         => [],
        'related_profiles'     => [],
        'hashtags_analysis'    => null,
        'mentions_analysis'    => null,
        'content_distribution' => null,
        'posting_heatmap'      => null,
        'language_mix'         => null,
        'locations'            => null,
        'sponsored_ratio'      => null,
        'reels_performance'    => null,
        'bio_optimization'     => null,
        'account_health'       => null,
        'comments_sentiment'   => null,
        'vision_analysis'      => null,
        'stories_data'         => null,
        'accessible'           => false,
    ];

    if (!$username) return $data;

    // محاولة جلب بيانات JSON من IG
    $apiUrl = "https://www.instagram.com/api/v1/users/web_profile_info/?username={$username}";
    $headers = [
        'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1',
        'Accept: application/json',
        'X-IG-App-ID: 936619743392459',
        'Referer: https://www.instagram.com/',
    ];
    $json = fetchJson($apiUrl, 12, $headers);

    if ($json && isset($json['data']['user'])) {
        $u = $json['data']['user'];
        $data['success']     = true;
        $data['source']      = 'web_profile_info';
        $data['accessible']  = true;
        $data['id']          = $u['id'] ?? null;
        $data['full_name']   = $u['full_name'] ?? '';
        $data['followers']   = $u['edge_followed_by']['count'] ?? null;
        $data['following']   = $u['edge_follow']['count'] ?? null;
        $data['posts_count'] = $u['edge_owner_to_timeline_media']['count'] ?? null;
        $data['bio']         = $u['biography'] ?? '';
        $data['bio_length']  = mb_strlen($data['bio']);
        $data['has_link']    = !empty($u['external_url']);
        $data['website']     = $u['external_url'] ?? '';
        $data['is_verified'] = $u['is_verified'] ?? false;
        $data['is_business'] = $u['is_business_account'] ?? false;
        $data['business_category'] = $u['category_name'] ?? $u['business_category_name'] ?? '';
        $data['private']     = $u['is_private'] ?? false;
        $data['has_reels']   = !empty($u['is_eligible_for_reels_remixing']);
        $data['profile_pic'] = $u['profile_pic_url'] ?? '';
        $data['highlight_reel_count'] = $u['highlight_reel_count'] ?? 0;
        $data['highlights']  = $data['highlight_reel_count'];

        // حلل البايو حتى من المسار العام (لا يحتاج Apify)
        if (function_exists('analyzeBioOptimization')) {
            $data['bio_optimization'] = analyzeBioOptimization(
                $data['bio'], $data['website'], (bool)$data['is_business'], (string)$data['business_category']
            );
        } else {
            require_once __DIR__ . '/instagram-deep.php';
            $data['bio_optimization'] = analyzeBioOptimization(
                $data['bio'], $data['website'], (bool)$data['is_business'], (string)$data['business_category']
            );
        }
        // Account Health (مبسّط — بدون متوسطات تفاعل لأن لا توجد منشورات)
        require_once __DIR__ . '/instagram-deep.php';
        $data['account_health'] = calcAccountHealthScore([
            'followers'            => (int)$data['followers'],
            'following'            => (int)$data['following'],
            'posts_count'          => (int)$data['posts_count'],
            'engagement_rate'      => 0,
            'is_verified'          => $data['is_verified'],
            'is_business'          => $data['is_business'],
            'private'              => $data['private'],
            'has_reels'            => $data['has_reels'],
            'website'              => $data['website'],
            'bio_length'           => $data['bio_length'],
            'posts_per_week'       => 0,
            'last_post_days'       => null,
            'highlight_reel_count' => $data['highlight_reel_count'],
        ]);
    } else {
        // fallback: scraping HTML
        $html = fetchHtml("https://www.instagram.com/{$username}/", 12);
        if ($html) {
            $data['success']    = true;
            $data['source']     = 'html_regex';
            $data['accessible'] = true;
            preg_match('/"edge_followed_by":\{"count":(\d+)\}/', $html, $mF);
            if ($mF) $data['followers'] = (int)$mF[1];
            preg_match('/"biography":"([^"]+)"/i', $html, $mBio);
            if ($mBio) {
                $data['bio'] = $mBio[1];
                $data['bio_length'] = mb_strlen($data['bio']);
            }
            $data['is_verified'] = (bool)preg_match('/"is_verified":true/i', $html);
        }
    }

    return $data;
}

// ============================================================
// Meta Ads Library API
// ============================================================
function fetchAdsLibrary(string $pageNameOrId, string $token): array {
    if (!$token || str_contains($token, 'YOUR')) {
        return ['error' => 'Meta token غير مضبوط', 'active_ads' => null];
    }

    $endpoint = "https://graph.facebook.com/v19.0/ads_archive"
        . "?search_terms=" . urlencode($pageNameOrId)
        . "&ad_reached_countries=['SA','AE','KW','QA','BH','OM','EG','YE']"
        . "&ad_active_status=ACTIVE"
        . "&limit=50"
        . "&fields=id,ad_creation_time,ad_creative_body,page_name,spend"
        . "&access_token=" . urlencode($token);

    $data = fetchJson($endpoint, 15);
    if (!$data || isset($data['error'])) {
        return ['error' => $data['error']['message'] ?? 'فشل Ads Library API', 'active_ads' => null];
    }

    $ads = $data['data'] ?? [];
    return [
        'active_ads'       => count($ads),
        'is_advertising'   => count($ads) > 0,
        'sample_ads'       => array_slice($ads, 0, 5),
        'total_found'      => $data['paging']['cursors'] !== null,
    ];
}

// ============================================================
// Website HTML Scanner (موقع الهبوط)
// ============================================================
function scanWebsiteHTML(string $url, array $cfg = []): array {
    $html = null;
    $httpCode = 200;
    $finalUrl = $url;
    $loadTime = 3.5;

    // ── 1) السحب السريع (cURL) المعزول عن الجافاسكربت
    if (!$html) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            // تحديث User Agent ليكون أكثر واقعية (Chrome 123)
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
                'Accept-Language: ar,en-US;en;q=0.9',
                'Cache-Control: max-age=0',
                'Sec-Ch-Ua: "Google Chrome";v="123", "Not:A-Brand";v="8", "Chromium";v="123"',
                'Sec-Ch-Ua-Mobile: ?0',
                'Sec-Ch-Ua-Platform: "Windows"',
                'Sec-Fetch-Dest: document',
                'Sec-Fetch-Mode: navigate',
                'Sec-Fetch-Site: none',
                'Sec-Fetch-User: ?1',
                'Upgrade-Insecure-Requests: 1'
            ],
            CURLOPT_ENCODING       => '',
        ]);
        $html      = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl  = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $loadTime  = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        curl_close($ch);
    }

    // ── 2) محاولة Apify (Puppeteer) كخطة بديلة إذا تم حجب cURL أو كان المحتوى فقيراً
    $isBlocked = ($httpCode === 403 || $httpCode === 401 || $httpCode === 0);
    $isPoor    = ($html && strlen($html) < 1500 && stripos($html, 'javascript') !== false); // احتمال صفحة انتظار JS

    if (($isBlocked || $isPoor || !$html || $httpCode >= 400) && ($cfg['analysis']['enable_apify'] ?? false)) {
        require_once __DIR__ . '/apify-scraper.php';
        if (function_exists('getValidApifyToken') && function_exists('scrapeWebsiteApify')) {
            $token = getValidApifyToken($cfg);
            if ($token) {
                $html = scrapeWebsiteApify($url, $token, $cfg);
                $loadTime = 5.0; // افتراضي لأن Puppeteer يستغرق وقتاً
                $httpCode = 200;
            }
        }
    }

    if (!$html) return ['error' => 'فشل جلب الموقع'];

    preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $mTitle);
    preg_match('/name=["\'`]description["\'`][^>]+content=["\'`]([^"\'`]+)/i', $html, $mDesc);
    preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $mH1);
    preg_match_all('/<h2[^>]*>(.*?)<\/h2>/is', $html, $mH2);

    // ── استخراج نص الخدمات / المنتجات ──────────────────────
    $services = [];
    preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $html, $liMatches);
    foreach (($liMatches[1] ?? []) as $li) {
        $text = trim(strip_tags($li));
        if (mb_strlen($text) > 5 && mb_strlen($text) < 100 && !preg_match('/^\d+$/', $text)) {
            $services[] = $text;
        }
    }
    preg_match_all('/<h[23][^>]*>(.*?)<\/h[23]>/is', $html, $hMatches);
    $sections = array_map(fn($h) => trim(strip_tags($h)), $hMatches[1] ?? []);

    return [
        'final_url'       => $finalUrl,
        'http_code'       => $httpCode,
        'load_time_s'     => round($loadTime, 2),
        'speed_rating'    => $loadTime < 2 ? 'ممتاز' : ($loadTime < 4 ? 'جيد' : 'بطيء'),
        'has_ssl'         => str_starts_with($finalUrl, 'https://'),
        'title'           => trim(strip_tags($mTitle[1] ?? '')),
        'title_length'    => mb_strlen(trim(strip_tags($mTitle[1] ?? ''))),
        'description'     => trim($mDesc[1] ?? ''),
        'desc_length'     => mb_strlen(trim($mDesc[1] ?? '')),
        'h1'              => trim(strip_tags($mH1[1] ?? '')),
        'h2_count'        => count($mH2[1] ?? []),
        'has_og_tags'     => (bool)preg_match('/property=["\'`]og:/i', $html),
        'has_schema'      => (bool)preg_match('/application\/ld\+json/i', $html),
        'has_fb_pixel'    => (bool)preg_match('/fbq\s*\(|facebook\.com\/tr\?|connect\.facebook\.net/i', $html),
        'has_ga'          => (bool)preg_match('/googletagmanager|gtag\s*\(|google-analytics/i', $html),
        'has_tiktok'      => (bool)preg_match('/analytics\.tiktok\.com|ttq\s*\./i', $html),
        'has_snapchat'    => (bool)preg_match('/snaptr\s*\(|sc-static\.net/i', $html),
        'has_whatsapp'    => (bool)preg_match('/wa\.me\/|api\.whatsapp\.com|whatsapp\.com\/send|whatsapp\.com\/channel|web\.whatsapp|واتساب|whatsapp/i', $html),
        'has_live_chat'   => (bool)preg_match('/tawk\.to|crisp\.chat|intercom\.io|livechatinc/i', $html),
        'has_cta'         => (bool)preg_match('/احجز|اشترك|تواصل|اطلب|ابدأ|سجل|buy now|get started|contact now/i', $html),
        'has_contact_form'=> (bool)preg_match('/<form[^>]*>/i', $html),
        'has_phone'       => (bool)preg_match('/\+?\d{7,15}|tel:/i', $html),
        'facebook_url'    => (preg_match('/https?:\/\/(?:www\.)?(?:facebook|fb)\.com\/(?!tr\?|plugins|events|sharer|share|dialog|groups)[a-zA-Z0-9._-]+/i', $html, $mFB)) ? $mFB[0] : null,
        'instagram_url'   => (preg_match('/https?:\/\/(?:www\.)?instagram\.com\/(?!p\/|explore|reel|stories|ar)[a-zA-Z0-9._-]+/i', $html, $mIG)) ? $mIG[0] : null,
        'twitter_url'     => (preg_match('/https?:\/\/(?:www\.)?(?:twitter|x)\.com\/[a-zA-Z0-9._-]+/i', $html, $mTW)) ? $mTW[0] : null,
        'tiktok_url'      => (preg_match('/https?:\/\/(?:www\.)?tiktok\.com\/@[a-zA-Z0-9._-]+/i', $html, $mTK)) ? $mTK[0] : null,
        'word_count'      => str_word_count(strip_tags($html)),
        // ✅ خدمات وأقسام الموقع
        'services_list'   => array_slice(array_unique($services), 0, 15),
        'sections_titles' => array_slice(array_filter($sections), 0, 10),
    ];
}

// ============================================================
// حساب درجة الفحص (0-100)
// ============================================================
function computeScanScore(array $result): int {
    $score = 0;

    // OG Tags (15 نقطة)
    $og = $result['og'] ?? [];
    if (!empty($og['title']))       $score += 5;
    if (!empty($og['description'])) $score += 4;
    if (!empty($og['image']))       $score += 3;
    if ($og['is_verified'] ?? false) $score += 3;

    // Social (25 نقطة)
    $social = $result['social'] ?? [];
    if (!empty($social['followers'])) {
        $f = $social['followers'];
        $score += $f > 100000 ? 10 : ($f > 10000 ? 7 : ($f > 1000 ? 4 : 2));
    }
    if ($social['has_contact'] ?? false) $score += 5;
    if ($social['has_cta_button'] ?? false) $score += 5;
    if ($social['is_verified'] ?? false)  $score += 5;

    // PageSpeed (20 نقطة)
    $ps = $result['pagespeed'] ?? [];
    if (!empty($ps['performance'])) {
        $p = $ps['performance'];
        $score += $p >= 90 ? 10 : ($p >= 70 ? 7 : ($p >= 50 ? 4 : 2));
    }
    if (!empty($ps['seo'])) {
        $s = $ps['seo'];
        $score += $s >= 90 ? 10 : ($s >= 70 ? 7 : ($s >= 50 ? 4 : 2));
    }

    // Website scan (25 نقطة)
    $ws = $result['website_scan'] ?? $result['website'] ?? [];
    if ($ws['has_ssl'] ?? false)     $score += 3;
    if ($ws['has_fb_pixel'] ?? false) $score += 5;
    if ($ws['has_ga'] ?? false)      $score += 4;
    if ($ws['has_og_tags'] ?? false)  $score += 3;
    if ($ws['has_whatsapp'] ?? false) $score += 3;
    if ($ws['has_cta'] ?? false)      $score += 4;
    if ($ws['has_schema'] ?? false)   $score += 3;

    // Ads (15 نقطة)
    $ads = $result['ads_library'] ?? [];
    if ($ads['is_advertising'] ?? false) $score += 15;

    return min(100, $score);
}

// ============================================================
// Helpers
// ============================================================
function normalizeUrl(string $url): ?string {
    $url = trim($url);
    if (!preg_match('/^https?:\/\//i', $url)) $url = 'https://' . $url;
    return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
}

// تحويل أرقام بصيغة K/M إلى أرقام كاملة (10K → 10000, 1.5M → 1500000)
function parseFollowerCount(string $raw): ?int {
    $raw = trim($raw);
    if (preg_match('/^([\d.]+)\s*[Kkك]/u', $raw, $m)) {
        return (int)((float)$m[1] * 1000);
    }
    if (preg_match('/^([\d.]+)\s*[Mmم]/u', $raw, $m)) {
        return (int)((float)$m[1] * 1000000);
    }
    if (preg_match('/^[\d,]+$/', str_replace(' ', '', $raw))) {
        return (int)str_replace([',', ' '], '', $raw);
    }
    return null;
}

function detectUrlType(string $url): string {
    if (preg_match('/facebook\.com/i', $url)) return 'facebook';
    if (preg_match('/instagram\.com/i', $url)) return 'instagram';
    if (preg_match('/tiktok\.com/i', $url)) return 'tiktok';
    if (preg_match('/(?:twitter|x)\.com/i', $url)) return 'twitter';
    return 'website';
}

function extractPageIdentifier(string $url): string {
    preg_match('/(?:facebook|instagram)\.com\/([^\/\?&#]+)/i', $url, $m);
    return $m[1] ?? '';
}

function extractWebsiteFromFB(array $og): ?string {
    return null; // يُستخرج من social scan
}

function fetchHtml(string $url, int $timeout = 12): string|false {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 4,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0',
        CURLOPT_HTTPHEADER     => ['Accept: text/html,*/*', 'Accept-Language: ar,en;q=0.8'],
        CURLOPT_ENCODING       => '',
    ]);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($html !== false && $code < 400) ? $html : false;
}

function fetchJson(string $url, int $timeout = 15, array $headers = []): ?array {
    $ch = curl_init();
    $defaultHeaders = ['Accept: application/json', 'User-Agent: Mozilla/5.0'];
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => array_merge($defaultHeaders, $headers),
        CURLOPT_ENCODING       => '',
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    if (!$body) return null;
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}

function scoreToRating(int $score): string {
    if ($score >= 90) return 'ممتاز';
    if ($score >= 70) return 'جيد';
    if ($score >= 50) return 'متوسط';
    return 'ضعيف';
}
