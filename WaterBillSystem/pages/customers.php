<?php
require_once __DIR__ . '/../includes/init.php';
require_role('admin', 'staff');

$pdo = db();

$where  = ['1=1'];
$params = [];

$q = trim((string)get('q', ''));
if ($q !== '') {
    $where[] = "(c.customer_code LIKE ? OR CONCAT(c.first_name,' ',c.last_name) LIKE ?
                 OR c.meter_number LIKE ? OR c.address LIKE ? OR c.barangay LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

$connType = (string)get('connection_type', '');
if (in_array($connType, ['residential', 'commercial', 'government'], true)) {
    $where[] = 'c.connection_type = ?';
    $params[] = $connType;
}

$status = (string)get('status', '');
if (in_array($status, ['active', 'inactive'], true)) {
    $where[] = 'c.status = ?';
    $params[] = $status;
}

$whereSql = implode(' AND ', $where);

$page    = max(1, (int)get('page', 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM customers c WHERE $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));

$sql = "SELECT c.*,
               (SELECT COALESCE(SUM(balance), 0)
                FROM bills b
                WHERE b.customer_id = c.id AND b.status IN ('unpaid','partial','overdue')) AS outstanding_balance
        FROM customers c
        WHERE $whereSql
        ORDER BY c.last_name ASC, c.first_name ASC, c.id DESC
        LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();

$totalCustomers  = (int)$pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();
$activeCustomers = (int)$pdo->query("SELECT COUNT(*) FROM customers WHERE status = 'active'")->fetchColumn();
$inactiveCount   = $totalCustomers - $activeCustomers;

$__title = 'Customers';
include __DIR__ . '/../includes/header.php';
?>

<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card d-flex justify-content-between">
      <div>
        <div class="label">Total Customers</div>
        <div class="value"><?= number_format($totalCustomers) ?></div>
      </div>
      <div class="icon"><i class="bi bi-people"></i></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card d-flex justify-content-between">
      <div>
        <div class="label">Active Accounts</div>
        <div class="value text-success"><?= number_format($activeCustomers) ?></div>
      </div>
      <div class="icon"><i class="bi bi-person-check"></i></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card d-flex justify-content-between">
      <div>
        <div class="label">Inactive Accounts</div>
        <div class="value text-muted"><?= number_format($inactiveCount) ?></div>
      </div>
      <div class="icon"><i class="bi bi-person-slash"></i></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span>Customer Directory</span>
    <a href="<?= e(url('/pages/customer-form.php')) ?>" class="btn btn-primary btn-sm">
      <i class="bi bi-person-plus me-1"></i> Add Customer
    </a>
  </div>

  <div class="card-body border-bottom">
    <form class="row g-2" method="get">
      <div class="col-md-5">
        <div class="input-group input-group-sm">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input name="q" class="form-control" placeholder="Search code, name, meter #, address, barangay…" value="<?= e($q) ?>">
        </div>
      </div>
      <div class="col-md-2">
        <select name="connection_type" class="form-select form-select-sm">
          <option value="">All Types</option>
          <?php foreach (['residential', 'commercial', 'government'] as $t): ?>
            <option value="<?= $t ?>" <?= $connType === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <option value="active"   <?= $status === 'active'   ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>
      <div class="col-md-3 d-flex gap-2">
        <button class="btn btn-sm btn-primary flex-grow-1">Filter</button>
        <a href="<?= e(url('/pages/customers.php')) ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
      </div>
    </form>
  </div>

  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Code</th>
          <th>Customer</th>
          <th>Meter #</th>
          <th>Type</th>
          <th>Address / Barangay</th>
          <th class="text-end">Balance</th>
          <th>Status</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$customers): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">No customers match your filters.</td></tr>
      <?php else: foreach ($customers as $c): ?>
        <tr>
          <td class="small fw-semibold"><?= e($c['customer_code']) ?></td>
          <td class="small">
            <a class="text-decoration-none fw-semibold"
               href="<?= e(url('/pages/customer-view.php?id=' . (int)$c['id'])) ?>">
              <?= e($c['first_name'] . ' ' . $c['last_name']) ?>
            </a>
            <?php if ($c['phone'] || $c['email']): ?>
              <br><span class="text-muted"><?= e(trim(($c['phone'] ?? '') . ($c['phone'] && $c['email'] ? ' · ' : '') . ($c['email'] ?? ''))) ?></span>
            <?php endif; ?>
          </td>
          <td class="small"><?= e($c['meter_number']) ?></td>
          <td>
            <span class="badge text-bg-light border text-capitalize"><?= e($c['connection_type']) ?></span>
          </td>
          <td class="small text-muted">
            <?= e($c['address']) ?>
            <?php if ($c['barangay'] || $c['city']): ?>
              <br><?= e(trim(($c['barangay'] ?? '') . ', ' . ($c['city'] ?? ''), ', ')) ?>
            <?php endif; ?>
          </td>
          <td class="text-end small fw-semibold <?= (float)$c['outstanding_balance'] > 0 ? 'text-danger' : 'text-success' ?>">
            <?= e(money($c['outstanding_balance'])) ?>
          </td>
          <td>
            <span class="badge text-capitalize <?= $c['status'] === 'active' ? 'text-bg-success' : 'text-bg-secondary' ?>">
              <?= e($c['status']) ?>
            </span>
          </td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-outline-primary py-0"
               href="<?= e(url('/pages/customer-view.php?id=' . (int)$c['id'])) ?>">View</a>
            <a class="btn btn-sm btn-outline-secondary py-0"
               href="<?= e(url('/pages/customer-form.php?id=' . (int)$c['id'])) ?>">Edit</a>
            <?php if ($c['status'] === 'active'): ?>
              <a class="btn btn-sm btn-outline-info py-0"
                 href="<?= e(url('/pages/reading-form.php?customer=' . (int)$c['id'])) ?>">+ Reading</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pages > 1): ?>
  <div class="card-footer bg-white">
    <ul class="pagination pagination-sm mb-0 justify-content-center">
      <?php for ($p = 1; $p <= $pages; $p++):
        $query = $_GET; $query['page'] = $p; ?>
        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
          <a class="page-link" href="?<?= e(http_build_query($query)) ?>"><?= $p ?></a>
        </li>
      <?php endfor; ?>
    </ul>
    <div class="text-center text-muted small mt-2">Showing <?= count($customers) ?> of <?= $total ?> customers</div>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
