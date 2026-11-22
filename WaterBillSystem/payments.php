<?php
require_once __DIR__ . '/../includes/init.php';
require_role('admin', 'staff');

$pdo = db();

$where  = ['1=1'];
$params = [];

$fromDate = trim((string)get('from', ''));
if ($fromDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
    $where[] = 'DATE(p.payment_date) >= ?';
    $params[] = $fromDate;
}

$toDate = trim((string)get('to', ''));
if ($toDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
    $where[] = 'DATE(p.payment_date) <= ?';
    $params[] = $toDate;
}

$method = (string)get('method', '');
if (in_array($method, ['cash', 'gcash', 'maya', 'bank_transfer', 'check'], true)) {
    $where[] = 'p.method = ?';
    $params[] = $method;
}

$q = trim((string)get('q', ''));
if ($q !== '') {
    $where[] = "(p.receipt_number LIKE ? OR c.customer_code LIKE ?
                 OR CONCAT(c.first_name,' ',c.last_name) LIKE ? OR b.bill_number LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}

$whereSql = implode(' AND ', $where);

$page    = max(1, (int)get('page', 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM payments p
     INNER JOIN bills b ON b.id = p.bill_id
     INNER JOIN customers c ON c.id = p.customer_id
     WHERE $whereSql"
);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));

$sql = "SELECT p.*, b.bill_number, b.billing_period,
               c.customer_code, c.first_name, c.last_name,
               u.full_name AS recorded_by_name
        FROM payments p
        INNER JOIN bills b ON b.id = p.bill_id
        INNER JOIN customers c ON c.id = p.customer_id
        LEFT JOIN users u ON u.id = p.recorded_by
        WHERE $whereSql
        ORDER BY p.payment_date DESC, p.id DESC
        LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

$todayTotal = (float)$pdo->query(
    "SELECT COALESCE(SUM(amount),0) FROM payments WHERE DATE(payment_date) = CURDATE()"
)->fetchColumn();

$monthTotal = (float)$pdo->query(
    "SELECT COALESCE(SUM(amount),0) FROM payments
     WHERE YEAR(payment_date)=YEAR(CURDATE()) AND MONTH(payment_date)=MONTH(CURDATE())"
)->fetchColumn();

$filteredTotal = 0.0;
if ($total > 0) {
    $sumStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(p.amount),0) FROM payments p
         INNER JOIN bills b ON b.id = p.bill_id
         INNER JOIN customers c ON c.id = p.customer_id
         WHERE $whereSql"
    );
    $sumStmt->execute($params);
    $filteredTotal = (float)$sumStmt->fetchColumn();
}

$allTimeTotal = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments")->fetchColumn();

$__title = 'Payments';
include __DIR__ . '/../includes/header.php';
?>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card d-flex justify-content-between">
      <div>
        <div class="label">Collected Today</div>
        <div class="value text-success"><?= e(money($todayTotal)) ?></div>
      </div>
      <div class="icon"><i class="bi bi-cash-stack"></i></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card d-flex justify-content-between">
      <div>
        <div class="label">This Month</div>
        <div class="value"><?= e(money($monthTotal)) ?></div>
      </div>
      <div class="icon"><i class="bi bi-calendar-check"></i></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card d-flex justify-content-between">
      <div>
        <div class="label">All-Time Collected</div>
        <div class="value"><?= e(money($allTimeTotal)) ?></div>
      </div>
      <div class="icon"><i class="bi bi-safe"></i></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card d-flex justify-content-between">
      <div>
        <div class="label">Filtered Sum</div>
        <div class="value text-primary"><?= e(money($filteredTotal)) ?></div>
        <small class="text-muted"><?= $total ?> payment(s)</small>
      </div>
      <div class="icon"><i class="bi bi-funnel"></i></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span>Payment Records</span>
    <a href="<?= e(url('/pages/payment-form.php')) ?>" class="btn btn-primary btn-sm">
      <i class="bi bi-plus-lg me-1"></i> Record Payment
    </a>
  </div>

  <div class="card-body border-bottom">
    <form class="row g-2" method="get">
      <div class="col-md-2">
        <label class="form-label small text-muted mb-1">From</label>
        <input type="date" name="from" class="form-control form-control-sm" value="<?= e($fromDate) ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label small text-muted mb-1">To</label>
        <input type="date" name="to" class="form-control form-control-sm" value="<?= e($toDate) ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label small text-muted mb-1">Method</label>
        <select name="method" class="form-select form-select-sm">
          <option value="">All Methods</option>
          <?php foreach (['cash','gcash','maya','bank_transfer','check'] as $m): ?>
            <option value="<?= $m ?>" <?= $method === $m ? 'selected' : '' ?>>
              <?= ucfirst(str_replace('_', ' ', $m)) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label small text-muted mb-1">Search</label>
        <div class="input-group input-group-sm">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input name="q" class="form-control" placeholder="Receipt #, customer, bill #…" value="<?= e($q) ?>">
        </div>
      </div>
      <div class="col-md-3 d-flex align-items-end gap-2">
        <button class="btn btn-sm btn-primary flex-grow-1">Filter</button>
        <a href="<?= e(url('/pages/payments.php')) ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
      </div>
    </form>
  </div>

  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Receipt #</th>
          <th>Date</th>
          <th>Customer</th>
          <th>Bill</th>
          <th>Method</th>
          <th class="text-end">Amount</th>
          <th>Recorded By</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$payments): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">No payments match your filters.</td></tr>
      <?php else: foreach ($payments as $p): ?>
        <tr>
          <td class="small fw-semibold"><?= e($p['receipt_number']) ?></td>
          <td class="small">
            <?= e(date('M j, Y', strtotime($p['payment_date']))) ?><br>
            <span class="text-muted"><?= e(date('g:i A', strtotime($p['payment_date']))) ?></span>
          </td>
          <td class="small">
            <a class="text-decoration-none" href="<?= e(url('/pages/customer-view.php?id=' . (int)$p['customer_id'])) ?>">
              <?= e($p['first_name'] . ' ' . $p['last_name']) ?>
            </a>
            <br><span class="text-muted"><?= e($p['customer_code']) ?></span>
          </td>
          <td class="small">
            <a class="text-decoration-none" href="<?= e(url('/pages/bill-view.php?id=' . (int)$p['bill_id'])) ?>">
              <?= e($p['bill_number']) ?>
            </a>
            <br><span class="text-muted"><?= e(period_label($p['billing_period'])) ?></span>
          </td>
          <td class="small text-capitalize"><?= e(str_replace('_', ' ', $p['method'])) ?>
            <?php if ($p['reference_no']): ?>
              <br><span class="text-muted"><?= e($p['reference_no']) ?></span>
            <?php endif; ?>
          </td>
          <td class="text-end fw-semibold text-success"><?= e(money($p['amount'])) ?></td>
          <td class="small text-muted"><?= e($p['recorded_by_name'] ?: '—') ?></td>
          <td class="text-end">
            <a class="btn btn-sm btn-outline-primary py-0"
               href="<?= e(url('/pages/receipt.php?id=' . (int)$p['id'])) ?>">Receipt</a>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pages > 1): ?>
  <div class="card-footer bg-white">
    <ul class="pagination pagination-sm mb-0 justify-content-center">
      <?php for ($pg = 1; $pg <= $pages; $pg++):
        $query = $_GET; $query['page'] = $pg; ?>
        <li class="page-item <?= $pg === $page ? 'active' : '' ?>">
          <a class="page-link" href="?<?= e(http_build_query($query)) ?>"><?= $pg ?></a>
        </li>
      <?php endfor; ?>
    </ul>
    <div class="text-center text-muted small mt-2">Showing <?= count($payments) ?> of <?= $total ?> payments</div>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>