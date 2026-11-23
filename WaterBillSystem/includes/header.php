<?php
declare(strict_types=1);

$__user  = auth_user();
$__title = $__title ?? 'Dashboard';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($__title) ?> — <?= e(APP_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= e(url('/assets/css/style.css')) ?>" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
</head>
<body>
<div class="wb-sidebar-backdrop" id="sidebarBackdrop"></div>

<?php include __DIR__ . '/sidebar.php'; ?>

<div class="wb-main">
  <header class="wb-topbar">
    <div class="d-flex align-items-center gap-3">
      <button class="btn btn-sm btn-light d-lg-none" id="sidebarToggle" aria-label="Toggle navigation">
        <i class="bi bi-list fs-5"></i>
      </button>
      <div>
        <h5 class="mb-0 fw-semibold"><?= e($__title) ?></h5>
        <small class="text-muted"><?= date('l, F j, Y') ?></small>
      </div>
    </div>
    <div class="d-flex align-items-center gap-3">
      <span class="badge text-bg-light border text-uppercase" style="letter-spacing:.04em">
        <i class="bi bi-shield-check me-1"></i><?= e($__user['role']) ?>
      </span>
      <div class="dropdown">
        <button class="btn btn-sm btn-light dropdown-toggle d-flex align-items-center gap-2" data-bs-toggle="dropdown">
          <i class="bi bi-person-circle fs-5"></i>
          <span class="d-none d-sm-inline"><?= e($__user['full_name']) ?></span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
          <li><a class="dropdown-item" href="<?= e(url('/pages/profile.php')) ?>"><i class="bi bi-gear me-2"></i>My Profile</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item text-danger" href="<?= e(url('/logout.php')) ?>"><i class="bi bi-box-arrow-right me-2"></i>Sign Out</a></li>
        </ul>
      </div>
    </div>
  </header>

  <main class="wb-content">
    <?php foreach (get_flashes() as $f): ?>
      <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show py-2">
        <?= e($f['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endforeach; ?>