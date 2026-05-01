<?php
header('Content-Type: application/json');
require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $client_domain = $_POST['client_domain'] ?? '';
    $competitors_domains = $_POST['competitors_domains'] ?? '[]';
    $scores_json = $_POST['scores_json'] ?? '{}';

    if (empty($name) || empty($phone) || empty($client_domain)) {
        echo json_encode(['success' => false, 'message' => 'بيانات غير مكتملة']);
        exit;
    }

    try {
        $sql = "INSERT INTO competitor_leads (client_name, client_phone, client_domain, competitors_domains, scores_json) VALUES (?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$name, $phone, $client_domain, $competitors_domains, $scores_json]);

        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        error_log($e->getMessage());
        echo json_encode(['success' => false, 'message' => 'حدث خطأ أثناء الحفظ']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid method']);
}
