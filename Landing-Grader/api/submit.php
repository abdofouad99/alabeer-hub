<?php
header('Content-Type: application/json');
require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $url = $_POST['url'] ?? '';
    $score = $_POST['score'] ?? 0;

    if(empty($name) || empty($phone) || empty($url)) {
        echo json_encode(['success' => false, 'message' => 'البيانات غير مكتملة']);
        exit;
    }

    try {
        $sql = "INSERT INTO landing_leads (client_name, client_phone, website_url, lp_score) VALUES (?, ?, ?, ?)";
        $stmt= $pdo->prepare($sql);
        $stmt->execute([$name, $phone, $url, $score]);
        
        echo json_encode(['success' => true]);
    } catch(PDOException $e) {
        error_log($e->getMessage());
        echo json_encode(['success' => false, 'message' => 'حدث خطأ أثناء الحفظ']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid method']);
}
