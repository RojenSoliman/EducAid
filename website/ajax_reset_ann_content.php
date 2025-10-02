<?php
session_start();
header('Content-Type:application/json');
if(!isset($_SESSION['user_id'])||$_SESSION['role']!=='super_admin'){http_response_code(403);die(json_encode(['success'=>false,'message'=>'Unauthorized']));}
require_once __DIR__.'/../../config/database.php';
$input=json_decode(file_get_contents('php://input'),true);
if(!isset($input['key'])){http_response_code(400);die(json_encode(['success'=>false,'message'=>'Missing key']));}
$key=$input['key']; $municipalityId=1; $adminId=$_SESSION['user_id'];
pg_query($connection,"BEGIN");
$res=pg_query_params($connection,"SELECT html,text_color,bg_color FROM announcements_content_blocks WHERE municipality_id=$1 AND block_key=$2",[$municipalityId,$key]);
if($res&&pg_num_rows($res)>0){
  $old=pg_fetch_assoc($res);
  pg_query_params($connection,"DELETE FROM announcements_content_blocks WHERE municipality_id=$1 AND block_key=$2",[$municipalityId,$key]);
  $stmt=pg_prepare($connection,"aud_del_ann".$key,"INSERT INTO announcements_content_audit(municipality_id,block_key,old_html,new_html,old_text_color,new_text_color,old_bg_color,new_bg_color,action_type,admin_id)VALUES($1,$2,$3,'',$4,null,$5,null,'DELETE',$6)");
  pg_execute($connection,"aud_del_ann".$key,[$municipalityId,$key,$old['html'],$old['text_color'],$old['bg_color'],$adminId]);
}
pg_query($connection,"COMMIT");
echo json_encode(['success'=>true,'message'=>'Block reset']);
