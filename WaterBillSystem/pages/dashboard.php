<?php
require_once __DIR__ . '/../includes/init.php';
require_login();
$u = auth_user(); $role = $u['role']; $pdo = db();
$__title = 'Dashboard';
include __DIR__ . '/../includes/header.php';
?>

<div class="alert alert-success d-flex align-items-center">
  <i class="bi bi-check-circle-fill fs-3 me-3"></i>
  <div>
    <h5 class="alert-heading">Foundation successfully installed!</h5>
    <p class="mb-0">You are logged in as <strong><?= e($u['full_name']) ?></strong> (<?= e($role) ?>).</p>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card d-flex justify-content-between">
      <div><div class="label">Database</div><div class="value text-success">Connected</div></div>
      <div class="icon"><i class="bi bi-database-check"></i></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header">Next Steps</div>
  <div class="card-body">
    <p>The core framework, database connection, and authentication are working perfectly.</p>
    <p class="mb-0">To install the remaining modules (Customers, Readings, Billing, Payments, Reports), continue to the next batch of PowerShell scripts.</p>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>