<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/statement_render.php';
require_role('admin', 'staff');

$pdo = db();

$period = trim((string)get('period', date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
    $period = date('Y-m');
}
$periodStart = $period . '-01';

$q = trim((string)get('q', ''));
$where  = ['b.billing_period = ?'];
$params = [$periodStart];
if ($q !== '') {
    $where[] = "(c.customer_code LIKE ? OR CONCAT(c.first_name,' ',c.last_name) LIKE ? OR b.bill_number LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
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

$stmt = $pdo->prepare(
    "SELECT b.*, c.customer_code, c.first_name, c.last_name, c.meter_number
     FROM bills b
     INNER JOIN customers c ON c.id = b.customer_id
     WHERE $whereSql
     ORDER BY c.last_name, c.first_name
     LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$bills = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT COUNT(*) AS n,
            COALESCE(SUM(total_amount),0) AS billed,
            COALESCE(SUM(amount_paid),0) AS paid,
            COALESCE(SUM(balance),0) AS balance
     FROM bills WHERE billing_period = ?"
);
$stmt->execute([$periodStart]);
$agg = $stmt->fetch();

$__title = 'Billing Statements';
include __DIR__ . '/../includes/header.php';
?>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card d-flex justify-content-between">
      <div>
        <div class="label">Statements This Period</div>
        <div class="value"><?= number_format((int)$agg['n']) ?></div>
        <small class="text-muted"><?= e(period_label($periodStart)) ?></small>
      </div>
      <div class="icon"><i class="bi bi-file-earmark-text"></i></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card d-flex justify-content-between">
      <div>
        <div class="label">Total Billed</div>
        <div class="value"><?= e(money($agg['billed'])) ?></div>
      </div>
      <div class="icon"><i class="bi bi-receipt"></i></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card d-flex justify-content-between">
      <div>
        <div class="label">Collected</div>
        <div class="value text-success"><?= e(money($agg['paid'])) ?></div>
      </div>
      <div class="icon"><i class="bi bi-cash-stack"></i></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card d-flex justify-content-between">
      <div>
        <div class="label">Outstanding</div>
        <div class="value text-danger"><?= e(money($agg['balance'])) ?></div>
      </div>
      <div class="icon"><i class="bi bi-exclamation-triangle"></i></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header">Statements by Billing Period</div>

  <div class="card-body border-bottom">
    <form class="row g-2" method="get">
      <div class="col-md-3">
        <label class="form-label small text-muted mb-1">Billing Period</label>
        <input type="month" name="period" class="form-control form-control-sm" value="<?= e($period) ?>">
      </div>
      <div class="col-md-5">
        <label class="form-label small text-muted mb-1">Search</label>
        <div class="input-group input-group-sm">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input name="q" class="form-control" placeholder="Customer or bill number…" value="<?= e($q) ?>">
        </div>
      </div>
      <div class="col-md-4 d-flex align-items-end gap-2">
        <button class="btn btn-sm btn-primary flex-grow-1">Load</button>
        <a href="<?= e(url('/pages/statements.php')) ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
      </div>
    </form>
  </div>

  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Bill #</th>
          <th>Customer</th>
          <th>Meter #</th>
          <th class="text-end">Consumption</th>
          <th class="text-end">Total</th>
          <th class="text-end">Balance</th>
          <th>Status</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$bills): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">
          No bills for <?= e(period_label($periodStart)) ?>.
          <a href="<?= e(url('/pages/bill-generate.php?period=' . urlencode($period))) ?>" class="ms-2">Generate bills →</a>
        </td></tr>
      <?php else: foreach ($bills as $b): ?>
        <tr>
          <td class="small fw-semibold"><?= e($b['bill_number']) ?></td>
          <td class="small"><?= e($b['first_name'] . ' ' . $b['last_name']) ?>
            <br><span class="text-muted"><?= e($b['customer_code']) ?></span></td>
          <td class="small"><?= e($b['meter_number']) ?></td>
          <td class="text-end small"><?= number_format((float)$b['consumption'], 2) ?> m³</td>
          <td class="text-end"><?= e(money($b['total_amount'])) ?></td>
          <td class="text-end fw-semibold <?= (float)$b['balance'] > 0 ? 'text-danger' : 'text-success' ?>">
            <?= e(money($b['balance'])) ?>
          </td>
          <td>
            <span class="badge text-capitalize text-bg-<?= match($b['status']) {
              'paid' => 'success', 'overdue' => 'danger', 'partial' => 'info', default => 'warning'
            } ?>"><?= e($b['status']) ?></span>
          </td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-outline-secondary py-0"
               href="<?= e(url('/pages/statement-view.php?id=' . (int)$b['id'])) ?>">View</a>
            <a class="btn btn-sm btn-outline-primary py-0"
               href="<?= e(url('/pages/statement-pdf.php?id=' . (int)$b['id'])) ?>">PDF</a>
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
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>