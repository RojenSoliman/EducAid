<!-- main-content.php -->
<div class="main-content" id="mainContent">
    <?php
    // Check the 'page' parameter to determine which content to display
    if (!isset($_GET['page'])) {
        include('content-dashboard.php'); // Default content if no page is set
    } else {
        $page = $_GET['page'];
        switch ($page) {
            case 'dashboard':
                include('content-dashboard.php');
                break;
            case 'applications':
                include('content-applications.php');
                break;
            case 'documents':
                include('content-documents.php');
                break;
            case 'profile':
                include('content-profile.php');
                break;
            case 'logout':
                include('content-logout.php');
                break;
            default:
                include('content-dashboard.php'); // Default content
        }
    }
    ?>
</div>
