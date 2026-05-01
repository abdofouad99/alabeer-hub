<?php
header('Content-Type: application/json');
require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $platform = $_POST['platform'] ?? '';
    $budget = $_POST['monthly_budget'] ?? '';
    $score = intval($_POST['campaign_score'] ?? 0);
    $problems = $_POST['problems_json'] ?? '[]';
    $answersJson = $_POST['answers_json'] ?? '[]';

    if (empty($name) || empty($phone)) {
        echo json_encode(['success' => false, 'message' => 'بيانات غير مكتملة']);
        exit;
    }

    try {
        $sql = "INSERT INTO campaign_leads (client_name, client_phone, platform, monthly_budget, campaign_score, problems_json, answers_json) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$name, $phone, $platform, $budget, $score, $problems, $answersJson]);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        error_log($e->getMessage());
        echo json_encode(['success' => false, 'message' => 'خطأ في الحفظ']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid method']);
}
