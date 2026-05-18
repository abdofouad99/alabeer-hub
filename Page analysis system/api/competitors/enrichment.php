<?php
/**
 * Competitor Enrichment — STEP 4
 * يسحب بيانات كل منافس من المنصات المتاحة باستخدام:
 *   - scrapeFacebook (موجود في apify-scraper.php)
 *   - scrapeInstagram (موجود)
 *   - scrapeTikTok (موجود)
 *   - scrapeTwitter (موجود)
 *   - fetchCompetitorGoogleReviews (جديد)
 *
 * Tier system:
 *   Tier 1: Quick analysis only (cURL، 0 Apify)
 *   Tier 2: منصات السوشيال للعميل فقط
 *   Tier 3: كل المنصات + Reviews
 */

declare(strict_types=1);

require_once __DIR__ . '/../apify-scraper.php';
require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/cross-platform-discovery.php';
require_once __DIR__ . '/google-reviews.php';

/**
 * @param array $competitor المنافس بعد cross-platform discovery
 * @param array $clientProfile للمعرفة أي منصات يهتم بها العميل
 * @param array $cfg الإعدادات
 * @return array منافس مُحدَّث مع كامل البيانات
 */
function enrichCompetitor(
    array $competitor,
    array $clientProfile,
    array $cfg
): array {
    $tier = (int)($cfg['analysis']['competitor_enrich_tier'] ?? 3);
    $cache = new CompetitorCache($cfg);

    $competitor['platforms'] = $competitor['platforms'] ?? [];
    $competitor['_meta']     = $competitor['_meta']     ?? ['fetched_at' => date('c')];

    // ── Tier 1: دائماً (مجاني) ──
    // الـ quick_analysis تم في cross-platform-discovery
    // هنا نتأكد منه
    if (empty($competitor['quick_analysis'])) {
        $competitor = discoverCompetitorSocialLinks($competitor, $cfg);
    }

    if ($tier <= 1) {
        $competitor['_meta']['tier_used'] = 1;
        $competitor['_meta']['data_completeness'] = calculateDataCompleteness($competitor);
        return $competitor;
    }

    // ── تحديد المنصات المُستهدفة ──
    $targetPlatforms = _determineTargetPlatforms($competitor, $clientProfile, $tier);

    logInfo('Enriching competitor', [
        'name'      => $competitor['name'] ?? '?',
        'tier'      => $tier,
        'platforms' => $targetPlatforms,
    ]);

    // ── سحب من كل منصة ──
    $token = function_exists('getValidApifyToken') ? getValidApifyToken($cfg) : '';

    foreach ($targetPlatforms as $platform) {
        $url = $competitor['social'][$platform] ?? '';
        if (empty($url)) continue;

        $cacheKey = buildCompetitorCacheKey($url, $platform);
        $cached = $cache->get($cacheKey);

        if ($cached !== null) {
            $competitor['platforms'][$platform] = $cached;
            $competitor['_meta'][$platform . '_source'] = 'cache';
            continue;
        }

        try {
            $data = _scrapePlatformForCompetitor($platform, $url, $token, $cfg);
            if (!empty($data) && ($data['success'] ?? false)) {
                $simplified = _simplifyPlatformData($platform, $data);
                $competitor['platforms'][$platform] = $simplified;
                $competitor['_meta'][$platform . '_source'] = 'apify_' . $platform;

                $cache->set($cacheKey, $simplified);
            } else {
                $competitor['_warnings'][] = "فشل سحب {$platform}: " . ($data['error'] ?? 'unknown');
            }
        } catch (\Throwable $e) {
            logError('Competitor platform scrape exception', [
                'platform' => $platform,
                'url'      => $url,
                'error'    => $e->getMessage(),
            ]);
            $competitor['_warnings'][] = "خطأ في {$platform}: " . $e->getMessage();
        }
    }

    // ── Google Reviews (Tier 3 فقط) ──
    if ($tier >= 3
        && ($cfg['analysis']['competitor_include_reviews'] ?? true)
        && !empty($competitor['place_id'])
    ) {
        $reviewsCacheKey = 'comp_reviews_' . md5($competitor['place_id']);
        $cachedReviews = $cache->get($reviewsCacheKey);

        if ($cachedReviews !== null) {
            $competitor['reviews_summary'] = $cachedReviews['summary'] ?? null;
            $competitor['reviews_sample']  = array_slice($cachedReviews['reviews'] ?? [], 0, 5);
            $competitor['_meta']['reviews_source'] = 'cache';
        } else {
            $maxReviews = (int)($cfg['analysis']['competitor_max_reviews_per_comp'] ?? 20);
            $reviewsResult = fetchCompetitorGoogleReviews(
                $competitor['place_id'],
                '', // no URL needed
                $cfg,
                $maxReviews
            );

            if ($reviewsResult['success'] ?? false) {
                $competitor['reviews_summary'] = $reviewsResult['summary'];
                $competitor['reviews_sample']  = array_slice($reviewsResult['reviews'], 0, 5);
                $competitor['_meta']['reviews_source'] = 'google_maps';

                $cache->set($reviewsCacheKey, $reviewsResult);
            }
        }
    }

    // ── حساب ads info (مجاناً من بيانات Facebook) ──
    if (!empty($competitor['platforms']['facebook'])) {
        $fb = $competitor['platforms']['facebook'];
        $competitor['ads_info'] = [
            'is_running_ads' => (bool)($fb['ads_running'] ?? false),
            'ads_count'      => (int)($fb['ads_count']    ?? 0),
            '_source'        => 'facebook_pageAdLibrary',
        ];
    }

    // ── حساب data completeness ──
    $competitor['_meta']['tier_used'] = $tier;
    $competitor['_meta']['data_completeness'] = calculateDataCompleteness($competitor);

    if ($competitor['_meta']['data_completeness'] < 30) {
        $competitor['_warning'] = 'بيانات شحيحة - التحليل قد يكون محدود';
    }

    return $competitor;
}

/**
 * تحديد المنصات للسحب بناءً على tier + ما لدى العميل
 */
function _determineTargetPlatforms(array $competitor, array $clientProfile, int $tier): array {
    $available = [];
    foreach (['facebook','instagram','tiktok','twitter'] as $p) {
        if (!empty($competitor['social'][$p])) {
            $available[] = $p;
        }
    }

    if ($tier === 2) {
        // فقط منصات العميل
        $clientPlatforms = $clientProfile['social_platforms'] ?? [];
        return array_intersect($available, $clientPlatforms);
    }

    // Tier 3: كل المنصات المتوفرة
    return $available;
}

/**
 * router لاستدعاء scrape المناسب
 */
function _scrapePlatformForCompetitor(
    string $platform,
    string $url,
    string $token,
    array  $cfg
): array {
    if (empty($token)) {
        return ['success' => false, 'error' => 'No Apify token'];
    }

    switch ($platform) {
        case 'facebook':
            if (function_exists('scrapeFacebook')) {
                return scrapeFacebook($url, $token, $cfg);
            }
            break;
        case 'instagram':
            if (function_exists('scrapeInstagram')) {
                return scrapeInstagram($url, $token, $cfg);
            }
            break;
        case 'tiktok':
            if (function_exists('scrapeTikTok')) {
                return scrapeTikTok($url, $token, $cfg);
            }
            break;
        case 'twitter':
            if (function_exists('scrapeTwitter')) {
                return scrapeTwitter($url, $token, $cfg);
            }
            break;
    }

    return ['success' => false, 'error' => "Platform {$platform} not supported"];
}

/**
 * تبسيط بيانات المنصة (نريد فقط الأرقام المهمة، ليس كل شيء)
 *
 * هذا يقلل حجم البيانات المُرسلة للـ AI ويحمي من تسريب بيانات غير مهمة
 */
function _simplifyPlatformData(string $platform, array $raw): array {
    switch ($platform) {
        case 'facebook':
            return [
                'platform'        => 'facebook',
                'url'             => $raw['url']             ?? null,
                'page_name'       => $raw['page_name']       ?? null,
                'followers'       => $raw['followers']       ?? null,
                'likes'           => $raw['likes']           ?? null,
                'category'        => $raw['category']        ?? null,
                'is_verified'     => $raw['is_verified']     ?? false,
                'rating'          => $raw['rating']          ?? null,
                'ratings_count'   => $raw['ratings_count']   ?? null,
                'engagement_rate' => $raw['engagement_rate'] ?? null,
                'avg_likes'       => $raw['avg_likes']       ?? null,
                'avg_comments'    => $raw['avg_comments']    ?? null,
                'avg_shares'      => $raw['avg_shares']      ?? null,
                'posts_per_week'  => $raw['posts_per_week']  ?? null,
                'last_post_days'  => $raw['last_post_days']  ?? null,
                'posts_count'     => $raw['posts_count']     ?? null,
                'ads_running'     => $raw['ads_running']     ?? false,
                'ads_count'       => $raw['ads_count']       ?? 0,
                // top_5_posts للـ AI لتحليل أسلوب المنافس
                'top_5_posts'     => array_slice($raw['top_5_posts'] ?? [], 0, 5),
                'page_health'     => $raw['page_health']    ?? null,
            ];

        case 'instagram':
            return [
                'platform'             => 'instagram',
                'url'                  => $raw['url']                  ?? null,
                'username'             => $raw['username']             ?? null,
                'full_name'            => $raw['full_name']            ?? null,
                'followers'            => $raw['followers']            ?? null,
                'following'            => $raw['following']            ?? null,
                'is_verified'          => $raw['is_verified']          ?? false,
                'is_business'          => $raw['is_business_account'] ?? false,
                'category'             => $raw['business_category']    ?? null,
                'engagement_rate'      => $raw['engagement_rate']      ?? null,
                'posts_count'          => $raw['posts_count']          ?? null,
                'posts_per_week'       => $raw['posts_per_week']       ?? null,
                'last_post_days'       => $raw['last_post_days']       ?? null,
                'avg_likes'            => $raw['avg_likes']            ?? null,
                'avg_comments'         => $raw['avg_comments']         ?? null,
                'reels_count'          => $raw['reels_count']          ?? null,
                'top_5_posts'          => array_slice($raw['top_5_posts'] ?? [], 0, 5),
                'account_health'       => $raw['account_health']       ?? null,
                'bio_optimization'     => $raw['bio_optimization']     ?? null,
            ];

        case 'tiktok':
            return [
                'platform'        => 'tiktok',
                'url'             => $raw['url']             ?? null,
                'username'        => $raw['username']        ?? null,
                'followers'       => $raw['followers']       ?? null,
                'following'       => $raw['following']       ?? null,
                'is_verified'     => $raw['is_verified']     ?? false,
                'videos_count'    => $raw['videos_count']    ?? null,
                'total_likes'     => $raw['total_likes']     ?? null,
                'avg_views'       => $raw['avg_views']       ?? null,
                'avg_likes'       => $raw['avg_likes']       ?? null,
                'avg_comments'    => $raw['avg_comments']    ?? null,
                'engagement_rate' => $raw['engagement_rate'] ?? null,
                'posts_per_week'  => $raw['posts_per_week']  ?? null,
                'top_5_videos'    => array_slice($raw['top_5_videos'] ?? [], 0, 5),
            ];

        case 'twitter':
            return [
                'platform'        => 'twitter',
                'url'             => $raw['url']             ?? null,
                'username'        => $raw['username']        ?? null,
                'followers'       => $raw['followers']       ?? null,
                'following'       => $raw['following']       ?? null,
                'is_verified'     => $raw['is_verified']     ?? false,
                'tweets_count'    => $raw['tweets_count']    ?? null,
                'engagement_rate' => $raw['engagement_rate'] ?? null,
                'avg_likes'       => $raw['avg_likes']       ?? null,
                'avg_retweets'    => $raw['avg_retweets']    ?? null,
                'avg_replies'     => $raw['avg_replies']     ?? null,
                'posts_per_week'  => $raw['posts_per_week']  ?? null,
                'top_5_tweets'    => array_slice($raw['top_5_tweets'] ?? [], 0, 5),
            ];
    }

    return $raw;
}

/**
 * Wrapper: enrich كل المنافسين الـ 5 بالتوازي/التتابع
 */
function enrichAllCompetitors(
    array $competitors,
    array $clientProfile,
    array $cfg
): array {
    $results = [];
    foreach ($competitors as $idx => $comp) {
        try {
            $enriched = enrichCompetitor($comp, $clientProfile, $cfg);
            $enriched['_position'] = $idx + 1;
            $results[] = $enriched;
        } catch (\Throwable $e) {
            logError('Failed to enrich competitor', [
                'name'  => $comp['name'] ?? '?',
                'error' => $e->getMessage(),
            ]);
            // أبقِ المنافس مع warning
            $comp['_error'] = $e->getMessage();
            $comp['_position'] = $idx + 1;
            $results[] = $comp;
        }
    }
    return $results;
}
