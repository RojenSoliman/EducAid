<?php
// Debug script to check municipality logos in navbar
session_start();
require_once __DIR__ . '/config/database.php';

echo "<!DOCTYPE html><html><head><title>Debug Navbar Logo</title></head><body>";
echo "<h1>Municipality Logo Debug</h1>";

// Check session
echo "<h2>Session Data:</h2>";
echo "<pre>";
echo "admin_id: " . ($_SESSION['admin_id'] ?? 'NOT SET') . "\n";
echo "role: " . ($_SESSION['role'] ?? 'NOT SET') . "\n";
echo "active_municipality_id: " . ($_SESSION['active_municipality_id'] ?? 'NOT SET') . "\n";
echo "</pre>";

if (!isset($connection)) {
    echo "<p style='color:red;'>ERROR: Database connection not available!</p>";
    exit;
}

echo "<h2>Municipality Logos in Database:</h2>";
echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
echo "<tr><th>ID</th><th>Name</th><th>Preset Logo</th><th>Custom Logo</th><th>Active Logo (COALESCE)</th><th>Logo Preview</th></tr>";

$result = pg_query($connection, "
    SELECT 
        municipality_id,
        name,
        preset_logo_image,
        custom_logo_image,
        COALESCE(custom_logo_image, preset_logo_image) AS active_logo
    FROM municipalities 
    ORDER BY municipality_id ASC
");

if ($result) {
    while ($row = pg_fetch_assoc($result)) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['municipality_id']) . "</td>";
        echo "<td>" . htmlspecialchars($row['name']) . "</td>";
        echo "<td style='font-size:11px;'>" . htmlspecialchars($row['preset_logo_image'] ?? 'NULL') . "</td>";
        echo "<td style='font-size:11px;'>" . htmlspecialchars($row['custom_logo_image'] ?? 'NULL') . "</td>";
        echo "<td style='font-size:11px; font-weight:bold;'>" . htmlspecialchars($row['active_logo'] ?? 'NULL') . "</td>";
        
        // Try to display the logo
        echo "<td>";
        if (!empty($row['active_logo'])) {
            $logo_path = trim($row['active_logo']);
            
            // Build the path (same as navbar logic)
            if (preg_match('#^data:image/[^;]+;base64,#i', $logo_path)) {
                $src = $logo_path;
            } elseif (preg_match('#^(?:https?:)?//#i', $logo_path)) {
                $src = $logo_path;
            } else {
                $normalized = str_replace('\\', '/', $logo_path);
                $normalized = preg_replace('#(?<!:)/{2,}#', '/', $normalized);
                $normalized = ltrim($normalized, '/');
                $encoded = implode('/', array_map('rawurlencode', explode('/', $normalized)));
                $src = $encoded; // Root level, no base_path needed
            }
            
            echo "<div>";
            echo "<img src='" . htmlspecialchars($src) . "' style='height:40px; max-width:100px; object-fit:contain;' onerror=\"this.parentElement.querySelector('.error-msg').style.display='block'\">";
            echo "<div class='error-msg' style='display:none; color:red; font-size:10px;'>Failed to load</div>";
            echo "<div style='font-size:10px; margin-top:5px;'>Path: " . htmlspecialchars($src) . "</div>";
            echo "</div>";
        } else {
            echo "<em>No logo</em>";
        }
        echo "</td>";
        echo "</tr>";
    }
    pg_free_result($result);
} else {
    echo "<tr><td colspan='6' style='color:red;'>Query failed: " . pg_last_error($connection) . "</td></tr>";
}

echo "</table>";

// Check admin assignments
if (isset($_SESSION['admin_id'])) {
    echo "<h2>Admin Municipality Assignments:</h2>";
    $admin_id = (int)$_SESSION['admin_id'];
    $assign_result = pg_query_params(
        $connection,
        "SELECT am.municipality_id, m.name 
         FROM admin_municipality_assignments am
         JOIN municipalities m ON am.municipality_id = m.municipality_id
         WHERE am.admin_id = $1
         ORDER BY am.municipality_id ASC",
        [$admin_id]
    );
    
    if ($assign_result && pg_num_rows($assign_result) > 0) {
        echo "<ul>";
        while ($row = pg_fetch_assoc($assign_result)) {
            echo "<li>ID: " . $row['municipality_id'] . " - " . htmlspecialchars($row['name']) . "</li>";
        }
        echo "</ul>";
        pg_free_result($assign_result);
    } else {
        echo "<p>No assignments found</p>";
    }
}

echo "</body></html>";
?>
