<?php
require_once __DIR__ . '/../includes/init.php';
require_role('admin', 'staff');

$pdo = db();
$id  = (int)get('id', 0);

$stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$customer = $stmt->fetch();

if (!$customer) {
    flash('danger', 'Customer not found.');
    redirect('/pages/customers.php');
}

$stmt = $pdo->prepare(
    "SELECT id, username, status, last_login
     FROM users WHERE customer_id = ? AND role = 'customer' LIMIT 1"
);
$stmt->execute([$id]);
$portalUser = $stmt->fetch();

$stmt = $pdo->prepare(
    "SELECT
        COALESCE(SUM(total_amount), 0) AS total_billed,
        COALESCE(SUM(amount_paid), 0) AS total_paid,
        COALESCE(SUM(CASE WHEN status IN ('unpaid','partial','overdue') THEN balance ELSE 0 END), 0) AS outstanding,
        COUNT(*) AS bill_count
     FROM bills WHERE customer_id = ?"
);
$stmt->execute([$id]);
$billStats = $stmt->fetch();

$stmt = $pdo->prepare('SELECT COALESCE(SUM(consumption), 0) FROM meter_readings WHERE customer_id = ?');
$stmt->execute([$id]);
$totalConsumption = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare(
    'SELECT mr.*, b.id AS bill_id, b.bill_number, b.status AS bill_status
     FROM meter_readings mr
     LEFT JOIN bills b ON b.reading_id = mr.id
     WHERE mr.customer_id = ?
     ORDER BY mr.billing_period DESC
     LIMIT 12'
);
$stmt->execute([$id]);
$readings = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT * FROM bills WHERE customer_id = ? ORDER BY billing_period DESC LIMIT 12'
);
$stmt->execute([$id]);
$bills = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT p.*, b.bill_number
     FROM payments p
     INNER JOIN bills b ON b.id = p.bill_id
     WHERE p.customer_id = ?
     ORDER BY p.payment_date DESC
     LIMIT 12'
);
$stmt->execute([$id]);
$payments = $stmt->fetchAll();

$__title = 'Customer Details';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <a href="<?= e(url('/pages/customers.php')) ?>" class="text-decoration-none small">
    <i class="bi bi-arrow-left me-1"></i>Back to Customers
  </a>
  <div class="d-flex gap-2">
    <?php if ($customer['status'] === 'active'): ?>
      <a href="<?= e(url('/pages/reading-form.php?customer=' . (int)$customer['id'])) ?>" class="btn btn-sm btn-primary">
        <i class="bi bi-speedometer me-1"></i>Record Reading
      </a>
    <?php endif; ?>
    <a href="<?= e(url('/pages/customer-form.php?id=' . (int)$customer['id'])) ?>" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-pencil me-1"></i>Edit Customer
    </a>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card d-flex justify-content-between">
      <div>
        <div class="label">Total Consumption</div>
        <div class="value"><?= number_format($totalConsumption, 2) ?> <small class="fs-6 text-muted">m³</small></div>
      </div>
      <div class="icon"><i class="bi bi-droplet"></i></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card d-flex justify-content-between">
      <div>
        <div class="label">Total Billed</div>
        <div class="value"><?= e(money($billStats['total_billed'])) ?></div>
        <small class="text-muted"><?= number_format((int)$billStats['bill_count']) ?> bill(s)</small>
      </div>
      <div class="icon"><i class="bi bi-receipt"></i></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card d-flex justify-content-between">
      <div>
        <div class="label">Total Paid</div>
        <div class="value text-success"><?= e(money($billStats['total_paid'])) ?></div>
      </div>
      <div class="icon"><i class="bi bi-cash-coin"></i></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card d-flex justify-content-between">
      <div>
        <div class="label">Outstanding Balance</div>
        <div class="value <?= (float)$billStats['outstanding'] > 0 ? 'text-danger' : 'text-success' ?>">
          <?= e(money($billStats['outstanding'])) ?>
        </div>
      </div>
      <div class="icon"><i class="bi bi-exclamation-triangle"></i></div>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-person-badge me-2"></i><?= e($customer['first_name'] . ' ' . $customer['last_name']) ?></span>
    <span class="badge text-capitalize <?= $customer['status'] === 'active' ? 'text-bg-success' : 'text-bg-secondary' ?>">
      <?= e($customer['status']) ?>
    </span>
  </div>
  <div class="card-body">
    <div class="row g-3 small">
      <div class="col-md-3">
        <div class="text-muted">Customer Code</div>
        <div class="fw-semibold"><?= e($customer['customer_code']) ?></div>
      </div>
      <div class="col-md-3">
        <div class="text-muted">Meter Number</div>
        <div class="fw-semibold"><?= e($customer['meter_number']) ?></div>
      </div>
      <div class="col-md-3">
        <div class="text-muted">Connection Type</div>
        <div class="fw-semibold text-capitalize"><?= e($customer['connection_type']) ?></div>
      </div>
      <div class="col-md-3">
        <div class="text-muted">Portal Login</div>
        <div>
          <?php if ($portalUser): ?>
            <span class="fw-semibold"><?= e($portalUser['username']) ?></span>
            <span class="badge <?= $portalUser['status'] === 'active' ? 'text-bg-success' : 'text-bg-secondary' ?> ms-1">
              <?= e($portalUser['status']) ?>
            </span>
          <?php else: ?>
            <span class="text-muted">None</span>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-md-6">
        <div class="text-muted">Address</div>
        <div>
          <?= e($customer['address']) ?>
          <?php if ($customer['barangay'] || $customer['city']): ?>
            , <?= e(trim(($customer['barangay'] ?? '') . ', ' . ($customer['city'] ?? ''), ', ')) ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-md-3">
        <div class="text-muted">Phone</div>
        <div><?= e($customer['phone'] ?: '—') ?></div>
      </div>
      <div class="col-md-3">
        <div class="text-muted">Email</div>
        <div><?= e($customer['email'] ?: '—') ?></div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>Recent Meter Readings</span>
        <?php if ($customer['status'] === 'active'): ?>
          <a href="<?= e(url('/pages/reading-form.php?customer=' . (int)$customer['id'])) ?>" class="btn btn-sm btn-link text-decoration-none">+ Add</a>
        <?php endif; ?>
      </div>
      <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
          <thead class="table-light">
            <tr>
              <th>Period</th>
              <th class="text-end">Prev</th>
              <th class="text-end">Curr</th>
              <th class="text-end">Cons.</th>
              <th>Bill</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$readings): ?>
            <tr><td colspan="5" class="text-center text-muted py-4">No meter readings recorded yet.</td></tr>
          <?php else: foreach ($readings as $r): ?>
            <tr>
              <td class="small"><?= e(period_label($r['billing_period'])) ?></td>
              <td class="text-end small"><?= number_format((float)$r['previous_reading'], 2) ?></td>
              <td class="text-end small"><?= number_format((float)$r['current_reading'], 2) ?></td>
              <td class="text-end small fw-semibold"><?= number_format((float)$r['consumption'], 2) ?> m³</td>
              <td>
                <?php if ($r['bill_id']): ?>
                  <a class="badge text-decoration-none text-bg-<?= $r['bill_status'] === 'paid' ? 'success' : ($r['bill_status'] === 'overdue' ? 'danger' : 'warning') ?>"
                     href="<?= e(url('/pages/bill-view.php?id=' . (int)$r['bill_id'])) ?>">
                    <?= e($r['bill_number']) ?>
                  </a>
                <?php else: ?>
                  <a class="badge text-bg-light text-muted border text-decoration-none"
                     href="<?= e(url('/pages/reading-form.php?id=' . (int)$r['id'])) ?>">Unbilled (Edit)</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header">Recent Bills</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
          <thead class="table-light">
            <tr>
              <th>Bill #</th>
              <th>Period</th>
              <th class="text-end">Total</th>
              <th class="text-end">Balance</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$bills): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">No bills generated yet.</td></tr>
          <?php else: foreach ($bills as $b): ?>
            <tr>
              <td class="small fw-semibold"><?= e($b['bill_number']) ?></td>
              <td class="small"><?= e(period_label($b['billing_period'])) ?></td>
              <td class="text-end small"><?= e(money($b['total_amount'])) ?></td>
              <td class="text-end small fw-semibold <?= (float)$b['balance'] > 0 ? 'text-danger' : 'text-success' ?>">
                <?= e(money($b['balance'])) ?>
              </td>
              <td>
                <span class="badge text-capitalize text-bg-<?= match($b['status']) {
                  'paid' => 'success', 'overdue' => 'danger', 'partial' => 'info', default => 'warning'
                } ?>"><?= e($b['status']) ?></span>
              </td>
              <td class="text-end text-nowrap">
                <a class="btn btn-sm btn-outline-primary py-0"
                   href="<?= e(url('/pages/bill-view.php?id=' . (int)$b['id'])) ?>">View</a>
                <?php if ($b['status'] !== 'paid' && (float)$b['balance'] > 0): ?>
                  <a class="btn btn-sm btn-outline-success py-0"
                     href="<?= e(url('/pages/payment-form.php?bill_id=' . (int)$b['id'])) ?>">Pay</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header">Recent Payments</div>
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>Receipt #</th>
          <th>Date</th>
          <th>Bill #</th>
          <th>Method</th>
          <th>Reference</th>
          <th class="text-end">Amount</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$payments): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">No payments recorded yet.</td></tr>
      <?php else: foreach ($payments as $p): ?>
        <tr>
          <td class="small fw-semibold"><?= e($p['receipt_number']) ?></td>
          <td class="small"><?= e(date('M j, Y g:i A', strtotime($p['payment_date']))) ?></td>
          <td class="small"><?= e($p['bill_number']) ?></td>
          <td class="small text-capitalize"><?= e(str_replace('_', ' ', $p['method'])) ?></td>
          <td class="small text-muted"><?= e($p['reference_no'] ?: '—') ?></td>
          <td class="text-end small fw-semibold text-success"><?= e(money($p['amount'])) ?></td>
          <td class="text-end">
            <a class="btn btn-sm btn-outline-primary py-0"
               href="<?= e(url('/pages/receipt.php?id=' . (int)$p['id'])) ?>">Receipt</a>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
