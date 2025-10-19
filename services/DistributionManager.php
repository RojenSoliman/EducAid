<?php
require_once __DIR__ . '/../config/database.php';

class DistributionManager {
    private $conn;
    
    public function __construct() {
        global $connection;
        $this->conn = $connection;
    }
    
    public function endDistribution($distributionId, $adminId, $compressNow = false) {
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
            
            pg_query_params($this->conn,
                "UPDATE distributions SET status = 'ended', ended_at = NOW() WHERE distribution_id = $1",
                [$distributionId]);
            
            pg_query($this->conn, "COMMIT");
            
            $result = [
                'success' => true,
                'message' => 'Distribution ended successfully',
                'distribution_id' => $distributionId
            ];
            
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
        
        // Get current academic period from config
        $periodQuery = "SELECT key, value FROM config WHERE key IN ('current_academic_year', 'current_semester')";
        $periodResult = pg_query($this->conn, $periodQuery);
        $academicYear = null;
        $semester = null;
        while ($row = pg_fetch_assoc($periodResult)) {
            if ($row['key'] === 'current_academic_year') $academicYear = $row['value'];
            if ($row['key'] === 'current_semester') $semester = $row['value'];
        }
        
        // Return ONE logical distribution representing the current distribution cycle
        // Count all students with 'given' status (distributed aid) in this cycle
        $query = "SELECT 
                    0 as id,  -- Using 0 as the current cycle ID
                    NOW() as created_at,
                    'active' as status,
                    NULL::text as year_level,
                    NULL::text as semester,
                    COUNT(DISTINCT s.student_id) as student_count,
                    0 as file_count,
                    0 as total_size
                 FROM students s
                 WHERE s.status = 'given'";
        
        $result = pg_query($this->conn, $query);
        $data = $result ? pg_fetch_all($result) : [];
        
        // Only return the distribution if there are students with 'given' status
        if ($data && isset($data[0]) && $data[0]['student_count'] > 0) {
            $data[0]['year_level'] = $academicYear;
            $data[0]['semester'] = $semester;
            return $data;
        }
        
        return [];
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
