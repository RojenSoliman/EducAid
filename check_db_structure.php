<?php
include 'config/database.php';

echo "=== STUDENTS TABLE STRUCTURE ===\n";
$result = pg_query($connection, "SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_name = 'students' ORDER BY ordinal_position;");
while($row = pg_fetch_assoc($result)) {
    echo $row['column_name'] . ' - ' . $row['data_type'] . ' (' . $row['is_nullable'] . ')' . "\n";
}

echo "\n=== ALL TABLES IN DATABASE ===\n";
$result = pg_query($connection, "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name;");
while($row = pg_fetch_assoc($result)) {
    echo $row['table_name'] . "\n";
}

echo "\n=== CHECKING FOR PROFILE/IMAGE COLUMNS ===\n";
$result = pg_query($connection, "SELECT table_name, column_name, data_type FROM information_schema.columns WHERE column_name LIKE '%profile%' OR column_name LIKE '%image%' OR column_name LIKE '%photo%' OR column_name LIKE '%picture%' OR column_name LIKE '%avatar%';");
while($row = pg_fetch_assoc($result)) {
    echo $row['table_name'] . '.' . $row['column_name'] . ' - ' . $row['data_type'] . "\n";
}
?>