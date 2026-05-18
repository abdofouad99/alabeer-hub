<?php
/**
 * Helper functions for Competitors v2
 */

declare(strict_types=1);

/**
 * توليد cache key من URL منافس
 */
function buildCompetitorCacheKey(string $url, string $platform): string {
    $domain = parse_url($url, PHP_URL_HOST) ?? $url;
    $domain = preg_replace('/^www\./', '', mb_strtolower($domain));
    return "comp_{$platform}_{$domain}";
}

/**
 * حساب مدى اكتمال البيانات (0-100%)
 *
 * @param array $comp المنافس بعد enrichment
 */
function calculateDataCompleteness(array $comp): int {
    $score = 0;

    // followers من أي منصة
    $hasFollowers = false;
    foreach (['facebook','instagram','tiktok','twitter'] as $p) {
        if (!empty($comp['platforms'][$p]['followers'])) {
            $hasFollowers = true;
            break;
        }
    }
    if ($hasFollowers) $score += 20;

    // engagement
    $hasEngagement = false;
    foreach (['facebook','instagram','tiktok','twitter'] as $p) {
        if (!empty($comp['platforms'][$p]['engagement_rate'])) {
            $hasEngagement = true;
            break;
        }
    }
    if ($hasEngagement) $score += 20;

    // posts/recent activity
    $hasActivity = false;
    foreach (['facebook','instagram','tiktok','twitter'] as $p) {
        if (!empty($comp['platforms'][$p]['posts_per_week'])) {
            $hasActivity = true;
            break;
        }
    }
    if ($hasActivity) $score += 15;

    // website + tech_stack
    if (!empty($comp['quick_analysis']['has_ssl'])) $score += 8;
    if (!empty($comp['quick_analysis']['tech_stack'])) $score += 7;

    // reviews/rating
    if (!empty($comp['rating'])) $score += 8;
    if (!empty($comp['reviews_summary']['total'])) $score += 7;

    // ads info
    if (isset($comp['platforms']['facebook']['ads_running'])) $score += 8;
    if (isset($comp['platforms']['facebook']['ads_count'])) $score += 7;

    return min(100, $score);
}

/**
 * استخراج username من URL منصة
 */
function extractUsernameFromUrl(string $url, string $platform): string {
    switch ($platform) {
        case 'facebook':
            if (preg_match('/facebook\.com\/([a-zA-Z0-9._\-]+)/i', $url, $m)) {
                return $m[1];
            }
            break;
        case 'instagram':
            if (preg_match('/instagram\.com\/([a-zA-Z0-9._]+)/i', $url, $m)) {
                return $m[1];
            }
            break;
        case 'tiktok':
            if (preg_match('/tiktok\.com\/@([a-zA-Z0-9._]+)/i', $url, $m)) {
                return $m[1];
            }
            break;
        case 'twitter':
            if (preg_match('/(?:twitter|x)\.com\/([a-zA-Z0-9_]+)/i', $url, $m)) {
                return $m[1];
            }
            break;
    }
    return '';
}

/**
 * بناء meta data لكل قيمة (track مصدرها)
 */
function buildMeta(array $sourceMap): array {
    $meta = ['fetched_at' => date('c')];
    foreach ($sourceMap as $field => $source) {
        $meta[$field . '_source'] = $source;
    }
    return $meta;
}
