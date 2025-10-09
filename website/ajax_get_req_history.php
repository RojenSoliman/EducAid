<?php
// Fetch requirements page edit history (super admin only)
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
@include_once __DIR__ . '/../includes/permissions.php';
function out($ok,$msg='',$extra=[]){echo json_encode(array_merge(['success'=>$ok,'message'=>$msg],$extra));exit;}
if($_SERVER['REQUEST_METHOD']!=='POST') out(false,'Invalid method');
$is_super_admin=false; if(isset($_SESSION['admin_id']) && function_exists('getCurrentAdminRole')){ $role=@getCurrentAdminRole($connection); if($role==='super_admin') $is_super_admin=true; }
if(!$is_super_admin) out(false,'Unauthorized');
$raw=file_get_contents('php://input'); $in=json_decode($raw,true)?:[];
$block=trim($in['block']??''); $limit=(int)($in['limit']??50); $action=trim($in['action_type']??'');
$where=[]; $params=[];
if($block!==''){$where[]='block_key=$1';$params[]=$block;}
if($action!==''){$c=count($params)+1;$where[]="action_type=$$c";$params[]=$action;}
$sql="SELECT audit_id, block_key, action_type, new_html AS html, new_text_color AS text_color, new_bg_color AS bg_color, admin_username, created_at FROM requirements_content_audit WHERE municipality_id=1".(count($where)?' AND '.implode(' AND ',$where):'')." ORDER BY created_at DESC LIMIT $limit";
$res=count($params)?@pg_query_params($connection,$sql,$params):@pg_query($connection,$sql);
$recs=[]; if($res){while($r=pg_fetch_assoc($res)) $recs[]=$r;}
out(true,'',['records'=>$recs]);
