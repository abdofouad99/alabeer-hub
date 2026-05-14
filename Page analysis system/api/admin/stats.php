<?php
// ============================================================
// api/admin/stats.php — إحصائيات الداشبورد
// GET /api/admin/stats.php
// ============================================================
require_once __DIR__ . '/middleware.php';
$cfg = require __DIR__ . '/../config.php';
setCors();
requireAdmin();

$db = getDB();

// Total + avg score + tier distribution
$totals = $db->query("
    SELECT
        COUNT(*) AS total,
        ROUND(AVG(score)) AS avg_score,
        SUM(tier='green')  AS green,
        SUM(tier='yellow') AS yellow,
        SUM(tier='red')    AS red
    FROM assessments
")->fetch();

// Timeline last 14 days
$timeline = $db->query("
    SELECT
        DATE(created_at) AS date,
        COUNT(*) AS count
    FROM assessments
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
    GROUP BY DATE(created_at)
    ORDER BY date ASC
")->fetchAll();

jsonOut([
    'total'    => (int)$totals['total'],
    'avg'      => (int)$totals['avg_score'],
    'green'    => (int)$totals['green'],
    'yellow'   => (int)$totals['yellow'],
    'red'      => (int)$totals['red'],
    'timeline' => $timeline,
]);
