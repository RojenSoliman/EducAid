<?php
$page = $_GET['page'] ?? 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>EduAid - <?= ucfirst($page) ?></title>
  <link rel="stylesheet" href="assets/style.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
  <div class="wrapper">
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-container">
      <?php include 'includes/header.php'; ?>

      <main>
        <?php
          $pageFile = "includes/pages/$page.php";
          if (file_exists($pageFile)) {
            include $pageFile;
          } else {
            echo "<h2>404 - Page not found</h2>";
          }
        ?>
      </main>

      <?php include 'includes/footer.php'; ?>
    </div>
  </div>

  <script src="scripts/sidebar.js"></script>
</body>
</html>
