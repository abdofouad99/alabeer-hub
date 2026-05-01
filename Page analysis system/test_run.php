<?php
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/analyze.php';

echo "Creating dummy lead...\n";
$stmt = $db->prepare("INSERT INTO leads (full_name, phone, company_name, website_url, country, city, project_type) VALUES (?, ?, ?, ?, ?, ?, ?)");
$stmt->execute(['Test User', '123456789', 'AlAbeer Marketing', 'https://alabeermarketing.com/', 'Yemen', 'Sanaa', 'marketing']);
$leadId = $db->lastInsertId();

$stmt = $db->prepare("INSERT INTO assessments (lead_id, status) VALUES (?, ?)");
$stmt->execute([$leadId, 'submitted']);
$assessmentId = $db->lastInsertId();

echo "Running analysis for assessment ID: $assessmentId\n";
echo "Please wait, this will take some time...\n";

$result = runAnalysis($assessmentId);

echo "\n--- Analysis Result ---\n";
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
echo "\n-----------------------\n";
