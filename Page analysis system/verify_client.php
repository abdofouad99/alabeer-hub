<?php
// verify_client.php — Fetch lead and report details for ID 308
require_once __DIR__ . '/api/db.php';

try {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT a.id, a.ai_report, l.full_name, l.company_name, l.project_type, l.website_url, l.platform
        FROM assessments a
        LEFT JOIN leads l ON a.lead_id = l.id
        WHERE a.id = ?
    ");
    $stmt->execute([308]);
    $row = $stmt->fetch();

    $out = "";
    if (!$row) {
        $out = "ERROR: Assessment 308 not found.";
    } else {
        $out .= "=== CLIENT DETAILS FOR ID 308 ===\n";
        $out .= "Full Name: " . $row['full_name'] . "\n";
        $out .= "Company Name: " . $row['company_name'] . "\n";
        $out .= "Project Type: " . $row['project_type'] . "\n";
        $out .= "Website URL: " . $row['website_url'] . "\n";
        $out .= "Platform: " . $row['platform'] . "\n\n";

        $aiReport = json_decode($row['ai_report'], true);
        $out .= "=== RAW PAGE 7 ENGAGEMENT DATA ===\n";
        if (isset($aiReport['page_7_engagement'])) {
            $out .= json_encode($aiReport['page_7_engagement'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        } else {
            $out .= "page_7_engagement is missing in ai_report!\n";
        }
    }

    file_put_contents('verify_client_output.txt', $out);
    echo "SUCCESS: Output written to verify_client_output.txt\n";
} catch (Exception $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
}
