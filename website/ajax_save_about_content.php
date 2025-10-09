<?php
// Super admin only about page content save endpoint (separate storage)
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
@include_once __DIR__ . '/../includes/permissions.php';

function resp($ok,$msg='',$extra=[]){echo json_encode(array_merge(['success'=>$ok,'message'=>$msg],$extra));exit;}
if($_SERVER['REQUEST_METHOD']!=='POST') resp(false,'Invalid method');
$is_super_admin=false; if(isset($_SESSION['admin_id']) && function_exists('getCurrentAdminRole')){ $role=@getCurrentAdminRole($connection); if($role==='super_admin') $is_super_admin=true; }
if(!$is_super_admin) resp(false,'Unauthorized');
$raw=file_get_contents('php://input'); $data=json_decode($raw,true);
if(!is_array($data) || empty($data['blocks']) || !is_array($data['blocks'])) resp(false,'Invalid payload');

// Ensure tables exist
@pg_query($connection, "CREATE TABLE IF NOT EXISTS about_content_blocks (id SERIAL PRIMARY KEY, municipality_id INT NOT NULL DEFAULT 1, block_key TEXT NOT NULL, html TEXT NOT NULL, text_color VARCHAR(20) DEFAULT NULL, bg_color VARCHAR(20) DEFAULT NULL, updated_at TIMESTAMPTZ DEFAULT NOW(), UNIQUE(municipality_id,block_key))");
@pg_query($connection, "CREATE TABLE IF NOT EXISTS about_content_audit (audit_id BIGSERIAL PRIMARY KEY, municipality_id INT NOT NULL DEFAULT 1, block_key TEXT NOT NULL, admin_id INT NOT NULL, admin_username TEXT NULL, action_type VARCHAR(20) NOT NULL, old_html TEXT NULL, new_html TEXT NULL, old_text_color VARCHAR(20) NULL, new_text_color VARCHAR(20) NULL, old_bg_color VARCHAR(20) NULL, new_bg_color VARCHAR(20) NULL, created_at TIMESTAMPTZ DEFAULT NOW())");

$keys=array_filter(array_map(fn($b)=>trim($b['key']??''),$data['blocks']));
$existing=[]; if($keys){ $ph=[];$params=[]; foreach($keys as $i=>$k){$ph[]='$'.($i+1);$params[]=$k;} $sel=@pg_query_params($connection, "SELECT block_key, html, text_color, bg_color FROM about_content_blocks WHERE municipality_id=1 AND block_key IN (".implode(',',$ph).")", $params); if($sel){ while($r=pg_fetch_assoc($sel)) $existing[$r['block_key']]=$r; }}

$upStmt=@pg_prepare($connection,'up_about',"INSERT INTO about_content_blocks (municipality_id, block_key, html, text_color, bg_color) VALUES (1,$1,$2,$3,$4) ON CONFLICT (municipality_id, block_key) DO UPDATE SET html=EXCLUDED.html, text_color=EXCLUDED.text_color, bg_color=EXCLUDED.bg_color, updated_at=NOW()");
$updated=0;$errors=[]; $adminId=(int)($_SESSION['admin_id']??0); $adminUsername=$_SESSION['admin_username']??null;
foreach($data['blocks'] as $blk){
  $key=trim($blk['key']??''); $html=trim($blk['html']??''); $styles=$blk['styles']??[]; if($key===''||$html==='') continue;
  $html_clean=preg_replace('#<script[^>]*>.*?</script>#is','',$html);
  $textColor=preg_match('/^#[0-9a-fA-F]{3,8}$/',$styles['color']??'')?$styles['color']:null;
  $bgColor=preg_match('/^#[0-9a-fA-F]{3,8}$/',$styles['backgroundColor']??'')?$styles['backgroundColor']:null;
  $prev=$existing[$key]??null;
  $res=@pg_execute($connection,'up_about',[$key,$html_clean,$textColor,$bgColor]);
  if($res){$updated++; @pg_query_params($connection,"INSERT INTO about_content_audit (municipality_id, block_key, admin_id, admin_username, action_type, old_html, new_html, old_text_color, new_text_color, old_bg_color, new_bg_color) VALUES (1,$1,$2,$3,'update',$4,$5,$6,$7,$8,$9)",[ $key,$adminId,$adminUsername,$prev['html']??null,$html_clean,$prev['text_color']??null,$textColor,$prev['bg_color']??null,$bgColor ]); } else { $errors[]=$key; }
}
if($updated===0 && empty($errors)) resp(false,'Nothing updated');
resp(true,'Updated '.$updated.' block(s)', ['errors'=>$errors]);
