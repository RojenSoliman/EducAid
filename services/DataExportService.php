<?php
/**
 * DataExportService
 * Compiles a student's personal data into a downloadable ZIP archive of JSON files.
 */
class DataExportService {
    private $db;
    private $baseExportDir;

    public function __construct($dbConnection, $baseExportDir = null) {
        $this->db = $dbConnection;
        $this->baseExportDir = $baseExportDir ?: __DIR__ . '/../data/exports';
    }

    /**
     * Ensure export directory exists
     */
    private function ensureExportDir() {
        if (!is_dir($this->baseExportDir)) {
            mkdir($this->baseExportDir, 0755, true);
        }
    }

    /**
     * Generate a new secure download token
     */
    public function generateToken(int $bytes = 32): string {
        return bin2hex(random_bytes($bytes));
        }

    /**
     * Build data export for a given student and return file info
     * Returns: ['success' => bool, 'zip_path' => string, 'size' => int, 'error' => string|null]
     */
    public function buildExport(string $studentId) : array {
        $this->ensureExportDir();

        // Create a working directory
        $ts = date('Ymd_His');
        $workDir = $this->baseExportDir . "/{$studentId}_{$ts}";
        if (!mkdir($workDir, 0755, true)) {
            return ['success' => false, 'error' => 'Failed to create export work directory'];
        }

        try {
            // Collect datasets
            $datasets = [
                'profile' => $this->getStudentProfile($studentId),
                'login_history' => $this->getLoginHistory($studentId),
                'active_sessions' => $this->getActiveSessions($studentId),
                'notifications' => $this->getNotifications($studentId),
                'documents' => $this->getDocuments($studentId),
                'audit_logs' => $this->getAuditLogs($studentId)
            ];

            // Write each dataset as JSON
            foreach ($datasets as $name => $data) {
                $this->writeJson($workDir . "/{$name}.json", $data);
            }

            // Zip it up
            $zipPath = $workDir . '.zip';
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
                return ['success' => false, 'error' => 'Failed to create ZIP'];
            }
            // Add files from workDir
            $files = scandir($workDir);
            foreach ($files as $f) {
                if ($f === '.' || $f === '..') continue;
                $zip->addFile($workDir . '/' . $f, $f);
            }
            $zip->close();

            // Compute size
            $size = file_exists($zipPath) ? filesize($zipPath) : 0;

            // Clean up workDir (leave only ZIP)
            $this->rrmdir($workDir);

            return ['success' => true, 'zip_path' => $zipPath, 'size' => $size, 'error' => null];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function writeJson(string $path, $data) {
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function rrmdir($dir) {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $p = $dir . '/' . $item;
            if (is_dir($p)) { $this->rrmdir($p); }
            else { @unlink($p); }
        }
        @rmdir($dir);
    }

    private function getStudentProfile(string $studentId) {
        $res = pg_query_params($this->db, "SELECT * FROM students WHERE student_id = $1 LIMIT 1", [$studentId]);
        return $res ? (pg_fetch_assoc($res) ?: []) : [];
    }

    private function getLoginHistory(string $studentId) {
        $res = pg_query_params($this->db, "SELECT * FROM student_login_history WHERE student_id = $1 ORDER BY login_time DESC", [$studentId]);
        return $res ? (pg_fetch_all($res) ?: []) : [];
    }

    private function getActiveSessions(string $studentId) {
        $res = pg_query_params($this->db, "SELECT * FROM student_active_sessions WHERE student_id = $1 ORDER BY is_current DESC, last_activity DESC", [$studentId]);
        return $res ? (pg_fetch_all($res) ?: []) : [];
    }

    private function getNotifications(string $studentId) {
        // student_notifications table may exist
        $check = @pg_query($this->db, "SELECT to_regclass('public.student_notifications') AS tbl");
        $has = false;
        if ($check) { $row = pg_fetch_assoc($check); $has = ($row && $row['tbl'] !== null); }
        if (!$has) return [];
        $res = pg_query_params($this->db, "SELECT * FROM student_notifications WHERE student_id = $1 ORDER BY created_at DESC NULLS LAST", [$studentId]);
        return $res ? (pg_fetch_all($res) ?: []) : [];
    }

    private function getDocuments(string $studentId) {
        $check = @pg_query($this->db, "SELECT to_regclass('public.documents') AS tbl");
        $has = false;
        if ($check) { $row = pg_fetch_assoc($check); $has = ($row && $row['tbl'] !== null); }
        if (!$has) return [];
        $res = pg_query_params($this->db, "SELECT document_id, document_type_code, document_type_name, file_name, file_path, file_size_bytes, verification_status, upload_year, status, last_modified FROM documents WHERE student_id = $1 ORDER BY last_modified DESC NULLS LAST", [$studentId]);
        return $res ? (pg_fetch_all($res) ?: []) : [];
    }

    private function getAuditLogs(string $studentId) {
        $check = @pg_query($this->db, "SELECT to_regclass('public.audit_logs') AS tbl");
        $has = false;
        if ($check) { $row = pg_fetch_assoc($check); $has = ($row && $row['tbl'] !== null); }
        if (!$has) return [];
        $res = pg_query_params($this->db, "SELECT * FROM audit_logs WHERE user_type = 'student' AND (user_id::text = $1 OR username = $1) ORDER BY created_at DESC NULLS LAST", [$studentId]);
        return $res ? (pg_fetch_all($res) ?: []) : [];
    }
}
