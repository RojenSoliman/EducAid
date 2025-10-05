<?php
$_GET['edit'] = '1';
session_start();
$_SESSION['admin_id'] = 1; // assume admin id 1
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/permissions.php';
// monkey patch getCurrentAdminRole to return super_admin if function uses DB? we can't easily set but we can define stub? maybe function in permissions expects DB. We'll include and rely on DB to fetch admin role; need to ensure admin id 1 exists.
ob_start();
include __DIR__ . '/../website/contact.php';
$html = ob_get_clean();
file_put_contents('contact_edit.html', $html);
