<?php
/**
 * ════════════════════════════════════════════════════════════════
 * Diagnostics View Endpoint
 * ────────────────────────────────────────────────────────────────
 * GET /api/diagnostics-view.php?id={scan_id}
 *
 * يُرجع JSON كامل لـ diagnostics_log لـ scan معيّن.
 * بدون حماية (السيرفر داخلي/محمي حسب قرار المستخدم).
 * ────────────────────────────────────────────────────────────────
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/diagnostics.php';

$scanId = (int)($_GET['id'] ?? 0);
if ($scanId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'معرّف الفحص مطلوب (?id=N)'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = getDB();

    // جلب أساسيات الـ assessment للسياق
    $stmt = $pdo->prepare(
        "SELECT a.id, a.created_at, a.status, a.scan_status,
                l.full_name, l.company_name, l.facebook_url, l.instagram_url, l.website_url
         FROM assessments a
         LEFT JOIN leads l ON l.id = a.lead_id
         WHERE a.id = ?"
    );
    $stmt->execute([$scanId]);
    $meta = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$meta) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'لا يوجد فحص بهذا المعرف'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $log = Diag::read($pdo, $scanId);

    if (!$log) {
        echo json_encode([
            'success' => true,
            'meta' => $meta,
            'log' => null,
            'message' => 'لا يوجد diagnostics_log محفوظ لهذا الفحص. ربما تم قبل تفعيل النظام أو حُذف بعد 30 يوم.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'success' => true,
        'meta' => $meta,
        'log' => $log,
    ], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
