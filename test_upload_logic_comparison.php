<?php
include 'config/database.php';

echo "<h1>Upload Progress Logic Comparison</h1>";

$student_id = 'TRIAS-0AA90A';
echo "<h3>Student: $student_id</h3>";

// OLD LOGIC (counts all documents)
$old_id_query = "SELECT COUNT(*) AS total_uploaded FROM documents WHERE student_id = $1 AND type IN ('id_picture')";
$old_id_result = pg_query_params($connection, $old_id_query, [$student_id]);
$old_id_row = pg_fetch_assoc($old_id_result);

$old_grades_query = "SELECT COUNT(*) AS grades_uploaded FROM documents WHERE student_id = $1 AND type = 'academic_grades'";
$old_grades_result = pg_query_params($connection, $old_grades_query, [$student_id]);
$old_grades_row = pg_fetch_assoc($old_grades_result);

// NEW LOGIC (only upload_document.php)
$new_id_query = "SELECT COUNT(*) AS total_uploaded FROM documents 
                WHERE student_id = $1 AND type IN ('id_picture') 
                AND file_path LIKE '%/assets/uploads/students/%'";
$new_id_result = pg_query_params($connection, $new_id_query, [$student_id]);
$new_id_row = pg_fetch_assoc($new_id_result);

$new_grades_query = "SELECT COUNT(*) AS grades_uploaded FROM documents 
                    WHERE student_id = $1 AND type = 'academic_grades'
                    AND file_path LIKE '%/assets/uploads/students/%'";
$new_grades_result = pg_query_params($connection, $new_grades_query, [$student_id]);
$new_grades_row = pg_fetch_assoc($new_grades_result);

echo "<table border='1' style='border-collapse: collapse; margin: 20px 0;'>";
echo "<tr style='background: #f0f0f0;'><th>Document Type</th><th>OLD Logic (All Sources)</th><th>NEW Logic (upload_document.php only)</th><th>Status Change</th></tr>";

$old_id_status = ($old_id_row['total_uploaded'] >= 1) ? 'Uploaded' : 'Required';
$new_id_status = ($new_id_row['total_uploaded'] >= 1) ? 'Uploaded' : 'Required';
$id_change = ($old_id_status != $new_id_status) ? '🔄 CHANGED' : '⚪ Same';

$old_grades_status = ($old_grades_row['grades_uploaded'] > 0) ? 'Uploaded' : 'Required';
$new_grades_status = ($new_grades_row['grades_uploaded'] > 0) ? 'Uploaded' : 'Required';
$grades_change = ($old_grades_status != $new_grades_status) ? '🔄 CHANGED' : '⚪ Same';

echo "<tr>";
echo "<td><strong>ID Picture</strong></td>";
echo "<td>{$old_id_row['total_uploaded']} → $old_id_status</td>";
echo "<td>{$new_id_row['total_uploaded']} → $new_id_status</td>";
echo "<td>$id_change</td>";
echo "</tr>";

echo "<tr>";
echo "<td><strong>Academic Grades</strong></td>";
echo "<td>{$old_grades_row['grades_uploaded']} → $old_grades_status</td>";
echo "<td>{$new_grades_row['grades_uploaded']} → $new_grades_status</td>";
echo "<td>$grades_change</td>";
echo "</tr>";

echo "</table>";

echo "<h3>Summary</h3>";
echo "<p>✅ <strong>NEW Logic:</strong> Only counts documents uploaded through the upload_document.php page</p>";
echo "<p>❌ <strong>OLD Logic:</strong> Counted documents from registration, admin uploads, etc.</p>";

if ($new_id_status == 'Required' && $new_grades_status == 'Required') {
    echo "<p>🎯 <strong>Perfect!</strong> Both documents now show 'Required' - user can test fresh uploads</p>";
}

pg_close($connection);
?>