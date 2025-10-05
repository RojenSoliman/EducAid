<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
@include_once __DIR__ . '/../includes/permissions.php';

function ann_blocks_resp($ok, $msg = '', $extra = []) {
	echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
	exit;
}

if (!isset($connection)) {
	ann_blocks_resp(false, 'Database unavailable');
}

$is_super_admin = false;
if (isset($_SESSION['admin_id']) && function_exists('getCurrentAdminRole')) {
	$role = @getCurrentAdminRole($connection);
	if ($role === 'super_admin') {
		$is_super_admin = true;
	}
}
if (!$is_super_admin && isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin') {
	$is_super_admin = true;
}

if (!$is_super_admin) {
	http_response_code(403);
	ann_blocks_resp(false, 'Unauthorized');
}

$municipalityId = 1;
$res = pg_query_params($connection, "SELECT block_key,html,text_color,bg_color FROM announcements_content_blocks WHERE municipality_id=$1", [$municipalityId]);
$blocks = [];
if ($res) {
	while ($r = pg_fetch_assoc($res)) {
		$blocks[$r['block_key']] = [
			'html' => $r['html'],
			'textColor' => $r['text_color'],
			'bgColor' => $r['bg_color']
		];
	}
	pg_free_result($res);
}

ann_blocks_resp(true, 'OK', ['blocks' => $blocks]);
