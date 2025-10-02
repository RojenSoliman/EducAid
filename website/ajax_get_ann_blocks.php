<?php
session_start();
header('Content-Type:application/json');
if(!isset($_SESSION['user_id'])||$_SESSION['role']!=='super_admin'){http_response_code(403);die(json_encode(['success'=>false,'message'=>'Unauthorized']));}
require_once __DIR__.'/../../config/database.php';
$municipalityId=1;
$res=pg_query_params($connection,"SELECT block_key,html,text_color,bg_color FROM announcements_content_blocks WHERE municipality_id=$1",[$municipalityId]);
$blocks=[];
if($res){while($r=pg_fetch_assoc($res)){$blocks[$r['block_key']]=['html'=>$r['html'],'textColor'=>$r['text_color'],'bgColor'=>$r['bg_color']];}pg_free_result($res);}
echo json_encode(['success'=>true,'blocks'=>$blocks]);
