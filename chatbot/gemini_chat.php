<?php
// gemini_chat.php (dynamic model selection)
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/chatbot_errors.log');

define('GEMINI_MODEL_CACHE_FILE', __DIR__ . '/gemini_model_cache.json');
define('GEMINI_MODEL_CACHE_TTL', 86400); // refresh once per day
define('GEMINI_MAX_RETRY_ATTEMPTS', 6);
define('GEMINI_BASE_BACKOFF_SECONDS', 1.0);
define('GEMINI_BACKOFF_CAP_SECONDS', 32.0);
define('GEMINI_CONCURRENCY_LIMIT', max(1, (int)(getenv('GEMINI_MAX_CONCURRENCY') ?: 4)));
define('GEMINI_CONCURRENCY_WAIT_MS', 150);
define('GEMINI_CONCURRENCY_MAX_WAIT_MS', 10000);

$envBootstrap = __DIR__ . '/../config/env.php';
if(is_file($envBootstrap)){
  require_once $envBootstrap;
}

function gemini_log_attempt($payload){
  $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
  if($encoded){
    error_log('[GeminiAttempt] ' . $encoded);
  } else {
    error_log('[GeminiAttempt] ' . print_r($payload, true));
  }
}

function gemini_parse_retry_after($headerValue){
  if(!$headerValue){
    return null;
  }
  if(is_numeric($headerValue)){
    return (float)$headerValue;
  }
  $asTime = strtotime($headerValue);
  if($asTime){
    $diff = $asTime - time();
    return $diff > 0 ? (float)$diff : 0.0;
  }
  return null;
}

function gemini_calculate_backoff($retryIndex, $retryAfterSeconds){
  if($retryAfterSeconds !== null){
    return min(GEMINI_BACKOFF_CAP_SECONDS, max(0.0, $retryAfterSeconds));
  }
  $cap = GEMINI_BASE_BACKOFF_SECONDS * pow(2, max(0, $retryIndex - 1));
  $cap = min(GEMINI_BACKOFF_CAP_SECONDS, $cap);
  if($cap <= 0){
    return 0.0;
  }
  return mt_rand(0, (int)round($cap * 1000)) / 1000;
}

function gemini_load_model_inventory($versions, $apiKey){
  $now = time();
  $fresh = null;
  $stale = null;
  if(is_file(GEMINI_MODEL_CACHE_FILE)){
    $raw = file_get_contents(GEMINI_MODEL_CACHE_FILE);
    $decoded = json_decode($raw, true);
    if(is_array($decoded) && isset($decoded['fetched_at'], $decoded['models'])){
      $age = $now - (int)$decoded['fetched_at'];
      if($age < GEMINI_MODEL_CACHE_TTL){
        $fresh = $decoded['models'];
      } else {
        $stale = $decoded['models'];
      }
    }
  }

  if($fresh !== null){
    return $fresh;
  }

  $models = [];
  foreach($versions as $ver){
    $u = 'https://generativelanguage.googleapis.com/' . $ver . '/models?key=' . urlencode($apiKey);
    $ch = curl_init($u);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 10,
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_SSL_VERIFYHOST => 2
    ]);
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if($err || $code !== 200){
      gemini_log_attempt([
        'phase' => 'model_inventory_fetch',
        'version' => $ver,
        'http_code' => $code,
        'error' => $err ?: 'unexpected_http_' . $code
      ]);
      continue;
    }
    $decoded = json_decode($r, true);
    if(isset($decoded['models']) && is_array($decoded['models'])){
      foreach($decoded['models'] as $model){
        if(isset($model['name'])){
          $clean = str_replace('models/', '', $model['name']);
          $models[$ver][] = $clean;
        }
      }
    }
  }

  if(!empty($models)){
    $payload = json_encode(['fetched_at' => $now, 'models' => $models], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if($payload !== false){
      file_put_contents(GEMINI_MODEL_CACHE_FILE, $payload);
    }
    return $models;
  }

  return is_array($stale) ? $stale : [];
}

function gemini_extract_rate_headers($headers){
  $wanted = [
    'x-ratelimit-limit',
    'x-ratelimit-remaining',
    'x-ratelimit-reset',
    'x-ratelimit-token-consumed',
    'x-ratelimit-tokens-remaining'
  ];
  $out = [];
  foreach($wanted as $key){
    if(isset($headers[$key])){
      $out[$key] = $headers[$key];
    }
  }
  return $out;
}

function gemini_concurrency_acquire($maxSlots){
  $lockPath = __DIR__ . '/gemini_concurrency.lock';
  $deadline = microtime(true) + (GEMINI_CONCURRENCY_MAX_WAIT_MS / 1000);
  $sleepMicros = GEMINI_CONCURRENCY_WAIT_MS * 1000;
  while(microtime(true) < $deadline){
    $fh = @fopen($lockPath, 'c+');
    if(!$fh){
      usleep($sleepMicros);
      continue;
    }
    if(!flock($fh, LOCK_EX)){
      fclose($fh);
      usleep($sleepMicros);
      continue;
    }
    rewind($fh);
    $raw = stream_get_contents($fh);
    $count = 0;
    if($raw !== false){
      $raw = trim($raw);
      if($raw !== ''){
        $count = (int)$raw;
        if($count < 0){
          $count = 0;
        }
      }
    }
    if($count < $maxSlots){
      $count++;
      ftruncate($fh, 0);
      rewind($fh);
      fwrite($fh, (string)$count);
      fflush($fh);
      flock($fh, LOCK_UN);
      fclose($fh);
      return true;
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    usleep($sleepMicros);
  }
  return false;
}

function gemini_concurrency_release(){
  $lockPath = __DIR__ . '/gemini_concurrency.lock';
  $fh = @fopen($lockPath, 'c+');
  if(!$fh){
    return;
  }
  if(!flock($fh, LOCK_EX)){
    fclose($fh);
    return;
  }
  rewind($fh);
  $raw = stream_get_contents($fh);
  $count = 0;
  if($raw !== false){
    $raw = trim($raw);
    if($raw !== ''){
      $count = (int)$raw;
      if($count < 0){
        $count = 0;
      }
    }
  }
  if($count > 0){
    $count--;
  }
  ftruncate($fh, 0);
  rewind($fh);
  fwrite($fh, (string)$count);
  fflush($fh);
  flock($fh, LOCK_UN);
  fclose($fh);
}

$API_KEY = getenv('GEMINI_API_KEY') ?: '';
if($API_KEY === ''){
  gemini_log_attempt(['status' => 'missing_api_key']);
  http_response_code(500);
  echo json_encode(['error' => 'API key missing']);
  exit;
}

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

// Optimized prompt - shorter for faster response, broader capabilities
$prompt = "You are EducAid Assistant for the scholarship program in General Trias, Cavite.\n\n" .
  "Your role:\n" .
  "- Answer questions about eligibility, requirements, documents, application process, and deadlines\n" .
  "- Help with general student concerns, academic guidance, and university/scholarship information\n" .
  "- Be conversational, helpful, and friendly for casual chat or greetings\n" .
  "- Keep responses concise (2-3 sentences for simple questions, more detail when needed)\n" .
  "- If you don't know something specific, guide them to check the official portal or contact the office\n\n" .
  "Student message: " . $userMessage;

// Optimized: Prioritize fastest flash models (2.0 flash is fastest, then 1.5 flash)
$preference = [
  'gemini-2.0-flash-lite',           // Fastest - prioritize this
  'gemini-2.0-flash-lite-001',
  'gemini-2.0-flash',                // Very fast
  'gemini-2.0-flash-001',
  'gemini-1.5-flash',                // Fast and reliable
  'gemini-1.5-flash-001',
  'gemini-flash-latest',
  'gemini-2.5-flash',
  'gemini-2.5-flash-lite',
  'gemini-1.5-pro',                  // Slower, more capable - use as last resort
  'gemini-pro-latest'
];

$versions = ['v1','v1beta'];
$modelInventory = gemini_load_model_inventory($versions, $API_KEY);

// Build ordered candidate list (version + model) preserving preference
$attempts = [];
foreach($preference as $candidate){
  foreach($versions as $ver){
    if(isset($modelInventory[$ver]) && !empty($modelInventory[$ver]) && in_array($candidate, $modelInventory[$ver], true)){
      $attempts[] = [$ver,$candidate];
      break; // do not add duplicate for other versions
    }
  }
}
// Fallback: if list empty (listing failed) just brute-force with preference across versions
if(empty($attempts)){
  foreach($versions as $ver){ foreach($preference as $cand){ $attempts[] = [$ver,$cand]; } }
}

// Optimized payload with generation config for faster, more concise responses
$payload = [
  'contents' => [[ 'role'=>'user','parts'=>[[ 'text'=>$prompt ]] ]],
  'generationConfig' => [
    'temperature' => 0.7,           // Balanced creativity/consistency
    'topK' => 20,                   // Reduced from default 40 for faster generation
    'topP' => 0.9,                  // Slightly reduced for faster response
    'maxOutputTokens' => 512,       // Limit response length for speed (increased to 512 for better answers)
    'candidateCount' => 1           // Only generate one response
  ]
];

$totalCandidates = count($attempts);
$maxAllowedAttempts = max(1, $totalCandidates * GEMINI_MAX_RETRY_ATTEMPTS);

if(!gemini_concurrency_acquire(GEMINI_CONCURRENCY_LIMIT)){
  gemini_log_attempt([
    'status' => 'concurrency_limit',
    'max_slots' => GEMINI_CONCURRENCY_LIMIT,
    'attempt_queue' => $totalCandidates
  ]);
  http_response_code(503);
  echo json_encode([
    'error' => 'Chatbot busy',
    'detail' => 'Maximum concurrency reached. Please retry shortly.'
  ]);
  exit;
}

$gateHeld = true;
$reply = null; $used = null; $apiVer = null; $lastError = null; $rawResponse = null; $httpCode = null;
$attemptIndex = 0; $attemptBudgetExceeded = false;

foreach($attempts as [$ver,$model]){
  $retryCount = 0;
  while($retryCount < GEMINI_MAX_RETRY_ATTEMPTS){
    if($attemptIndex >= $maxAllowedAttempts){
      $lastError = 'Attempt budget exceeded';
      $attemptBudgetExceeded = true;
      gemini_log_attempt([
        'status' => 'attempt_budget_exceeded',
        'max_attempts' => $maxAllowedAttempts,
        'attempt_index' => $attemptIndex,
        'model' => $model,
        'api_version' => $ver
      ]);
      break 2;
    }

    $attemptIndex++;
    $endpoint = 'https://generativelanguage.googleapis.com/' . $ver . '/models/' . rawurlencode($model) . ':generateContent?key=' . urlencode($API_KEY);
    $responseHeaders = [];
    $ch = curl_init($endpoint);
    curl_setopt_array($ch,[
      CURLOPT_POST => true,
      CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
      CURLOPT_POSTFIELDS => json_encode($payload),
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CONNECTTIMEOUT => 5,      // Reduced from 10 to 5 seconds
      CURLOPT_TIMEOUT => 20,            // Reduced from 40 to 20 seconds
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_SSL_VERIFYHOST => 2,
      CURLOPT_HEADERFUNCTION => function($ch, $header) use (&$responseHeaders){
        $len = strlen($header);
        $parts = explode(':', $header, 2);
        if(count($parts) === 2){
          $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
        return $len;
      }
    ]);
    if(defined('CURLOPT_TCP_KEEPALIVE')){
      curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
      if(defined('CURLOPT_TCP_KEEPIDLE')){
        curl_setopt($ch, CURLOPT_TCP_KEEPIDLE, 30);
      }
      if(defined('CURLOPT_TCP_KEEPINTVL')){
        curl_setopt($ch, CURLOPT_TCP_KEEPINTVL, 10);
      }
    }
    $resp = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = $errno ? curl_error($ch) : '';
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $rateHeaders = gemini_extract_rate_headers($responseHeaders);
    $logContext = [
      'model' => $model,
      'api_version' => $ver,
      'attempt_index' => $attemptIndex,
      'retry_count' => $retryCount,
      'http_code' => $httpCode
    ];
    if(!empty($rateHeaders)){
      $logContext['rate_limit'] = $rateHeaders;
    }

    if($errno){
      $lastError = 'Curl: ' . ($err ?: ('errno ' . $errno));
      $logContext['error'] = $lastError;
      $retryableCurlCodes = [
        defined('CURLE_OPERATION_TIMEOUTED') ? CURLE_OPERATION_TIMEOUTED : 28,
        defined('CURLE_COULDNT_CONNECT') ? CURLE_COULDNT_CONNECT : 7,
        defined('CURLE_COULDNT_RESOLVE_HOST') ? CURLE_COULDNT_RESOLVE_HOST : 6,
        defined('CURLE_SEND_ERROR') ? CURLE_SEND_ERROR : 55,
        defined('CURLE_RECV_ERROR') ? CURLE_RECV_ERROR : 56,
        defined('CURLE_GOT_NOTHING') ? CURLE_GOT_NOTHING : 52
      ];
      $retryableCurl = in_array($errno, $retryableCurlCodes, true);
      if($retryableCurl && ($retryCount + 1) < GEMINI_MAX_RETRY_ATTEMPTS){
        $retryCount++;
        $retryAfter = gemini_parse_retry_after($responseHeaders['retry-after'] ?? null);
        $delay = gemini_calculate_backoff($retryCount, $retryAfter);
        $logContext['status'] = 'retrying';
        $logContext['retry_after'] = $retryAfter;
        $logContext['backoff_seconds'] = $delay;
        gemini_log_attempt($logContext);
        if($delay > 0){
          usleep((int)round($delay * 1000000));
        }
        continue;
      }
      $logContext['status'] = $retryableCurl ? 'retry_limit_reached' : 'curl_error';
      gemini_log_attempt($logContext);
      break;
    }

    if($httpCode >= 200 && $httpCode < 300){
      $decoded = json_decode($resp, true);
      if(!$decoded){
        $lastError = 'JSON decode failure';
        $rawResponse = $resp;
        if(($retryCount + 1) < GEMINI_MAX_RETRY_ATTEMPTS){
          $retryCount++;
          $delay = gemini_calculate_backoff($retryCount, null);
          $logContext['status'] = 'retrying';
          $logContext['backoff_seconds'] = $delay;
          $logContext['note'] = 'decode_failure';
          gemini_log_attempt($logContext);
          if($delay > 0){
            usleep((int)round($delay * 1000000));
          }
          continue;
        }
        $logContext['status'] = 'decode_error';
        gemini_log_attempt($logContext);
        break;
      }
      if(isset($decoded['error'])){
        $lastError = 'API ' . ($decoded['error']['message'] ?? 'unknown');
        $rawResponse = json_encode($decoded['error']);
        $logContext['status'] = 'api_error';
        $logContext['response_snippet'] = $rawResponse ? substr($rawResponse, 0, 300) : null;
        gemini_log_attempt($logContext);
        break;
      }
      $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;
      if(!$text){
        $lastError = 'No text in response';
        $rawResponse = $resp;
        if(($retryCount + 1) < GEMINI_MAX_RETRY_ATTEMPTS){
          $retryCount++;
          $delay = gemini_calculate_backoff($retryCount, null);
          $logContext['status'] = 'retrying';
          $logContext['backoff_seconds'] = $delay;
          $logContext['note'] = 'empty_response';
          gemini_log_attempt($logContext);
          if($delay > 0){
            usleep((int)round($delay * 1000000));
          }
          continue;
        }
        $logContext['status'] = 'empty_response';
        gemini_log_attempt($logContext);
        break;
      }

      $reply = $text;
      $used = $model;
      $apiVer = $ver;
      $rawResponse = $resp;
      $logContext['status'] = 'success';
      if(isset($decoded['responseId'])){
        $logContext['response_id'] = $decoded['responseId'];
      }
      if(isset($decoded['usageMetadata']['totalTokenCount'])){
        $logContext['total_tokens'] = $decoded['usageMetadata']['totalTokenCount'];
      }
      gemini_log_attempt($logContext);
      break 2;
    }

    $rawResponse = $resp;
    $retryAfter = gemini_parse_retry_after($responseHeaders['retry-after'] ?? null);
    $isRetryableHttp = ($httpCode === 408) || ($httpCode === 429) || ($httpCode >= 500 && $httpCode < 600 && $httpCode !== 501 && $httpCode !== 505);
    if($isRetryableHttp && ($retryCount + 1) < GEMINI_MAX_RETRY_ATTEMPTS){
      $retryCount++;
      $delay = gemini_calculate_backoff($retryCount, $retryAfter);
      $logContext['status'] = 'retrying';
      $logContext['retry_after'] = $retryAfter;
      $logContext['backoff_seconds'] = $delay;
      $logContext['response_snippet'] = $resp ? substr($resp, 0, 300) : null;
      gemini_log_attempt($logContext);
      if($delay > 0){
        usleep((int)round($delay * 1000000));
      }
      continue;
    }

    $lastError = 'HTTP ' . $httpCode;
    $logContext['status'] = $isRetryableHttp ? 'retry_limit_reached' : 'http_error';
    $logContext['response_snippet'] = $resp ? substr($resp, 0, 300) : null;
    gemini_log_attempt($logContext);
    break;
  }
}

if($gateHeld){
  gemini_concurrency_release();
  $gateHeld = false;
}

if($reply===null){
  gemini_log_attempt([
    'status' => 'exhausted_attempts',
    'last_error' => $lastError,
    'http_code' => $httpCode,
    'attempt_index' => $attemptIndex,
    'attempt_budget' => $maxAllowedAttempts,
    'attempt_budget_exceeded' => $attemptBudgetExceeded,
    'attempted_models' => array_map(function($t){ return $t[0] . ':' . $t[1]; }, $attempts)
  ]);
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