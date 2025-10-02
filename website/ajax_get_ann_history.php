<?php
session_start();
header('Content-Type:application/json');
if(!isset($_SESSION['user_id'])||$_SESSION['role']!=='super_admin'){http_response_code(403);die(json_encode(['success'=>false,'message'=>'Unauthorized']));}
require_once __DIR__.'/../../config/database.php';
$input=json_decode(file_get_contents('php://input'),true);
if(!isset($input['key'])){http_response_code(400);die(json_encode(['success'=>false,'message'=>'Missing key']));}
$key=$input['key']; $municipalityId=1;
$res=pg_query_params($connection,"SELECT id,old_html,new_html,old_text_color,new_text_color,old_bg_color,new_bg_color,action_type,changed_at,admin_id FROM announcements_content_audit WHERE municipality_id=$1 AND block_key=$2 ORDER BY changed_at DESC LIMIT 50",[$municipalityId,$key]);
$history=[];
if($res){
  while($r=pg_fetch_assoc($res)){
    $history[]=['id'=>$r['id'],'oldHtml'=>$r['old_html'],'newHtml'=>$r['new_html'],'oldTextColor'=>$r['old_text_color'],'newTextColor'=>$r['new_text_color'],'oldBgColor'=>$r['old_bg_color'],'newBgColor'=>$r['new_bg_color'],'actionType'=>$r['action_type'],'changedAt'=>$r['changed_at'],'adminId'=>$r['admin_id']];
  }
  pg_free_result($res);
}
echo json_encode(['success'=>true,'history'=>$history]);
