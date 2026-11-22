<?php
require_once __DIR__ . '/../includes/init.php';
require_role('admin', 'staff');

$pdo = db();

$where  = ['1=1'];
$params = [];

$month = trim((string)get('month', ''));
if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
    $where[] = 'mr.billing_period = ?';
    $params[] = $month . '-01';
}

$q = trim((string)get('q', ''));
if ($q !== '') {
    $where[] = "(c.customer_code LIKE ? OR CONCAT(c.first_name,' ',c.last_name) LIKE ? OR c.meter_number LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}

$billStatus = (string)get('bill_status', '');
if ($billStatus === 'billed') {
    $where[] = 'b.id IS NOT NULL';
} elseif ($billStatus === 'unbilled') {
    $where[] = 'b.id IS NULL';
}

$whereSql = implode(' AND ', $where);

$page    = max(1, (int)get('page', 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$countSql = "SELECT COUNT(*)
             FROM meter_readings mr
             INNER JOIN customers c ON c.id = mr.customer_id
             LEFT JOIN bills b ON b.reading_id = mr.id
             WHERE $whereSql";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));

$sql = "SELECT mr.*,
          c.customer_code, c.first_name, c.last_name, c.meter_number, c.id AS customer_id,
          b.id AS bill_id, b.bill_number, b.status AS bill_status
        FROM meter_readings mr
        INNER JOIN customers c ON c.id = mr.customer_id
        LEFT JOIN bills b ON b.reading_id = mr.id
        WHERE $whereSql
        ORDER BY mr.billing_period DESC, c.last_name, c.first_name
        LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$readings = $stmt->fetchAll();

$firstOfMonth = date('Y-m-01');
$stmt = $pdo->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(consumption),0) AS c FROM meter_readings WHERE billing_period = ?');
$stmt->execute([$firstOfMonth]);
$monthStats = $stmt->fetch();

$unbilledCount = (int)$pdo->query(
    'SELECT COUNT(*) FROM meter_readings mr LEFT JOIN bills b ON b.reading_id = mr.id WHERE b.id IS NULL'
)->fetchColumn();

$totalReadings = (int)$pdo->query('SELECT COUNT(*) FROM meter_readings')->fetchColumn();

$__title = 'Meter Readings';
include __DIR__ . '/../includes/header.php';
?>

<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card d-flex justify-content-between">
      <div>
        <div class="label">Readings This Month</div>
        <div class="value"><?= number_format((int)$monthStats['n']) ?></div>
      </div>
      <div class="icon"><i class="bi bi-speedometer"></i></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card d-flex justify-content-between">
      <div>
        <div class="label">Consumption This Month</div>
        <div class="value"><?= number_format((float)$monthStats['c'], 2) ?> <small class="text-muted fs-6">m³</small></div>
      </div>
      <div class="icon"><i class="bi bi-droplet"></i></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card d-flex justify-content-between">
      <div>
        <div class="label">Unbilled Readings</div>
        <div class="value text-warning"><?= number_format($unbilledCount) ?></div>
        <small class="text-muted"><?= number_format($totalReadings) ?> total readings</small>
      </div>
      <div class="icon"><i class="bi bi-hourglass-split"></i></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span>Reading History</span>
    <a href="<?= e(url('/pages/reading-form.php')) ?>" class="btn btn-primary btn-sm">
      <i class="bi bi-plus-lg me-1"></i> Record Reading
    </a>
  </div>

  <div class="card-body border-bottom">
    <form class="row g-2" method="get">
      <div class="col-md-3">
        <input type="month" name="month" class="form-control form-control-sm" value="<?= e($month) ?>">
      </div>
      <div class="col-md-4">
        <div class="input-group input-group-sm">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input name="q" class="form-control" placeholder="Search customer or meter #…" value="<?= e($q) ?>">
        </div>
      </div>
      <div class="col-md-2">
        <select name="bill_status" class="form-select form-select-sm">
          <option value="">All Readings</option>
          <option value="billed"   <?= $billStatus === 'billed'   ? 'selected' : '' ?>>Billed</option>
          <option value="unbilled" <?= $billStatus === 'unbilled' ? 'selected' : '' ?>>Unbilled</option>
        </select>
      </div>
      <div class="col-md-3 d-flex gap-2">
        <button class="btn btn-sm btn-primary flex-grow-1">Filter</button>
        <a href="<?= e(url('/pages/readings.php')) ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
      </div>
    </form>
  </div>

  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Billing Period</th>
          <th>Customer</th>
          <th>Reading Date</th>
          <th class="text-end">Previous</th>
          <th class="text-end">Current</th>
          <th class="text-end">Consumption</th>
          <th>Bill</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$readings): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">No meter readings match your filters.</td></tr>
      <?php else: foreach ($readings as $r): ?>
        <tr>
          <td class="small fw-semibold"><?= e(period_label($r['billing_period'])) ?></td>
          <td class="small">
            <a class="text-decoration-none" href="<?= e(url('/pages/customer-view.php?id=' . (int)$r['customer_id'])) ?>">
              <?= e($r['first_name'] . ' ' . $r['last_name']) ?>
            </a>
            <br><span class="text-muted"><?= e($r['customer_code']) ?> · Meter <?= e($r['meter_number']) ?></span>
          </td>
          <td class="small"><?= e(date('M j, Y', strtotime($r['reading_date']))) ?></td>
          <td class="text-end small"><?= number_format((float)$r['previous_reading'], 2) ?></td>
          <td class="text-end small"><?= number_format((float)$r['current_reading'], 2) ?></td>
          <td class="text-end fw-semibold"><?= number_format((float)$r['consumption'], 2) ?> m³</td>
          <td>
            <?php if ($r['bill_id']): ?>
              <span class="badge text-bg-<?= $r['bill_status'] === 'paid' ? 'success' : ($r['bill_status'] === 'overdue' ? 'danger' : 'warning') ?>">
                <?= e($r['bill_number']) ?>
              </span>
            <?php else: ?>
              <span class="badge text-bg-light text-muted border">Unbilled</span>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <?php if (!$r['bill_id']): ?>
              <a class="btn btn-sm btn-outline-secondary py-0"
                 href="<?= e(url('/pages/reading-form.php?id=' . (int)$r['id'])) ?>">Edit</a>
            <?php else: ?>
              <span class="text-muted small">Locked</span>
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
    <div class="text-center text-muted small mt-2">Showing <?= count($readings) ?> of <?= $total ?> readings</div>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>