<?php
require_once __DIR__ . '/../api/config.php';
$stmt = $db->query("SELECT analysis_data FROM assessments ORDER BY id DESC LIMIT 1");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($row && $row['analysis_data']) {
    $data = json_decode($row['analysis_data'], true);
    if (isset($data['breakdown'])) {
        echo json_encode($data['breakdown'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } else {
        echo "NO BREAKDOWN FOUND IN ANALYSIS_DATA";
    }
} else {
    echo "NO ROW FOUND";
}
