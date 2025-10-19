<?php
require_once __DIR__ . '/../config/database.php';

class FileCompressionService {
    private $conn;
    
    public function __construct() {
        global $connection;
        $this->conn = $connection;
    }
    
    public function compressDistribution($distributionId, $adminId) {
        try {
            pg_query($this->conn, "BEGIN");
            
            // Get distribution details
            $distQuery = "SELECT d.*
                         FROM distributions d
                         WHERE d.distribution_id = $1";
            $distResult = pg_query_params($this->conn, $distQuery, [$distributionId]);
            
            if (!$distResult || pg_num_rows($distResult) === 0) {
                throw new Exception("Distribution not found");
            }
            
            $distribution = pg_fetch_assoc($distResult);
            
            // Get all files for this distribution
            $filesQuery = "SELECT df.*, s.student_id as lrn, s.first_name, s.last_name, '' as middle_name
                          FROM distribution_files df
                          JOIN students s ON df.student_id = s.student_id
                          WHERE df.distribution_id = $1 
                          AND df.is_archived = false
                          ORDER BY df.student_id, df.file_type";
            $filesResult = pg_query_params($this->conn, $filesQuery, [$distributionId]);
            
            if (!$filesResult || pg_num_rows($filesResult) === 0) {
                throw new Exception("No files found to compress");
            }
            
            $files = pg_fetch_all($filesResult);
            
            // Group files by student
            $studentFiles = [];
            foreach ($files as $file) {
                $studentId = $file['student_id'];
                if (!isset($studentFiles[$studentId])) {
                    $studentFiles[$studentId] = [
                        'info' => $file,
                        'files' => []
                    ];
                }
                $studentFiles[$studentId]['files'][] = $file;
            }
            
            // Create distribution archive folder
            $archiveBaseDir = __DIR__ . '/../uploads/distributions/';
            $distFolderName = sprintf(
                "Distribution_%s_ID%s", 
                date('Y-m-d', strtotime($distribution['date_given'] ?? 'now')),
                $distribution['distribution_id']
            );
            $distArchiveDir = $archiveBaseDir . $distFolderName . '/';
            
            if (!file_exists($distArchiveDir)) {
                mkdir($distArchiveDir, 0755, true);
            }
            
            $totalOriginalSize = 0;
            $totalCompressedSize = 0;
            $filesCompressed = 0;
            $studentsProcessed = 0;
            $compressionLog = [];
            
            // Compress files for each student
            foreach ($studentFiles as $studentId => $data) {
                $studentInfo = $data['info'];
                $studentFilesList = $data['files'];
                
                $studentName = sprintf(
                    "%s_%s_%s",
                    $studentInfo['lrn'],
                    $studentInfo['last_name'],
                    $studentInfo['first_name']
                );
                $studentName = preg_replace('/[^A-Za-z0-9_-]/', '_', $studentName);
                
                $zipFilename = $studentName . '.zip';
                $zipPath = $distArchiveDir . $zipFilename;
                
                $zip = new ZipArchive();
                if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
                    throw new Exception("Cannot create ZIP file: $zipPath");
                }
                
                $studentOriginalSize = 0;
                
                foreach ($studentFilesList as $file) {
                    $filePath = __DIR__ . '/../uploads/' . $file['file_path'];
                    
                    if (file_exists($filePath)) {
                        $zipEntryName = $file['file_type'] . '_' . basename($file['file_path']);
                        $zip->addFile($filePath, $zipEntryName);
                        $studentOriginalSize += $file['file_size'];
                        $filesCompressed++;
                    }
                }
                
                $zip->close();
                
                $zipSize = filesize($zipPath);
                $totalOriginalSize += $studentOriginalSize;
                $totalCompressedSize += $zipSize;
                
                // Update distribution_files records
                foreach ($studentFilesList as $file) {
                    $updateQuery = "UPDATE distribution_files 
                                   SET is_compressed = true, 
                                       compression_date = NOW()
                                   WHERE file_id = $1";
                    pg_query_params($this->conn, $updateQuery, [$file['file_id']]);
                }
                
                $studentsProcessed++;
                $compressionLog[] = sprintf(
                    "Student %s: %d files, %.2f KB → %.2f KB (%.1f%%)",
                    $studentName,
                    count($studentFilesList),
                    $studentOriginalSize / 1024,
                    $zipSize / 1024,
                    ($zipSize / $studentOriginalSize * 100)
                );
            }
            
            // Update distributions table
            pg_query_params($this->conn,
                "UPDATE distributions 
                 SET files_compressed = true, compression_date = NOW()
                 WHERE distribution_id = $1",
                [$distributionId]);
            
            // Log the operation
            $spaceSaved = $totalOriginalSize - $totalCompressedSize;
            $this->logOperation(
                'compress_distribution', $adminId, $distributionId, null,
                $filesCompressed, $totalOriginalSize, $totalCompressedSize, $spaceSaved,
                'success', null
            );
            
            pg_query($this->conn, "COMMIT");
            
            return [
                'success' => true,
                'message' => "Distribution compressed successfully",
                'statistics' => [
                    'students_processed' => $studentsProcessed,
                    'files_compressed' => $filesCompressed,
                    'original_size' => $totalOriginalSize,
                    'compressed_size' => $totalCompressedSize,
                    'space_saved' => $spaceSaved,
                    'compression_ratio' => round(($totalCompressedSize / $totalOriginalSize * 100), 2),
                    'archive_location' => $distFolderName
                ],
                'log' => $compressionLog
            ];
            
        } catch (Exception $e) {
            pg_query($this->conn, "ROLLBACK");
            
            $this->logOperation(
                'compress_distribution', $adminId, $distributionId, null,
                0, 0, 0, 0, 'failed', $e->getMessage()
            );
            
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    private function logOperation($operationType, $adminId, $distributionId, $studentId, 
                                  $fileCount, $originalSize, $compressedSize, $spaceSaved, 
                                  $status, $errorMessage) {
        try {
            $logQuery = "INSERT INTO file_archive_log 
                        (operation, performed_by, distribution_id, student_id, 
                         file_count, total_size_before, total_size_after, space_saved, 
                         operation_status, error_message)
                        VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)";
            
            pg_query_params($this->conn, $logQuery, [
                $operationType, $adminId, $distributionId, $studentId,
                $fileCount, $originalSize, $compressedSize, $spaceSaved,
                $status, $errorMessage
            ]);
        } catch (Exception $e) {
            error_log("Failed to log file operation: " . $e->getMessage());
        }
    }
}
