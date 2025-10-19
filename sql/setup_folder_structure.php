<?php
/**
 * ============================================================================
 * PHASE 1: File Management System - Folder Structure Setup
 * ============================================================================
 * Purpose: Create the comprehensive folder structure for file management
 * Date: 2025-10-19
 * ============================================================================
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>📁 Phase 1: File Management System - Folder Setup</h1>";
echo "<p>Creating comprehensive folder structure...</p>";
echo "<hr>";

// Base uploads directory
$baseDir = __DIR__ . '/../uploads';

// Define folder structure
$folders = [
    // Active students
    'students' => [
        'description' => 'Active student files',
        'subdirs' => [
            '.gitkeep' => 'Keep directory in git'
        ]
    ],
    
    // Distribution archives (organized by academic year and distribution)
    'distributions' => [
        'description' => 'Distribution file archives organized by academic year',
        'subdirs' => [
            '.gitkeep' => 'Keep directory in git'
        ]
    ],
    
    // Archived students (organized by year archived)
    'archived_students' => [
        'description' => 'Archived student files organized by archive year',
        'subdirs' => [
            '.gitkeep' => 'Keep directory in git'
        ]
    ],
    
    // Temporary uploads
    'temp' => [
        'description' => 'Temporary upload storage',
        'subdirs' => [
            '.gitkeep' => 'Keep directory in git'
        ]
    ]
];

$results = [
    'created' => [],
    'exists' => [],
    'errors' => []
];

/**
 * Create directory with proper permissions
 */
function createDirectory($path, $description) {
    global $results;
    
    if (!file_exists($path)) {
        if (mkdir($path, 0755, true)) {
            $results['created'][] = [
                'path' => $path,
                'description' => $description
            ];
            return true;
        } else {
            $results['errors'][] = [
                'path' => $path,
                'error' => 'Failed to create directory'
            ];
            return false;
        }
    } else {
        $results['exists'][] = [
            'path' => $path,
            'description' => $description
        ];
        return true;
    }
}

/**
 * Create .gitkeep file
 */
function createGitkeep($dir) {
    $gitkeepFile = $dir . '/.gitkeep';
    if (!file_exists($gitkeepFile)) {
        file_put_contents($gitkeepFile, '# Keep this directory in version control');
    }
}

/**
 * Create README file
 */
function createReadme($dir, $content) {
    $readmeFile = $dir . '/README.md';
    if (!file_exists($readmeFile)) {
        file_put_contents($readmeFile, $content);
    }
}

echo "<h2>Creating Base Directories...</h2>";

// Create main folders
foreach ($folders as $folderName => $config) {
    $folderPath = $baseDir . '/' . $folderName;
    
    echo "<div style='background: #f0f0f0; padding: 10px; margin: 10px 0; border-left: 4px solid #3498db;'>";
    echo "<strong>📂 " . ucfirst($folderName) . "</strong><br>";
    echo "<em>" . $config['description'] . "</em><br>";
    
    if (createDirectory($folderPath, $config['description'])) {
        createGitkeep($folderPath);
        echo "✅ Created/Verified<br>";
        echo "<code style='background: #e8e8e8; padding: 2px 6px;'>$folderPath</code>";
    } else {
        echo "❌ Failed to create<br>";
    }
    
    echo "</div>";
}

// Create README files with documentation
echo "<h2>Creating Documentation...</h2>";

// Students README
$studentsReadme = <<<'README'
# Active Students Directory

This directory contains files for currently active (non-archived) students.

## Structure

```
students/
├── {student_id}/                    # One folder per student
│   ├── profile/                     # Profile photos
│   │   └── photo.jpg
│   ├── registration/                # Initial registration documents
│   │   ├── birth_certificate.pdf
│   │   ├── form137.pdf
│   │   └── good_moral.pdf
│   └── current_distribution/        # Current active distribution only
│       └── {distribution_id}/
│           ├── requirements/
│           └── documents/
```

## Notes

- Files are moved to `distributions/` when distribution ends
- Student folder is moved to `archived_students/` when student is archived
- Only current distribution files are kept here
- Old distribution files are in `distributions/` folder

## Maintenance

- Clean up temp files regularly
- Compress old distributions
- Monitor disk space usage
README;

createReadme($baseDir . '/students', $studentsReadme);

// Distributions README
$distributionsReadme = <<<'README'
# Distribution Archives Directory

This directory contains compressed archives of completed distributions.

## Structure

```
distributions/
├── {academic_year}/                 # e.g., 2024-2025
│   └── {distribution_id}/           # e.g., DIST-2024-001
│       ├── {student_id}/
│       │   ├── submitted_2024-10-15.zip    # Compressed files
│       │   ├── metadata.json               # File inventory
│       │   └── receipt.pdf                 # Distribution receipt
│       └── distribution_info.json          # Distribution metadata
```

## File Lifecycle

1. **During Distribution**: Files in `students/{id}/current_distribution/`
2. **After Distribution Ends**: Files compressed and moved here
3. **When Student Archived**: Files moved to `archived_students/`
4. **After Retention Period**: Files eligible for purge

## Compression

- Files are automatically compressed to save space
- Typical compression ratio: 30-40%
- Original file list preserved in metadata.json
- Files can be decompressed on-demand

## Retention Policy

- Default retention: 5 years after student archives
- Configurable in system settings
- Purge notifications sent before deletion
README;

createReadme($baseDir . '/distributions', $distributionsReadme);

// Archived Students README
$archivedReadme = <<<'README'
# Archived Students Directory

This directory contains files for archived students.

## Structure

```
archived_students/
├── {archive_year}/                  # Year student was archived
│   └── {student_id}/
│       ├── profile/
│       │   └── photo.jpg
│       ├── registration/            # Original registration docs
│       │   └── ...
│       ├── distributions/           # ALL past distributions
│       │   ├── 2022-2023/
│       │   │   └── DIST-2022-001_submitted.zip
│       │   ├── 2023-2024/
│       │   │   ├── DIST-2023-001_submitted.zip
│       │   │   └── DIST-2023-002_submitted.zip
│       │   └── 2024-2025/
│       │       └── DIST-2024-001_submitted.zip
│       └── archive_metadata.json    # Archive info
```

## Archiving Process

1. Student marked as archived
2. Profile and registration docs copied here
3. All distribution files moved here
4. Original student folder deleted
5. Metadata created with archive info

## Restoration

When student is unarchived:
1. Files moved back to `students/` and `distributions/`
2. Archive folder deleted
3. Database updated
4. Student can access files again

## Purge System

- Files purged after retention period (default 5 years)
- Email notifications sent before purge
- Manual override available
- Audit log maintained
README;

createReadme($baseDir . '/archived_students', $archivedReadme);

// Create example student structure
echo "<h2>Creating Example Structure...</h2>";

$exampleStudentId = 'EXAMPLE-2024-001';
$exampleStudentPath = $baseDir . '/students/' . $exampleStudentId;

$exampleDirs = [
    $exampleStudentPath . '/profile',
    $exampleStudentPath . '/registration',
    $exampleStudentPath . '/current_distribution'
];

foreach ($exampleDirs as $dir) {
    if (createDirectory($dir, 'Example student folder')) {
        echo "✅ Created example: <code>$dir</code><br>";
    }
}

// Create example metadata file
$exampleMetadata = [
    'student_id' => $exampleStudentId,
    'created_at' => date('Y-m-d H:i:s'),
    'purpose' => 'Example structure for testing',
    'folders' => [
        'profile' => 'Student profile photos',
        'registration' => 'Initial registration documents',
        'current_distribution' => 'Files for active distribution'
    ]
];

file_put_contents(
    $exampleStudentPath . '/structure_info.json',
    json_encode($exampleMetadata, JSON_PRETTY_PRINT)
);

echo "<br>";

// Summary
echo "<hr>";
echo "<h2>📊 Summary</h2>";

echo "<div style='background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; margin: 10px 0; border-radius: 5px;'>";
echo "<h3 style='color: #155724; margin-top: 0;'>✅ Directories Created</h3>";
if (!empty($results['created'])) {
    echo "<ul>";
    foreach ($results['created'] as $item) {
        echo "<li><strong>" . basename($item['path']) . "</strong>: " . $item['description'] . "</li>";
    }
    echo "</ul>";
} else {
    echo "<p>No new directories created (all exist)</p>";
}
echo "</div>";

echo "<div style='background: #d1ecf1; border: 1px solid #bee5eb; padding: 15px; margin: 10px 0; border-radius: 5px;'>";
echo "<h3 style='color: #0c5460; margin-top: 0;'>ℹ️ Existing Directories</h3>";
if (!empty($results['exists'])) {
    echo "<ul>";
    foreach ($results['exists'] as $item) {
        echo "<li><strong>" . basename($item['path']) . "</strong>: " . $item['description'] . "</li>";
    }
    echo "</ul>";
} else {
    echo "<p>No existing directories found</p>";
}
echo "</div>";

if (!empty($results['errors'])) {
    echo "<div style='background: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; margin: 10px 0; border-radius: 5px;'>";
    echo "<h3 style='color: #721c24; margin-top: 0;'>❌ Errors</h3>";
    echo "<ul>";
    foreach ($results['errors'] as $error) {
        echo "<li><strong>" . $error['path'] . "</strong>: " . $error['error'] . "</li>";
    }
    echo "</ul>";
    echo "</div>";
}

// Disk space check
echo "<hr>";
echo "<h2>💾 Disk Space Check</h2>";

$totalSpace = disk_total_space($baseDir);
$freeSpace = disk_free_space($baseDir);
$usedSpace = $totalSpace - $freeSpace;

echo "<div style='background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; margin: 10px 0; border-radius: 5px;'>";
echo "<p><strong>Total Space:</strong> " . formatBytes($totalSpace) . "</p>";
echo "<p><strong>Used Space:</strong> " . formatBytes($usedSpace) . " (" . round(($usedSpace / $totalSpace) * 100, 2) . "%)</p>";
echo "<p><strong>Free Space:</strong> " . formatBytes($freeSpace) . " (" . round(($freeSpace / $totalSpace) * 100, 2) . "%)</p>";
echo "</div>";

function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    
    for ($i = 0; $bytes > 1024; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}

echo "<hr>";
echo "<h2>✅ Phase 1: Folder Setup Complete!</h2>";
echo "<p><strong>Next Steps:</strong></p>";
echo "<ol>";
echo "<li>Run the SQL migration: <code>phase1_file_management_system.sql</code></li>";
echo "<li>Build file archiving service (Phase 2)</li>";
echo "<li>Implement compression utilities</li>";
echo "<li>Create admin UI for file management</li>";
echo "</ol>";

echo "<div style='background: #e7f3ff; border: 2px solid #2196F3; padding: 20px; margin: 20px 0; border-radius: 8px;'>";
echo "<h3 style='margin-top: 0;'>📋 Quick Reference</h3>";
echo "<p><strong>Folder Locations:</strong></p>";
echo "<ul style='font-family: monospace; background: white; padding: 15px; border-radius: 5px;'>";
echo "<li>Active Students: <code>$baseDir/students/</code></li>";
echo "<li>Distribution Archives: <code>$baseDir/distributions/</code></li>";
echo "<li>Archived Students: <code>$baseDir/archived_students/</code></li>";
echo "<li>Temporary Files: <code>$baseDir/temp/</code></li>";
echo "</ul>";
echo "</div>";
?>
