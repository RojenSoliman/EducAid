<?php
// Rollback a how-it-works page block to a previous version (super admin only)
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
@include_once __DIR__ . '/../includes/permissions.php';
function out($ok,$msg='',$extra=[]){echo json_encode(array_merge(['success'=>$ok,'message'=>$msg],$extra));exit;}
if($_SERVER['REQUEST_METHOD']!=='POST') out(false,'Invalid method');
$is_super_admin=false; if(isset($_SESSION['admin_id']) && function_exists('getCurrentAdminRole')){ $role=@getCurrentAdminRole($connection); if($role==='super_admin') $is_super_admin=true; }
if(!$is_super_admin) out(false,'Unauthorized');
$raw=file_get_contents('php://input');$in=json_decode($raw,true);if(!$in||!isset($in['audit_id'])) out(false,'Missing audit_id');
$auditId=(int)$in['audit_id'];$ar=@pg_query_params($connection,"SELECT block_key, new_html, new_text_color, new_bg_color FROM how_it_works_content_audit WHERE municipality_id=1 AND audit_id=$1",[$auditId]);if(!$ar||pg_num_rows($ar)!==1) out(false,'Audit record not found');
$a=pg_fetch_assoc($ar);$blockKey=$a['block_key'];$html=$a['new_html'];$textColor=$a['new_text_color'];$bgColor=$a['new_bg_color'];
$currRes=@pg_query_params($connection,"SELECT html, text_color, bg_color FROM how_it_works_content_blocks WHERE municipality_id=1 AND block_key=$1",[$blockKey]); $curr=$currRes&&pg_num_rows($currRes)===1?pg_fetch_assoc($currRes):null;
$up=@pg_query_params($connection,"INSERT INTO how_it_works_content_blocks (municipality_id, block_key, html, text_color, bg_color) VALUES (1,$1,$2,$3,$4) ON CONFLICT (municipality_id, block_key) DO UPDATE SET html=EXCLUDED.html, text_color=EXCLUDED.text_color, bg_color=EXCLUDED.bg_color, updated_at=NOW()",[$blockKey,$html,$textColor,$bgColor]); if(!$up) out(false,'Failed applying rollback');
$adminId=(int)($_SESSION['admin_id']??0);$adminUsername=$_SESSION['admin_username']??null;
@pg_query_params($connection,"INSERT INTO how_it_works_content_audit (municipality_id, block_key, admin_id, admin_username, action_type, old_html, new_html, old_text_color, new_text_color, old_bg_color, new_bg_color) VALUES (1,$1,$2,$3,'rollback',$4,$5,$6,$7,$8,$9)",[$blockKey,$adminId,$adminUsername,$curr['html']??null,$html,$curr['text_color']??null,$textColor,$curr['bg_color']??null,$bgColor]);
out(true,'Rollback applied',['block_key'=>$blockKey]);
