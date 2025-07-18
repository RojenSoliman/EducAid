<!-- sidebar.php -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <button id="toggleSidebar" class="toggle-btn">&#9776;</button> <!-- Hamburger Icon -->
        <h2>Sidebar Title</h2>
    </div>
    <ul class="sidebar-links">
        <li><a href="#">Dashboard</a></li>
        <li><a href="#">Applications</a></li>
        <li><a href="#">Documents</a></li>
        <li><a href="#">My Profile</a></li>
        <li><a href="#">Logout</a></li>

        <?php
        // Example PHP logic to show admin-specific content
        $userRole = 'admin'; // Hardcoded role for experimentation

        if ($userRole === 'admin'): ?>
            <li><a href="#">Admin Panel</a></li>
            <li><a href="#">Manage Users</a></li>
        <?php endif; ?>
    </ul>
</div>
