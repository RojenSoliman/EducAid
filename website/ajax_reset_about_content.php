<?php
session_start(); header('Content-Type: application/json');
require_once __DIR__.'/../config/database.php'; @include_once __DIR__.'/../includes/permissions.php';
function out($ok,$msg='',$extra=[]){echo json_encode(array_merge(['success'=>$ok,'message'=>$msg],$extra));exit;}
if($_SERVER['REQUEST_METHOD']!=='POST') out(false,'Invalid method');
$is_super_admin=false; if(isset($_SESSION['admin_id']) && function_exists('getCurrentAdminRole')){ $role=@getCurrentAdminRole($connection); if($role==='super_admin') $is_super_admin=true; }
if(!$is_super_admin) out(false,'Unauthorized');
$raw=file_get_contents('php://input'); $payload=json_decode($raw,true)?:[]; $action=$payload['action']??'';
if($action==='reset_all'){
  $existing=@pg_query($connection,"SELECT block_key, html, text_color, bg_color FROM about_content_blocks WHERE municipality_id=1"); $blocks=[]; if($existing){ while($r=pg_fetch_assoc($existing)) $blocks[]=$r; }
  $del=@pg_query($connection,"DELETE FROM about_content_blocks WHERE municipality_id=1"); if(!$del) out(false,'Deletion failed');
  if($blocks){ $adminId=(int)($_SESSION['admin_id']??0); $adminUsername=$_SESSION['admin_username']??null; foreach($blocks as $b){ @pg_query_params($connection,"INSERT INTO about_content_audit (municipality_id, block_key, admin_id, admin_username, action_type, old_html, new_html, old_text_color, new_text_color, old_bg_color, new_bg_color) VALUES (1,$1,$2,$3,'reset_all',$4,NULL,$5,NULL,$6,NULL)",[ $b['block_key'],$adminId,$adminUsername,$b['html'],$b['text_color'],$b['bg_color'] ]); }}
  out(true,'All about page blocks reset');
}
out(false,'Unknown action');
