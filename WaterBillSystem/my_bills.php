<?php
require_once __DIR__ . '/../includes/init.php';
require_role('customer');

$u   = auth_user();
$pdo = db();
$cid = (int)($u['customer_id'] ?? 0);

if (!$cid) {
    flash('danger', 'Your account is not linked to a customer record. Contact your water provider.');
    redirect('/pages/dashboard.php');
}

$where  = ['b.customer_id = ?'];
$params = [$cid];

$status = (string)get('status', '');
if (in_array($status, ['paid', 'unpaid', 'partial', 'overdue'], true)) {
    $where[] = 'b.status = ?';
    $params[] = $status;
}

$whereSql = implode(' AND ', $where);

$page    = max(1, (int)get('page', 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM bills b WHERE $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));

$stmt = $pdo->prepare(
    "SELECT b.*, mr.previous_reading, mr.current_reading
     FROM bills b
     INNER JOIN meter_readings mr ON mr.id = b.reading_id
     WHERE $whereSql
     ORDER BY b.billing_period DESC
     LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$bills = $stmt->fetchAll();

$__title = 'My Bills';
include __DIR__ . '/../includes/header.php';
?>

<?php if (!$bills && $total === 0 && !$status): ?>
  <div class="card">
    <div class="card-body text-center py-5">
      <div class="text-muted mb-3" style="font-size:3rem"><i class="bi bi-receipt"></i></div>
      <h5>No bills yet</h5>
      <p class="text-muted mb-0">
        Your water bills will appear here once your provider generates your first statement.
      </p>
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span>Billing History</span>
      <form method="get" class="d-flex gap-2">
        <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">All Statuses</option>
          <?php foreach (['unpaid','partial','paid','overdue'] as $s): ?>
            <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>

    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Bill #</th>
            <th>Period</th>
            <th class="text-end">Consumption</th>
            <th class="text-end">Total</th>
            <th class="text-end">Balance</th>
            <th>Due Date</th>
            <th>Status</th>
            <th class="text-end"></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($bills as $b): ?>
          <tr>
            <td class="small fw-semibold"><?= e($b['bill_number']) ?></td>
            <td class="small"><?= e(period_label($b['billing_period'])) ?></td>
            <td class="text-end small"><?= number_format((float)$b['consumption'], 2) ?> m³</td>
            <td class="text-end"><?= e(money($b['total_amount'])) ?></td>
            <td class="text-end fw-semibold <?= (float)$b['balance'] > 0 ? 'text-danger' : 'text-success' ?>">
              <?= e(money($b['balance'])) ?>
            </td>
            <td class="small"><?= e(date('M j, Y', strtotime($b['due_date']))) ?></td>
            <td>
              <span class="badge text-capitalize text-bg-<?= match($b['status']) {
                'paid' => 'success', 'overdue' => 'danger', 'partial' => 'info', default => 'warning'
              } ?>"><?= e($b['status']) ?></span>
            </td>
            <td class="text-end">
              <a class="btn btn-sm btn-outline-primary py-0"
                 href="<?= e(url('/pages/my-bill-view.php?id=' . (int)$b['id'])) ?>">View</a>
            </td>
          </tr>
        <?php endforeach; ?>
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
    </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>