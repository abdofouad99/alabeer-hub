<?php
// ============================================================
// api/gcp-video-analyzer.php
// Cloud Video Intelligence API — تحليل محتوى الفيديو
// يستخرج: النص المنطوق (Hook) + العناصر المرئية + النصوص
// ============================================================

// ── JWT: توليد Access Token من Service Account ──────────────
function _gcpBase64Url(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function gcpGetAccessToken(array $creds): ?string {
    $now    = time();
    $header = _gcpBase64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claims = _gcpBase64Url(json_encode([
        'iss'   => $creds['client_email'],
        'scope' => 'https://www.googleapis.com/auth/cloud-platform',
        'aud'   => $creds['token_uri'],
        'exp'   => $now + 3600,
        'iat'   => $now,
    ]));
    $toSign = "$header.$claims";
    $pkey   = openssl_pkey_get_private($creds['private_key']);
    if (!$pkey) return null;
    openssl_sign($toSign, $sig, $pkey, 'sha256WithRSAEncryption');
    $jwt = $toSign . '.' . _gcpBase64Url($sig);

    $ch = curl_init($creds['token_uri']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
        CURLOPT_TIMEOUT => 30,
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $res['access_token'] ?? null;
}

// ── GCS: رفع ملف وحذفه ─────────────────────────────────────
function _gcpGcsUpload(string $file, string $bucket, string $obj, string $token): bool {
    $url     = "https://storage.googleapis.com/upload/storage/v1/b/{$bucket}/o?uploadType=media&name=" . urlencode($obj);
    $content = file_get_contents($file);
    $ch      = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => $content,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer $token",
            "Content-Type: video/mp4",
            "Content-Length: " . strlen($content),
        ],
        CURLOPT_TIMEOUT        => 120,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    unset($content);
    return $code >= 200 && $code < 300;
}

function _gcpGcsDelete(string $bucket, string $obj, string $token): void {
    $ch = curl_init("https://storage.googleapis.com/storage/v1/b/{$bucket}/o/" . urlencode($obj));
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token"],
        CURLOPT_TIMEOUT        => 20,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// ── Helper: تحميل فيديو عبر CURL ───────────────────────────
function _gcpDownloadVideo(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
        CURLOPT_HTTPHEADER     => ['Accept: video/mp4,video/*'],
    ]);
    $data = curl_exec($ch);
    curl_close($ch);
    return (is_string($data) && strlen($data) > 10000) ? $data : null;
}

// ── الدالة الرئيسية ─────────────────────────────────────────
function analyzeVideoContent(
    string $videoUrl,
    string $credentialsFile = '',
    string $bucket          = 'alabeer-hub-video-analysis'
): array {
    $empty = [
        'analyzed'      => false,
        'hook_text'     => '',
        'transcript'    => '',
        'labels'        => [],
        'video_topics'  => [],
        'text_overlays' => [],
        'error'         => null,
    ];

    // ── 1) تحميل الـ Credentials ────────────────────────────
    if (!$credentialsFile) $credentialsFile = __DIR__ . '/gcp-credentials.json';
    if (!file_exists($credentialsFile)) {
        $empty['error'] = 'gcp-credentials.json not found';
        return $empty;
    }
    $creds = json_decode(file_get_contents($credentialsFile), true);
    if (!$creds) { $empty['error'] = 'Invalid credentials JSON'; return $empty; }

    // ── 2) Access Token ──────────────────────────────────────
    $token = gcpGetAccessToken($creds);
    if (!$token) { $empty['error'] = 'Failed to obtain GCP token'; return $empty; }

    // ── 3) تحميل الفيديو ────────────────────────────────────
    $videoData = _gcpDownloadVideo($videoUrl);
    if (!$videoData) { $empty['error'] = 'Could not download video'; return $empty; }
    if (strlen($videoData) > 50 * 1024 * 1024) { $empty['error'] = 'Video >50MB'; return $empty; }

    $tmpFile = sys_get_temp_dir() . '/alabeer_vid_' . uniqid() . '.mp4';
    file_put_contents($tmpFile, $videoData);
    unset($videoData);

    // ── 4) رفع إلى GCS ──────────────────────────────────────
    $objName = 'tmp/v_' . uniqid() . '.mp4';
    $uploaded = _gcpGcsUpload($tmpFile, $bucket, $objName, $token);
    @unlink($tmpFile);
    if (!$uploaded) { $empty['error'] = 'GCS upload failed'; return $empty; }

    // ── 5) طلب تحليل الفيديو ────────────────────────────────
    $gcsUri = "gs://{$bucket}/{$objName}";
    $body   = json_encode([
        'inputUri' => $gcsUri,
        'features' => ['SPEECH_TRANSCRIPTION', 'LABEL_DETECTION', 'TEXT_DETECTION'],
        'videoContext' => [
            'speechTranscriptionConfig' => [
                'languageCode'              => 'ar-SA',
                'alternativeLanguageCodes'  => ['en-US'],
                'enableAutomaticPunctuation' => true,
            ],
        ],
    ]);
    $ch = curl_init('https://videointelligence.googleapis.com/v1/videos:annotate');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token", "Content-Type: application/json"],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $op = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (empty($op['name'])) {
        _gcpGcsDelete($bucket, $objName, $token);
        $empty['error'] = 'API start failed: ' . ($op['error']['message'] ?? 'unknown');
        return $empty;
    }

    // ── 6) انتظار النتيجة (max 90s) ─────────────────────────
    $opUrl  = "https://videointelligence.googleapis.com/v1/{$op['name']}";
    $result = null;
    for ($i = 0; $i < 18; $i++) {
        sleep(5);
        $ch = curl_init($opUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token"],
            CURLOPT_TIMEOUT        => 30,
        ]);
        $r = json_decode(curl_exec($ch), true);
        curl_close($ch);
        if (!empty($r['done'])) { $result = $r; break; }
    }
    _gcpGcsDelete($bucket, $objName, $token);

    if (!$result || !empty($result['error'])) {
        $empty['error'] = 'Analysis timed out or failed';
        return $empty;
    }

    // ── 7) استخراج النتائج (GCP يعيد result منفصل لكل feature) ─
    $allResults = $result['response']['annotationResults'] ?? [];

    $transcript = '';
    $labels     = [];
    $overlays   = [];

    foreach ($allResults as $ann) {
        // تجاهل النتائج التي فيها خطأ فقط بدون بيانات
        if (!empty($ann['error']) &&
            empty($ann['speechTranscriptions']) &&
            empty($ann['shotLabelAnnotations']) &&
            empty($ann['segmentLabelAnnotations']) &&
            empty($ann['textAnnotations'])) {
            continue;
        }

        // Speech → Transcript
        foreach ($ann['speechTranscriptions'] ?? [] as $utt) {
            $t = trim($utt['alternatives'][0]['transcript'] ?? '');
            if ($t) $transcript .= ' ' . $t;
        }

        // Labels
        foreach (array_merge($ann['shotLabelAnnotations'] ?? [], $ann['segmentLabelAnnotations'] ?? []) as $lbl) {
            $conf = $lbl['segments'][0]['confidence'] ?? 0;
            if ($conf > 0.65) $labels[] = $lbl['entity']['description'] ?? '';
        }

        // Text overlays
        foreach ($ann['textAnnotations'] ?? [] as $t) {
            $txt = trim($t['text'] ?? '');
            if (mb_strlen($txt) > 3) $overlays[] = $txt;
        }
    }

    $transcript = trim($transcript);
    $sentences  = preg_split('/[.!?،؟]\s+/', $transcript);
    $hook       = trim($sentences[0] ?? mb_substr($transcript, 0, 120));
    $labels     = array_values(array_unique(array_filter($labels)));

    // Topics (AR)
    $map = [
        'food'=>'طعام','restaurant'=>'مطعم','fashion'=>'أزياء','clothing'=>'ملابس',
        'beauty'=>'جمال','cosmetics'=>'مستحضرات','fitness'=>'لياقة','technology'=>'تقنية',
        'real estate'=>'عقارات','car'=>'سيارات','travel'=>'سفر','person'=>'أشخاص',
        'product'=>'منتج','text'=>'نص مرئي','logo'=>'شعار',
    ];
    $topics = [];
    foreach ($labels as $lbl) {
        foreach ($map as $en => $ar) {
            if (stripos($lbl, $en) !== false) $topics[] = $ar;
        }
    }

    return [
        'analyzed'      => true,
        'hook_text'     => $hook,
        'transcript'    => $transcript,
        'labels'        => array_slice($labels, 0, 10),
        'video_topics'  => array_values(array_unique($topics)),
        'text_overlays' => array_values(array_unique(array_slice($overlays, 0, 5))),
        'error'         => null,
    ];
}
