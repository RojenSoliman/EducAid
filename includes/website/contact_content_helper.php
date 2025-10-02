<?php
/**
 * Contact Content Helper
 * Loads saved content blocks from database for the Contact page
 * Used for inline editing by Super Admin
 */

if (!isset($connection)) {
    require_once __DIR__ . '/../config/database.php';
}

// Global to store loaded blocks
$CONTACT_SAVED_BLOCKS = [];

// Load all saved blocks from database (always, not just in edit mode)
// This ensures the page always shows the latest saved content
try {
    $stmt = $connection->prepare("
        SELECT block_key, html, text_color, bg_color 
        FROM contact_content_blocks 
        WHERE municipality_id = 1
    ");
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($results as $row) {
        $CONTACT_SAVED_BLOCKS[$row['block_key']] = [
            'html' => $row['html'],
            'text_color' => $row['text_color'],
            'bg_color' => $row['bg_color']
        ];
    }
} catch (Exception $e) {
    error_log("Contact content helper error: " . $e->getMessage());
}

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
    
    $content = $CONTACT_SAVED_BLOCKS[$key]['html'] ?? $default;
    $editAttr = ($IS_EDIT_MODE ?? false) ? ' contenteditable="true" data-block-key="' . htmlspecialchars($key) . '"' : '';
    $editClass = ($IS_EDIT_MODE ?? false) ? ' editable-content' : '';
    
    echo "<{$tag} class=\"{$classes}{$editClass}\"{$editAttr}>";
    echo $content;
    echo "</{$tag}>";
}

/**
 * Get inline styles for a content block
 * @param string $key Block identifier
 * @return string CSS style attribute
 */
function contact_block_style($key) {
    global $CONTACT_SAVED_BLOCKS;
    
    $styles = [];
    if (isset($CONTACT_SAVED_BLOCKS[$key]['text_color'])) {
        $styles[] = 'color: ' . $CONTACT_SAVED_BLOCKS[$key]['text_color'];
    }
    if (isset($CONTACT_SAVED_BLOCKS[$key]['bg_color']) && $CONTACT_SAVED_BLOCKS[$key]['bg_color'] !== 'transparent') {
        $styles[] = 'background-color: ' . $CONTACT_SAVED_BLOCKS[$key]['bg_color'];
    }
    
    return !empty($styles) ? ' style="' . implode('; ', $styles) . '"' : '';
}

/**
 * Sanitize HTML content (basic sanitization)
 * @param string $html Raw HTML
 * @return string Sanitized HTML
 */
function contact_sanitize_html($html) {
    // Allow basic HTML tags
    $allowed_tags = '<p><br><strong><em><u><a><ul><ol><li><h1><h2><h3><h4><h5><h6><span><div>';
    return strip_tags($html, $allowed_tags);
}
