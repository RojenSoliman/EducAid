<?php
// SidebarThemeService.php - Handle sidebar theme settings

class SidebarThemeService {
    private $connection;
    
    public function __construct($connection) {
        $this->connection = $connection;
    }
    
    /**
     * Get current sidebar theme settings
     */
    public function getCurrentSettings($municipalityId = 1) {
        $query = "SELECT * FROM sidebar_theme_settings WHERE municipality_id = $1 LIMIT 1";
        $result = pg_query_params($this->connection, $query, [$municipalityId]);
        
        if ($result && ($row = pg_fetch_assoc($result))) {
            return $row;
        }
        
        return $this->getDefaultSettings();
    }
    
    /**
     * Get default sidebar theme settings
     */
    public function getDefaultSettings() {
        return [
            'sidebar_bg_start' => '#f8f9fa',
            'sidebar_bg_end' => '#ffffff',
            'sidebar_border_color' => '#dee2e6',
            'nav_text_color' => '#212529',
            'nav_icon_color' => '#6c757d',
            'nav_hover_bg' => '#e9ecef',
            'nav_hover_text' => '#212529',
            'nav_active_bg' => '#0d6efd',
            'nav_active_text' => '#ffffff',
            'profile_avatar_bg_start' => '#0d6efd',
            'profile_avatar_bg_end' => '#0b5ed7',
            'profile_name_color' => '#212529',
            'profile_role_color' => '#6c757d',
            'profile_border_color' => '#dee2e6',
            'submenu_bg' => '#f8f9fa',
            'submenu_text_color' => '#495057',
            'submenu_hover_bg' => '#e9ecef',
            'submenu_active_bg' => '#e7f3ff',
            'submenu_active_text' => '#0d6efd'
        ];
    }
    
    /**
     * Validate color values
     */
    public function validateSettings($settings) {
        $errors = [];
        $colorFields = [
            'sidebar_bg_start', 'sidebar_bg_end', 'sidebar_border_color',
            'nav_text_color', 'nav_icon_color', 'nav_hover_bg', 'nav_hover_text',
            'nav_active_bg', 'nav_active_text', 'profile_avatar_bg_start',
            'profile_avatar_bg_end', 'profile_name_color', 'profile_role_color',
            'profile_border_color', 'submenu_bg', 'submenu_text_color',
            'submenu_hover_bg', 'submenu_active_bg', 'submenu_active_text'
        ];
        
        foreach ($colorFields as $field) {
            if (isset($settings[$field])) {
                $color = $settings[$field];
                if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
                    $errors[$field] = 'Invalid color format. Use #RRGGBB format.';
                }
            }
        }
        
        return $errors;
    }
    
    /**
     * Sanitize settings
     */
    public function sanitizeSettings($settings) {
        $sanitized = [];
        $allowedFields = [
            'sidebar_bg_start', 'sidebar_bg_end', 'sidebar_border_color',
            'nav_text_color', 'nav_icon_color', 'nav_hover_bg', 'nav_hover_text',
            'nav_active_bg', 'nav_active_text', 'profile_avatar_bg_start',
            'profile_avatar_bg_end', 'profile_name_color', 'profile_role_color',
            'profile_border_color', 'submenu_bg', 'submenu_text_color',
            'submenu_hover_bg', 'submenu_active_bg', 'submenu_active_text'
        ];
        
        foreach ($allowedFields as $field) {
            if (isset($settings[$field])) {
                $sanitized[$field] = strtolower(trim($settings[$field]));
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Update sidebar theme settings
     */
    public function updateSettings($settings, $municipalityId = 1) {
        $sanitized = $this->sanitizeSettings($settings);
        $errors = $this->validateSettings($sanitized);
        
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }
        
        // Check if settings exist
        $existsQuery = "SELECT id FROM sidebar_theme_settings WHERE municipality_id = $1";
        $existsResult = pg_query_params($this->connection, $existsQuery, [$municipalityId]);
        
        if ($existsResult && pg_num_rows($existsResult) > 0) {
            // Update existing settings
            $updateFields = [];
            $values = [];
            $paramIndex = 1;
            
            foreach ($sanitized as $field => $value) {
                $updateFields[] = "$field = \$$paramIndex";
                $values[] = $value;
                $paramIndex++;
            }
            
            $values[] = $municipalityId;
            $updateQuery = "UPDATE sidebar_theme_settings SET " . implode(', ', $updateFields) . " WHERE municipality_id = \$$paramIndex";
            $result = pg_query_params($this->connection, $updateQuery, $values);
        } else {
            // Insert new settings
            $fields = array_keys($sanitized);
            $fields[] = 'municipality_id';
            $values = array_values($sanitized);
            $values[] = $municipalityId;
            
            $placeholders = [];
            for ($i = 1; $i <= count($values); $i++) {
                $placeholders[] = "\$$i";
            }
            
            $insertQuery = "INSERT INTO sidebar_theme_settings (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
            $result = pg_query_params($this->connection, $insertQuery, $values);
        }
        
        if ($result) {
            $this->logActivity('Sidebar theme settings updated', $sanitized);
            return ['success' => true];
        } else {
            return ['success' => false, 'errors' => ['database' => 'Failed to save settings']];
        }
    }
    
    /**
     * Log activity
     */
    private function logActivity($action, $details) {
        if (!isset($_SESSION['admin_id'])) return;
        
        $logQuery = "INSERT INTO admin_activity_log (admin_id, action, details, ip_address) VALUES ($1, $2, $3, $4)";
        $logDetails = json_encode($details);
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        pg_query_params($this->connection, $logQuery, [
            $_SESSION['admin_id'],
            $action,
            $logDetails,
            $ipAddress
        ]);
    }
}