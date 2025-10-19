<?php
require_once __DIR__ . '/../config/database.php';

class DistributionManager {
    private $conn;
    private $studentResetColumnCache = null;
    
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
            
            // FIRST: Compress files BEFORE resetting students (compression needs status='given')
            $compressionResult = null;
            if ($compressNow) {
                require_once __DIR__ . '/FileCompressionService.php';
                $compressionService = new FileCompressionService();
                $compressionResult = $compressionService->compressDistribution($distributionId, $adminId);
                
                if (!$compressionResult['success']) {
                    throw new Exception("Compression failed: " . $compressionResult['message']);
                }
            }
            
            // Update distribution status to ended
            pg_query_params($this->conn,
                "UPDATE distributions SET status = 'ended', ended_at = NOW() WHERE distribution_id = $1",
                [$distributionId]);
            
            // Reset all students with 'given' status back to 'applicant'
            // Clear their payroll numbers and QR codes for the next cycle
            $studentsReset = $this->resetGivenStudents();
            
            error_log("DistributionManager: Reset $studentsReset students from 'given' to 'applicant' and cleared payroll/QR codes");

            // Clear schedule data so the next distribution starts clean
            $this->clearScheduleData();
            
            // Set global distribution status to inactive
            pg_query($this->conn, "
                INSERT INTO config (key, value) VALUES ('distribution_status', 'inactive')
                ON CONFLICT (key) DO UPDATE SET value = 'inactive'
            ");
            
            pg_query($this->conn, "COMMIT");
            
            $result = [
                'success' => true,
                'message' => 'Distribution ended successfully and status set to inactive',
                'distribution_id' => $distributionId,
                'students_reset' => $studentsReset,
                'compression' => $compressionResult
            ];
            
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
        
        // Scan the enrollment_forms, grades, id_pictures, indigency, letter_to_mayor folders for files matching our students
        $folders = ['student/enrollment_forms', 'student/grades', 'student/id_pictures', 'student/indigency', 'student/letter_to_mayor'];
        
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
                    d.student_id,
                    1 as student_count,
                    0 as file_count,
                    0 as original_size,
                    0 as current_size,
                    0 as avg_compression_ratio,
                    0 as archived_files_count
                 FROM distributions d
                 ORDER BY d.date_given DESC";
        
        $result = pg_query($this->conn, $query);
        $distributions = $result ? pg_fetch_all($result) ?: [] : [];
        
        // Enhance with actual file data from ZIP archives
        $distributionsPath = __DIR__ . '/../assets/uploads/distributions';
        
        foreach ($distributions as &$dist) {
            $zipFile = $distributionsPath . '/' . $dist['id'] . '.zip';
            
            if (file_exists($zipFile) && is_file($zipFile)) {
                $dist['files_compressed'] = true;
                $dist['current_size'] = filesize($zipFile);
                
                // Try to get file count from ZIP
                $zip = new ZipArchive();
                if ($zip->open($zipFile) === true) {
                    $dist['file_count'] = $zip->numFiles;
                    $dist['archived_files_count'] = $zip->numFiles;
                    $zip->close();
                }
                
                // Estimate original size (compressed size * 2 for typical compression ratio)
                $dist['original_size'] = $dist['current_size'] * 2;
                $spaceSaved = $dist['original_size'] - $dist['current_size'];
                $dist['avg_compression_ratio'] = $dist['original_size'] > 0 
                    ? round(($spaceSaved / $dist['original_size']) * 100, 1) 
                    : 0;
            }
        }
        
        return $distributions;
    }
    
    public function getCompressionStatistics() {
        // Get distribution counts
        $distQuery = pg_query($this->conn, "
            SELECT 
                COUNT(*) as total_distributions,
                COUNT(CASE WHEN status = 'ended' THEN 1 END) as compressed_distributions
            FROM distributions
        ");
        
        $distStats = pg_fetch_assoc($distQuery);
        
        // Scan actual distribution ZIP files to calculate space
        $distributionsPath = __DIR__ . '/../assets/uploads/distributions';
        $totalCompressedSize = 0;
        $distributionCount = 0;
        
        if (is_dir($distributionsPath)) {
            $zipFiles = glob($distributionsPath . '/*.zip');
            foreach ($zipFiles as $zipFile) {
                if (is_file($zipFile)) {
                    $totalCompressedSize += filesize($zipFile);
                    $distributionCount++;
                }
            }
        }
        
        // Estimate original size (before compression)
        // Assumption: ZIP compression typically achieves 30-60% compression
        // For calculation, we'll estimate original was 2x compressed size
        $estimatedOriginalSize = $totalCompressedSize * 2;
        $spaceSaved = $estimatedOriginalSize - $totalCompressedSize;
        
        $avgCompressionRatio = $estimatedOriginalSize > 0 
            ? (($spaceSaved / $estimatedOriginalSize) * 100) 
            : 0;
        
        return [
            'total_distributions' => (int)($distStats['total_distributions'] ?? 0),
            'compressed_distributions' => $distributionCount,
            'total_original_size' => $estimatedOriginalSize,
            'total_current_size' => $totalCompressedSize,
            'total_space_saved' => $spaceSaved,
            'avg_compression_ratio' => round($avgCompressionRatio, 1),
            'compression_percentage' => round($avgCompressionRatio, 1)
        ];
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
        $stats = [];
        
        // 1. ACTIVE STUDENTS - Scan actual files in assets/uploads/student/
        $activeUploadsPath = __DIR__ . '/../assets/uploads/student';
        $activeFolders = ['enrollment_forms', 'grades', 'id_pictures', 'indigency', 'letter_to_mayor'];
        
        $activeFiles = 0;
        $activeSize = 0;
        $activeStudentsQuery = pg_query($this->conn, "SELECT COUNT(DISTINCT student_id) as count FROM students WHERE status IN ('active', 'given')");
        $activeStudentCount = pg_fetch_assoc($activeStudentsQuery)['count'] ?? 0;
        
        foreach ($activeFolders as $folder) {
            $folderPath = $activeUploadsPath . '/' . $folder;
            if (is_dir($folderPath)) {
                $files = glob($folderPath . '/*.*');
                foreach ($files as $file) {
                    if (is_file($file)) {
                        $activeFiles++;
                        $activeSize += filesize($file);
                    }
                }
            }
        }
        
        $stats[] = [
            'category' => 'active',
            'student_count' => (int)$activeStudentCount,
            'file_count' => $activeFiles,
            'total_size' => $activeSize
        ];
        
        // 2. PAST DISTRIBUTIONS - Scan ZIP files in assets/uploads/distributions/
        $distributionsPath = __DIR__ . '/../assets/uploads/distributions';
        $distFiles = 0;
        $distSize = 0;
        $distCount = 0;
        
        if (is_dir($distributionsPath)) {
            $zipFiles = glob($distributionsPath . '/*.zip');
            $distFiles = count($zipFiles);
            foreach ($zipFiles as $zipFile) {
                if (is_file($zipFile)) {
                    $distSize += filesize($zipFile);
                    $distCount++;
                }
            }
        }
        
        $stats[] = [
            'category' => 'distributions',
            'student_count' => $distCount, // Number of distribution archives
            'file_count' => $distFiles,
            'total_size' => $distSize
        ];
        
        // 3. ARCHIVED STUDENTS - From database view (if exists)
        $archivedQuery = pg_query($this->conn, "
            SELECT 
                COUNT(*) as student_count,
                0 as file_count,
                0 as total_size
            FROM students 
            WHERE is_archived = true
        ");
        
        if ($archivedQuery && pg_num_rows($archivedQuery) > 0) {
            $archivedData = pg_fetch_assoc($archivedQuery);
            $stats[] = [
                'category' => 'archived',
                'student_count' => (int)$archivedData['student_count'],
                'file_count' => (int)$archivedData['file_count'],
                'total_size' => (int)$archivedData['total_size']
            ];
        } else {
            // Fallback: scan archived_students folder if exists
            $archivedPath = __DIR__ . '/../assets/uploads/archived_students';
            $archivedFiles = 0;
            $archivedSize = 0;
            
            if (is_dir($archivedPath)) {
                $files = glob($archivedPath . '/*.*', GLOB_BRACE);
                foreach ($files as $file) {
                    if (is_file($file)) {
                        $archivedFiles++;
                        $archivedSize += filesize($file);
                    }
                }
            }
            
            $archivedStudentsQuery = pg_query($this->conn, "SELECT COUNT(*) as count FROM students WHERE is_archived = true");
            $archivedStudentCount = $archivedStudentsQuery ? (pg_fetch_assoc($archivedStudentsQuery)['count'] ?? 0) : 0;
            
            $stats[] = [
                'category' => 'archived',
                'student_count' => (int)$archivedStudentCount,
                'file_count' => $archivedFiles,
                'total_size' => $archivedSize
            ];
        }
        
        return $stats;
    }

    private function resetGivenStudents() {
        if ($this->studentResetColumnCache === null) {
            $this->studentResetColumnCache = [
                'payroll_no' => false,
                'payroll_number' => false,
                'qr_code_path' => false,
                'qr_code' => false,
            ];

            $columnQuery = "SELECT column_name FROM information_schema.columns WHERE table_name = 'students' AND column_name IN ('payroll_no','payroll_number','qr_code_path','qr_code')";
            $columnResult = pg_query($this->conn, $columnQuery);
            if ($columnResult) {
                while ($row = pg_fetch_assoc($columnResult)) {
                    $name = $row['column_name'];
                    if (array_key_exists($name, $this->studentResetColumnCache)) {
                        $this->studentResetColumnCache[$name] = true;
                    }
                }
            }
        }

        $setParts = ["status = 'applicant'"];

        if ($this->studentResetColumnCache['payroll_no']) {
            $setParts[] = 'payroll_no = NULL';
        } elseif ($this->studentResetColumnCache['payroll_number']) {
            $setParts[] = 'payroll_number = NULL';
        }

        if ($this->studentResetColumnCache['qr_code_path']) {
            $setParts[] = 'qr_code_path = NULL';
        } elseif ($this->studentResetColumnCache['qr_code']) {
            $setParts[] = 'qr_code = NULL';
        }

        $query = "UPDATE students SET " . implode(', ', $setParts) . " WHERE status = 'given'";
        $result = pg_query($this->conn, $query);

        if (!$result) {
            throw new Exception('Failed to reset students: ' . pg_last_error($this->conn));
        }

        $deleteQr = pg_query($this->conn, "DELETE FROM qr_codes");
        if ($deleteQr === false) {
            throw new Exception('Failed to clear QR codes: ' . pg_last_error($this->conn));
        }

        return pg_affected_rows($result);
    }

    private function clearScheduleData() {
        $deleteResult = pg_query($this->conn, "DELETE FROM schedules");
        if ($deleteResult === false) {
            throw new Exception('Failed to clear schedules: ' . pg_last_error($this->conn));
        }

        $settingsPath = __DIR__ . '/../data/municipal_settings.json';
        $settings = [];
        if (file_exists($settingsPath)) {
            $decoded = json_decode(file_get_contents($settingsPath), true);
            if (is_array($decoded)) {
                $settings = $decoded;
            }
        }

        $settings['schedule_published'] = false;
        if (isset($settings['schedule_meta'])) {
            unset($settings['schedule_meta']);
        }

        $encoded = json_encode($settings, JSON_PRETTY_PRINT);
        if ($encoded === false) {
            throw new Exception('Failed to encode schedule settings to JSON');
        }

        if (file_put_contents($settingsPath, $encoded) === false) {
            throw new Exception('Failed to update schedule settings file');
        }
    }
}
