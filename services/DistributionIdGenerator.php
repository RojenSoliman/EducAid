<?php
/**
 * Distribution ID Generator Service
 * Generates identifiable distribution IDs like: GENERALTRIAS-DISTR-2025-10-19-001
 */

class DistributionIdGenerator {
    private $conn;
    private $municipalityCode;
    
    public function __construct($connection, $municipalityCode = 'GENERALTRIAS') {
        $this->conn = $connection;
        $this->municipalityCode = $municipalityCode;
    }
    
    /**
     * Generate a unique distribution ID
     * Format: MUNICIPALITY-DISTR-YYYY-MM-DD-NNN
     * Example: GENERALTRIAS-DISTR-2025-10-19-001
     * 
     * @return string Unique distribution ID
     */
    public function generateDistributionId() {
        $date = date('Y-m-d');
        $dateFormatted = date('Y-m-d'); // For ID: 2025-10-19
        
        // Get the count of distributions created today
        $countQuery = "SELECT COUNT(*) as count FROM distributions 
                      WHERE DATE(date_given) = $1";
        $countResult = pg_query_params($this->conn, $countQuery, [$date]);
        $countRow = pg_fetch_assoc($countResult);
        $todayCount = intval($countRow['count']) + 1; // Next sequence number
        
        // Format sequence number as 3 digits (001, 002, 003, etc.)
        $sequence = str_pad($todayCount, 3, '0', STR_PAD_LEFT);
        
        // Build the distribution ID
        $distributionId = "{$this->municipalityCode}-DISTR-{$dateFormatted}-{$sequence}";
        
        // Verify uniqueness (in case of race condition)
        $checkQuery = "SELECT COUNT(*) as count FROM distributions WHERE distribution_id = $1";
        $checkResult = pg_query_params($this->conn, $checkQuery, [$distributionId]);
        $checkRow = pg_fetch_assoc($checkResult);
        
        if ($checkRow && intval($checkRow['count']) > 0) {
            // ID already exists, increment and try again
            return $this->generateDistributionIdWithSequence($dateFormatted, $todayCount + 1);
        }
        
        return $distributionId;
    }
    
    /**
     * Generate distribution ID with specific sequence number
     * Used for resolving conflicts
     */
    private function generateDistributionIdWithSequence($dateFormatted, $sequence) {
        $sequenceStr = str_pad($sequence, 3, '0', STR_PAD_LEFT);
        return "{$this->municipalityCode}-DISTR-{$dateFormatted}-{$sequenceStr}";
    }
    
    /**
     * Validate distribution ID format
     * 
     * @param string $distributionId
     * @return bool
     */
    public static function validateFormat($distributionId) {
        // Pattern: MUNICIPALITY-DISTR-YYYY-MM-DD-NNN
        $pattern = '/^[A-Z]+-DISTR-\d{4}-\d{2}-\d{2}-\d{3}$/';
        return preg_match($pattern, $distributionId) === 1;
    }
    
    /**
     * Extract date from distribution ID
     * 
     * @param string $distributionId
     * @return string|null Date in YYYY-MM-DD format or null if invalid
     */
    public static function extractDate($distributionId) {
        if (!self::validateFormat($distributionId)) {
            return null;
        }
        
        // Extract date part (YYYY-MM-DD)
        $parts = explode('-', $distributionId);
        if (count($parts) >= 5) {
            return "{$parts[2]}-{$parts[3]}-{$parts[4]}";
        }
        
        return null;
    }
    
    /**
     * Extract sequence number from distribution ID
     * 
     * @param string $distributionId
     * @return int|null Sequence number or null if invalid
     */
    public static function extractSequence($distributionId) {
        if (!self::validateFormat($distributionId)) {
            return null;
        }
        
        // Extract sequence part (last 3 digits)
        $parts = explode('-', $distributionId);
        if (count($parts) >= 6) {
            return intval($parts[5]);
        }
        
        return null;
    }
}
?>
