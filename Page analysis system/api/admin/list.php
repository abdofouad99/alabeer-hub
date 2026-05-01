<?php
// ============================================================
// api/admin/list.php — قائمة التقييمات للأدمن
// GET /api/admin/list.php?limit=200&q=keyword
// ============================================================
require_once __DIR__ . '/middleware.php';
setCors();
requireAdmin();

$limit = min((int)($_GET['limit'] ?? 200), 500);
$q     = trim($_GET['q'] ?? '');

$db   = getDB();
$sql  = "SELECT id, created_at, score, tier, summary, lead_id FROM assessments";
$params = [];

if ($q) {
    $sql .= " WHERE (summary LIKE ? OR tier LIKE ?)";
    $params = ["%$q%", "%$q%"];
}
$sql .= " ORDER BY created_at DESC LIMIT $limit";

$stmt = $db->prepare($sql);
$stmt->execute($params);
jsonOut($stmt->fetchAll());
