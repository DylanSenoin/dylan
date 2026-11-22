<?php
require_once __DIR__ . '/../includes/init.php';
require_role('admin', 'staff');

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'sweep_overdue') {
    verify_csrf();

    $stmt = $pdo->prepare(
        "SELECT b.id, b.total_amount, b.balance, b.penalty, wr.penalty_rate
         FROM bills b
         INNER JOIN water_rates wr ON wr.id = b.rate_id
         WHERE b.due_date < CURDATE()
           AND b.status IN ('unpaid', 'partial')"
    );
    $stmt->execute();
    $targets = $stmt->fetchAll();

    $penaltyEnabled = setting('penalty_enabled', '1') === '1';
    $penalized = 0;
    $flipped   = 0;

    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare(
            'UPDATE bills
             SET penalty = penalty + ?,
                 total_amount = total_amount + ?,
                 balance = balance + ?,
                 status = "overdue"
             WHERE id = ?'
        );
        foreach ($targets as $b) {
            $add = 0.0;
            if ($penaltyEnabled && (float)$b['penalty'] === 0.0 && (float)$b['penalty_rate'] > 0) {
                $add = round((float)$b['balance'] * ((float)$b['penalty_rate'] / 100), 2);
                $penalized++;
            }
            $update->execute([$add, $add, $add, (int)$b['id']]);
            $flipped++;
        }
        $pdo->commit();
        log_activity('SWEEP', 'Bills', "Overdue sweep: $flipped bill(s) flagged, $penalized penalized");
        flash('success', "Overdue sweep complete: $flipped bill(s) flagged as overdue" .
            ($penalized ? ", $penalized with penalty applied." : '.'));
    } catch (Throwable $ex) {
        $pdo->rollBack();
        flash('danger', 'Sweep failed: ' . $ex->getMessage());
    }
    redirect('/pages/bills.php');
}

$where  = ['1=1'];
$params = [];

$period = trim((string)get('period', ''));
if ($period !== '' && preg_match('/^\d{4}-\d{2}$/', $period)) {
    $where[] = 'b.billing_period = ?';
    $params[] = $period . '-01';
}

$status = (string)get('status', '');
if (in_array($status, ['unpaid', 'partial', 'paid', 'overdue'], true)) {
    $where[] = 'b.status = ?';
    $params[] = $status;
}

$q = trim((string)get('q', ''));
if ($q !== '') {
    $where[] = "(b.bill_number LIKE ? OR c.customer_code LIKE ?
                 OR CONCAT(c.first_name,' ',c.last_name) LIKE ? OR c.meter_number LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}

$whereSql = implode(' AND ', $where);

$page    = max(1, (int)get('page', 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM bills b INNER JOIN customers c ON c.id = b.customer_id WHERE $whereSql"
);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));

$sql = "SELECT b.*, c.customer_code, c.first_name, c.last_name, c.meter_number
        FROM bills b
        INNER JOIN customers c ON c.id = b.customer_id
        WHERE $whereSql
        ORDER BY b.billing_period DESC, b.id DESC
        LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$bills = $stmt->fetchAll();

$totalBills   = (int)$pdo->query('SELECT COUNT(*) FROM bills')->fetchColumn();
$totalBilled  = (float)$pdo->query('SELECT COALESCE(SUM(total_amount),0) FROM bills')->fetchColumn();
$totalCollect = (float)$pdo->query('SELECT COALESCE(SUM(amount_paid),0) FROM bills')->fetchColumn();
$outstanding  = (float)$pdo->query(
    "SELECT COALESCE(SUM(balance),0) FROM bills WHERE status IN ('unpaid','partial','overdue')"
)->fetchColumn();
$overdueCount = (int)$pdo->query(
    "SELECT COUNT(*) FROM bills WHERE status = 'overdue'"
)->fetchColumn();

$unbilled = (int)$pdo->query(
    'SELECT COUNT(*) FROM meter_readings mr LEFT JOIN bills b ON b.reading_id = mr.id WHERE b.id IS NULL'
)->fetchColumn();

$__title = 'Water Billing';
include __DIR__ . '/../includes/header.php';
?>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card d-flex justify-content-between">
      <div>
        <div class="label">Bills Generated</div>
        <div class="value"><?= number_format($totalBills) ?></div>
        <small class="text-muted"><?= number_format($unbilled) ?> reading(s) unbilled</small>
      </div>
      <div class="icon"><i class="bi bi-receipt"></i></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card d-flex justify-content-between">
      <div>
        <div class="label">Total Billed</div>
        <div class="value"><?= e(money($totalBilled)) ?></div>
        <small class="text-success"><?= e(money($totalCollect)) ?> collected</small>
      </div>
      <div class="icon"><i class="bi bi-file-earmark-text"></i></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card d-flex justify-content-between">
      <div>
        <div class="label">Outstanding</div>
        <div class="value text-danger"><?= e(money($outstanding)) ?></div>
        <small class="text-muted">across unpaid bills</small>
      </div>
      <div class="icon"><i class="bi bi-exclamation-triangle"></i></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card d-flex justify-content-between">
      <div>
        <div class="label">Overdue Bills</div>
        <div class="value text-danger"><?= number_format($overdueCount) ?></div>
        <small class="text-muted">flagged by sweep</small>
      </div>
      <div class="icon"><i class="bi bi-clock-history"></i></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span>Bills</span>
    <div class="d-flex gap-2">
      <form method="post" class="d-inline" onsubmit="return confirm('Run the overdue sweep? Bills past their due date will be flagged and penalized (if enabled).')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="sweep_overdue">
        <button class="btn btn-outline-warning btn-sm">
          <i class="bi bi-arrow-repeat me-1"></i> Sweep Overdue
        </button>
      </form>
      <a href="<?= e(url('/pages/bill-generate.php')) ?>" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i> Generate Bills<?= $unbilled > 0 ? " ($unbilled)" : '' ?>
      </a>
    </div>
  </div>

  <div class="card-body border-bottom">
    <form class="row g-2" method="get">
      <div class="col-md-2">
        <input type="month" name="period" class="form-control form-control-sm" value="<?= e($period) ?>">
      </div>
      <div class="col-md-2">
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <?php foreach (['unpaid','partial','paid','overdue'] as $s): ?>
            <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-5">
        <div class="input-group input-group-sm">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input name="q" class="form-control" placeholder="Search bill #, customer, or meter #…" value="<?= e($q) ?>">
        </div>
      </div>
      <div class="col-md-3 d-flex gap-2">
        <button class="btn btn-sm btn-primary flex-grow-1">Filter</button>
        <a href="<?= e(url('/pages/bills.php')) ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
      </div>
    </form>
  </div>

  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Bill #</th>
          <th>Customer</th>
          <th>Period</th>
          <th class="text-end">Consumption</th>
          <th class="text-end">Total</th>
          <th class="text-end">Balance</th>
          <th>Due Date</th>
          <th>Status</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$bills): ?>
        <tr><td colspan="9" class="text-center text-muted py-4">
          No bills match your filters.<?= $unbilled > 0 ? ' You have ' . $unbilled . ' reading(s) waiting to be billed.' : '' ?>
        </td></tr>
      <?php else: foreach ($bills as $b): ?>
        <tr>
          <td class="small fw-semibold"><?= e($b['bill_number']) ?></td>
          <td class="small">
            <a class="text-decoration-none" href="<?= e(url('/pages/customer-view.php?id=' . (int)$b['customer_id'])) ?>">
              <?= e($b['first_name'] . ' ' . $b['last_name']) ?>
            </a>
            <br><span class="text-muted"><?= e($b['customer_code']) ?></span>
          </td>
          <td class="small"><?= e(period_label($b['billing_period'])) ?></td>
          <td class="text-end small"><?= number_format((float)$b['consumption'], 2) ?> m³</td>
          <td class="text-end"><?= e(money($b['total_amount'])) ?></td>
          <td class="text-end fw-semibold <?= (float)$b['balance'] > 0 ? 'text-danger' : 'text-success' ?>">
            <?= e(money($b['balance'])) ?>
          </td>
          <td class="small <?= strtotime($b['due_date']) < time() && $b['status'] !== 'paid' ? 'text-danger' : '' ?>">
            <?= e(date('M j, Y', strtotime($b['due_date']))) ?>
          </td>
          <td>
            <span class="badge text-capitalize text-bg-<?= match($b['status']) {
              'paid' => 'success', 'overdue' => 'danger', 'partial' => 'info', default => 'warning'
            } ?>"><?= e($b['status']) ?></span>
          </td>
          <td class="text-end">
            <a class="btn btn-sm btn-outline-primary py-0"
               href="<?= e(url('/pages/bill-view.php?id=' . (int)$b['id'])) ?>">View</a>
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
    <div class="text-center text-muted small mt-2">Showing <?= count($bills) ?> of <?= $total ?> bills</div>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>