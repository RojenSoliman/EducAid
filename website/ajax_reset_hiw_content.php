<?php
// Reset all how-it-works page blocks (super admin only)
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
@include_once __DIR__ . '/../includes/permissions.php';
function out($ok,$msg=''){echo json_encode(['success'=>$ok,'message'=>$msg]);exit;}
if($_SERVER['REQUEST_METHOD']!=='POST') out(false,'Invalid method');
$is_super_admin=false; if(isset($_SESSION['admin_id']) && function_exists('getCurrentAdminRole')){ $role=@getCurrentAdminRole($connection); if($role==='super_admin') $is_super_admin=true; }
if(!$is_super_admin) out(false,'Unauthorized');
  $existing=@pg_query($connection,"SELECT block_key, html, text_color, bg_color FROM how_it_works_content_blocks WHERE municipality_id=1"); $blocks=[]; if($existing){ while($r=pg_fetch_assoc($existing)) $blocks[]=$r; }
  $del=@pg_query($connection,"DELETE FROM how_it_works_content_blocks WHERE municipality_id=1"); if(!$del) out(false,'Deletion failed');
  $adminId=(int)($_SESSION['admin_id']??0); $adminUsername=$_SESSION['admin_username']??null;
  foreach($blocks as $b){ @pg_query_params($connection,"INSERT INTO how_it_works_content_audit (municipality_id, block_key, admin_id, admin_username, action_type, old_html, old_text_color, old_bg_color) VALUES (1,$1,$2,$3,'reset_all',$4,$5,$6)",[$b['block_key'],$adminId,$adminUsername,$b['html'],$b['text_color'],$b['bg_color']]); }
  out(true,'All blocks reset to defaults');
