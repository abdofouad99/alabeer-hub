# Graph Report - Page analysis system  (2026-04-30)

## Corpus Check
- 109 files · ~332,287 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 396 nodes · 815 edges · 19 communities detected
- Extraction: 92% EXTRACTED · 8% INFERRED · 0% AMBIGUOUS · INFERRED: 66 edges (avg confidence: 0.8)
- Token cost: 0 input · 0 output

## Community Hubs (Navigation)
- [[_COMMUNITY_Community 0|Community 0]]
- [[_COMMUNITY_Community 1|Community 1]]
- [[_COMMUNITY_Community 2|Community 2]]
- [[_COMMUNITY_Community 3|Community 3]]
- [[_COMMUNITY_Community 4|Community 4]]
- [[_COMMUNITY_Community 5|Community 5]]
- [[_COMMUNITY_Community 6|Community 6]]
- [[_COMMUNITY_Community 7|Community 7]]
- [[_COMMUNITY_Community 8|Community 8]]
- [[_COMMUNITY_Community 9|Community 9]]
- [[_COMMUNITY_Community 10|Community 10]]
- [[_COMMUNITY_Community 11|Community 11]]
- [[_COMMUNITY_Community 12|Community 12]]
- [[_COMMUNITY_Community 13|Community 13]]
- [[_COMMUNITY_Community 14|Community 14]]
- [[_COMMUNITY_Community 15|Community 15]]
- [[_COMMUNITY_Community 16|Community 16]]
- [[_COMMUNITY_Community 17|Community 17]]
- [[_COMMUNITY_Community 18|Community 18]]

## God Nodes (most connected - your core abstractions)
1. `runPageScan()` - 24 edges
2. `runAnalysis()` - 21 edges
3. `callAIProvider()` - 16 edges
4. `parseAIResponse()` - 15 edges
5. `scrapeInstagram()` - 15 edges
6. `runAnalysis()` - 14 edges
7. `scrapeTikTok()` - 14 edges
8. `scrapeFacebook()` - 13 edges
9. `scrapeInstagram()` - 12 edges
10. `scrapeInstagram()` - 12 edges

## Surprising Connections (you probably didn't know these)
- `loadAssessmentData()` --calls--> `getDB()`  [INFERRED]
  backup_temp\api\ai-analyze.php → backup_temp\api\db.php
- `runAnalysis()` --calls--> `runGeminiAnalysis()`  [INFERRED]
  backup_temp\api\analyze.php → backup_temp\api\ai-analyze.php
- `runAnalysis()` --calls--> `runGeminiAnalysis()`  [INFERRED]
  backup_temp\api\analyze_backup.php → backup_temp\api\ai-analyze.php
- `scanUrl()` --calls--> `runPageScan()`  [INFERRED]
  backup_temp\api\analyze.php → backup_temp\api\page-scan.php
- `runAnalysis()` --calls--> `logInfo()`  [INFERRED]
  backup_temp\api\analyze.php → backup_temp\api\init.php

## Communities

### Community 0 - "Community 0"
Cohesion: 0.08
Nodes (10): Cache, clear(), delete(), FileCache, get(), getCache(), has(), RedisCache (+2 more)

### Community 1 - "Community 1"
Cohesion: 0.19
Nodes (28): buildContentAnalysis(), buildPrompt(), callAIProvider(), callDeepSeek(), callDeepSeekR1Nvidia(), callGemini(), callGPTOSS(), callGroq() (+20 more)

### Community 2 - "Community 2"
Cohesion: 0.25
Nodes (26): analyzeDeepContent(), _apifyStartRun(), _apifyWaitAndFetch(), calcAvgComments(), calcAvgEngagement(), calcAvgLikes(), calcIGEngagement(), calcLastPostDays() (+18 more)

### Community 3 - "Community 3"
Cohesion: 0.22
Nodes (24): buildChips(), buildPreviewMeta(), captureStep(), detectUrlType(), extractDomain(), flashSaved(), handleBack(), handleNext() (+16 more)

### Community 4 - "Community 4"
Cohesion: 0.26
Nodes (22): getValidApifyToken(), enrichCompetitorsData(), computeScanScore(), detectUrlType(), extractPageIdentifier(), _extractSocialFromSitemap(), extractWebsiteFromFB(), fetchAdsLibrary() (+14 more)

### Community 5 - "Community 5"
Cohesion: 0.26
Nodes (21): analyzeDeepContent(), _apifyStartRun(), _apifyWaitAndFetch(), calcAvgComments(), calcAvgEngagement(), calcAvgLikes(), calcIGEngagement(), calcLastPostDays() (+13 more)

### Community 6 - "Community 6"
Cohesion: 0.15
Nodes (5): checkRateLimit(), getRateLimiter(), getRateLimitHeaders(), RateLimiter, RateLimitTest

### Community 7 - "Community 7"
Cohesion: 0.28
Nodes (20): analyzeDeepContent(), _apifyStartRun(), _apifyWaitAndFetch(), calcAvgComments(), calcAvgEngagement(), calcAvgLikes(), calcIGEngagement(), calcLastPostDays() (+12 more)

### Community 8 - "Community 8"
Cohesion: 0.21
Nodes (19): animateTo(), copyLink(), downloadPdf(), formatNum(), makeBar(), renderActionPlan(), renderAdsLibrary(), renderCompetitors() (+11 more)

### Community 9 - "Community 9"
Cohesion: 0.29
Nodes (14): adminTierColor(), checkAuth(), doLogout(), exportCSV(), filterList(), loadLead(), loadList(), loadStats() (+6 more)

### Community 10 - "Community 10"
Cohesion: 0.36
Nodes (12): applyScanBoosts(), buildActionWeek(), clamp(), genDetailedBreakdown(), genInsights(), genRecommendations(), runAnalysis(), scanUrl() (+4 more)

### Community 11 - "Community 11"
Cohesion: 0.38
Nodes (11): applyScanBoosts(), buildActionWeek(), clamp(), genInsights(), genRecommendations(), runAnalysis(), scanUrl(), scanWebsite() (+3 more)

### Community 12 - "Community 12"
Cohesion: 0.26
Nodes (2): getLogger(), Logger

### Community 13 - "Community 13"
Cohesion: 0.24
Nodes (6): requireAdmin(), getDB(), jsonError(), jsonOut(), runMigrations(), setCors()

### Community 14 - "Community 14"
Cohesion: 0.22
Nodes (1): LoggerTest

### Community 15 - "Community 15"
Cohesion: 0.54
Nodes (6): animateCounters(), animateRings(), generatePDF(), renderData(), sanitize(), sanitizeRelaxed()

### Community 16 - "Community 16"
Cohesion: 0.67
Nodes (2): getApifyToken(), getGeminiKey()

### Community 17 - "Community 17"
Cohesion: 0.67
Nodes (1): runActor()

### Community 18 - "Community 18"
Cohesion: 0.67
Nodes (1): addLine()

## Knowledge Gaps
- **Thin community `Community 12`** (12 nodes): `getLogger()`, `Logger`, `.__construct()`, `.debug()`, `.error()`, `.getRecentLogs()`, `.info()`, `.log()`, `.rotateLogFile()`, `.warning()`, `logger.php`, `logger.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 14`** (9 nodes): `LoggerTest.php`, `LoggerTest`, `.setUp()`, `.tearDown()`, `.testLoggerContextFormatting()`, `.testLoggerFiltersByLevel()`, `.testLoggerLogsMessages()`, `.testLoggerRotation()`, `LoggerTest.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 16`** (4 nodes): `getApifyToken()`, `getGeminiKey()`, `config.php`, `config.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 17`** (3 nodes): `debug-apify.php`, `runActor()`, `debug-apify.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 18`** (3 nodes): `addLine()`, `test-analysis.php`, `test-analysis.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `runAnalysis()` connect `Community 10` to `Community 1`, `Community 2`, `Community 4`, `Community 7`, `Community 13`?**
  _High betweenness centrality (0.074) - this node is a cross-community bridge._
- **Why does `checkApiRateLimit()` connect `Community 1` to `Community 6`?**
  _High betweenness centrality (0.043) - this node is a cross-community bridge._
- **Why does `checkRateLimit()` connect `Community 6` to `Community 1`?**
  _High betweenness centrality (0.041) - this node is a cross-community bridge._
- **Are the 8 inferred relationships involving `runPageScan()` (e.g. with `scanUrl()` and `runAnalysis()`) actually correct?**
  _`runPageScan()` has 8 INFERRED edges - model-reasoned connections that need verification._
- **Are the 15 inferred relationships involving `runAnalysis()` (e.g. with `logInfo()` and `getDB()`) actually correct?**
  _`runAnalysis()` has 15 INFERRED edges - model-reasoned connections that need verification._
- **Are the 3 inferred relationships involving `scrapeInstagram()` (e.g. with `runAnalysis()` and `runAnalysis()`) actually correct?**
  _`scrapeInstagram()` has 3 INFERRED edges - model-reasoned connections that need verification._
- **Should `Community 0` be split into smaller, more focused modules?**
  _Cohesion score 0.08 - nodes in this community are weakly interconnected._