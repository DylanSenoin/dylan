<?php
require_once __DIR__ . '/../includes/init.php';
require_login();

$u    = auth_user();
$role = $u['role'];
$pdo  = db();

if ($role === 'customer') {
    $cid = (int)($u['customer_id'] ?? 0);

    $stmt = $pdo->prepare(
        'SELECT b.*, mr.previous_reading, mr.current_reading
         FROM bills b
         JOIN meter_readings mr ON mr.id = b.reading_id
         WHERE b.customer_id = ?
         ORDER BY b.billing_period DESC LIMIT 6'
    );
    $stmt->execute([$cid]);
    $recentBills = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN status IN ('unpaid','partial','overdue') THEN balance ELSE 0 END),0) AS outstanding,
            COALESCE(SUM(amount_paid),0) AS total_paid,
            COUNT(*) AS bill_count
         FROM bills WHERE customer_id = ?"
    );
    $stmt->execute([$cid]);
    $totals = $stmt->fetch();

    $stmt = $pdo->prepare(
        'SELECT billing_period, consumption
         FROM meter_readings WHERE customer_id = ?
         ORDER BY billing_period ASC LIMIT 12'
    );
    $stmt->execute([$cid]);
    $consumptionRows = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        'SELECT id, bill_number, billing_period, total_amount, balance, status, due_date
         FROM bills WHERE customer_id = ? AND status IN ("unpaid","partial","overdue")
         ORDER BY due_date ASC LIMIT 5'
    );
    $stmt->execute([$cid]);
    $upcoming = $stmt->fetchAll();

    $latest = $recentBills[0] ?? null;

    $__title = 'Dashboard';
    include __DIR__ . '/../includes/header.php';
    ?>

    <div class="row g-3 mb-4">
      <div class="col-md-4">
        <div class="stat-card d-flex justify-content-between">
          <div>
            <div class="label">Outstanding Balance</div>
            <div class="value text-danger"><?= e(money($totals['outstanding'])) ?></div>
          </div>
          <div class="icon"><i class="bi bi-exclamation-triangle"></i></div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="stat-card d-flex justify-content-between">
          <div>
            <div class="label">Total Paid</div>
            <div class="value text-success"><?= e(money($totals['total_paid'])) ?></div>
          </div>
          <div class="icon"><i class="bi bi-check2-circle"></i></div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="stat-card d-flex justify-content-between">
          <div>
            <div class="label">Bills on Record</div>
            <div class="value"><?= (int)$totals['bill_count'] ?></div>
          </div>
          <div class="icon"><i class="bi bi-receipt"></i></div>
        </div>
      </div>
    </div>

    <?php if ($latest): ?>
    <div class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>Latest Statement — <?= e(period_label($latest['billing_period'])) ?></span>
        <span class="badge text-bg-<?= $latest['status'] === 'paid' ? 'success' : ($latest['status'] === 'overdue' ? 'danger' : 'warning') ?> text-capitalize">
          <?= e($latest['status']) ?>
        </span>
      </div>
      <div class="card-body">
        <div class="row g-3 small">
          <div class="col-md-3"><div class="text-muted">Previous Reading</div><div class="fw-semibold"><?= number_format((float)$latest['previous_reading'], 2) ?> m³</div></div>
          <div class="col-md-3"><div class="text-muted">Current Reading</div><div class="fw-semibold"><?= number_format((float)$latest['current_reading'], 2) ?> m³</div></div>
          <div class="col-md-3"><div class="text-muted">Consumption</div><div class="fw-semibold"><?= number_format((float)$latest['consumption'], 2) ?> m³</div></div>
          <div class="col-md-3"><div class="text-muted">Amount Due</div><div class="fw-semibold text-primary"><?= e(money($latest['balance'])) ?></div></div>
        </div>
      </div>
      <div class="card-footer bg-white d-flex gap-2">
        <a href="<?= e(url('/pages/my-bill-view.php?id=' . (int)$latest['id'])) ?>" class="btn btn-sm btn-primary">
          <i class="bi bi-eye me-1"></i>View Bill
        </a>
        <a href="<?= e(url('/pages/my-bills.php')) ?>" class="btn btn-sm btn-outline-secondary">All Bills</a>
      </div>
    </div>
    <?php endif; ?>

    <div class="row g-3">
      <div class="col-lg-7">
        <div class="card h-100">
          <div class="card-header">Consumption History (m³)</div>
          <div class="card-body"><canvas id="custConsumptionChart" height="200"></canvas></div>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="card h-100">
          <div class="card-header">Upcoming / Unpaid Bills</div>
          <div class="card-body p-0">
            <?php if (!$upcoming): ?>
              <p class="text-muted text-center py-4 mb-0">No unpaid bills. 🎉</p>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                  <thead><tr><th>Bill #</th><th>Period</th><th class="text-end">Due</th><th></th></tr></thead>
                  <tbody>
                  <?php foreach ($upcoming as $b): ?>
                    <tr>
                      <td class="small"><?= e($b['bill_number']) ?></td>
                      <td class="small"><?= e(period_label($b['billing_period'])) ?></td>
                      <td class="text-end small"><?= e(money($b['balance'])) ?></td>
                      <td class="text-end"><a class="btn btn-sm btn-outline-primary py-0" href="<?= e(url('/pages/my-bill-view.php?id=' . (int)$b['id'])) ?>">View</a></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <script>
    new Chart(document.getElementById('custConsumptionChart').getContext('2d'), {
      type: 'line',
      data: {
        labels: <?= json_encode(array_map(fn($r) => period_label($r['billing_period']), $consumptionRows)) ?>,
        datasets: [{
          label: 'm³',
          data: <?= json_encode(array_map(fn($r) => (float)$r['consumption'], $consumptionRows)) ?>,
          borderColor: '#1565c0',
          backgroundColor: 'rgba(21,101,192,.12)',
          tension: .3, fill: true, pointRadius: 3
        }]
      },
      options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
    });
    </script>

    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$stats = [];
$stats['customers_total']   = (int) $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$stats['customers_active']  = (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE status='active'")->fetchColumn();
$stats['bills_total']       = (int) $pdo->query("SELECT COUNT(*) FROM bills")->fetchColumn();
$stats['bills_unpaid']      = (int) $pdo->query("SELECT COUNT(*) FROM bills WHERE status IN ('unpaid','partial','overdue')")->fetchColumn();
$stats['payments_total']    = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments")->fetchColumn();
$stats['outstanding_total'] = (float) $pdo->query(
    "SELECT COALESCE(SUM(balance),0) FROM bills WHERE status IN ('unpaid','partial','overdue')"
)->fetchColumn();

$revRows = $pdo->query(
    "SELECT DATE_FORMAT(payment_date,'%Y-%m') ym, SUM(amount) total
     FROM payments WHERE YEAR(payment_date) = YEAR(CURDATE())
     GROUP BY ym ORDER BY ym"
)->fetchAll();
$revMap = [];
foreach ($revRows as $r) { $revMap[$r['ym']] = (float)$r['total']; }

$consRows = $pdo->query(
    "SELECT DATE_FORMAT(billing_period,'%Y-%m') ym, SUM(consumption) total
     FROM meter_readings WHERE YEAR(billing_period) = YEAR(CURDATE())
     GROUP BY ym ORDER BY ym"
)->fetchAll();
$consMap = [];
foreach ($consRows as $r) { $consMap[$r['ym']] = (float)$r['total']; }

$months = []; $revData = []; $consData = [];
for ($i = 11; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("first day of -$i month"));
    $months[]   = date('M Y', strtotime($ym . '-01'));
    $revData[]  = $revMap[$ym]  ?? 0;
    $consData[] = $consMap[$ym] ?? 0;
}

$statusCounts = ['paid' => 0, 'unpaid' => 0, 'partial' => 0, 'overdue' => 0];
foreach ($pdo->query("SELECT status, COUNT(*) c FROM bills GROUP BY status") as $row) {
    $statusCounts[$row['status']] = (int)$row['c'];
}

$monthRevenue = (float) $pdo->query(
    "SELECT COALESCE(SUM(amount),0) FROM payments
     WHERE YEAR(payment_date)=YEAR(CURDATE()) AND MONTH(payment_date)=MONTH(CURDATE())"
)->fetchColumn();

$recentPayments = $pdo->query(
    "SELECT p.receipt_number, p.amount, p.payment_date, p.method,
            CONCAT(c.first_name,' ',c.last_name) AS customer_name, c.customer_code
     FROM payments p JOIN customers c ON c.id = p.customer_id
     ORDER BY p.payment_date DESC LIMIT 6"
)->fetchAll();

$overdue = $pdo->query(
    "SELECT b.bill_number, b.due_date, b.balance, b.billing_period,
            CONCAT(c.first_name,' ',c.last_name) AS customer_name, c.customer_code
     FROM bills b JOIN customers c ON c.id = b.customer_id
     WHERE b.status IN ('unpaid','partial','overdue') AND b.due_date < CURDATE()
     ORDER BY b.due_date ASC LIMIT 6"
)->fetchAll();

$__title = 'Dashboard';
include __DIR__ . '/../includes/header.php';
?>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card d-flex justify-content-between">
      <div>
        <div class="label">Registered Customers</div>
        <div class="value"><?= number_format($stats['customers_total']) ?></div>
        <small class="text-success"><?= number_format($stats['customers_active']) ?> active</small>
      </div>
      <div class="icon"><i class="bi bi-people"></i></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card d-flex justify-content-between">
      <div>
        <div class="label">Bills Generated</div>
        <div class="value"><?= number_format($stats['bills_total']) ?></div>
        <small class="text-danger"><?= number_format($stats['bills_unpaid']) ?> unpaid</small>
      </div>
      <div class="icon"><i class="bi bi-receipt"></i></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card d-flex justify-content-between">
      <div>
        <div class="label">Total Collected</div>
        <div class="value text-success"><?= e(money($stats['payments_total'])) ?></div>
        <small class="text-muted"><?= e(money($monthRevenue)) ?> this month</small>
      </div>
      <div class="icon"><i class="bi bi-cash-coin"></i></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card d-flex justify-content-between">
      <div>
        <div class="label">Outstanding Balance</div>
        <div class="value text-danger"><?= e(money($stats['outstanding_total'])) ?></div>
        <small class="text-muted">across all active bills</small>
      </div>
      <div class="icon"><i class="bi bi-exclamation-triangle"></i></div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header">Revenue vs. Consumption — Last 12 Months</div>
      <div class="card-body"><canvas id="revChart" height="140"></canvas></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header">Paid vs. Unpaid</div>
      <div class="card-body"><canvas id="statusChart" height="200"></canvas></div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>Recent Payments</span>
        <a href="<?= e(url('/pages/payments.php')) ?>" class="btn btn-sm btn-link text-decoration-none">View all</a>
      </div>
      <div class="card-body p-0">
        <?php if (!$recentPayments): ?>
          <p class="text-muted text-center py-4 mb-0">No payments recorded yet.</p>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
              <thead><tr><th>Receipt</th><th>Customer</th><th>Method</th><th class="text-end">Amount</th></tr></thead>
              <tbody>
              <?php foreach ($recentPayments as $p): ?>
                <tr>
                  <td class="small"><?= e($p['receipt_number']) ?><br><span class="text-muted"><?= e(date('M j', strtotime($p['payment_date']))) ?></span></td>
                  <td class="small"><?= e($p['customer_name']) ?><br><span class="text-muted"><?= e($p['customer_code']) ?></span></td>
                  <td class="small text-capitalize"><?= e(str_replace('_', ' ', $p['method'])) ?></td>
                  <td class="text-end fw-semibold text-success"><?= e(money($p['amount'])) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>Overdue Bills</span>
        <a href="<?= e(url('/pages/bills.php?status=overdue')) ?>" class="btn btn-sm btn-link text-decoration-none">View all</a>
      </div>
      <div class="card-body p-0">
        <?php if (!$overdue): ?>
          <p class="text-muted text-center py-4 mb-0">No overdue bills. 👌</p>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
              <thead><tr><th>Bill #</th><th>Customer</th><th>Due</th><th class="text-end">Balance</th></tr></thead>
              <tbody>
              <?php foreach ($overdue as $b): ?>
                <tr>
                  <td class="small"><?= e($b['bill_number']) ?></td>
                  <td class="small"><?= e($b['customer_name']) ?></td>
                  <td class="small text-danger"><?= e(date('M j, Y', strtotime($b['due_date']))) ?></td>
                  <td class="text-end fw-semibold"><?= e(money($b['balance'])) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
new Chart(document.getElementById('revChart').getContext('2d'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($months) ?>,
    datasets: [
      { label: 'Revenue (₱)', data: <?= json_encode($revData) ?>, backgroundColor: 'rgba(21,101,192,.75)', borderRadius: 6, yAxisID: 'y' },
      { label: 'Consumption (m³)', type: 'line', data: <?= json_encode($consData) ?>, borderColor: '#00acc1', backgroundColor: 'rgba(0,172,193,.15)', tension: .3, fill: false, yAxisID: 'y1' }
    ]
  },
  options: {
    responsive: true,
    interaction: { mode: 'index', intersect: false },
    scales: {
      y:  { position: 'left',  beginAtZero: true, ticks: { callback: v => '₱' + Number(v).toLocaleString() } },
      y1: { position: 'right', beginAtZero: true, grid: { drawOnChartArea: false } }
    }
  }
});

new Chart(document.getElementById('statusChart').getContext('2d'), {
  type: 'doughnut',
  data: {
    labels: ['Paid', 'Unpaid', 'Partial', 'Overdue'],
    datasets: [{
      data: [<?= $statusCounts['paid'] ?>, <?= $statusCounts['unpaid'] ?>, <?= $statusCounts['partial'] ?>, <?= $statusCounts['overdue'] ?>],
      backgroundColor: ['#198754', '#ffc107', '#0dcaf0', '#dc3545'],
      borderWidth: 0
    }]
  },
  options: { plugins: { legend: { position: 'bottom' } }, cutout: '62%' }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>