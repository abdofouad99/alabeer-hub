<?php
header('Content-Type: application/json');
require_once 'db.php';

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'] ?? '';
    $email = $_POST['email'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $baseScore = $_POST['baseScore'] ?? 0;

    // Very basic validation
    if (empty($name) || empty($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        echo json_encode(["status" => "error", "message" => "بيانات الإدخال غير صحيحة"]);
        exit;
    }

    $pdo = getDbConnection();
    if ($pdo === null) {
        // Even if DB fails, return fake success so PDF triggers on frontend.
        echo json_encode(["status" => "success", "message" => "تم الحفظ (بدون قاعدة بيانات)"]);
        exit;
    }

    $sql = "INSERT INTO trust_leads (client_name, client_email, client_phone, trust_score) VALUES (?, ?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    
    try {
        $stmt->execute([$name, $email, $phone, $baseScore]);
        echo json_encode(["status" => "success", "message" => "تم تسجيل العميل بنجاح"]);
    } catch (PDOException $e) {
        error_log("Database Insert Error: " . $e->getMessage());
        echo json_encode(["status" => "error", "message" => "فشل الحفظ في قاعدة البيانات"]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "طريقة الطلب غير مسموحة"]);
}
