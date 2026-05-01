<?php
session_name('alabeer_compmap_sess');
session_start();
session_destroy();
echo json_encode(['success' => true]);
