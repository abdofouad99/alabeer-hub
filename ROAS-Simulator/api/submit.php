<?php
require_once __DIR__ . '/db.php';

// قراءة بيانات JSON
$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    sendJson(['success' => false, 'message' => 'لم يتم إرسال بيانات صالحة'], 400);
}

// التحقق من الحقول الأساسية
$required = ['full_name', 'phone', 'monthly_budget', 'product_price', 'profit_margin', 'current_cpa'];
foreach ($required as $field) {
    if (empty($data[$field])) {
        sendJson(['success' => false, 'message' => "الحقل $field مطلوب"], 400);
    }
}

try {
    $pdo = DB::getInstance();
    
    // إدخال المحاكاة في قاعدة البيانات
    $stmt = $pdo->prepare("
        INSERT INTO simulations (
            full_name, phone, company_name, website_url, 
            monthly_budget, product_price, profit_margin, current_cpa, 
            current_profit, potential_profit, financial_status, lead_status
        ) VALUES (
            :full_name, :phone, :company_name, :website_url, 
            :monthly_budget, :product_price, :profit_margin, :current_cpa, 
            :current_profit, :potential_profit, :financial_status, 'new'
        )
    ");

    $stmt->execute([
        ':full_name' => htmlspecialchars(strip_tags($data['full_name'])),
        ':phone' => htmlspecialchars(strip_tags($data['phone'])),
        ':company_name' => htmlspecialchars(strip_tags($data['company_name'] ?? '')),
        ':website_url' => htmlspecialchars(strip_tags($data['website_url'] ?? '')),
        
        ':monthly_budget' => floatval($data['monthly_budget']),
        ':product_price' => floatval($data['product_price']),
        ':profit_margin' => floatval($data['profit_margin']),
        ':current_cpa' => floatval($data['current_cpa']),
        
        ':current_profit' => floatval($data['current_profit']),
        ':potential_profit' => floatval($data['potential_profit']),
        ':financial_status' => htmlspecialchars(strip_tags($data['financial_status']))
    ]);

    $sim_id = $pdo->lastInsertId();

    sendJson([
        'success' => true,
        'message' => 'تم حفظ المعطيات بنجاح',
        'simulation_id' => $sim_id
    ]);

} catch (Exception $e) {
    sendJson(['success' => false, 'message' => 'حدث خطأ في الخادم: ' . $e->getMessage()], 500);
}
