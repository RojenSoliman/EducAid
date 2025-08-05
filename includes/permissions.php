<?php
// permissions.php - Role-based access control helper

function getCurrentAdminRole($connection) {
    if (isset($_SESSION['admin_id'])) {
        $roleQuery = pg_query_params($connection, "SELECT role FROM admins WHERE admin_id = $1", [$_SESSION['admin_id']]);
        $roleData = pg_fetch_assoc($roleQuery);
        return $roleData['role'] ?? 'super_admin';
    }
    return 'super_admin'; // Default for backward compatibility
}

function checkPermission($required_role, $connection, $redirect_on_fail = true) {
    $current_role = getCurrentAdminRole($connection);
    
    if ($required_role === 'super_admin' && $current_role !== 'super_admin') {
        if ($redirect_on_fail) {
            header("Location: homepage.php?error=access_denied");
            exit;
        }
        return false;
    }
    
    return true;
}

function hasPermission($required_role, $connection) {
    return checkPermission($required_role, $connection, false);
}

// Define which pages require super admin access
$super_admin_pages = [
    'manage_slots.php',
    'verify_students.php',
    'manage_schedules.php',
    'admin_management.php',
    'system_data.php',
    'settings.php'
];

function isRestrictedPage($page_name) {
    global $super_admin_pages;
    return in_array($page_name, $super_admin_pages);
}
?>
