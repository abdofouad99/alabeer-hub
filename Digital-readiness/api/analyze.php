<?php
// ============================================================
// api/analyze.php — خوارزمية تحليل الجاهزية الرقمية
// ============================================================

function clamp(float $n, float $min, float $max): float {
    return max($min, min($max, $n));
}

function stageFromScore(int $score): string {
    if ($score < 21) return 'offline';
    if ($score < 41) return 'beginner';
    if ($score < 61) return 'growing';
    if ($score < 81) return 'advanced';
    return 'digital';
}

function stageLabel(string $stage): string {
    return match($stage) {
        'offline'  => 'غير رقمي',
        'beginner' => 'مبتدئ',
        'growing'  => 'نامي',
        'advanced' => 'متقدم',
        'digital'  => 'رقمي بالكامل',
        default    => $stage,
    };
}

function scoreFromAnswers(array $m): array {
    // ── المحور 1: التواجد الرقمي (25%) ──────────────────────
    $presence = (
        (($m['has_website'] ?? false) ? 1 : 0) * 0.25 +
        (($m['has_social_accounts'] ?? false) ? 1 : 0) * 0.20 +
        (($m['google_maps_listed'] ?? false) ? 1 : 0) * 0.20 +
        (($m['domain_email'] ?? false) ? 1 : 0) * 0.15 +
        (($m['website_mobile_friendly'] ?? false) ? 1 : 0) * 0.20
    ) * 25;

    // ── المحور 2: البيع الإلكتروني (20%) ────────────────────
    $selling = (
        (($m['online_store'] ?? false) ? 1 : 0) * 0.30 +
        (($m['payment_gateway'] ?? false) ? 1 : 0) * 0.25 +
        (($m['shipping_system'] ?? false) ? 1 : 0) * 0.20 +
        (($m['product_catalog'] ?? false) ? 1 : 0) * 0.15 +
        (($m['invoicing_system'] ?? false) ? 1 : 0) * 0.10
    ) * 20;

    // ── المحور 3: التسويق الرقمي (25%) ──────────────────────
    $marketing = (
        (($m['runs_ads'] ?? false) ? 1 : 0) * 0.25 +
        (($m['content_plan'] ?? false) ? 1 : 0) * 0.20 +
        (($m['email_marketing'] ?? false) ? 1 : 0) * 0.15 +
        (($m['seo_active'] ?? false) ? 1 : 0) * 0.15 +
        (is_array($m['platforms_used'] ?? null) ? clamp(count($m['platforms_used']) / 4, 0, 1) : 0) * 0.15 +
        (($m['whatsapp_business'] ?? false) ? 1 : 0) * 0.10
    ) * 25;

    // ── المحور 4: الأتمتة والأنظمة (15%) ────────────────────
    $automation = (
        (($m['uses_crm'] ?? false) ? 1 : 0) * 0.30 +
        (($m['whatsapp_api'] ?? false) ? 1 : 0) * 0.25 +
        (($m['auto_invoicing'] ?? false) ? 1 : 0) * 0.20 +
        (($m['project_management'] ?? false) ? 1 : 0) * 0.15 +
        (($m['chatbot'] ?? false) ? 1 : 0) * 0.10
    ) * 15;

    // ── المحور 5: البيانات والقياس (15%) ─────────────────────
    $data = (
        (($m['analytics_installed'] ?? false) ? 1 : 0) * 0.30 +
        (($m['tracks_kpis'] ?? false) ? 1 : 0) * 0.25 +
        (($m['monthly_reports'] ?? false) ? 1 : 0) * 0.20 +
        (($m['pixel_installed'] ?? false) ? 1 : 0) * 0.15 +
        (($m['data_driven_decisions'] ?? false) ? 1 : 0) * 0.10
    ) * 15;

    $breakdown = [
        'presence'   => (int)round($presence),
        'selling'    => (int)round($selling),
        'marketing'  => (int)round($marketing),
        'automation' => (int)round($automation),
        'data'       => (int)round($data),
    ];

    $score = (int)round(array_sum($breakdown));
    return compact('score', 'breakdown');
}

function genInsights(array $breakdown, array $m): array {
    $strengths = $weaknesses = [];

    // ── نقاط القوة ──
    if (($m['has_website'] ?? false) && ($m['website_mobile_friendly'] ?? false))
        $strengths[] = 'وجود موقع إلكتروني متجاوب مع الجوال.';
    if (($m['online_store'] ?? false) && ($m['payment_gateway'] ?? false))
        $strengths[] = 'بنية بيع إلكتروني متكاملة (متجر + دفع).';
    if (($m['runs_ads'] ?? false) && ($m['content_plan'] ?? false))
        $strengths[] = 'استراتيجية تسويق نشطة (إعلانات + محتوى).';
    if (($m['uses_crm'] ?? false))
        $strengths[] = 'استخدام نظام CRM لإدارة العملاء.';
    if (($m['analytics_installed'] ?? false) && ($m['tracks_kpis'] ?? false))
        $strengths[] = 'اعتماد واضح على البيانات في اتخاذ القرارات.';
    if (($m['google_maps_listed'] ?? false))
        $strengths[] = 'تواجد على خرائط جوجل يسهّل وصول العملاء.';
    if (($m['whatsapp_business'] ?? false))
        $strengths[] = 'استخدام واتساب أعمال للتواصل مع العملاء.';

    // ── نقاط الضعف ──
    if (!($m['has_website'] ?? false))
        $weaknesses[] = 'غياب موقع إلكتروني — 70% من العملاء يبحثون أونلاين أولاً.';
    if (!($m['online_store'] ?? false) && !($m['payment_gateway'] ?? false))
        $weaknesses[] = 'عدم وجود قناة بيع إلكتروني تفقدك عملاء جاهزين للشراء.';
    if (!($m['runs_ads'] ?? false))
        $weaknesses[] = 'عدم وجود إعلانات رقمية يقلل من وصولك لشرائح جديدة.';
    if (!($m['uses_crm'] ?? false))
        $weaknesses[] = 'غياب CRM يعني ضياع بيانات العملاء وعدم متابعتهم.';
    if (!($m['analytics_installed'] ?? false))
        $weaknesses[] = 'بدون أدوات تحليل أنت تعمل في الظلام.';
    if (!($m['email_marketing'] ?? false))
        $weaknesses[] = 'إهمال التسويق عبر البريد يفقدك أرخص قناة مبيعات.';
    if (!($m['seo_active'] ?? false))
        $weaknesses[] = 'غياب SEO يعني عدم ظهورك في نتائج البحث مجاناً.';

    if (!$strengths) $strengths = ['رغبة واعية في التحول الرقمي.', 'بداية صحيحة بتقييم الوضع الحالي.'];
    if (!$weaknesses) $weaknesses = ['الحاجة للتطوير المستمر.', 'مواكبة التقنيات الجديدة.'];

    return [
        'strengths'  => array_slice($strengths, 0, 4),
        'weaknesses' => array_slice($weaknesses, 0, 4),
    ];
}

function genRoadmap(int $score, string $stage, array $breakdown, array $m): array {
    $quickWins = $monthlyPlan = $tools = [];

    // ── خطة 7 أيام (Quick Wins) ──
    if ($stage === 'offline' || $stage === 'beginner') {
        $quickWins = [
            'أنشئ حساب Google My Business وأضف موقعك على الخريطة.',
            'افتح حسابات على Instagram + TikTok باسم موحّد.',
            'سجّل في واتساب أعمال وأضف كتالوج بسيط.',
            'اختر اسم نطاق (Domain) واحجزه الآن.',
        ];
    } elseif ($stage === 'growing') {
        $quickWins = [
            'ثبّت Google Analytics 4 على موقعك.',
            'أنشئ صفحة هبوط واحدة لأقوى منتج/خدمة.',
            'جهّز قائمة بريدية وابدأ بجمع الإيميلات.',
            'فعّل Meta Pixel على موقعك.',
        ];
    } else {
        $quickWins = [
            'اربط CRM بواتساب API للمتابعة الآلية.',
            'أتمت إرسال الفواتير والتذكيرات.',
            'أطلق حملة إعادة استهداف (Retargeting).',
            'حلّل بيانات آخر 90 يوم واستخرج 3 فرص.',
        ];
    }

    // ── خطة 30 يوم ──
    if ($stage === 'offline' || $stage === 'beginner') {
        $monthlyPlan = [
            ['week' => 'الأسبوع 1', 'tasks' => 'إنشاء موقع أو متجر بسيط (Shopify/Salla) + ربط الدومين.'],
            ['week' => 'الأسبوع 2', 'tasks' => 'تجهيز 10 قطع محتوى + نشر يومي على المنصات.'],
            ['week' => 'الأسبوع 3', 'tasks' => 'إطلاق أول حملة إعلانية بميزانية تجريبية (200 ريال).'],
            ['week' => 'الأسبوع 4', 'tasks' => 'تحليل النتائج + تحسين الحملة + إنشاء عرض خاص.'],
        ];
    } elseif ($stage === 'growing') {
        $monthlyPlan = [
            ['week' => 'الأسبوع 1', 'tasks' => 'تحسين SEO للصفحات الرئيسية + تثبيت أدوات التتبع.'],
            ['week' => 'الأسبوع 2', 'tasks' => 'إنشاء 3 صفحات هبوط لأهم الخدمات/المنتجات.'],
            ['week' => 'الأسبوع 3', 'tasks' => 'اختبار A/B لإعلانين + تجهيز حملة بريدية.'],
            ['week' => 'الأسبوع 4', 'tasks' => 'مراجعة KPIs + تقرير أداء شهري + تخطيط الشهر التالي.'],
        ];
    } else {
        $monthlyPlan = [
            ['week' => 'الأسبوع 1', 'tasks' => 'أتمتة رحلة العميل بالكامل (تسجيل → شراء → متابعة).'],
            ['week' => 'الأسبوع 2', 'tasks' => 'بناء Dashboard مخصص لتتبع KPIs اليومية.'],
            ['week' => 'الأسبوع 3', 'tasks' => 'إطلاق برنامج ولاء أو إحالة (Referral).'],
            ['week' => 'الأسبوع 4', 'tasks' => 'مراجعة LTV + تكلفة الاستحواذ + خطة التوسع.'],
        ];
    }

    // ── أدوات مقترحة ──
    if (!($m['has_website'] ?? false))
        $tools[] = ['name' => 'Salla / Shopify', 'desc' => 'بناء متجر أو موقع بسرعة', 'free' => true];
    if (!($m['uses_crm'] ?? false))
        $tools[] = ['name' => 'HubSpot CRM', 'desc' => 'إدارة العملاء مجاناً', 'free' => true];
    if (!($m['analytics_installed'] ?? false))
        $tools[] = ['name' => 'Google Analytics 4', 'desc' => 'تحليل زوار الموقع', 'free' => true];
    if (!($m['email_marketing'] ?? false))
        $tools[] = ['name' => 'Mailchimp', 'desc' => 'تسويق بريدي (مجاني حتى 500 جهة)', 'free' => true];
    if (!($m['content_plan'] ?? false))
        $tools[] = ['name' => 'Canva + Notion', 'desc' => 'تصميم + تخطيط المحتوى', 'free' => true];
    if (!($m['whatsapp_api'] ?? false))
        $tools[] = ['name' => 'WATI / Respond.io', 'desc' => 'واتساب API للأتمتة', 'free' => false];
    if (!($m['project_management'] ?? false))
        $tools[] = ['name' => 'Trello / Asana', 'desc' => 'إدارة المهام والمشاريع', 'free' => true];

    $summary = match($stage) {
        'offline'  => 'شركتك غير مرئية رقمياً — تحتاج تأسيس فوري للبنية الرقمية.',
        'beginner' => 'خطوات أولى جيدة، لكن تحتاج تسريع التحول لتلحق بالسوق.',
        'growing'  => 'أساسيات موجودة — الآن المطلوب تحسين وأتمتة.',
        'advanced' => 'بنية رقمية قوية — ركّز على التوسع والأتمتة الذكية.',
        'digital'  => 'متقدم رقمياً — حافظ على الابتكار وواصل القياس.',
    };

    return compact('summary', 'quickWins', 'monthlyPlan', 'tools');
}

function runAnalysis(int $assessmentId): array {
    $db = getDB();

    // 1) جلب الإجابات
    $stmt = $db->prepare('SELECT question_key, answer FROM answers WHERE assessment_id = ?');
    $stmt->execute([$assessmentId]);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $decoded = json_decode($row['answer'], true);
        $map[$row['question_key']] = $decoded !== null ? $decoded : $row['answer'];
    }

    // 2) حساب الدرجة
    ['score' => $score, 'breakdown' => $breakdown] = scoreFromAnswers($map);

    // 3) توليد التقرير
    $stage = stageFromScore($score);
    ['strengths' => $strengths, 'weaknesses' => $weaknesses] = genInsights($breakdown, $map);
    $roadmap = genRoadmap($score, $stage, $breakdown, $map);

    // 4) حفظ النتيجة
    $db->prepare("UPDATE assessments SET
        status='analyzed', score=?, stage=?, breakdown=?, summary=?,
        strengths=?, weaknesses=?, roadmap=?, quick_wins=?,
        monthly_plan=?, tools_suggested=?
        WHERE id=?")->execute([
        $score, $stage,
        json_encode($breakdown, JSON_UNESCAPED_UNICODE),
        $roadmap['summary'],
        json_encode($strengths, JSON_UNESCAPED_UNICODE),
        json_encode($weaknesses, JSON_UNESCAPED_UNICODE),
        json_encode($roadmap, JSON_UNESCAPED_UNICODE),
        json_encode($roadmap['quickWins'], JSON_UNESCAPED_UNICODE),
        json_encode($roadmap['monthlyPlan'], JSON_UNESCAPED_UNICODE),
        json_encode($roadmap['tools'], JSON_UNESCAPED_UNICODE),
        $assessmentId,
    ]);

    return [
        'ok' => true, 'score' => $score, 'stage' => $stage,
        'breakdown' => $breakdown, 'summary' => $roadmap['summary'],
        'strengths' => $strengths, 'weaknesses' => $weaknesses,
        'quickWins' => $roadmap['quickWins'],
        'monthlyPlan' => $roadmap['monthlyPlan'],
        'tools' => $roadmap['tools'],
    ];
}
