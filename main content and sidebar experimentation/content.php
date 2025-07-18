<!-- main-content.php -->
<div class="main-content" id="mainContent">
    <?php
    // Default content if no page parameter is provided
    if (!isset($_GET['page'])) {
        include('dashboard.php');
    } else {
        // Include content based on the page query parameter
        $page = $_GET['page'];
        switch ($page) {
            case 'dashboard':
                include('dashboard.php');
                break;
            case 'profile':
                include('profile.php');
                break;
            case 'settings':
                include('settings.php');
                break;
            default:
                include('dashboard.php'); // Default content
        }
    }
    ?>
</div>
