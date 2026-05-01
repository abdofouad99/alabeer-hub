<?php
require_once __DIR__ . '/../db.php';
$cfg = require __DIR__ . '/../config.php';
session_name($cfg['admin']['session_name']);
session_start();
setCors();
if (empty($_SESSION['admin_id'])) jsonError('Unauthorized', 401);
