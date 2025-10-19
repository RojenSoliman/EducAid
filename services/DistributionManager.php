<?php
require_once __DIR__ . '/../config/database.php';

class DistributionManager {
    private $conn;
    
    public function __construct() {
        global $connection;
        $this->conn = $connection;
    }
    
    public function endDistribution($distributionId, $adminId, $compressNow = true) {
        try {
            pg_query($this->conn, "BEGIN");
            
            $checkResult = pg_query_params($this->conn, 
                "SELECT * FROM distributions WHERE distribution_id = $1", 
                [$distributionId]);
            
            if (!$checkResult || pg_num_rows($checkResult) === 0) {
                throw new Exception("Distribution not found");
            }
            
            $distribution = pg_fetch_assoc($checkResult);
            
            if (isset($distribution['status']) && $distribution['status'] === 'ended') {
                throw new Exception("Distribution is already ended");
            }
            
            // Update distribution status to ended
            pg_query_params($this->conn,
                "UPDATE distributions SET status = 'ended', ended_at = NOW() WHERE distribution_id = $1",
                [$distributionId]);
            
            // Set global distribution status to inactive
            pg_query($this->conn, "
                INSERT INTO config (key, value) VALUES ('distribution_status', 'inactive')
                ON CONFLICT (key) DO UPDATE SET value = 'inactive'
            ");
            
            pg_query($this->conn, "COMMIT");
            
            $result = [
                'success' => true,
                'message' => 'Distribution ended successfully and status set to inactive',
                'distribution_id' => $distributionId
            ];
            
            // Always compress files when ending distribution
            if ($compressNow) {
                require_once __DIR__ . '/FileCompressionService.php';
                $compressionService = new FileCompressionService();
                $compressionResult = $compressionService->compressDistribution($distributionId, $adminId);
                $result['compression'] = $compressionResult;
            }
            
            return $result;
            
        } catch (Exception $e) {
            pg_query($this->conn, "ROLLBACK");
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    public function getActiveDistributions() {
        // Check global distribution status from config
        $statusQuery = "SELECT value FROM config WHERE key = 'distribution_status'";
        $statusResult = pg_query($this->conn, $statusQuery);
        $statusRow = pg_fetch_assoc($statusResult);
        $globalStatus = $statusRow ? $statusRow['value'] : 'inactive';
        
        // If distribution is not active globally, return empty
        if (!in_array($globalStatus, ['preparing', 'active'])) {
            return [];
        }
        
        // Get current academic period from config or active slot
        $slotQuery = "SELECT academic_year, semester FROM signup_slots WHERE is_active = true LIMIT 1";
        $slotResult = pg_query($this->conn, $slotQuery);
        $slot = $slotResult ? pg_fetch_assoc($slotResult) : null;
        
        $academicYear = $slot['academic_year'] ?? null;
        $semester = $slot['semester'] ?? null;
        
        if (!$academicYear || !$semester) {
            $periodQuery = "SELECT key, value FROM config WHERE key IN ('current_academic_year', 'current_semester')";
            $periodResult = pg_query($this->conn, $periodQuery);
            while ($row = pg_fetch_assoc($periodResult)) {
                if ($row['key'] === 'current_academic_year' && !$academicYear) $academicYear = $row['value'];
                if ($row['key'] === 'current_semester' && !$semester) $semester = $row['value'];
            }
        }
        
        // Get all students with 'given' status (distributed aid)
        $studentsQuery = "SELECT student_id FROM students WHERE status = 'given'";
        $studentsResult = pg_query($this->conn, $studentsQuery);
        $studentIds = [];
        while ($row = pg_fetch_assoc($studentsResult)) {
            $studentIds[] = $row['student_id'];
        }
        
        if (empty($studentIds)) {
            return []; // No distributed students yet
        }
        
        // Scan actual files in uploads directory
        // Files are stored in shared folders, not per-student folders
        $uploadsPath = __DIR__ . '/../assets/uploads';
        $totalFiles = 0;
        $totalSize = 0;
        
        // Scan the enrollment_forms, indigency, letter_to_mayor folders for files matching our students
        $folders = ['student/enrollment_forms', 'student/indigency', 'student/letter_to_mayor'];
        
        error_log("DistributionManager: Scanning uploads for " . count($studentIds) . " students with 'given' status");
        
        foreach ($folders as $folder) {
            $folderPath = $uploadsPath . '/' . $folder;
            if (is_dir($folderPath)) {
                $files = glob($folderPath . '/*.*');
                error_log("DistributionManager: Found " . count($files) . " files in $folder");
                
                foreach ($files as $file) {
                    if (is_file($file)) {
                        // Check if file belongs to any of our 'given' students
                        $filename = basename($file);
                        $filenameLower = strtolower($filename);
                        foreach ($studentIds as $studentId) {
                            $studentIdLower = strtolower($studentId);
                            // Files are named like: GENERALTRIAS-2025-3-9YW3ST_Soliman_Rojen_...
                            // Use case-insensitive matching
                            if (strpos($filenameLower, $studentIdLower) !== false) {
                                $totalFiles++;
                                $size = filesize($file);
                                $totalSize += $size;
                                error_log("DistributionManager: Matched file $filename to student $studentId ($size bytes)");
                                break; // Move to next file
                            }
                        }
                    }
                }
            } else {
                error_log("DistributionManager: Folder $folder NOT FOUND");
            }
        }
        
        error_log("DistributionManager: Total files: $totalFiles, Total size: $totalSize bytes");

        
        // Get a unique distribution ID (or create one if needed)
        // Check if there's an existing active distribution record
        $distQuery = "SELECT distribution_id FROM distributions WHERE status = 'active' LIMIT 1";
        $distResult = pg_query($this->conn, $distQuery);
        
        if ($distResult && pg_num_rows($distResult) > 0) {
            $distRow = pg_fetch_assoc($distResult);
            $distributionId = $distRow['distribution_id'];
        } else {
            // Create a new distribution record for this cycle
            require_once __DIR__ . '/DistributionIdGenerator.php';
            $idGenerator = new DistributionIdGenerator($this->conn, 'GENERALTRIAS');
            $distributionId = $idGenerator->generateDistributionId();
            
            // Insert the distribution record
            $insertQuery = "INSERT INTO distributions (distribution_id, status, date_given) VALUES ($1, 'active', NOW())";
            pg_query_params($this->conn, $insertQuery, [$distributionId]);
        }
        
        // Return ONE distribution representing the current active cycle
        return [[
            'id' => $distributionId,
            'created_at' => date('Y-m-d H:i:s'),
            'status' => 'active',
            'year_level' => $academicYear,
            'semester' => $semester,
            'student_count' => count($studentIds),
            'file_count' => $totalFiles,
            'total_size' => $totalSize
        ]];
    }
    
    public function getEndedDistributions($includeArchived = true) {
        $archivedCondition = $includeArchived ? "" : "AND df.is_archived = false";
        
        $query = "SELECT 
                    d.distribution_id as id,
                    d.date_given as created_at,
                    d.status,
                    d.ended_at,
                    COALESCE(d.files_compressed, false) as files_compressed,
                    d.compression_date,
                    NULL::integer as year_level,
                    NULL::integer as semester,
                    COUNT(DISTINCT df.student_id) as student_count,
                    COUNT(df.file_id) as file_count,
                    COALESCE(SUM(df.file_size), 0) as original_size,
                    COALESCE(SUM(df.file_size), 0) as current_size,
                    0 as avg_compression_ratio
                 FROM distributions d
                 LEFT JOIN distribution_files df ON d.distribution_id = df.distribution_id
                 WHERE d.status = 'ended' $archivedCondition
                 GROUP BY d.distribution_id, d.date_given, d.status, d.ended_at, 
                          d.files_compressed, d.compression_date
                 ORDER BY d.ended_at DESC";
        
        $result = pg_query($this->conn, $query);
        return $result ? pg_fetch_all($result) ?: [] : [];
    }
    
    public function getAllDistributions() {
        $query = "SELECT 
                    d.distribution_id as id,
                    d.date_given as created_at,
                    COALESCE(d.status, 'active') as status,
                    d.ended_at,
                    COALESCE(d.files_compressed, false) as files_compressed,
                    d.compression_date,
                    NULL::integer as year_level,
                    NULL::integer as semester,
                    COUNT(DISTINCT df.student_id) as student_count,
                    COUNT(df.file_id) as file_count,
                    COALESCE(SUM(df.file_size), 0) as original_size,
                    COALESCE(SUM(df.file_size), 0) as current_size,
                    0 as avg_compression_ratio,
                    COUNT(CASE WHEN df.is_archived THEN 1 END) as archived_files_count
                 FROM distributions d
                 LEFT JOIN distribution_files df ON d.distribution_id = df.distribution_id
                 GROUP BY d.distribution_id, d.date_given, d.status, d.ended_at, 
                          d.files_compressed, d.compression_date
                 ORDER BY d.date_given DESC";
        
        $result = pg_query($this->conn, $query);
        return $result ? pg_fetch_all($result) ?: [] : [];
    }
    
    public function getCompressionStatistics() {
        $query = "SELECT 
                    COUNT(DISTINCT distribution_id) as total_distributions,
                    COUNT(DISTINCT CASE WHEN is_compressed = true THEN distribution_id END) as compressed_distributions,
                    COALESCE(SUM(file_size), 0) as total_original_size,
                    COALESCE(SUM(file_size), 0) as total_current_size,
                    0 as total_space_saved,
                    0 as avg_compression_ratio
                 FROM distribution_files
                 WHERE is_archived = false";
        
        $result = pg_query($this->conn, $query);
        $stats = $result ? pg_fetch_assoc($result) : null;
        
        if (!$stats) {
            return [
                'total_distributions' => 0,
                'compressed_distributions' => 0,
                'total_original_size' => 0,
                'total_current_size' => 0,
                'total_space_saved' => 0,
                'avg_compression_ratio' => 0,
                'compression_percentage' => 0
            ];
        }
        
        $stats['compression_percentage'] = 0;
        return $stats;
    }
    
    public function getRecentArchiveLog($limit = 10) {
        $query = "SELECT 
                    fal.*,
                    TRIM(CONCAT(COALESCE(a.first_name,''),' ',COALESCE(a.last_name,''))) as admin_name,
                    s.student_id as lrn,
                    s.first_name || ' ' || s.last_name as student_name,
                    d.date_given as distribution_date,
                    NULL::integer as year_level,
                    NULL::integer as semester
                 FROM file_archive_log fal
                 LEFT JOIN admins a ON fal.performed_by = a.admin_id
                 LEFT JOIN students s ON fal.student_id = s.student_id
                 LEFT JOIN distributions d ON fal.distribution_id = d.distribution_id
                 ORDER BY fal.performed_at DESC
                 LIMIT $1";
        
        $result = pg_query_params($this->conn, $query, [$limit]);
        return $result ? pg_fetch_all($result) ?: [] : [];
    }
    
    public function getStorageStatistics() {
        $result = pg_query($this->conn, "SELECT * FROM storage_statistics ORDER BY category");
        return $result ? pg_fetch_all($result) ?: [] : [];
    }
}
