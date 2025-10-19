<?php
/**
 * Run distribution_id migration for distribution_snapshots table
 * This script adds columns needed to properly track distribution archives
 */

require_once __DIR__ . '/../config/database.php';

echo "Starting migration: Add distribution_id to distribution_snapshots\n";
echo "================================================================\n\n";

$migrations = [
    [
        'name' => 'Add distribution_id column',
        'check' => "SELECT 1 FROM information_schema.columns WHERE table_name = 'distribution_snapshots' AND column_name = 'distribution_id'",
        'sql' => "ALTER TABLE distribution_snapshots ADD COLUMN distribution_id TEXT"
    ],
    [
        'name' => 'Add archive_filename column',
        'check' => "SELECT 1 FROM information_schema.columns WHERE table_name = 'distribution_snapshots' AND column_name = 'archive_filename'",
        'sql' => "ALTER TABLE distribution_snapshots ADD COLUMN archive_filename TEXT"
    ],
    [
        'name' => 'Add files_compressed column',
        'check' => "SELECT 1 FROM information_schema.columns WHERE table_name = 'distribution_snapshots' AND column_name = 'files_compressed'",
        'sql' => "ALTER TABLE distribution_snapshots ADD COLUMN files_compressed BOOLEAN DEFAULT FALSE"
    ],
    [
        'name' => 'Add compression_date column',
        'check' => "SELECT 1 FROM information_schema.columns WHERE table_name = 'distribution_snapshots' AND column_name = 'compression_date'",
        'sql' => "ALTER TABLE distribution_snapshots ADD COLUMN compression_date TIMESTAMP"
    ],
    [
        'name' => 'Create index on distribution_id',
        'check' => "SELECT 1 FROM pg_indexes WHERE tablename = 'distribution_snapshots' AND indexname = 'idx_distribution_snapshots_dist_id'",
        'sql' => "CREATE INDEX idx_distribution_snapshots_dist_id ON distribution_snapshots(distribution_id)"
    ]
];

$successCount = 0;
$skipCount = 0;
$errorCount = 0;

foreach ($migrations as $migration) {
    echo "Running: {$migration['name']}... ";
    
    // Check if already exists
    $checkResult = pg_query($connection, $migration['check']);
    if ($checkResult && pg_num_rows($checkResult) > 0) {
        echo "SKIPPED (already exists)\n";
        $skipCount++;
        continue;
    }
    
    // Run migration
    $result = pg_query($connection, $migration['sql']);
    if ($result) {
        echo "SUCCESS ✓\n";
        $successCount++;
    } else {
        echo "FAILED ✗\n";
        echo "  Error: " . pg_last_error($connection) . "\n";
        $errorCount++;
    }
}

echo "\n================================================================\n";
echo "Migration Summary:\n";
echo "  - Successful: $successCount\n";
echo "  - Skipped: $skipCount\n";
echo "  - Failed: $errorCount\n";

if ($errorCount > 0) {
    echo "\nMigration completed with errors!\n";
    exit(1);
} else {
    echo "\nMigration completed successfully!\n";
    
    // Show updated table structure
    echo "\nUpdated distribution_snapshots columns:\n";
    $columnQuery = "SELECT column_name, data_type, is_nullable, column_default 
                    FROM information_schema.columns 
                    WHERE table_name = 'distribution_snapshots'
                    ORDER BY ordinal_position";
    $columnResult = pg_query($connection, $columnQuery);
    
    echo sprintf("%-30s %-20s %-12s %s\n", "Column", "Type", "Nullable", "Default");
    echo str_repeat("-", 80) . "\n";
    
    while ($row = pg_fetch_assoc($columnResult)) {
        echo sprintf("%-30s %-20s %-12s %s\n", 
            $row['column_name'], 
            $row['data_type'],
            $row['is_nullable'],
            $row['column_default'] ?: '-'
        );
    }
    
    exit(0);
}
?>
