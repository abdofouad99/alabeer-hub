<?php
// ============================================================
// api/admin-json.php — عرض JSON للتقرير (بدون token — للأدمن فقط)
// ⚠️ يعمل فقط من localhost — محمي تلقائياً
// الاستخدام: /api/admin-json.php?id=306
// ============================================================
require_once __DIR__ . '/db.php';

// ── حماية: localhost فقط ──────────────────────────────────────
$allowedIps = ['127.0.0.1', '::1', '::ffff:127.0.0.1'];
$clientIp   = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($clientIp, $allowedIps, true)) {
    http_response_code(403);
    exit('Forbidden');
}

header('Content-Type: application/json; charset=utf-8');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'id مطلوب']);
    exit;
}

try {
    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT a.*, l.full_name, l.company_name, l.website_url, l.facebook_url, l.instagram_url
         FROM assessments a
         LEFT JOIN leads l ON a.lead_id = l.id
         WHERE a.id = ?
         LIMIT 1"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'لم يُعثر على التقييم']);
        exit;
    }

    // فك JSON الحقول المخزّنة كـ JSON strings
    foreach (['scan_result', 'ai_report', 'answers', 'breakdown', 'recommendations'] as $col) {
        if (isset($row[$col]) && is_string($row[$col])) {
            $decoded = json_decode($row[$col], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $row[$col] = $decoded;
            }
        }
    }

    echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
