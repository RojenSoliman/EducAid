<?php
// How It Works Page content helper - isolates how-it-works page editable blocks in separate tables
// Functions: hiw_block($key,$defaultHtml), hiw_block_style($key)
// Sanitizer: hiw_sanitize_html($html)

// Ensure DB connection exists
if (!isset($connection)) { @include_once __DIR__ . '/../../config/database.php'; }

if (!function_exists('hiw_sanitize_html')) {
  function hiw_sanitize_html($html){
    $html=preg_replace('#<script[^>]*>.*?</script>#is','',$html);
    $html=preg_replace('/on[a-zA-Z]+\s*=\s*"[^"]*"/i','',$html);
    $html=preg_replace("/on[a-zA-Z]+\s*=\s*'[^']*'/i",'', $html);
    $html=preg_replace('/javascript:/i','',$html);
    return $html;
  }
}

// ALWAYS load blocks data (even if functions already exist) so fresh data is available
$HIW_SAVED_BLOCKS = [];
if (isset($connection)) {
  // Query may fail if table not yet created; that's fine (empty defaults used)
  $res = @pg_query($connection, "SELECT block_key, html, text_color, bg_color FROM how_it_works_content_blocks WHERE municipality_id=1");
  if ($res) { while($r=pg_fetch_assoc($res)) { $HIW_SAVED_BLOCKS[$r['block_key']] = $r; } pg_free_result($res); }
}

// Only define functions once
if (!function_exists('hiw_block')) {
  function hiw_block($key,$defaultHtml){
    global $HIW_SAVED_BLOCKS; if(isset($HIW_SAVED_BLOCKS[$key])){ $h=hiw_sanitize_html($HIW_SAVED_BLOCKS[$key]['html']); if($h!=='') return $h; } return $defaultHtml;
  }
}
if (!function_exists('hiw_block_style')) {
  function hiw_block_style($key){
    global $HIW_SAVED_BLOCKS; if(!isset($HIW_SAVED_BLOCKS[$key])) return ''; $r=$HIW_SAVED_BLOCKS[$key]; $s=[]; if(!empty($r['text_color'])) $s[]='color:'.$r['text_color']; if(!empty($r['bg_color'])) $s[]='background-color:'.$r['bg_color']; return $s? ' style="'.implode(';',$s).'"':'';
  }
}
