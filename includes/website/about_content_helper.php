<?php
// About Page content helper - isolates about page editable blocks in separate tables
// Functions: about_block($key,$defaultHtml), about_block_style($key)
// Sanitizer: about_sanitize_html($html)

if (function_exists('about_block')) { return; }
if (!isset($connection)) { @include_once __DIR__ . '/../../config/database.php'; }

if (!function_exists('about_sanitize_html')) {
  function about_sanitize_html($html){
    $html=preg_replace('#<script[^>]*>.*?</script>#is','',$html);
    $html=preg_replace('/on[a-zA-Z]+\s*=\s*"[^"]*"/i','',$html);
    $html=preg_replace("/on[a-zA-Z]+\s*=\s*'[^']*'/i",'', $html);
    $html=preg_replace('/javascript:/i','',$html);
    return $html;
  }
}

$ABOUT_SAVED_BLOCKS = [];
if (isset($connection)) {
  // Query may fail if table not yet created; that's fine (empty defaults used)
  $res = @pg_query($connection, "SELECT block_key, html, text_color, bg_color FROM about_content_blocks WHERE municipality_id=1");
  if ($res) { while($r=pg_fetch_assoc($res)) { $ABOUT_SAVED_BLOCKS[$r['block_key']] = $r; } pg_free_result($res); }
}

function about_block($key,$defaultHtml){
  global $ABOUT_SAVED_BLOCKS; if(isset($ABOUT_SAVED_BLOCKS[$key])){ $h=about_sanitize_html($ABOUT_SAVED_BLOCKS[$key]['html']); if($h!=='') return $h; } return $defaultHtml;
}
function about_block_style($key){
  global $ABOUT_SAVED_BLOCKS; if(!isset($ABOUT_SAVED_BLOCKS[$key])) return ''; $r=$ABOUT_SAVED_BLOCKS[$key]; $s=[]; if(!empty($r['text_color'])) $s[]='color:'.$r['text_color']; if(!empty($r['bg_color'])) $s[]='background-color:'.$r['bg_color']; return $s? ' style="'.implode(';',$s).'"':'';
}
