<?php
/**
 * Admin-only reCAPTCHA v3 diagnostics & log viewer
 */
include_once '../../config/database.php';
include_once '../../config/env.php';
include_once '../../config/validate_env.php';
session_start();

// Basic auth gate (adjust according to your existing admin auth mechanism)
if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$logFile = __DIR__ . '/../../data/security_verifications.log';
$entries = [];
if (is_readable($logFile)) {
    $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    // Only keep last 500 to avoid memory spikes
    $lines = array_slice($lines, -500);
    foreach ($lines as $ln) {
        $j = json_decode($ln, true);
        if (is_array($j)) { $entries[] = $j; }
    }
}

$total = count($entries);
$byAction = [];
$failReasons = [];
$scoreSum = 0; $scoreCount = 0;
$recent = [];
$now = time();
foreach ($entries as $e) {
    $act = $e['action'] ?? 'unknown';
    $byAction[$act] = ($byAction[$act] ?? 0) + 1;
    if (($e['result'] ?? '') === 'fail') {
        $r = $e['reason'] ?? 'other';
        $failReasons[$r] = ($failReasons[$r] ?? 0) + 1;
    }
    if (isset($e['score'])) { $scoreSum += (float)$e['score']; $scoreCount++; }
    // collect last 20 chronological
    $recent[] = $e;
}
$avgScore = $scoreCount ? round($scoreSum / $scoreCount, 3) : 0;

// Sort recent descending by timestamp
usort($recent, function($a,$b){ return strcmp($b['ts'] ?? '', $a['ts'] ?? ''); });
$recent = array_slice($recent, 0, 20);

function pct($part, $whole) { return $whole ? round(($part/$whole)*100,1) : 0; }

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>reCAPTCHA Diagnostics</title>
<link rel="stylesheet" href="../../assets/css/bootstrap.min.css" />
<style>
 body { padding:20px; font-family: system-ui, Arial, sans-serif; }
 .metric { background:#f8f9fa; padding:16px; border-radius:8px; border:1px solid #e2e6ea; }
 .metric h2 { font-size:1.1rem; margin:0 0 6px; text-transform:uppercase; letter-spacing:.5px; }
 code { font-size:.8rem; }
 table.table-sm td, table.table-sm th { padding:.4rem .5rem; }
 .badge-score { font-family:monospace; }
 .result-ok { color:#198754; font-weight:600; }
 .result-fail { color:#dc3545; font-weight:600; }
</style>
</head>
<body>
<h1 class="mb-4">reCAPTCHA v3 Diagnostics</h1>
<div class="row g-3 mb-4">
  <div class="col-md-3"><div class="metric"><h2>Total Entries</h2><div class="fs-4 fw-bold"><?php echo $total; ?></div></div></div>
  <div class="col-md-3"><div class="metric"><h2>Avg Score</h2><div class="fs-4 fw-bold"><?php echo $avgScore; ?></div></div></div>
  <div class="col-md-3"><div class="metric"><h2>Success %</h2><div class="fs-4 fw-bold"><?php
    $ok = 0; foreach($entries as $e){ if(($e['result'] ?? '')==='ok') $ok++; }
    echo $total? round(($ok/$total)*100,1):0; ?>%</div></div></div>
  <div class="col-md-3"><div class="metric"><h2>Failure %</h2><div class="fs-4 fw-bold"><?php echo $total? round(100 - (($ok/$total)*100),1):0; ?>%</div></div></div>
</div>

<h3>By Action</h3>
<table class="table table-bordered table-sm w-auto mb-4">
 <thead class="table-light"><tr><th>Action</th><th>Count</th><th>%</th></tr></thead>
 <tbody>
 <?php foreach($byAction as $act=>$cnt): ?>
  <tr><td><?php echo htmlspecialchars($act); ?></td><td><?php echo $cnt; ?></td><td><?php echo pct($cnt,$total); ?>%</td></tr>
 <?php endforeach; ?>
 <?php if (!$byAction): ?><tr><td colspan="3" class="text-muted">No data</td></tr><?php endif; ?>
 </tbody>
</table>

<h3>Failure Reasons</h3>
<table class="table table-bordered table-sm w-auto mb-4">
 <thead class="table-light"><tr><th>Reason</th><th>Count</th><th>% of total</th></tr></thead>
 <tbody>
 <?php foreach($failReasons as $reason=>$cnt): ?>
  <tr><td><?php echo htmlspecialchars($reason); ?></td><td><?php echo $cnt; ?></td><td><?php echo pct($cnt,$total); ?>%</td></tr>
 <?php endforeach; ?>
 <?php if(!$failReasons): ?><tr><td colspan="3" class="text-muted">No failures logged</td></tr><?php endif; ?>
 </tbody>
</table>

<h3>Most Recent (20)</h3>
<table class="table table-striped table-sm">
 <thead class="table-light"><tr>
  <th>Time (UTC)</th><th>Action</th><th>Result</th><th>Score</th><th>Reason</th><th>IP</th><th>Session</th><th>User Agent (truncated)</th>
 </tr></thead>
 <tbody>
 <?php foreach($recent as $e): ?>
  <tr>
    <td><code><?php echo htmlspecialchars($e['ts'] ?? ''); ?></code></td>
    <td><?php echo htmlspecialchars($e['action'] ?? ''); ?></td>
    <td class="<?php echo ($e['result'] ?? '')==='ok' ? 'result-ok':'result-fail'; ?>"><?php echo htmlspecialchars($e['result'] ?? ''); ?></td>
    <td><span class="badge bg-secondary badge-score"><?php echo isset($e['score'])?number_format($e['score'],3):''; ?></span></td>
    <td><?php echo htmlspecialchars($e['reason'] ?? ''); ?></td>
    <td><code><?php echo htmlspecialchars($e['ip'] ?? ''); ?></code></td>
    <td><code><?php echo htmlspecialchars($e['session'] ?? ''); ?></code></td>
    <td><small><?php echo htmlspecialchars($e['ua'] ?? ''); ?></small></td>
  </tr>
 <?php endforeach; ?>
 <?php if(!$recent): ?><tr><td colspan="8" class="text-muted">No entries</td></tr><?php endif; ?>
 </tbody>
</table>

<hr />
<form method="post" class="mb-3" onsubmit="return confirm('Rotate (archive + truncate) the log?');">
 <input type="hidden" name="rotate" value="1" />
 <button class="btn btn-sm btn-outline-danger">Rotate Log</button>
</form>
<?php
// Handle rotation (simple archive)
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['rotate'])) {
    $archiveName = $logFile . '.' . date('Ymd_His') . '.bak';
    if (is_readable($logFile) && filesize($logFile) > 0) {
        @copy($logFile, $archiveName);
        @file_put_contents($logFile, '');
        echo '<div class="alert alert-success">Log rotated. Reload page.</div>';
    } else {
        echo '<div class="alert alert-warning">Log empty or unreadable; nothing to rotate.</div>';
    }
}
?>
</body></html>
