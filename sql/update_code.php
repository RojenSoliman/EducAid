<?php
/**
 * Code Update Script for Student ID Migration
 * Updates PHP code to handle TEXT student_id instead of INTEGER
 */

class CodeUpdater {
    private $files_to_update = [];
    private $log = [];
    
    public function __construct() {
        $this->log("=== CODE UPDATE SCRIPT FOR STUDENT ID MIGRATION ===");
    }
    
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message";
        echo $logMessage . "\n";
        $this->log[] = $logMessage;
    }
    
    public function scanForFiles() {
        $this->log("Scanning for PHP files that need updates...");
        
        // Define directories to scan
        $directories = [
            '../modules',
            '../includes',
            '../services',
            '../'
        ];
        
        foreach ($directories as $dir) {
            $this->scanDirectory($dir);
        }
        
        $this->log("Found " . count($this->files_to_update) . " files that need updates");
        return $this->files_to_update;
    }
    
    private function scanDirectory($dir) {
        if (!is_dir($dir)) return;
        
        $files = glob($dir . '/*.php');
        $subdirs = glob($dir . '/*', GLOB_ONLYDIR);
        
        foreach ($files as $file) {
            if ($this->fileNeedsUpdate($file)) {
                $this->files_to_update[] = $file;
            }
        }
        
        foreach ($subdirs as $subdir) {
            $this->scanDirectory($subdir);
        }
    }
    
    private function fileNeedsUpdate($file) {
        $content = file_get_contents($file);
        
        // Check for patterns that need updating
        $patterns = [
            '/intval\s*\(\s*\$[^)]*student_id[^)]*\)/',
            '/\$[^=]*student_id[^=]*=\s*intval\s*\(/',
            '/student_id\s*::\s*int/',
            '/student_id.*TYPE\s+INTEGER/',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }
        
        return false;
    }
    
    public function updateFile($file) {
        $this->log("Updating file: " . basename($file));
        
        $content = file_get_contents($file);
        $originalContent = $content;
        
        // Pattern 1: Remove intval() calls for student_id variables
        $content = preg_replace(
            '/intval\s*\(\s*(\$[^)]*student_id[^)]*)\)/',
            '$1',
            $content
        );
        
        // Pattern 2: Update direct intval assignments
        $content = preg_replace(
            '/(\$[^=]*student_id[^=]*)\s*=\s*intval\s*\(\s*([^)]+)\s*\);/',
            '$1 = $2;',
            $content
        );
        
        // Pattern 3: Update type casting in SQL-like contexts (not actual SQL files)
        if (!str_ends_with($file, '.sql')) {
            $content = preg_replace(
                '/student_id\s*::\s*int/',
                'student_id',
                $content
            );
        }
        
        // Pattern 4: Fix specific problematic patterns
        // Fix array_map('intval', $student_ids) to handle TEXT IDs
        $content = preg_replace(
            '/array_map\s*\(\s*[\'"]intval[\'"]\s*,\s*(\$[^)]*student_ids?[^)]*)\)/',
            'array_map(function($id) { return trim($id); }, $1)',
            $content
        );
        
        // Check if content was actually changed
        if ($content !== $originalContent) {
            file_put_contents($file, $content);
            $this->log("  ✓ Updated: " . basename($file));
            return true;
        } else {
            $this->log("  - No changes needed: " . basename($file));
            return false;
        }
    }
    
    public function updateAllFiles() {
        $files = $this->scanForFiles();
        $updatedCount = 0;
        
        foreach ($files as $file) {
            if ($this->updateFile($file)) {
                $updatedCount++;
            }
        }
        
        $this->log("=== UPDATE COMPLETE ===");
        $this->log("Total files scanned: " . count($files));
        $this->log("Files updated: $updatedCount");
        
        return $updatedCount;
    }
    
    public function createBackup() {
        $this->log("Creating backup of files before update...");
        $backupDir = 'backup_before_student_id_migration_' . date('Y-m-d_H-i-s');
        
        if (!mkdir($backupDir, 0755, true)) {
            $this->log("ERROR: Could not create backup directory");
            return false;
        }
        
        $files = $this->scanForFiles();
        foreach ($files as $file) {
            $relativePath = str_replace('../', '', $file);
            $backupPath = $backupDir . '/' . $relativePath;
            
            // Create directory structure
            $backupFileDir = dirname($backupPath);
            if (!is_dir($backupFileDir)) {
                mkdir($backupFileDir, 0755, true);
            }
            
            copy($file, $backupPath);
        }
        
        $this->log("Backup created: $backupDir");
        return $backupDir;
    }
    
    public function validateUpdates() {
        $this->log("=== VALIDATING UPDATES ===");
        $files = $this->scanForFiles();
        
        foreach ($files as $file) {
            $content = file_get_contents($file);
            
            // Check for remaining problematic patterns
            if (preg_match('/intval\s*\(\s*\$[^)]*student_id[^)]*\)/', $content)) {
                $this->log("WARNING: Still has intval(student_id) in " . basename($file));
            }
            
            // Check for PHP syntax errors
            $syntaxCheck = shell_exec("php -l '$file' 2>&1");
            if (strpos($syntaxCheck, 'No syntax errors detected') === false) {
                $this->log("ERROR: Syntax error in " . basename($file) . ": $syntaxCheck");
            }
        }
        
        $this->log("Validation complete");
    }
}

// Run the updater if called directly
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    echo "Student ID Code Updater\n";
    echo "======================\n\n";
    
    echo "This will update PHP code to handle TEXT student_id instead of INTEGER.\n";
    echo "It will remove intval() calls and fix type casting issues.\n\n";
    
    echo "Do you want to proceed? (y/N): ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    
    if (trim($line) === 'y' || trim($line) === 'Y') {
        $updater = new CodeUpdater();
        
        // Create backup first
        $backupDir = $updater->createBackup();
        if (!$backupDir) {
            echo "Failed to create backup. Aborting.\n";
            exit(1);
        }
        
        // Update files
        $updatedCount = $updater->updateAllFiles();
        
        // Validate updates
        $updater->validateUpdates();
        
        if ($updatedCount > 0) {
            echo "\n🎉 Code updates completed successfully!\n";
            echo "Files updated: $updatedCount\n";
            echo "Backup created: $backupDir\n";
            exit(0);
        } else {
            echo "\n✅ No updates needed - code is already compatible!\n";
            exit(0);
        }
    } else {
        echo "Update cancelled.\n";
        exit(0);
    }
}
?>