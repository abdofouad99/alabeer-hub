<?php
// اختبار مباشر لـ cURL Scanner
header('Content-Type: application/json; charset=utf-8');

$url = $_GET['url'] ?? 'https://alabeermarketing.com';

$url = trim($url);
if (!preg_match('/^https?:\/\//i', $url)) {
    $url = 'https://' . $url;
}

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
    CURLOPT_ENCODING       => '',
]);

$html      = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$finalUrl  = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
$curlError = curl_error($ch);
$loadTime  = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
curl_close($ch);

if (!$html) {
    echo json_encode(['success' => false, 'error' => $curlError, 'httpCode' => $httpCode], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $mTitle);
preg_match('/meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)/i', $html, $mDesc);
preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $mH1);

$result = [
    'success'     => true,
    'url'         => $finalUrl,
    'httpCode'    => $httpCode,
    'loadTime'    => round($loadTime, 2) . 'ث',
    'title'       => trim(strip_tags($mTitle[1] ?? 'غير موجود')),
    'description' => trim($mDesc[1] ?? 'غير موجودة'),
    'h1'          => trim(strip_tags($mH1[1] ?? 'غير موجود')),
    'hasPixel'    => (bool)preg_match('/fbq\s*\(|facebook\.com\/tr/i', $html),
    'hasGA'       => (bool)preg_match('/googletagmanager\.com|gtag\s*\(/i', $html),
    'hasWhatsApp' => (bool)preg_match('/wa\.me\/|whatsapp/i', $html),
    'hasSSL'      => str_starts_with($finalUrl, 'https://'),
];

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
