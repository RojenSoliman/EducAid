<?php
class HeaderThemeService {
    private $connection;
    private $municipality_id;

    public function __construct($connection, $municipality_id = 1) {
        $this->connection = $connection;
        $this->municipality_id = $municipality_id;
    }

    public function getDefaultSettings(): array {
        return [
            'header_bg_color' => '#ffffff',
            'header_border_color' => '#e1e7e3',
            'header_text_color' => '#2e7d32',
            'header_icon_color' => '#2e7d32',
            'header_hover_bg' => '#e9f5e9',
            'header_hover_icon_color' => '#1b5e20'
        ];
    }

    public function getCurrentSettings(): array {
        $query = "SELECT * FROM header_theme_settings WHERE municipality_id = $1 LIMIT 1";
        $res = @pg_query_params($this->connection, $query, [$this->municipality_id]);
        if ($res && ($row = pg_fetch_assoc($res))) {
            return array_merge($this->getDefaultSettings(), $row);
        }
        return $this->getDefaultSettings();
    }

    private function isValidHex($color): bool {
        return is_string($color) && preg_match('/^#[0-9A-Fa-f]{6}$/', $color);
    }

    public function sanitize(array $data): array {
        $allowed = array_keys($this->getDefaultSettings());
        $out = [];
        foreach ($allowed as $field) {
            if (isset($data[$field])) {
                $val = trim($data[$field]);
                $out[$field] = $val;
            }
        }
        return $out;
    }

    public function validate(array $data): array {
        $errors = [];
        foreach ($data as $k => $v) {
            if (!$this->isValidHex($v)) {
                $errors[$k] = 'Invalid hex color';
            }
        }
        return $errors;
    }

    public function save(array $data, int $admin_id): array {
        $sanitized = $this->sanitize($data);
        $errors = $this->validate($sanitized);
        if ($errors) return ['success' => false, 'errors' => $errors];

        // Build upsert
        $defaults = $this->getDefaultSettings();
        $final = array_merge($defaults, $sanitized);
        $query = "INSERT INTO header_theme_settings (
            municipality_id, header_bg_color, header_border_color, header_text_color,
            header_icon_color, header_hover_bg, header_hover_icon_color, updated_by
          ) VALUES ($1,$2,$3,$4,$5,$6,$7,$8)
          ON CONFLICT (municipality_id) DO UPDATE SET
            header_bg_color = EXCLUDED.header_bg_color,
            header_border_color = EXCLUDED.header_border_color,
            header_text_color = EXCLUDED.header_text_color,
            header_icon_color = EXCLUDED.header_icon_color,
            header_hover_bg = EXCLUDED.header_hover_bg,
            header_hover_icon_color = EXCLUDED.header_hover_icon_color,
            updated_at = CURRENT_TIMESTAMP,
            updated_by = EXCLUDED.updated_by";
        $params = [
            $this->municipality_id,
            $final['header_bg_color'],
            $final['header_border_color'],
            $final['header_text_color'],
            $final['header_icon_color'],
            $final['header_hover_bg'],
            $final['header_hover_icon_color'],
            $admin_id
        ];
        $ok = pg_query_params($this->connection, $query, $params);
        if ($ok) {
            $this->logActivity($admin_id, $sanitized);
            return ['success' => true];
        }
        return ['success' => false, 'errors' => ['database' => 'Failed to save header theme settings']];
    }

    private function logActivity(int $admin_id, array $changed) : void {
        $exists = pg_query_params($this->connection, "SELECT 1 FROM information_schema.tables WHERE table_name=$1", ['admin_activity_log']);
        if (!$exists || !pg_fetch_row($exists)) return;
        $log = "INSERT INTO admin_activity_log (admin_id, action, details, ip_address, timestamp) VALUES ($1,$2,$3,$4,CURRENT_TIMESTAMP)";
        $details = json_encode(['header_theme_updated' => $changed, 'municipality_id' => $this->municipality_id]);
        @pg_query_params($this->connection, $log, [
            $admin_id,
            'Header Theme Updated',
            $details,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
    }
}
