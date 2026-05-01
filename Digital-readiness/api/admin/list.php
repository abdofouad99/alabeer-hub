<?php
require_once __DIR__ . '/middleware.php';
$limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT) ?: 100;
$db    = getDB();
$stmt  = $db->prepare("SELECT a.id, a.created_at, a.score, a.stage, a.summary, a.lead_id,
                               l.full_name, l.company_name
                        FROM assessments a
                        LEFT JOIN leads l ON a.lead_id = l.id
                        ORDER BY a.created_at DESC LIMIT ?");
$stmt->execute([$limit]);
jsonOut($stmt->fetchAll());
