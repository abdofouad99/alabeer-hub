<?php
// verify_308.php — Verify DB values for Assessment 308
require_once __DIR__ . '/api/db.php';

try {
    $db = getDB();
    $stmt = $db->prepare("SELECT ai_report, strengths, weaknesses, is_unlocked FROM assessments WHERE id = ?");
    $stmt->execute([308]);
    $row = $stmt->fetch();

    if (!$row) {
        echo "ERROR: Assessment ID 308 not found in database!\n";
        exit(1);
    }

    echo "--- ASSESSMENT 308 DB RECORD FOUND ---\n";
    echo "Is Unlocked: " . ($row['is_unlocked'] ? "Yes (paid)" : "No (free)") . "\n\n";

    $aiReport = json_decode($row['ai_report'], true);
    if (!$aiReport) {
        echo "ERROR: ai_report field is not valid JSON!\n";
        echo "Raw ai_report field: " . substr($row['ai_report'], 0, 500) . "...\n";
        exit(1);
    }

    echo "--- ENGAGEMENT ANALYSIS (PAGE 7) ---\n";
    $eng = $aiReport['page_7_engagement'] ?? null;
    if ($eng) {
        echo "Engagement Score: " . ($eng['engagement_score'] ?? 'NULL') . "\n";
        if (isset($eng['benchmarks'])) {
            echo "Industry Benchmark: " . ($eng['benchmarks']['industry'] ?? 'NULL') . "\n";
            echo "Client Benchmark: " . ($eng['benchmarks']['client'] ?? 'NULL') . "\n";
            echo "Verdict: " . ($eng['benchmarks']['verdict'] ?? 'NULL') . "\n";
        } else {
            echo "Benchmarks: NOT FOUND\n";
        }

        echo "\nEngagement Killers:\n";
        if (isset($eng['engagement_killers'])) {
            foreach ($eng['engagement_killers'] as $k => $killer) {
                echo "  - Killer " . ($k + 1) . ":\n";
                echo "    * Point: " . ($killer['point'] ?? 'NULL') . "\n";
                echo "    * Impact: " . ($killer['impact'] ?? 'NULL') . "\n";
                echo "    * Fix: " . ($killer['fix'] ?? 'NULL') . "\n";
            }
        } else {
            echo "  NOT FOUND\n";
        }

        echo "\nComment Strategy:\n";
        if (isset($eng['comment_strategy'])) {
            echo "  Response Time Recommendation: " . ($eng['comment_strategy']['response_time_recommendation'] ?? 'NULL') . "\n";
            echo "  Scenarios:\n";
            if (isset($eng['comment_strategy']['scenarios'])) {
                foreach ($eng['comment_strategy']['scenarios'] as $sc) {
                    echo "    * Scenario: " . ($sc['scenario'] ?? 'NULL') . "\n";
                    echo "      Response: " . ($sc['response'] ?? 'NULL') . "\n";
                }
            }
        }
    } else {
        echo "page_7_engagement key is missing from ai_report!\n";
    }

} catch (Exception $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
}
