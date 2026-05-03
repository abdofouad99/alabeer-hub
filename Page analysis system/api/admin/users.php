<?php
// ============================================================
// api/admin/users.php — قائمة العملاء (leads) للوحة التحكم
// GET /api/admin/users.php?limit=50&offset=0&q=keyword
//
// 🔒 محمي بـ session-based admin auth (requireAdmin).
// 📦 شكل الاستجابة:
//    { ok:true, total:int, items:[ { id, name, email, phone,
//      project_type, country, source, status, created_at,
//      latest_score, latest_tier, latest_assessment_id } ] }
// ============================================================

require_once __DIR__ . '/middleware.php';
setCors();
requireAdmin();

$db = getDB();

$limit  = max(1, min((int)($_GET['limit']  ?? 50), 200));
$offset = max(0, (int)($_GET['offset'] ?? 0));
$q      = trim((string)($_GET['q'] ?? ''));

// إجمالي السطور (مع تطبيق الـ search لو وُجد)
$where  = '';
$params = [];
if ($q !== '') {
    $where = " WHERE l.full_name LIKE :q OR l.email LIKE :q OR l.phone LIKE :q
                  OR l.company_name LIKE :q OR l.project_type LIKE :q";
    $params[':q'] = '%' . $q . '%';
}

$stmtTotal = $db->prepare("SELECT COUNT(*) FROM leads l" . $where);
$stmtTotal->execute($params);
$total = (int) $stmtTotal->fetchColumn();

// الصفوف: نضمّ آخر تقييم لكل lead (إن وجد) عبر subquery
// نُطابق على MAX(id) — وليس MAX(created_at) — لأن timestamps قد تتطابق
// لـ assessments متعددة على نفس الـ lead، فيُكرّر الصف. الـ id فريد دوماً.
$sql = "
    SELECT
        l.id, l.full_name AS name, l.email, l.phone,
        l.company_name, l.project_type, l.country, l.source, l.status,
        l.created_at,
        a.id AS latest_assessment_id, a.score AS latest_score, a.tier AS latest_tier
    FROM leads l
    LEFT JOIN (
        SELECT a1.* FROM assessments a1
        JOIN (SELECT lead_id, MAX(id) AS mx_id FROM assessments GROUP BY lead_id) m
            ON a1.id = m.mx_id
    ) a ON a.lead_id = l.id
    {$where}
    ORDER BY l.created_at DESC
    LIMIT {$limit} OFFSET {$offset}
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$items = array_map(function ($r) {
    return [
        'id'                    => (int)$r['id'],
        'name'                  => $r['name']         ?: '',
        'email'                 => $r['email']        ?: '',
        'phone'                 => $r['phone']        ?: '',
        'company_name'          => $r['company_name'] ?: '',
        'project_type'          => $r['project_type'] ?: '',
        'country'               => $r['country']      ?: '',
        'source'                => $r['source']       ?: '',
        'status'                => $r['status']       ?: 'new',
        'created_at'            => $r['created_at'] ? date('Y-m-d H:i', strtotime($r['created_at'])) : '',
        'latest_score'          => $r['latest_score'] !== null ? (int)$r['latest_score'] : null,
        'latest_tier'           => $r['latest_tier']  ?: null,
        'latest_assessment_id'  => $r['latest_assessment_id'] !== null ? (int)$r['latest_assessment_id'] : null,
    ];
}, $rows);

jsonOut([
    'ok'     => true,
    'total'  => $total,
    'limit'  => $limit,
    'offset' => $offset,
    'q'      => $q,
    'items'  => $items,
]);
