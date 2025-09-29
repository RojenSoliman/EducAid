<?php
// gemini_chat.php (dynamic model selection)
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/chatbot_errors.log');

$API_KEY = 'AIzaSyCLuUUm8C8X3o_qCx3NaGd8NsmSRJXMDGo';
if(!$API_KEY){ http_response_code(500); echo json_encode(['error'=>'API key missing']); exit; }

// Health / list diag
if(isset($_GET['diag'])){
  $versions=['v1','v1beta']; $out=[];
  foreach($versions as $ver){
    $u='https://generativelanguage.googleapis.com/'.$ver.'/models?key='.urlencode($API_KEY);
    $ch=curl_init($u); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
    $r=curl_exec($ch); $c=curl_getinfo($ch,CURLINFO_HTTP_CODE); $e=curl_error($ch); curl_close($ch);
    $parsed=json_decode($r,true); $names=[]; if(!$e && $c===200 && isset($parsed['models'])){ foreach($parsed['models'] as $m){ if(isset($m['name'])) $names[]=$m['name']; } }
    $out[]=['version'=>$ver,'http_code'=>$c,'error'=>$e?:($parsed['error']['message']??null),'models'=>$names];
  }
  echo json_encode(['diagnostic'=>true,'list'=>$out]); exit;
}

$raw=file_get_contents('php://input');
$in=json_decode($raw,true);
$userMessage=trim($in['message']??'');
if($userMessage===''){ http_response_code(400); echo json_encode(['error'=>'Empty message']); exit; }

$prompt = "You are EducAid Assistant, the official AI helper for the EducAid scholarship program in General Trias City, Cavite. " .
  "Provide accurate, concise assistance about eligibility, required documents, process steps, deadlines (remind they change), and contact details. Maintain privacy (RA 10173). If unsure, advise verifying on the official portal.\n\n" .
  "USER MESSAGE: " . $userMessage;

// Preferred logical ordering (fast flash > flash lite > pro)
$preference = [
  'gemini-2.5-flash',
  'gemini-2.5-flash-lite',
  'gemini-2.0-flash',
  'gemini-flash-latest',
  'gemini-2.0-flash-001',
  'gemini-2.0-flash-lite',
  'gemini-2.0-flash-lite-001',
  'gemini-2.5-pro',
  'gemini-pro-latest'
];

$versions = ['v1','v1beta'];
$modelInventory = []; // version => [models]
foreach($versions as $ver){
  $u='https://generativelanguage.googleapis.com/'.$ver.'/models?key='.urlencode($API_KEY);
  $ch=curl_init($u); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
  $r=curl_exec($ch); $c=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
  if($c===200){ $d=json_decode($r,true); if(isset($d['models'])){ foreach($d['models'] as $m){ if(isset($m['name'])) $modelInventory[$ver][] = str_replace('models/','',$m['name']); } } }
}

// Build ordered candidate list (version + model) preserving preference
$attempts = [];
foreach($preference as $candidate){
  foreach($versions as $ver){
    if(!empty($modelInventory[$ver]) && in_array($candidate,$modelInventory[$ver])){
      $attempts[] = [$ver,$candidate];
      break; // do not add duplicate for other versions
    }
  }
}
// Fallback: if list empty (listing failed) just brute-force with preference across versions
if(empty($attempts)){
  foreach($versions as $ver){ foreach($preference as $cand){ $attempts[] = [$ver,$cand]; } }
}

$payload = [ 'contents' => [[ 'role'=>'user','parts'=>[[ 'text'=>$prompt ]] ]] ];

$reply = null; $used = null; $apiVer = null; $lastError = null; $rawResponse = null; $httpCode = null;
foreach($attempts as [$ver,$model]){
  $endpoint='https://generativelanguage.googleapis.com/'.$ver.'/models/'.rawurlencode($model).':generateContent?key='.urlencode($API_KEY);
  $ch=curl_init($endpoint);
  curl_setopt_array($ch,[
    CURLOPT_POST=>true,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
    CURLOPT_POSTFIELDS=>json_encode($payload),
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_TIMEOUT=>30,
    CURLOPT_SSL_VERIFYPEER=>true,
    CURLOPT_SSL_VERIFYHOST=>2,
  ]);
  $resp=curl_exec($ch); $httpCode=curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
  error_log("Attempt model=$model ver=$ver code=$httpCode err=$err");
  if($err){ $lastError='Curl: '.$err; continue; }
  if($httpCode!==200){ $lastError='HTTP '.$httpCode; $rawResponse=$resp; continue; }
  $decoded=json_decode($resp,true);
  if(!$decoded){ $lastError='JSON decode failure'; continue; }
  if(isset($decoded['error'])){ $lastError='API '.$decoded['error']['message']; $rawResponse=json_encode($decoded['error']); continue; }
  $text=$decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;
  if(!$text){ $lastError='No text in response'; continue; }
  $reply=$text; $used=$model; $apiVer=$ver; break;
}

if($reply===null){
  http_response_code(502);
  echo json_encode([
    'error'=>'Chatbot unavailable',
    'detail'=>$lastError,
    'http_code'=>$httpCode,
    'last_body'=>($rawResponse && strlen($rawResponse)<1200)?$rawResponse:substr((string)$rawResponse,0,1200),
    'attempted_models'=>array_map(function($t){return $t[0].':'.$t[1];}, $attempts),
    'available_models'=>$modelInventory
  ]);
  exit;
}

echo json_encode([
  'reply'=>$reply,
  'model_used'=>$used,
  'api_version'=>$apiVer
]);