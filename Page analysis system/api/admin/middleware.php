<?php
// ============================================================
// api/admin/middleware.php — حماية routes الأدمن
// ============================================================
require_once __DIR__ . '/../db.php';

function requireAdmin(): void {
    $cfg = require __DIR__ . '/../config.php';
    session_name($cfg['admin']['session_name']);
    session_start();
    if (empty($_SESSION['admin_id'])) {
        jsonError('Unauthorized', 401);
    }
}
