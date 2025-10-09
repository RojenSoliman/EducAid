<?php
session_start(); header('Content-Type: application/json');
require_once __DIR__.'/../config/database.php'; @include_once __DIR__.'/../includes/permissions.php';
function out($ok,$msg='',$extra=[]){echo json_encode(array_merge(['success'=>$ok,'message'=>$msg],$extra));exit;}
if($_SERVER['REQUEST_METHOD']!=='POST') out(false,'Invalid method');
$is_super_admin=false; if(isset($_SESSION['admin_id']) && function_exists('getCurrentAdminRole')){ $role=@getCurrentAdminRole($connection); if($role==='super_admin') $is_super_admin=true; }
if(!$is_super_admin) out(false,'Unauthorized');
$raw=file_get_contents('php://input'); $data=json_decode($raw,true)?:[]; $auditId=(int)($data['audit_id']??0); if($auditId<=0) out(false,'Missing audit_id');
$sel=@pg_query_params($connection,"SELECT audit_id, block_key, old_html, new_html, old_text_color, new_text_color, old_bg_color, new_bg_color FROM about_content_audit WHERE municipality_id=1 AND audit_id=$1",[$auditId]); if(!$sel||pg_num_rows($sel)!==1) out(false,'Audit entry not found'); $row=pg_fetch_assoc($sel);
$blockKey=$row['block_key']; $html=$row['new_html']!==null && $row['new_html']!==''? $row['new_html']:($row['old_html']??''); $textColor=$row['new_text_color']??$row['old_text_color']??null; $bgColor=$row['new_bg_color']??$row['old_bg_color']??null;
$currRes=@pg_query_params($connection,"SELECT html, text_color, bg_color FROM about_content_blocks WHERE municipality_id=1 AND block_key=$1",[$blockKey]); $curr=$currRes&&pg_num_rows($currRes)===1?pg_fetch_assoc($currRes):null;
$up=@pg_query_params($connection,"INSERT INTO about_content_blocks (municipality_id, block_key, html, text_color, bg_color) VALUES (1,$1,$2,$3,$4) ON CONFLICT (municipality_id, block_key) DO UPDATE SET html=EXCLUDED.html, text_color=EXCLUDED.text_color, bg_color=EXCLUDED.bg_color, updated_at=NOW()",[$blockKey,$html,$textColor,$bgColor]); if(!$up) out(false,'Failed applying rollback');
$adminId=(int)($_SESSION['admin_id']??0); $adminUsername=$_SESSION['admin_username']??null; @pg_query_params($connection,"INSERT INTO about_content_audit (municipality_id, block_key, admin_id, admin_username, action_type, old_html, new_html, old_text_color, new_text_color, old_bg_color, new_bg_color) VALUES (1,$1,$2,$3,'rollback',$4,$5,$6,$7,$8,$9)",[ $blockKey,$adminId,$adminUsername,$curr['html']??null,$html,$curr['text_color']??null,$textColor,$curr['bg_color']??null,$bgColor ]);
out(true,'Rollback applied',['block_key'=>$blockKey,'html'=>$html,'text_color'=>$textColor,'bg_color'=>$bgColor]);
