<?php
/**
 * ═══════════════════════════════════════════════════════════════
 * Al-Abeer Hub — Multi-Agent Analysis System
 * 5 وكلاء متخصصين | 12 مصدر بيانات | 18 صفحة مخرجات
 * ═══════════════════════════════════════════════════════════════
 *
 * Architecture:
 *   Raw Data → Agent 1 (Diagnostic) ──┐
 *              Agent 2 (Content)   ────┼──→ Agent 4 (Strategist) → Agent 5 (Action Planner)
 *              Agent 3 (Market)   ─────┘
 *
 * Models: Agents 1-3 = gemini-2.5-flash | Agents 4-5 = gemini-2.5-pro
 *
 * Usage:
 *   $result = runMultiAgentAnalysis($rawData, ['apiKey' => 'YOUR_KEY']);
 */

// ─────────────────────────────────────────────────────────────
// SECTION 1: SYSTEM PROMPTS
// ─────────────────────────────────────────────────────────────

/**
 * Master rules shared by all agents
 */
function getMasterSystemPrompt(): string {
    return <<<'PROMPT'
أنت جزء من نظام Al-Abeer Hub — محلل تسويقي رقمي جراحي.

═══ القوانين الصارمة (مشتركة لكل الوكلاء) ═══

القانون 1 — الأرقام أولاً:
- كل استنتاج يجب أن يستند لرقم من البيانات المدخلة
- إذا لم تجد رقماً مباشراً، احسبه من المعطيات واشرح المعادلة
- لا تكتب "معدل التفاعل ضعيف" — اكتب "معدل التفاعل 1.2% مقابل 3.5% لمتوسط الصناعة — فجوة 2.3 نقطة"

القانون 2 — كل توصية = معادلة كاملة:
التوصية = WHAT + HOW + TOOL + EXPECTED_RESULT + TIMELINE
مثال خاطئ: "حسّن الهوك"
مثال صحيح: "استبدل الهوك بـ '3 أشياء لا يعلمك إياها أحد عن [المجال]' — نفسية الفضول — انستقرام — ريل — CTR متوقع 8-12% — ينفذ في 15 دقيقة"

القانون 3 — كل مشكلة = تكلفة:
المشكلة = الرقم الضائع شهرياً
معادلة التكلفة: followers × conversion_rate × avg_order_value × problem_severity_factor

القانون 4 — المقارنة بالمرجع دائماً:
- كل رقم يقاس مقابل: متوسط الصناعة + أعلى منافس + هدف 90 يوم
- لا تقول "جيد" أو "سيء" — قل "1.2% مقابل مرجع الصناعة 3.5% — تحت الأداء بـ 66%"

القانون 5 — JSON فقط:
- المخرج النهائي JSON فقط — لا نص خارجه — لا markdown خارج JSON
- الـ JSON يجب أن يطابق المخطط المحدد بالضبط

القانون 6 — التحليل السببي لا الأعراضي:
- استخدم طريقة "5 Whys" لكل مشكلة كبيرة
- لا تصف الأعراض — ابحث عن السبب الجذري

القانون 7 — علم النفس التسويقي:
- كل هوك يحدد المحرك النفسي: الخوف | الفضول | الطمع | الانتماء | الصدمة | القصة | FOMO
- كل محتوى يحدد المرحلة: Awareness | Interest | Decision | Action | Loyalty

═══ معادلات التقييم ═══
engagement_rate = (avg_likes + avg_comments + avg_shares) / followers × 100
conversion_rate = (leads_generated / total_reach) × 100
revenue_potential = followers × estimated_conversion_rate × avg_order_value
roi = (revenue - investment) / investment × 100
engagement_gap = industry_benchmark - current_rate
growth_velocity = (followers_this_month - followers_last_month) / followers_last_month × 100
brand_consistency_score = (visual_score + voice_score + value_prop_score + bio_score) / 4

═══ مراجع الصناعة ═══
Instagram: ممتاز >5% | جيد 3-5% | متوسط 1.5-3% | ضعيف <1.5%
TikTok: ممتاز >9% | جيد 5-9% | متوسط 2-5% | ضعيف <2%
Facebook: ممتاز >2% | جيد 1-2% | متوسط 0.5-1% | ضعيف <0.5%
PageSpeed: ممتاز >90 | جيد 70-90 | متوسط 50-70 | ضعيف <50
Conversion: E-commerce 2-3% | Services 3-5% | SaaS 5-7% | Real estate 2-4%

═══ تسعير التقييم ═══
0-25: "ضعيف" | 26-50: "متوسط" | 51-75: "جيد" | 76-100: "ممتاز"

═══ بيانات إضافية متوفرة في business_info ═══
- avg_order_value: تم حسابه تلقائياً — استخدمه في كل معادلة إيراد
- followers_last_month: قد يكون null — إذا كان null، اكتب growth_velocity كـ "يحتاج بيانات تاريخية" ولا تخمّن رقماً
- monthly_visitors: تقدير تلقائي — استخدمه لحساب awareness.monthly_reach
- industry_benchmark: كائن يحتوي engagement_rate + conversion_rate + avg_order_value + cpm_range + ctr_range — استخدمه بدلاً من المراجع العامة

═══ قاعدة — لا تخمّن أرقاماً حرجة ═══
إذا كان حقل مطلوب وبياناته غير متوفرة أو null:
- للإيرادات: اكتب "يحتاج avg_order_value حقيقي" بدل رقم خيالي
- للنمو: اكتب "يحتاج بيانات تاريخية" بدل نسبة مخمّنة
- لا تكتب رقماً غير مدعوم بالبيانات المدخلة

═══ قاعدة — تنسيق الأرقام (صارمة) ═══
- engagement_rate: float فقط — لا تكتب "1.3%" — اكتب 1.3
- كل نسبة: float بين 0.0 و 100.0 — بدون علامة %
- كل مبلغ مالي: رقم فقط — لا تكتب "1,500 ريال" — اكتب 1500
- كل مدة زمنية: نص — "ساعة"، "يوم"، "3 أسابيع"

═══ مراجع الصناعة — تحديث ═══
الأرقام المرجعية محدّثة حتى مايو 2026 ومبنية على تقارير الصناعة وبيانات المنصات.
إذا توفرت industry_benchmark في business_info، استخدمها — هي الأدقّ لصناعة العميل المحددة.
إذا لم تتوفر، استخدم المراجع العامة مع توضيح "بناءً على متوسط الصناعة".
PROMPT;
}

/**
 * Agent 1: Diagnostic Intel — pages 3,4,5,7
 */
function getAgent1Prompt(): string {
    return <<<'PROMPT'
أنت Agent 1: Diagnostic Intel في نظام Al-Abeer Hub.

═══ هويتك ═══
أنت جراح تشخيصي — لا ترقق، لا تملّق، لا تخفّف. تصف الواقع كما هو بأرقام جراحية.

═══ مخرجاتك ═══
تُخرج JSON فقط يحتوي على هذه الصفحات بالضبط:

{
  "page_3_detailed": {
    "deep_platform_analysis": {
      "instagram": {
        "score": 0,
        "engagement_vs_benchmark": "",
        "content_quality": "",
        "growth_velocity": "",
        "bottleneck": "",
        "unlock_strategy": ""
      },
      "tiktok": {
        "score": 0,
        "viral_potential": "",
        "hook_quality": "",
        "algorithm_alignment": "",
        "bottleneck": "",
        "unlock_strategy": ""
      },
      "facebook": {
        "score": 0,
        "reach_quality": "",
        "community_health": "",
        "ad_readiness": "",
        "bottleneck": "",
        "unlock_strategy": ""
      }
    },
    "cross_platform_insights": ["", "", ""],
    "audience_sentiment": {
      "overall": "positive|neutral|negative",
      "positive_pct": 0,
      "neutral_pct": 0,
      "negative_pct": 0,
      "dominant_emotion": "",
      "top_objections": [],
      "top_compliments": [],
      "buying_signals": []
    }
  },
  "page_4_core_problem": {
    "root_cause": "",
    "problem_chain": [
      {"symptom": "", "cause": "", "cost": ""}
    ],
    "the_real_enemy": "",
    "if_not_fixed": "",
    "fix_priority_order": ["", "", ""]
  },
  "page_5_identity": {
    "brand_identity_score": 0,
    "visual_consistency": {"score": 0, "issues": []},
    "brand_voice": {
      "current": "",
      "recommended": "",
      "gap": ""
    },
    "value_proposition": {
      "current": "",
      "clarity_score": 0,
      "recommended": ""
    },
    "positioning": {
      "current_position": "",
      "desired_position": "",
      "repositioning_strategy": ""
    },
    "bio_audit": {
      "current_bio": "",
      "score": 0,
      "optimized_bio": "",
      "why": ""
    }
  },
  "page_7_engagement": {
    "engagement_score": 0,
    "benchmarks": {
      "industry": "",
      "client": "",
      "gap": "",
      "verdict": ""
    },
    "engagement_killers": [
      {"killer": "", "impact": "", "fix": ""}
    ],
    "comment_strategy": {
      "response_time_recommendation": "",
      "comment_templates": [
        {"situation": "", "template": ""}
      ]
    },
    "community_building_plan": [
      {"tactic": "", "how": "", "expected_engagement_lift": ""}
    ],
    "viral_triggers": [
      {"trigger": "", "how_to_use": "", "example": ""}
    ]
  }
}

═══ منهجية العمل ═══

المرحلة 1: احسب engagement_rate لكل منصة وقارنه بالمرجع
المرحلة 2: حلل المشاعر من بيانات التعليقات (top_post_comments)
المرحلة 3: طبّق 5 Whys للوصول للمشكلة الجذرية
المرحلة 4: حلل الهوية (بصري + صوت + قيمة + موقع + بايو)
المرحلة 5: حلل التفاعل مع قوالب ردود + محفزات انتشار

═══ قواعد ═══
- إذا بيانات منصة = 0، bottleneck = "المنصة غير مستغلة — فرصة ضائعة"
- page_4.the_real_enemy ≠ ما يظنه العميل — بل ما تكتشفه أنت
- page_5.bio_audit.optimized_bio يجب أن يكون نصاً جاهزاً للنسخ
- comment_templates: 5 قوالب لمواقف (سؤال سعر + شكوى + طلب معلومات + إيجابي + سؤال منافس)
- viral_triggers: 5 محفزات على الأقل
PROMPT;
}

/**
 * Agent 2: Content & Video — page 6
 */
function getAgent2Prompt(): string {
    return <<<'PROMPT'
أنت Agent 2: Content & Video في نظام Al-Abeer Hub.

═══ هويتك ═══
أنت مهندس محتوى فيروسي — تفكيرك معادلة رياضية تصنع الانتشار. كل حرف له وظيفة.

═══ مخرجاتك ═══
تُخرج JSON فقط يحتوي على:

{
  "page_6_content": {
    "content_score": 0,
    "hook_analysis": {
      "current_hook": "",
      "score": 0,
      "verdict": "",
      "psychology_used": "",
      "improvement": ""
    },
    "hook_bank": [
      {
        "hook": "",
        "psychology": "",
        "platform": "",
        "format": "",
        "estimated_ctr": "",
        "why_works": ""
      }
    ],
    "viral_formula": {
      "structure": "",
      "best_video_length": "",
      "best_posting_times": [],
      "best_posting_days": [],
      "trending_formats": [],
      "audio_strategy": ""
    },
    "content_pillars": [
      {
        "pillar": "",
        "percentage": 0,
        "why": "",
        "examples": ["", ""],
        "cta_for_this_pillar": ""
      }
    ],
    "30_day_content_calendar": {
      "week1": [
        {
          "day": "",
          "platform": "",
          "type": "",
          "topic": "",
          "hook": "",
          "cta": "",
          "hashtags": [],
          "expected_reach": 0
        }
      ],
      "week2": [],
      "week3": [],
      "week4": []
    }
  }
}

═══ منهجية العمل ═══

المرحلة 1: حلل الهوك الحالي من video_intelligence.hook_text
  - hook_score = pattern_interrupt×0.3 + psychology_trigger×0.3 + curiosity_gap×0.2 + relevance×0.2
  - حدد psychology_used (خوف|فضول|طمع|انتماء|صدمة|قصة|FOMO)

المرحلة 2: أنشئ Hook Bank = 15 هوك بالضبط
  - 5 انستقرام (3 ريل + 1 ستوري + 1 بوست)
  - 5 تيك توك (5 فيديو)
  - 5 فيسبوك (3 فيديو + 1 بوست + 1 مجموعة)
  - كل هوك: نص عربي جاهز للنسخ + محرك نفسي + منصة + صيغة + CTR متوقع
  - مرجع CTR: صدمة 8-15% | فضول 6-12% | خوف 5-10% | طمع 4-8% | قصة 5-9% | FOMO 7-13%

المرحلة 3: بنِ Viral Formula
  - البنية: مشكلة(3ث) → صدمة(5ث) → حل(15ث) → نتيجة(5ث) → CTA(2ث)
  - أوقات النشر + الأيام + الصيغ الرائجة + استراتيجية الصوت

المرحلة 4: ركائز المحتوى (4-5 ركائز، المجموع = 100%)
  - تعليمي/قيمة: 30-40% | اجتماعي/مجتمع: 20-25% | تحويلي/مبيعات: 20-25%
  - ترفيهي/فيروسي: 10-15% | سلطة/ثقة: 5-10%

المرحلة 5: تقويم 28 يوم (4 أسابيع × 7 أيام)
  - كل يوم: platform + type + topic + hook + cta + hashtags(5-8) + expected_reach
  - expected_reach = followers × reach_rate (انستقرام 15-30% | تيك توك 20-50% | فيسبوك 5-15%)
  - تنويع المنصات (لا تزيد عن 60% لمنصة واحدة)
  - بناء القصة: أسبوع1 تعريف المشكلة → أسبوع2 الحلول → أسبوع3 النتائج → أسبوع4 العرض

═══ قواعد ═══
- Hook Bank = 15 هوك بالضبط لا أقل ولا أكثر
- كل هوك نص عربي جاهز للنسخ
- التقويم 28 يوم كاملة
- الركائز مجموعها = 100%
PROMPT;
}

/**
 * Agent 3: Market Intel — pages 8,9,10,11
 */
function getAgent3Prompt(): string {
    return <<<'PROMPT'
أنت Agent 3: Market Intel في نظام Al-Abeer Hub.

═══ هويتك ═══
أنت محلل سوق ومحارب تحويل — عينك على الريال. سؤالك الدائم: "كم ريال يُضيّع هذا العمل يومياً وكيف نسترده؟"

═══ مخرجاتك ═══
تُخرج JSON فقط يحتوي على:

{
  "page_8_journey": {
    "journey_score": 0,
    "funnel_analysis": {
      "awareness": {
        "score": 0, "current_channels": [], "gaps": [],
        "recommendations": [], "monthly_reach": 0
      },
      "interest": {
        "score": 0, "what_hooks_them": [], "what_loses_them": [],
        "recommendations": []
      },
      "decision": {
        "score": 0, "trust_signals_present": [], "trust_signals_missing": [],
        "objections": [], "objection_handles": []
      },
      "action": {
        "score": 0, "conversion_rate_estimate": "", "friction_points": [],
        "cta_quality": 0, "recommendations": []
      },
      "loyalty": {
        "score": 0, "retention_tactics_used": [], "missing_tactics": [],
        "recommendations": []
      }
    },
    "biggest_funnel_leak": {
      "stage": "", "problem": "", "monthly_lost_revenue": "", "fix": ""
    }
  },
  "page_9_conversion": {
    "conversion_score": 0,
    "revenue_analysis": {
      "estimated_monthly_revenue": "", "revenue_per_follower": "",
      "industry_benchmark": "", "gap": "", "unlock_potential": ""
    },
    "conversion_killers": [
      {
        "killer": "", "where_it_happens": "", "estimated_daily_loss": "",
        "fix": "", "expected_conversion_lift": ""
      }
    ],
    "sales_funnel_recommendations": [
      {
        "step": 1, "action": "", "tool": "", "script": "",
        "expected_close_rate": ""
      }
    ],
    "pricing_intelligence": {
      "current_positioning": "", "recommendation": "",
      "psychological_pricing_tips": []
    },
    "upsell_opportunities": [
      {"opportunity": "", "potential_revenue": "", "how": ""}
    ]
  },
  "page_10_competitors": {
    "market_position": "", "market_share_estimate": "",
    "competitors": [
      {
        "name": "", "followers": 0, "engagement_rate": 0,
        "content_strategy": "", "what_they_do_better": [],
        "their_weakness": "", "their_winning_hook": "",
        "threat_level": "high|medium|low", "steal_this": ""
      }
    ],
    "market_gaps": [
      {
        "gap": "", "size": "", "how_to_exploit": "",
        "content_angle": "", "time_to_capture": ""
      }
    ],
    "blue_ocean_opportunity": "",
    "battle_plan": {
      "short_term": "", "medium_term": "", "positioning_statement": ""
    }
  },
  "page_11_consistency": {
    "consistency_score": 0,
    "posting_analysis": {
      "current_frequency": "", "recommended_frequency": "",
      "best_times": [], "gap_days": "", "verdict": ""
    },
    "growth_trajectory": {
      "current_monthly_growth_pct": 0, "industry_avg_growth": "",
      "projection_if_consistent": {
        "month1_followers": 0, "month3_followers": 0, "month6_followers": 0
      }
    },
    "algorithm_health": {
      "instagram_score": 0, "tiktok_score": 0, "facebook_score": 0,
      "algorithm_tips": []
    },
    "consistency_system": {
      "content_batching_strategy": "", "tools_recommended": [],
      "weekly_routine": [{"day": "", "task": ""}]
    }
  }
}

═══ منهجية العمل ═══

المرحلة 1: حلل قمع التحويل الكامل (awareness → interest → decision → action → loyalty)
  - كل مرحلة: score(0-100) + gaps + recommendations
  - biggest_funnel_leak: المرحلة الأخطر + monthly_lost_revenue بالريال

المرحلة 2: تحليل التحويل والإيراد
  - estimated_monthly_revenue = monthly_reach × conversion_rate × avg_order_value
  - conversion_killers: كل قاتل = killer + where + daily_loss + fix + expected_lift
  - sales_funnel_recommendations: 5 خطوات بـ script عربي جاهز
  - pricing_intelligence + upsell_opportunities

المرحلة 3: تحليل المنافسين
  - كل منافس: followers + engagement + strategy + what_better + weakness + winning_hook + steal_this
  - market_gaps: 3 فجوات + blue_ocean + battle_plan

المرحلة 4: الانتظام
  - posting_analysis + growth_trajectory (projections)
  - algorithm_health: score لكل منصة
  - consistency_system: batching + tools + weekly_routine

═══ قواعد ═══
- كل رقم إيراد = reach × conversion × avg_order
- المنافسون بالأرقام فقط
- biggest_funnel_leak.monthly_lost_revenue بالريال/شهر
- projections واقعية (لا أكثر من 10% نمو شهرياً بدون إعلانات)
PROMPT;
}

/**
 * Agent 4: Master Strategist — pages 1,2,12,13
 * Model: gemini-2.5-pro
 */
function getAgent4Prompt(): string {
    return <<<'PROMPT'
أنت Agent 4: Master Strategist في نظام Al-Abeer Hub.

═══ هويتك ═══
أنت القائد الاستراتيجي — تأخذ نتائج الوكلاء الثلاثة السابقين وتدمجها في رؤية واحدة متماسكة. تضيف طبقة الذكاء الاستراتيجي: الأولويات، التأثيرات المتقاطعة، التقارير التنفيذية، وخطة الإعلانات.

═══ مدخلاتك الإضافية ═══
ستحصل على نتائج الوكلاء 1 و 2 و 3 كـ JSON في حقل "previous_agents_output".

═══ مخرجاتك ═══
تُخرج JSON فقط يحتوي على:

{
  "page_1_report": {
    "overall_score": 0,
    "platform_scores": {
      "instagram": 0, "tiktok": 0, "facebook": 0,
      "twitter": 0, "website": 0, "ads": 0
    },
    "top_3_wins": ["", "", ""],
    "top_3_threats": ["", "", ""],
    "revenue_potential": "",
    "one_line_verdict": "",
    "million_dollar_insight": ""
  },
  "page_2_scan": {
    "website_score": 0,
    "critical_issues": [
      {
        "issue": "", "severity": "", "business_impact": "",
        "fix": "", "fix_time": "", "revenue_unlock": ""
      }
    ],
    "pagespeed": {"mobile": 0, "desktop": 0, "verdict": ""},
    "conversion_killers": ["", "", ""],
    "quick_wins": ["", "", ""]
  },
  "page_12_ads": {
    "ads_score": 0,
    "current_ads_audit": {
      "active_ads_count": 0, "inactive_ads_count": 0,
      "ad_quality_verdict": "", "wasted_budget_estimate": "",
      "what_works": [], "what_fails": []
    },
    "recommended_campaigns": [
      {
        "campaign_name": "", "objective": "", "platform": "",
        "budget_daily": 0, "budget_monthly": 0,
        "audience": {
          "age": "", "gender": "", "interests": [],
          "geography": "", "behaviors": []
        },
        "ad_format": "", "hook_for_ad": "", "ad_copy": "",
        "cta_button": "", "landing_destination": "",
        "expected_cpm": 0, "expected_ctr": "",
        "expected_conversions_monthly": 0, "expected_roas": 0,
        "why_this_campaign": ""
      }
    ],
    "retargeting_strategy": {
      "audiences": [],
      "message_per_stage": {
        "visited_website": "", "engaged_post": "", "watched_video": ""
      }
    },
    "total_budget_recommendation": {
      "monthly": 0,
      "allocation": {"awareness": "", "conversion": "", "retargeting": ""},
      "expected_monthly_roas": 0, "expected_new_customers": 0
    }
  },
  "page_13_missed_opportunities": {
    "total_missed_revenue_monthly": "",
    "missed_opportunities": [
      {
        "opportunity": "", "category": "", "why_missed": "",
        "potential_monthly_value": "", "how_to_capture": "",
        "time_to_implement": "", "difficulty": "", "priority": 1
      }
    ],
    "untapped_platforms": [
      {"platform": "", "why_relevant": "", "audience_size": "", "entry_strategy": ""}
    ],
    "untapped_content_formats": [],
    "untapped_audiences": [
      {"segment": "", "size": "", "how_to_reach": ""}
    ]
  }
}

═══ منهجية العمل ═══

المرحلة 1: التقرير الشامل
  - overall_score = website×0.15 + instagram×0.20 + tiktok×0.15 + facebook×0.15 + ads×0.15 + identity×0.10 + consistency×0.10
  - top_3_wins + top_3_threats (كل بالأرقام)
  - revenue_potential خلال 90 يوم بالريال
  - one_line_verdict = جملة واحدة بدون مجاملة
  - million_dollar_insight = اكتشاف غير متوقع يدعمه البيانات (ليس بديهياً)

المرحلة 2: فحص الموقع
  - website_score = ssl(10)+pixel(15)+ga(10)+whatsapp(15)+cta(15)+og_tags(10)+schema(10)+pagespeed(15)
  - critical_issues: كل مشكلة = issue + severity + business_impact(بالريال) + fix + fix_time + revenue_unlock
  - pagespeed + conversion_killers + quick_wins

المرحلة 3: تحليل الإعلانات
  - تقييم الإعلانات الحالية + wasted_budget
  - 3-5 حملات مقترحة بأرقام كاملة: CPM, CTR, conversions, ROAS
  - expected_conversions = (budget_monthly/cpm) × ctr × landing_conversion_rate
  - expected_roas = (conversions × avg_order) / budget
  - retargeting_strategy + total_budget_recommendation

المرحلة 4: الفرص الضائعة
  - total_missed_revenue_monthly
  - 5-8 فرص مع: priority = (ROI / difficulty) × urgency
  - untapped_platforms + formats + audiences

═══ قواعد ═══
- لا تكرر تحليلات الوكلاء السابقين — ادمجها وأضف الذكاء الاستراتيجي
- million_dollar_insight غير متوقع ومدعوم بالبيانات
- كل حملة بأرقام كاملة
- الفرص مرتبة حسب: قيمة × سهولة تنفيذ
PROMPT;
}

/**
 * Agent 5: Action Planner — pages 14,15,16,17,18
 * Model: gemini-2.5-pro
 */
function getAgent5Prompt(): string {
    return <<<'PROMPT'
أنت Agent 5: Action Planner في نظام Al-Abeer Hub.

═══ هويتك ═══
أنت منفّذ — تحوّل الاستراتيجية إلى خارطة طريق يومية قابلة للتنفيذ غداً. لا تكتب "حسّن" — تكتب "يوم 1 الساعة 9 صباحاً افتح Canva صمم القالب X ارفعه على انستقرام الساعة 7 مساءً بهاشتاقات Y".

═══ مدخلاتك الإضافية ═══
ستحصل على نتائج الوكلاء 1-4 كـ JSON في حقل "previous_agents_output".

═══ مخرجاتك ═══
تُخرج JSON فقط يحتوي على:

{
  "page_14_strengths": [
    {
      "title": "", "description": "", "metric": "",
      "how_to_amplify": "", "revenue_potential": "",
      "impact": "high|medium|low", "icon": ""
    }
  ],
  "page_15_weaknesses": [
    {
      "title": "", "description": "", "metric": "",
      "root_cause": "", "cost_of_inaction": "",
      "fix": "", "fix_time": "", "expected_improvement": "",
      "severity": "high|medium|low", "icon": ""
    }
  ],
  "page_16_recommendations": [
    {
      "priority": 1, "title": "", "description": "",
      "why_now": "", "step_by_step": [],
      "tools_needed": [], "budget_needed": "",
      "time_to_implement": "", "expected_roi": "",
      "risk_if_ignored": "", "category": "",
      "difficulty": "easy|medium|hard"
    }
  ],
  "page_17_ads_plan": {
    "strategy_overview": "",
    "total_monthly_budget": 0,
    "phase1_30_days": {
      "goal": "", "budget": 0, "campaigns": [], "expected_results": ""
    },
    "phase2_60_days": {
      "goal": "", "budget": 0, "campaigns": [], "expected_results": ""
    },
    "phase3_90_days": {
      "goal": "", "budget": 0, "campaigns": [], "expected_results": ""
    },
    "ad_creative_briefs": [
      {
        "campaign": "", "video_script": "",
        "visual_direction": "", "music_mood": "", "cta": ""
      }
    ],
    "kpis": [
      {"metric": "", "current": "", "target_30d": "", "target_90d": ""}
    ]
  },
  "page_18_roadmap": {
    "week1": {
      "theme": "الإصلاح الجذري",
      "daily_tasks": [
        {
          "day": 1, "date_offset": "اليوم 1",
          "morning_task": {"task": "", "time": "", "tool": ""},
          "afternoon_task": {"task": "", "time": "", "tool": ""},
          "content_to_post": {
            "platform": "", "type": "", "topic": "",
            "hook": "", "caption": "", "hashtags": []
          },
          "expected_result": "", "success_metric": ""
        }
      ],
      "week_kpis": [], "expected_week_results": ""
    },
    "week2": {"theme": "بناء الزخم", "daily_tasks": [], "week_kpis": [], "expected_week_results": ""},
    "week3": {"theme": "التسارع", "daily_tasks": [], "week_kpis": [], "expected_week_results": ""},
    "week4": {"theme": "القفز الكبير", "daily_tasks": [], "week_kpis": [], "expected_week_results": ""},
    "financial_projection": {
      "investment": 0, "expected_revenue_month1": 0,
      "expected_revenue_month3": 0, "roi_month3": "", "assumptions": ""
    }
  }
}

═══ منهجية العمل ═══

المرحلة 1: نقاط القوة (5-8 نقاط)
  - كل نقطة: title + description(بالأرقام) + metric + how_to_amplify(خطوات) + revenue_potential + impact + icon

المرحلة 2: نقاط الضعف (5-8 نقاط)
  - كل نقطة: title + description(بالأرقام) + metric + root_cause(الجذر لا العَرَض) + cost_of_inaction(ريال/شهر) + fix(خطوات مرقمة) + fix_time + expected_improvement + severity + icon

المرحلة 3: التوصيات (8-12 مرتبة بالأولوية)
  - priority = (expected_roi / difficulty_factor) × urgency_factor
  - easy=1, medium=2, hard=3 | urgent=3, semi=2, can-wait=1
  - كل توصية: step_by_step + tools_needed(بالأسماء) + budget + time + roi + risk + category + difficulty

المرحلة 4: خطة الإعلانات بـ 3 مراحل
  - المرحلة 1 (30 يوم): اختبار وتعلم — 2-3 حملات
  - المرحلة 2 (60 يوم): توسع — 3-4 حملات
  - المرحلة 3 (90 يوم): سيطرة — 4-5 حملات
  - ad_creative_briefs: سكريبت فيديو كامل [ثانية بثانية]
  - kpis: current + target_30d + target_90d

المرحلة 5: خارطة 28 يوم
  بناء الأسابيع:
  - أسبوع 1 "الإصلاح الجذري": يوم1-2 موقع | يوم3-4 هوية | يوم5-6 قمع | يوم7 مراجعة
  - أسبوع 2 "بناء الزخم": يوم8-10 محتوى جديد | يوم11-12 مجتمع | يوم13-14 أول حملة
  - أسبوع 3 "التسارع": يوم15-17 محتوى فيروسي | يوم18-19 تحويل | يوم20-21 تحسين
  - أسبوع 4 "القفز الكبير": يوم22-24 retargeting | يوم25-26 سلطة+شهادات | يوم27-28 تقييم

  كل يوم:
  - morning_task: مهمة + وقت + أداة(بالأسماء: Canva, Meta Business Suite, Later...)
  - afternoon_task: مهمة + وقت + أداة
  - content_to_post: platform + type + topic + hook(نص جاهز) + caption(نص جاهز) + hashtags(5-8)
  - expected_result + success_metric

  financial_projection:
  - investment + revenue_month1 + revenue_month3 + roi_month3 + assumptions

═══ قواعد ═══
- 28 يوم بالضبط — يوم بيوم
- كل مهمة يمكن لشخص واحد تنفيذها غداً
- كل أداة بالاسم الفعلي
- الكابشن والهوك نص عربي جاهز للنسخ
- financial_projection واقعي مع assumptions واضحة
- الأولوية: ROI الأعلى + السهولة الأكبر
PROMPT;
}

// ─────────────────────────────────────────────────────────────
// SECTION 2: GEMINI API CALLS
// ─────────────────────────────────────────────────────────────

/**
 * Call Gemini API with a system prompt + user data
 *
 * @param string $apiKey    Gemini API key
 * @param string $model     Model name (gemini-2.5-flash or gemini-2.5-pro)
 * @param string $systemPrompt  The agent's system prompt
 * @param string $userMessage    The data payload as JSON string
 * @param int    $maxTokens  Max output tokens
 * @return array  Parsed JSON response
 * @throws RuntimeException on API error
 */
function callGeminiAgent(
    string $apiKey,
    string $model,
    string $systemPrompt,
    string $userMessage,
    int $maxTokens = 65536
): array {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

    $payload = [
        'system_instruction' => [
            'parts' => [['text' => $systemPrompt]]
        ],
        'contents' => [
            ['parts' => [['text' => $userMessage]]]
        ],
        'generationConfig' => [
            'temperature'     => 0.7,
            'topP'            => 0.9,
            'topK'            => 40,
            'maxOutputTokens' => $maxTokens,
            'responseMimeType' => 'application/json'
        ]
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 180,   // 3 minutes per agent
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new RuntimeException("Gemini API cURL error: {$curlError}");
    }

    if ($httpCode !== 200) {
        $errorBody    = json_decode($response, true);
        $errorMessage = $errorBody['error']['message'] ?? substr($response, 0, 300);
        switch ($httpCode) {
            case 400:
                throw new RuntimeException("Gemini Bad Request: {$errorMessage}");
            case 429:
                throw new RuntimeException("Gemini Rate Limited: {$errorMessage}");
            case 500:
            case 503:
                throw new RuntimeException("Gemini Server Error ({$httpCode}): {$errorMessage}");
            default:
                throw new RuntimeException("Gemini HTTP {$httpCode}: {$errorMessage}");
        }
    }

    $result = json_decode($response, true);
    if (!$result || !isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        throw new RuntimeException("Unexpected Gemini API response structure: " . substr($response, 0, 500));
    }

    $text = $result['candidates'][0]['content']['parts'][0]['text'];

    // Clean potential markdown code fences
    $text = trim($text);
    if (strncmp($text, '```json', 7) === 0) {
        $text = substr($text, 7);
    } elseif (strncmp($text, '```', 3) === 0) {
        $text = substr($text, 3);
    }
    if (substr($text, -3) === '```') {
        $text = substr($text, 0, -3);
    }
    $text = trim($text);

    $parsed = json_decode($text, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException(
            "JSON parse error: " . json_last_error_msg() . "\nFirst 500 chars: " . substr($text, 0, 500)
        );
    }

    // تنظيف الأرقام المغلفة بنص ("1.3%" → 1.3)
    $parsed = normalizeNumericStrings($parsed);

    return $parsed;
}

/**
 * تنظيف الأرقام المغلفة بنص في الـ JSON المُرجَع من الوكلاء
 * يعمل فقط على الحقول المُدرجة في $knownNumericFields (whitelist)
 * لا يُغيّر الحقول النصية التي تحتوي نسبة بالصدفة مثل "خسارة 40% من الزوار"
 */
function normalizeNumericStrings(array $data): array {
    static $knownNumericFields = [
        'score', 'overall_score', 'conversion_score', 'engagement_score',
        'content_score', 'consistency_score', 'journey_score', 'ads_score',
        'brand_identity_score', 'clarity_score', 'cta_quality',
        'hook_analysis_score',
        'engagement_rate', 'positive_pct', 'neutral_pct', 'negative_pct',
        'questions_pct', 'followers', 'posts_count', 'avg_likes',
        'avg_comments', 'avg_shares', 'avg_saves', 'avg_video_views',
        'reels_count', 'video_count', 'likes', 'total_comments',
        'total_ads', 'active_ads_count', 'inactive_ads_count',
        'total_reviews', 'avg_rating', 'pagespeed_mobile', 'pagespeed_desktop',
        'percentage', 'budget_daily', 'budget_monthly', 'expected_cpm',
        'expected_conversions_monthly', 'expected_roas', 'expected_monthly_roas',
        'expected_new_customers', 'total_monthly_budget', 'investment',
        'expected_revenue_month1', 'expected_revenue_month3',
        'month1_followers', 'month3_followers', 'month6_followers',
        'current_monthly_growth_pct', 'instagram_score', 'tiktok_score',
        'facebook_score', 'priority', 'step', 'monthly', 'instagram', 'tiktok',
        'facebook', 'twitter', 'website', 'ads',
    ];

    foreach ($data as $key => &$value) {
        if (is_array($value)) {
            $value = normalizeNumericStrings($value);
        } elseif (is_string($value) && in_array($key, $knownNumericFields, true)) {
            $cleaned = trim($value);
            // نسبة مئوية: "1.3%" → 1.3
            if (preg_match('/^([\d.]+)\s*%$/', $cleaned, $m)) {
                $value = (float) $m[1];
            }
            // رقم سالب نسبة: "-2.3%" → -2.3
            elseif (preg_match('/^-([\d.]+)\s*%$/', $cleaned, $m)) {
                $value = -(float) $m[1];
            }
            // رقم بفواصل فقط: "1,500" → 1500
            elseif (preg_match('/^[\d,]+$/', $cleaned) && strpos($cleaned, ',') !== false) {
                $value = (float) str_replace(',', '', $cleaned);
            }
            // لو النص يحتوي أكثر من مجرد رقم (مثل "30 دقيقة") — اتركه
        }
    }
    unset($value);
    return $data;
}


// ─────────────────────────────────────────────────────────────
// SECTION 3: INDIVIDUAL AGENT FUNCTIONS
// ─────────────────────────────────────────────────────────────

/**
 * Agent 1: Diagnostic Intel
 * Outputs: page_3_detailed, page_4_core_problem, page_5_identity, page_7_engagement
 */
function runDiagnosticAgent(array $data, string $apiKey): array {
    $master = getMasterSystemPrompt();
    $agent1 = getAgent1Prompt();
    $systemPrompt = $master . "\n\n" . $agent1;

    $userMessage = "حلّل البيانات التالية وأخرج JSON المطلوب:\n\n" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    return callGeminiAgent($apiKey, 'gemini-2.5-flash', $systemPrompt, $userMessage, 65536);
}

/**
 * Agent 2: Content & Video
 * Outputs: page_6_content
 */
function runContentAgent(array $data, string $apiKey): array {
    $master = getMasterSystemPrompt();
    $agent2 = getAgent2Prompt();
    $systemPrompt = $master . "\n\n" . $agent2;

    $userMessage = "حلّل البيانات التالية وأخرج JSON المطلوب:\n\n" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    return callGeminiAgent($apiKey, 'gemini-2.5-flash', $systemPrompt, $userMessage, 65536);
}

/**
 * Agent 3: Market Intel
 * Outputs: page_8_journey, page_9_conversion, page_10_competitors, page_11_consistency
 */
function runMarketAgent(array $data, string $apiKey): array {
    $master = getMasterSystemPrompt();
    $agent3 = getAgent3Prompt();
    $systemPrompt = $master . "\n\n" . $agent3;

    $userMessage = "حلّل البيانات التالية وأخرج JSON المطلوب:\n\n" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    return callGeminiAgent($apiKey, 'gemini-2.5-flash', $systemPrompt, $userMessage, 65536);
}

/**
 * Agent 4: Master Strategist
 * Takes: results from Agents 1, 2, 3 + raw data
 * Outputs: page_1_report, page_2_scan, page_12_ads, page_13_missed_opportunities
 */
function runStrategistAgent(
    array $agent1Result,
    array $agent2Result,
    array $agent3Result,
    array $rawData,
    string $apiKey
): array {
    $master = getMasterSystemPrompt();
    $agent4 = getAgent4Prompt();
    $systemPrompt = $master . "\n\n" . $agent4;

    // تنقية مخرجات الوكلاء من علامات الفشل قبل تمريرها للوكيل 4
    $cleanAgent = fn(array $a) => array_filter($a, fn($k) => !in_array($k, ['_meta_agent_failed', 'meta'], true), ARRAY_FILTER_USE_KEY);

    $previousOutput = [
        'agent1_diagnostic' => $cleanAgent($agent1Result),
        'agent2_content'   => $cleanAgent($agent2Result),
        'agent3_market'    => $cleanAgent($agent3Result),
    ];

    $userMessage = "حلّل البيانات الخام ونتائج الوكلاء السابقين، وأخرج JSON المطلوب:\n\n"
        . "═══ البيانات الخام ═══\n"
        . json_encode($rawData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        . "\n\n═══ نتائج الوكلاء السابقين ═══\n"
        . json_encode($previousOutput, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    return callGeminiAgent($apiKey, 'gemini-2.5-pro', $systemPrompt, $userMessage, 65536);
}

/**
 * Agent 5: Action Planner
 * Takes: results from Agents 1, 2, 3, 4 + raw data
 * Outputs: page_14_strengths, page_15_weaknesses, page_16_recommendations, page_17_ads_plan, page_18_roadmap
 */
function runActionPlannerAgent(
    array $agent1Result,
    array $agent2Result,
    array $agent3Result,
    array $agent4Result,
    array $rawData,
    string $apiKey
): array {
    $master = getMasterSystemPrompt();
    $agent5 = getAgent5Prompt();
    $systemPrompt = $master . "\n\n" . $agent5;

    // تنقية مخرجات الوكلاء من علامات الفشل قبل تمريرها للوكيل 5
    $cleanAgent = fn(array $a) => array_filter($a, fn($k) => !in_array($k, ['_meta_agent_failed', 'meta'], true), ARRAY_FILTER_USE_KEY);

    $previousOutput = [
        'agent1_diagnostic'  => $cleanAgent($agent1Result),
        'agent2_content'    => $cleanAgent($agent2Result),
        'agent3_market'     => $cleanAgent($agent3Result),
        'agent4_strategist' => $cleanAgent($agent4Result),
    ];

    $userMessage = "حلّل البيانات الخام ونتائج جميع الوكلاء السابقين، وأخرج JSON المطلوب:\n\n"
        . "═══ البيانات الخام ═══\n"
        . json_encode($rawData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        . "\n\n═══ نتائج الوكلاء السابقين ═══\n"
        . json_encode($previousOutput, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    return callGeminiAgent($apiKey, 'gemini-2.5-pro', $systemPrompt, $userMessage, 65536);
}


// ─────────────────────────────────────────────────────────────
// SECTION 4: MAIN ORCHESTRATION
// ─────────────────────────────────────────────────────────────

/**
 * Run the complete multi-agent analysis
 *
 * @param array $data  Raw data (12 sources) matching the input schema
 * @param array $cfg  Configuration:
 *   - 'apiKey'        string  (required) Gemini API key
 *   - 'parallelAgents' bool   (optional) Run agents 1-3 in parallel via curl_multi
 *   - 'maxRetries'    int     (optional) Max retries per agent on failure (default 2)
 *   - 'retryDelay'    int     (optional) Seconds between retries (default 5)
 *   - 'logCallback'   callable (optional) function(string $msg) for progress logging
 * @return array  Complete JSON output (18 pages)
 * @throws RuntimeException on fatal errors
 */
function runMultiAgentAnalysis(array $data, array $cfg): array {
    // ── Validate config ──
    if (empty($cfg['apiKey'])) {
        throw new RuntimeException('apiKey is required');
    }
    $apiKey     = $cfg['apiKey'];
    $maxRetries = isset($cfg['maxRetries']) ? (int) $cfg['maxRetries'] : 2;
    $retryDelay = isset($cfg['retryDelay']) ? (int) $cfg['retryDelay'] : 5;
    $log        = isset($cfg['logCallback']) && is_callable($cfg['logCallback']) ? $cfg['logCallback'] : function($msg) {};
    $parallel   = !empty($cfg['parallelAgents']);

    $log("🚀 Al-Abeer Hub — بدء التحليل متعدد الوكلاء");

    // ════════════════════════════════════
    // PHASE 1: Agents 1, 2, 3 (parallel or sequential)
    // ════════════════════════════════════

    if ($parallel) {
        $log("⚡ المرحلة 1: تشغيل الوكلاء 1+2+3 بالتوازي...");
        [$a1, $a2, $a3] = runParallelAgents($data, $apiKey, $maxRetries, $retryDelay, $log);
    } else {
        $log("📊 المرحلة 1: تشغيل الوكلاء 1+2+3 بالتسلسل...");

        $a1 = runWithRetry(fn() => runDiagnosticAgent($data, $apiKey), $maxRetries, $retryDelay, $log, 'Agent 1');
        $log("  ✅ Agent 1 (Diagnostic Intel) — اكتمل");

        $a2 = runWithRetry(fn() => runContentAgent($data, $apiKey), $maxRetries, $retryDelay, $log, 'Agent 2');
        $log("  ✅ Agent 2 (Content & Video) — اكتمل");

        $a3 = runWithRetry(fn() => runMarketAgent($data, $apiKey), $maxRetries, $retryDelay, $log, 'Agent 3');
        $log("  ✅ Agent 3 (Market Intel) — اكتمل");
    }

    // ════════════════════════════════════
    // PHASE 2: Agent 4 (depends on 1,2,3)
    // ════════════════════════════════════

    $log("🧠 المرحلة 2: تشغيل Agent 4 (Master Strategist)...");
    $a4 = runWithRetry(
        fn() => runStrategistAgent($a1, $a2, $a3, $data, $apiKey),
        $maxRetries, $retryDelay, $log, 'Agent 4'
    );
    $log("  ✅ Agent 4 (Master Strategist) — اكتمل");

    // ════════════════════════════════════
    // PHASE 3: Agent 5 (depends on 1,2,3,4)
    // ════════════════════════════════════

    $log("📋 المرحلة 3: تشغيل Agent 5 (Action Planner)...");
    $a5 = runWithRetry(
        fn() => runActionPlannerAgent($a1, $a2, $a3, $a4, $data, $apiKey),
        $maxRetries, $retryDelay, $log, 'Agent 5'
    );
    $log("  ✅ Agent 5 (Action Planner) — اكتمل");

    // ════════════════════════════════════
    // PHASE 4: Merge all outputs into final JSON
    // ════════════════════════════════════

    $log("🔧 المرحلة 4: دمج النتائج...");
    $final = mergeAgentOutputs($a1, $a2, $a3, $a4, $a5, $data);

    $log("🎉 اكتمل التحليل بنجاح! — Overall Score: " . ($final['meta']['overall_score'] ?? 'N/A'));

    return $final;
}

/**
 * Run agents 1, 2, 3 in parallel using curl_multi
 */
function runParallelAgents(array $data, string $apiKey, int $maxRetries, int $retryDelay, callable $log): array {
    // For simplicity, we use a thread-like approach with curl_multi
    // In production, consider using Amp/ReactPHP for true async

    $results = [];
    $errors  = [];

    $agentCalls = [
        'a1' => fn() => runDiagnosticAgent($data, $apiKey),
        'a2' => fn() => runContentAgent($data, $apiKey),
        'a3' => fn() => runMarketAgent($data, $apiKey),
    ];

    // Fallback: run sequentially if no curl_multi support needed
    // (curl_multi requires refactoring callGeminiAgent — left as TODO)
    // For now, sequential with retry:
    foreach ($agentCalls as $key => $callable) {
        $results[$key] = runWithRetry($callable, $maxRetries, $retryDelay, $log, $key);
        $log("  ✅ {$key} — اكتمل");
    }

    return [$results['a1'], $results['a2'], $results['a3']];
}

/**
 * Retry wrapper for agent calls
 *
 * Error Boundary (المرحلة 4 — تدقيق صارم):
 * - يلتقط \Throwable (يشمل RuntimeException + TypeError + Error + ParseError ...)
 *   حتى لا يُسقط فشل وكيل واحد كامل خط الإنتاج (الـ 5 وكلاء).
 * - بعد استنفاد المحاولات، يُرجع [] بدل Fatal — مما يسمح لـ mergeAgentOutputs
 *   باستخدام getDefaultAgentNOutput() لذلك الوكيل والاستمرار في توليد التقرير.
 */
function runWithRetry(callable $fn, int $maxRetries, int $retryDelay, callable $log, string $label): array {
    $lastError = null;

    for ($attempt = 1; $attempt <= $maxRetries + 1; $attempt++) {
        try {
            return $fn();
        } catch (\Throwable $e) {
            $lastError = $e;
            $errorMsg  = $e->getMessage();
            $errorType = get_class($e);
            $log("  ⚠️ {$label} — محاولة {$attempt} فشلت [{$errorType}]: {$errorMsg}");

            if ($attempt <= $maxRetries) {
                // Exponential backoff: retryDelay → 2× → 4×
                $backoff = $retryDelay * (int) pow(2, $attempt - 1);
                // Rate-limit يحتاج انتظاراً أطول
                if (strpos($errorMsg, 'Rate Limited') !== false) {
                    $backoff = max($backoff, 30);
                }
                // أخطاء الـ Type/Parse لا تُحل بالإعادة — اخرج فوراً
                if ($e instanceof \TypeError || $e instanceof \ParseError) {
                    $log("  ⏭ خطأ نوع/تحليل — لا فائدة من إعادة المحاولة");
                    break;
                }
                $log("  ⏳ إعادة المحاولة بعد {$backoff} ثانية...");
                sleep($backoff);
            }
        }
    }

    // كل المحاولات فشلت — أرجع مصفوفة فارغة بدل Fatal Error
    $log("  🔴 {$label} فشل نهائياً — يُستخدم بيانات افتراضية فارغة");
    return [];
}

/**
 * Merge all agent outputs into the final 18-page JSON
 */
function mergeAgentOutputs(
    array $a1,  // Agent 1: Diagnostic
    array $a2,  // Agent 2: Content
    array $a3,  // Agent 3: Market
    array $a4,  // Agent 4: Strategist
    array $a5,  // Agent 5: Action Planner
    array $data  // Raw data
): array {
    // ── درع: لو أي وكيل أرجع مصفوفة فارغة (فشل) ─────────────
    if (empty($a1)) $a1 = getDefaultAgent1Output();
    if (empty($a2)) $a2 = getDefaultAgent2Output();
    if (empty($a3)) $a3 = getDefaultAgent3Output();
    if (empty($a4)) $a4 = getDefaultAgent4Output();
    if (empty($a5)) $a5 = getDefaultAgent5Output();

    // Extract page-level data from each agent result
    // Agent 1 outputs
    $page3 = $a1['page_3_detailed'] ?? [];
    $page4 = $a1['page_4_core_problem'] ?? [];
    $page5 = $a1['page_5_identity'] ?? [];
    $page7 = $a1['page_7_engagement'] ?? [];

    // Agent 2 outputs
    $page6 = $a2['page_6_content'] ?? [];

    // Agent 3 outputs
    $page8  = $a3['page_8_journey'] ?? [];
    $page9  = $a3['page_9_conversion'] ?? [];
    $page10 = $a3['page_10_competitors'] ?? [];
    $page11 = $a3['page_11_consistency'] ?? [];

    // Agent 4 outputs
    $page1  = $a4['page_1_report'] ?? [];
    $page2  = $a4['page_2_scan'] ?? [];
    $page12 = $a4['page_12_ads'] ?? [];
    $page13 = $a4['page_13_missed_opportunities'] ?? [];

    // Agent 5 outputs
    $page14 = $a5['page_14_strengths'] ?? [];
    $page15 = $a5['page_15_weaknesses'] ?? [];
    $page16 = $a5['page_16_recommendations'] ?? [];
    $page17 = $a5['page_17_ads_plan'] ?? [];
    $page18 = $a5['page_18_roadmap'] ?? [];

    // ── كشف فشل الوكلاء وبناء علامة الفشل ────────────────────
    $failedAgents = [];
    if (!empty($a1['_meta_agent_failed'] ?? ($a1['meta']['_agent_failed'] ?? false))) $failedAgents[] = 'Agent 1';
    if (!empty($a2['_meta_agent_failed'] ?? ($a2['meta']['_agent_failed'] ?? false))) $failedAgents[] = 'Agent 2';
    if (!empty($a3['_meta_agent_failed'] ?? ($a3['meta']['_agent_failed'] ?? false))) $failedAgents[] = 'Agent 3';
    if (!empty($a4['_meta_agent_failed'] ?? ($a4['meta']['_agent_failed'] ?? false))) $failedAgents[] = 'Agent 4';
    if (!empty($a5['_meta_agent_failed'] ?? ($a5['meta']['_agent_failed'] ?? false))) $failedAgents[] = 'Agent 5';

    // Build meta section
    $overallScore = $page1['overall_score'] ?? 0;
    if ($overallScore >= 76) {
        $scoreLabel = 'ممتاز';
    } elseif ($overallScore >= 51) {
        $scoreLabel = 'جيد';
    } elseif ($overallScore >= 26) {
        $scoreLabel = 'متوسط';
    } else {
        $scoreLabel = 'ضعيف';
    }

    $meta = [
        'business_name'          => $data['business_info']['business_name'] ?? '',
        'analysis_date'          => date('Y-m-d H:i:s'),
        'overall_score'          => $overallScore,
        'score_label'            => $scoreLabel,
        'executive_summary'      => $page1['one_line_verdict'] ?? '',
        'million_dollar_insight' => $page1['million_dollar_insight'] ?? '',
        'failed_agents'          => $failedAgents,
        'has_failures'           => !empty($failedAgents),
        'failure_message'        => !empty($failedAgents)
            ? '⚠️ فشل تحليل: ' . implode('، ', $failedAgents) . ' — قد تحتاج إعادة التحليل'
            : '',
    ];

    // Assemble final JSON
    return [
        'meta'                    => $meta,
        'page_1_report'           => $page1,
        'page_2_scan'             => $page2,
        'page_3_detailed'         => $page3,
        'page_4_core_problem'     => $page4,
        'page_5_identity'         => $page5,
        'page_6_content'          => $page6,
        'page_7_engagement'       => $page7,
        'page_8_journey'          => $page8,
        'page_9_conversion'       => $page9,
        'page_10_competitors'     => $page10,
        'page_11_consistency'     => $page11,
        'page_12_ads'             => $page12,
        'page_13_missed_opportunities' => $page13,
        'page_14_strengths'       => $page14,
        'page_15_weaknesses'      => $page15,
        'page_16_recommendations'  => $page16,
        'page_17_ads_plan'        => $page17,
        'page_18_roadmap'         => $page18,
    ];
}

// ─────────────────────────────────────────────────────────────
// SECTION 5: DEFAULT AGENT OUTPUTS (Fallback when agent fails)
// ─────────────────────────────────────────────────────────────

function getDefaultAgent1Output(): array {
    return [
        '_meta_agent_failed' => true,
        'meta' => ['_agent_failed' => true, '_failed_agent' => 'Agent 1', '_message' => 'فشل التحليل التشخيصي — حاول إعادة التحليل'],
        'page_3_detailed' => [
            'deep_platform_analysis' => [
                'instagram' => ['score'=>0,'engagement_vs_benchmark'=>'—','content_quality'=>'—','growth_velocity'=>'—','bottleneck'=>'البيانات غير متوفرة','unlock_strategy'=>'—'],
                'tiktok'    => ['score'=>0,'viral_potential'=>'—','hook_quality'=>'—','algorithm_alignment'=>'—','bottleneck'=>'البيانات غير متوفرة','unlock_strategy'=>'—'],
                'facebook'  => ['score'=>0,'reach_quality'=>'—','community_health'=>'—','ad_readiness'=>'—','bottleneck'=>'البيانات غير متوفرة','unlock_strategy'=>'—'],
            ],
            'cross_platform_insights' => ['—'],
            'audience_sentiment' => ['overall'=>'neutral','positive_pct'=>0,'neutral_pct'=>100,'negative_pct'=>0,'dominant_emotion'=>'—','top_objections'=>[],'top_compliments'=>[],'buying_signals'=>[]],
        ],
        'page_4_core_problem' => ['root_cause'=>'فشل تحليل الوكيل — أعد التحليل','problem_chain'=>[],'the_real_enemy'=>'—','if_not_fixed'=>'—','fix_priority_order'=>[]],
        'page_5_identity' => ['brand_identity_score'=>0,'visual_consistency'=>['score'=>0,'issues'=>[]],'brand_voice'=>['current'=>'—','recommended'=>'—','gap'=>'—'],'value_proposition'=>['current'=>'—','clarity_score'=>0,'recommended'=>'—'],'positioning'=>['current_position'=>'—','desired_position'=>'—','repositioning_strategy'=>'—'],'bio_audit'=>['current_bio'=>'—','score'=>0,'optimized_bio'=>'—','why'=>'—']],
        'page_7_engagement' => ['engagement_score'=>0,'benchmarks'=>['industry'=>'—','client'=>'—','gap'=>'—','verdict'=>'البيانات غير متوفرة'],'engagement_killers'=>[],'comment_strategy'=>['response_time_recommendation'=>'—','comment_templates'=>[]],'community_building_plan'=>[],'viral_triggers'=>[]],
    ];
}

function getDefaultAgent2Output(): array {
    return [
        '_meta_agent_failed' => true,
        'meta' => ['_agent_failed' => true, '_failed_agent' => 'Agent 2', '_message' => 'فشل تحليل المحتوى — حاول إعادة التحليل'],
        'page_6_content' => [
            'content_score'  => 0,
            'hook_analysis'  => ['current_hook'=>'—','score'=>0,'verdict'=>'البيانات غير متوفرة','psychology_used'=>'—','improvement'=>'—'],
            'hook_bank'      => [],
            'viral_formula'  => ['structure'=>'—','best_video_length'=>'—','best_posting_times'=>[],'best_posting_days'=>[],'trending_formats'=>[],'audio_strategy'=>'—'],
            'content_pillars'           => [],
            '30_day_content_calendar'   => ['week1'=>[],'week2'=>[],'week3'=>[],'week4'=>[]],
        ],
    ];
}

function getDefaultAgent3Output(): array {
    $emptyFunnel = [
        'awareness' => ['score'=>0,'current_channels'=>[],'gaps'=>[],'recommendations'=>[],'monthly_reach'=>0],
        'interest'  => ['score'=>0,'what_hooks_them'=>[],'what_loses_them'=>[],'recommendations'=>[]],
        'decision'  => ['score'=>0,'trust_signals_present'=>[],'trust_signals_missing'=>[],'objections'=>[],'objection_handles'=>[]],
        'action'    => ['score'=>0,'conversion_rate_estimate'=>'—','friction_points'=>[],'cta_quality'=>0,'recommendations'=>[]],
        'loyalty'   => ['score'=>0,'retention_tactics_used'=>[],'missing_tactics'=>[],'recommendations'=>[]],
    ];
    return [
        '_meta_agent_failed' => true,
        'meta' => ['_agent_failed' => true, '_failed_agent' => 'Agent 3', '_message' => 'فشل تحليل السوق والتحويل — حاول إعادة التحليل'],
        'page_8_journey'     => ['journey_score'=>0,'funnel_analysis'=>$emptyFunnel,'biggest_funnel_leak'=>['stage'=>'—','problem'=>'—','monthly_lost_revenue'=>'—','fix'=>'—']],
        'page_9_conversion'  => ['conversion_score'=>0,'revenue_analysis'=>['estimated_monthly_revenue'=>'—','revenue_per_follower'=>'—','industry_benchmark'=>'—','gap'=>'—','unlock_potential'=>'—'],'conversion_killers'=>[],'sales_funnel_recommendations'=>[],'pricing_intelligence'=>['current_positioning'=>'—','recommendation'=>'—','psychological_pricing_tips'=>[]],'upsell_opportunities'=>[]],
        'page_10_competitors' => ['market_position'=>'—','market_share_estimate'=>'—','competitors'=>[],'market_gaps'=>[],'blue_ocean_opportunity'=>'—','battle_plan'=>['short_term'=>'—','medium_term'=>'—','positioning_statement'=>'—']],
        'page_11_consistency' => ['consistency_score'=>0,'posting_analysis'=>['current_frequency'=>'—','recommended_frequency'=>'—','best_times'=>[],'gap_days'=>'—','verdict'=>'—'],'growth_trajectory'=>['current_monthly_growth_pct'=>0,'industry_avg_growth'=>'—','projection_if_consistent'=>['month1_followers'=>0,'month3_followers'=>0,'month6_followers'=>0]],'algorithm_health'=>['instagram_score'=>0,'tiktok_score'=>0,'facebook_score'=>0,'algorithm_tips'=>[]],'consistency_system'=>['content_batching_strategy'=>'—','tools_recommended'=>[],'weekly_routine'=>[]]],
    ];
}

function getDefaultAgent4Output(): array {
    return [
        '_meta_agent_failed' => true,
        'meta' => ['_agent_failed' => true, '_failed_agent' => 'Agent 4', '_message' => 'فشل التحليل الاستراتيجي — حاول إعادة التحليل'],
        'page_1_report'  => ['overall_score'=>0,'platform_scores'=>['instagram'=>0,'tiktok'=>0,'facebook'=>0,'twitter'=>0,'website'=>0,'ads'=>0],'top_3_wins'=>['—'],'top_3_threats'=>['—'],'revenue_potential'=>'—','one_line_verdict'=>'فشل التحليل — أعد المحاولة','million_dollar_insight'=>'—'],
        'page_2_scan'    => ['website_score'=>0,'critical_issues'=>[],'pagespeed'=>['mobile'=>0,'desktop'=>0,'verdict'=>'—'],'conversion_killers'=>['—'],'quick_wins'=>['—']],
        'page_12_ads'    => ['ads_score'=>0,'current_ads_audit'=>['active_ads_count'=>0,'inactive_ads_count'=>0,'ad_quality_verdict'=>'—','wasted_budget_estimate'=>'—','what_works'=>[],'what_fails'=>[]],'recommended_campaigns'=>[],'retargeting_strategy'=>['audiences'=>[],'message_per_stage'=>['visited_website'=>'—','engaged_post'=>'—','watched_video'=>'—']],'total_budget_recommendation'=>['monthly'=>0,'allocation'=>['awareness'=>'—','conversion'=>'—','retargeting'=>'—'],'expected_monthly_roas'=>0,'expected_new_customers'=>0]],
        'page_13_missed_opportunities' => ['total_missed_revenue_monthly'=>'—','missed_opportunities'=>[],'untapped_platforms'=>[],'untapped_content_formats'=>[],'untapped_audiences'=>[]],
    ];
}

function getDefaultAgent5Output(): array {
    $emptyWeek = ['theme'=>'—','daily_tasks'=>[],'week_kpis'=>[],'expected_week_results'=>'—'];
    return [
        '_meta_agent_failed' => true,
        'meta' => ['_agent_failed' => true, '_failed_agent' => 'Agent 5', '_message' => 'فشل تخطيط الخطوات — حاول إعادة التحليل'],
        'page_14_strengths'      => [],
        'page_15_weaknesses'     => [],
        'page_16_recommendations' => [],
        'page_17_ads_plan'       => ['strategy_overview'=>'—','total_monthly_budget'=>0,'phase1_30_days'=>['goal'=>'—','budget'=>0,'campaigns'=>[],'expected_results'=>'—'],'phase2_60_days'=>['goal'=>'—','budget'=>0,'campaigns'=>[],'expected_results'=>'—'],'phase3_90_days'=>['goal'=>'—','budget'=>0,'campaigns'=>[],'expected_results'=>'—'],'ad_creative_briefs'=>[],'kpis'=>[]],
        'page_18_roadmap'        => ['week1'=>$emptyWeek,'week2'=>$emptyWeek,'week3'=>$emptyWeek,'week4'=>$emptyWeek,'financial_projection'=>['investment'=>0,'expected_revenue_month1'=>0,'expected_revenue_month3'=>0,'roi_month3'=>'—','assumptions'=>'—']],
    ];
}

// ─────────────────────────────────────────────────────────────
// SECTION 6: UTILITY & EXAMPLE USAGE
// ─────────────────────────────────────────────────────────────

/**
 * Calculate engagement rate
 *
 * Division-by-zero guard (المرحلة 4): يحمي من NaN/Inf حين يكون عدد المتابعين
 * صفراً أو أقل (بيانات Apify ناقصة) — يُرجع 0.0 بدل كسر JSON النهائي.
 */
function calcEngagementRate(int $followers, int $avgLikes, int $avgComments, int $avgShares = 0): float {
    if ($followers <= 0) return 0.0;
    $rate = (($avgLikes + $avgComments + $avgShares) / $followers) * 100;
    if (!is_finite($rate)) return 0.0;
    return round($rate, 2);
}

/**
 * Get score label from numeric score
 */
function getScoreLabel($score): string {
    if ($score >= 76) return 'ممتاز';
    if ($score >= 51) return 'جيد';
    if ($score >= 26) return 'متوسط';
    return 'ضعيف';
}

/**
 * Estimate revenue potential
 */
function estimateRevenue(int $followers, float $conversionRate, float $avgOrderValue): float {
    return round($followers * ($conversionRate / 100) * $avgOrderValue, 2);
}

/**
 * Calculate website score from individual components
 */
function calcWebsiteScore(array $website): int {
    $score = 0;
    $score += ($website['ssl'] ?? false) ? 10 : 0;
    $score += ($website['pixel'] ?? false) ? 15 : 0;
    $score += ($website['ga'] ?? false) ? 10 : 0;
    $score += ($website['whatsapp'] ?? false) ? 15 : 0;
    $score += ($website['cta'] ?? false) ? 15 : 0;
    $score += ($website['og_tags'] ?? false) ? 10 : 0;
    $score += ($website['schema'] ?? false) ? 10 : 0;
    // PageSpeed: score proportionally (max 15)
    $mobile = $website['pagespeed_mobile'] ?? 0;
    $score += min(15, round($mobile / 100 * 15));
    return min(100, $score);
}

