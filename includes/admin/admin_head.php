<?php
// Unified head include for admin pages
// Usage:
//   $page_title = 'Dashboard'; // optional
//   $extra_css = ['../../assets/css/admin/manage_applicants.css']; // optional array of extra CSS hrefs
// Then: include '../../includes/admin/admin_head.php';
if (!isset($page_title) || trim($page_title) === '') {
    $page_title = 'Admin';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= htmlspecialchars($page_title) ?> - EducAid Admin</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" />
  <link rel="stylesheet" href="../../assets/css/admin/homepage.css" />
  <link rel="stylesheet" href="../../assets/css/admin/sidebar.css" />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet" />
<?php if (!empty($extra_css) && is_array($extra_css)): foreach ($extra_css as $cssFile): ?>
  <link rel="stylesheet" href="<?= htmlspecialchars($cssFile) ?>" />
<?php endforeach; endif; ?>
</head>
