<?php
session_start();
header('Content-Type:application/json');
if(!isset($_SESSION['user_id'])||$_SESSION['role']!=='super_admin'){http_response_code(403);die(json_encode(['success'=>false,'message'=>'Unauthorized']));}
require_once __DIR__.'/../../config/database.php';
$input=json_decode(file_get_contents('php://input'),true);
if(!isset($input['blocks'])||!is_array($input['blocks'])){http_response_code(400);die(json_encode(['success'=>false,'message'=>'Invalid blocks']));}
$adminId=$_SESSION['user_id']; $municipalityId=1; $updated=[];
pg_query($connection,"BEGIN");
foreach($input['blocks'] as $b){
  if(!isset($b['key'])){continue;}
  $key=$b['key']; $html=isset($b['html'])?$b['html']:''; $textColor=isset($b['textColor'])&&$b['textColor']!=''?$b['textColor']:null; $bgColor=isset($b['bgColor'])&&$b['bgColor']!=''?$b['bgColor']:null;
  $res=pg_query_params($connection,"SELECT html,text_color,bg_color FROM announcements_content_blocks WHERE municipality_id=$1 AND block_key=$2",[$municipalityId,$key]);
  if($res&&pg_num_rows($res)>0){
    $old=pg_fetch_assoc($res);
    $stmt=pg_prepare($connection,"upd_ann".$key,"UPDATE announcements_content_blocks SET html=$1,text_color=$2,bg_color=$3,updated_at=NOW() WHERE municipality_id=$4 AND block_key=$5");
    pg_execute($connection,"upd_ann".$key,[$html,$textColor,$bgColor,$municipalityId,$key]);
    $stmt2=pg_prepare($connection,"aud_ann".$key,"INSERT INTO announcements_content_audit(municipality_id,block_key,old_html,new_html,old_text_color,new_text_color,old_bg_color,new_bg_color,action_type,admin_id) VALUES($1,$2,$3,$4,$5,$6,$7,$8,'UPDATE',$9)");
    pg_execute($connection,"aud_ann".$key,[$municipalityId,$key,$old['html'],$html,$old['text_color'],$textColor,$old['bg_color'],$bgColor,$adminId]);
  }else{
    $stmt=pg_prepare($connection,"ins_ann".$key,"INSERT INTO announcements_content_blocks(municipality_id,block_key,html,text_color,bg_color,updated_at)VALUES($1,$2,$3,$4,$5,NOW()) ON CONFLICT(municipality_id,block_key)DO UPDATE SET html=$3,text_color=$4,bg_color=$5,updated_at=NOW()");
    pg_execute($connection,"ins_ann".$key,[$municipalityId,$key,$html,$textColor,$bgColor]);
    $stmt2=pg_prepare($connection,"aud_ins_ann".$key,"INSERT INTO announcements_content_audit(municipality_id,block_key,old_html,new_html,old_text_color,new_text_color,old_bg_color,new_bg_color,action_type,admin_id)VALUES($1,$2,'',$3,null,$4,null,$5,'INSERT',$6)");
    pg_execute($connection,"aud_ins_ann".$key,[$municipalityId,$key,$html,$textColor,$bgColor,$adminId]);
  }
  $updated[]=$key;
}
pg_query($connection,"COMMIT");
echo json_encode(['success'=>true,'message'=>'Announcements content saved','updated'=>$updated]);
