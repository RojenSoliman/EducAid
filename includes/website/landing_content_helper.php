<?php
// Shared helper for inline editable content blocks (topbar, footer, landing page sections)
// Loads landing_content_blocks into memory and exposes lp_block(), lp_block_style(), lp_sanitize_html().
// Safe to include multiple times (guarded by function_exists checks).

if (function_exists('lp_block')) {
    // Already loaded in this request
    return;
}

// Ensure DB connection exists
if (!isset($connection)) {
    @include_once __DIR__ . '/../../config/database.php';
}

// Basic sanitizer (align with landing page logic) - only declare if not already present
if (!function_exists('lp_sanitize_html')) {
    function lp_sanitize_html($html) {
        $html = preg_replace('#<script[^>]*>.*?</script>#is','',$html);
        $html = preg_replace('/on[a-zA-Z]+\s*=\s*"[^"]*"/i','',$html);
        $html = preg_replace("/on[a-zA-Z]+\s*=\s*'[^']*'/i",'', $html);
        $html = preg_replace('/javascript:/i','',$html);
        return $html;
    }
}

// Load saved blocks
$LP_SAVED_BLOCKS = [];
if (isset($connection)) {
    $resBlocks = @pg_query($connection, "SELECT block_key, html, text_color, bg_color FROM landing_content_blocks WHERE municipality_id=1");
    if ($resBlocks) {
        while ($r = pg_fetch_assoc($resBlocks)) { $LP_SAVED_BLOCKS[$r['block_key']] = $r; }
        pg_free_result($resBlocks);
    }
}

if (!function_exists('lp_block')) {
    function lp_block($key, $defaultHtml) {
        global $LP_SAVED_BLOCKS;
        if (isset($LP_SAVED_BLOCKS[$key])) {
            $h = lp_sanitize_html($LP_SAVED_BLOCKS[$key]['html']);
            if ($h !== '') return $h;
        }
        return $defaultHtml;
    }
}

if (!function_exists('lp_block_style')) {
    function lp_block_style($key) {
        global $LP_SAVED_BLOCKS;
        if (!isset($LP_SAVED_BLOCKS[$key])) return '';
        $r = $LP_SAVED_BLOCKS[$key];
        $s = [];
        if (!empty($r['text_color'])) $s[] = 'color:'.$r['text_color'];
        if (!empty($r['bg_color'])) $s[] = 'background-color:'.$r['bg_color'];
        return $s ? ' style="'.implode(';',$s).'"' : '';
    }
}
