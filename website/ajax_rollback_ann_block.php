<?php
session_start();
header('Content-Type:application/json');
if(!isset($_SESSION['user_id'])||$_SESSION['role']!=='super_admin'){http_response_code(403);die(json_encode(['success'=>false,'message'=>'Unauthorized']));}
require_once __DIR__.'/../../config/database.php';
$input=json_decode(file_get_contents('php://input'),true);
if(!isset($input['key'])||!isset($input['auditId'])){http_response_code(400);die(json_encode(['success'=>false,'message'=>'Missing parameters']));}
$key=$input['key']; $auditId=$input['auditId']; $municipalityId=1; $adminId=$_SESSION['user_id'];
$res=pg_query_params($connection,"SELECT old_html,old_text_color,old_bg_color FROM announcements_content_audit WHERE id=$1 AND municipality_id=$2 AND block_key=$3",[$auditId,$municipalityId,$key]);
if(!$res||pg_num_rows($res)==0){http_response_code(404);die(json_encode(['success'=>false,'message'=>'Audit entry not found']));}
$audit=pg_fetch_assoc($res);
pg_query($connection,"BEGIN");
$cur=pg_query_params($connection,"SELECT html,text_color,bg_color FROM announcements_content_blocks WHERE municipality_id=$1 AND block_key=$2",[$municipalityId,$key]);
if($cur&&pg_num_rows($cur)>0){
  $current=pg_fetch_assoc($cur);
  pg_query_params($connection,"UPDATE announcements_content_blocks SET html=$1,text_color=$2,bg_color=$3,updated_at=NOW() WHERE municipality_id=$4 AND block_key=$5",[$audit['old_html'],$audit['old_text_color'],$audit['old_bg_color'],$municipalityId,$key]);
  $stmt=pg_prepare($connection,"aud_rb_ann".$key,"INSERT INTO announcements_content_audit(municipality_id,block_key,old_html,new_html,old_text_color,new_text_color,old_bg_color,new_bg_color,action_type,admin_id)VALUES($1,$2,$3,$4,$5,$6,$7,$8,'ROLLBACK',$9)");
  pg_execute($connection,"aud_rb_ann".$key,[$municipalityId,$key,$current['html'],$audit['old_html'],$current['text_color'],$audit['old_text_color'],$current['bg_color'],$audit['old_bg_color'],$adminId]);
}else{
  $stmt=pg_prepare($connection,"ins_rb_ann".$key,"INSERT INTO announcements_content_blocks(municipality_id,block_key,html,text_color,bg_color,updated_at)VALUES($1,$2,$3,$4,$5,NOW())");
  pg_execute($connection,"ins_rb_ann".$key,[$municipalityId,$key,$audit['old_html'],$audit['old_text_color'],$audit['old_bg_color']]);
  $stmt2=pg_prepare($connection,"aud_rb_ins_ann".$key,"INSERT INTO announcements_content_audit(municipality_id,block_key,old_html,new_html,old_text_color,new_text_color,old_bg_color,new_bg_color,action_type,admin_id)VALUES($1,$2,'',$3,null,$4,null,$5,'ROLLBACK',$6)");
  pg_execute($connection,"aud_rb_ins_ann".$key,[$municipalityId,$key,$audit['old_html'],$audit['old_text_color'],$audit['old_bg_color'],$adminId]);
}
pg_query($connection,"COMMIT");
echo json_encode(['success'=>true,'message'=>'Rolled back to selected version']);
