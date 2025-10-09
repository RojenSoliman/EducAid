<?php
/**
 * Contact Content Helper
 * Loads saved content blocks from database for the Contact page
 * Used for inline editing by Super Admin
 */

// Ensure DB connection exists
if (!isset($connection)) {
    @include_once __DIR__ . '/../../config/database.php';
}

if (!function_exists('contact_sanitize_html')) {
    function contact_sanitize_html($html) {
        $html = preg_replace('#<script[^>]*>.*?</script>#is', '', $html);
        $html = preg_replace('/on[a-zA-Z]+\s*=\s*"[^"]*"/i', '', $html);
        $html = preg_replace("/on[a-zA-Z]+\s*=\s*'[^']*'/i", '', $html);
        $html = preg_replace('/javascript:/i', '', $html);
        return $html;
    }
}

// ALWAYS load blocks data (even if functions already exist) so fresh data is available
$CONTACT_SAVED_BLOCKS = [];
if (isset($connection)) {
    // Query may fail if table not yet created; that's fine (empty defaults used)
    $res = @pg_query($connection, "SELECT block_key, html, text_color, bg_color FROM contact_content_blocks WHERE municipality_id=1");
    if ($res) {
        while ($r = pg_fetch_assoc($res)) {
            $CONTACT_SAVED_BLOCKS[$r['block_key']] = $r;
        }
        pg_free_result($res);
    }
}


// Only define functions once
if (!function_exists('contact_block')) {
    /**
     * Output an editable content block
     * @param string $key Unique identifier for this block
     * @param string $default Default HTML content if not in database
     * @param string $tag HTML tag to wrap content (default: div)
     * @param string $classes Additional CSS classes
     * @return void
     */
    function contact_block($key, $default = '', $tag = 'div', $classes = '') {
        global $CONTACT_SAVED_BLOCKS, $IS_EDIT_MODE;
        
        // Get content from database or use default
        $content = $default; // Start with default
        if (isset($CONTACT_SAVED_BLOCKS[$key]) && isset($CONTACT_SAVED_BLOCKS[$key]['html'])) {
            $saved_html = contact_sanitize_html($CONTACT_SAVED_BLOCKS[$key]['html']);
            // Only use saved content if it's not empty after sanitization
            if (trim($saved_html) !== '') {
                $content = $saved_html;
            }
        }
        
        // Ensure content is not empty
        if (empty(trim($content))) {
            $content = $default;
        }
        
        $editAttr = ($IS_EDIT_MODE ?? false) ? ' contenteditable="true" data-lp-key="' . htmlspecialchars($key) . '"' : '';
        $editClass = ($IS_EDIT_MODE ?? false) ? ' editable-content' : '';
        
        echo "<{$tag} class=\"{$classes}{$editClass}\"{$editAttr}>";
        echo $content;
        echo "</{$tag}>";
    }
}

if (!function_exists('contact_block_style')) {
    /**
     * Get inline styles for a content block
     * @param string $key Block identifier
     * @return string CSS style attribute
     */
    function contact_block_style($key) {
        global $CONTACT_SAVED_BLOCKS;
        
        if (!isset($CONTACT_SAVED_BLOCKS[$key])) return '';
        
        $r = $CONTACT_SAVED_BLOCKS[$key];
        $styles = [];
        
        if (!empty($r['text_color'])) {
            $styles[] = 'color:' . $r['text_color'];
        }
        if (!empty($r['bg_color']) && $r['bg_color'] !== 'transparent') {
            $styles[] = 'background-color:' . $r['bg_color'];
        }
        
        return $styles ? ' style="' . implode(';', $styles) . '"' : '';
    }
}

