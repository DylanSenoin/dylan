<?php
require_once __DIR__ . '/../includes/init.php';
require_role('customer');

$u   = auth_user();
$pdo = db();
$cid = (int)($u['customer_id'] ?? 0);
$id  = (int)get('id', 0);

if (!$cid) {
    flash('danger', 'Your account is not linked to a customer record.');
    redirect('/pages/dashboard.php');
}

$stmt = $pdo->prepare(
    'SELECT b.*, mr.previous_reading, mr.current_reading, mr.reading_date,
            c.customer_code, c.first_name, c.last_name, c.address, c.barangay, c.city,
            c.meter_number, c.connection_type
     FROM bills b
     INNER JOIN meter_readings mr ON mr.id = b.reading_id
     INNER JOIN customers c ON c.id = b.customer_id
     WHERE b.id = ? AND b.customer_id = ?
     LIMIT 1'
);
$stmt->execute([$id, $cid]);
$bill = $stmt->fetch();

if (!$bill) {
    flash('danger', 'Bill not found.');
    redirect('/pages/my-bills.php');
}

$stmt = $pdo->prepare(
    'SELECT * FROM payments WHERE bill_id = ? ORDER BY payment_date ASC'
);
$stmt->execute([$id]);
$payments = $stmt->fetchAll();

$__title = 'Bill Details';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 no-print">
  <a href="<?= e(url('/pages/my-bills.php')) ?>" class="text-decoration-none small">
    <i class="bi bi-arrow-left me-1"></i>Back to My Bills
  </a>
  <div class="d-flex gap-2">
    <a href="<?= e(url('/pages/statement-view.php?id=' . (int)$bill['id'])) ?>"
       class="btn btn-sm btn-primary">
      <i class="bi bi-file-earmark-pdf me-1"></i>Statement
    </a>
    <button class="btn btn-sm btn-outline-secondary" onclick="window.print()">
      <i class="bi bi-printer me-1"></i>Print
    </button>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 border-bottom pb-3 mb-3">
      <div>
        <h5 class="mb-1"><i class="bi bi-droplet-fill text-primary me-2"></i><?= e(setting('provider_name', APP_NAME)) ?></h5>
        <div class="small text-muted">
          <?= e(setting('provider_address', '')) ?><br>
          <?= e(setting('provider_contact', '')) ?> · <?= e(setting('provider_email', '')) ?>
        </div>
      </div>
      <div class="text-end">
        <div class="text-muted small text-uppercase">Billing Statement</div>
        <div class="fs-4 fw-bold"><?= e($bill['bill_number']) ?></div>
        <span class="badge text-capitalize text-bg-<?= match($bill['status']) {
          'paid' => 'success', 'overdue' => 'danger', 'partial' => 'info', default => 'warning'
        } ?>"><?= e($bill['status']) ?></span>
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <div class="text-muted small text-uppercase">Billed To</div>
        <div class="fw-semibold"><?= e($bill['first_name'] . ' ' . $bill['last_name']) ?></div>
        <div class="small"><?= e($bill['customer_code']) ?> · Meter <?= e($bill['meter_number']) ?></div>
        <div class="small text-muted">
          <?= e($bill['address']) ?><br>
          <?= e(trim(($bill['barangay'] ?? '') . ', ' . ($bill['city'] ?? ''), ', ')) ?>
        </div>
        <div class="small text-capitalize mt-1">Connection: <?= e($bill['connection_type']) ?></div>
      </div>
      <div class="col-md-6 text-md-end">
        <div class="text-muted small text-uppercase">Billing Period</div>
        <div class="fw-semibold"><?= e(period_label($bill['billing_period'])) ?></div>
        <div class="small text-muted">
          <?= e(date('M j, Y', strtotime($bill['period_start']))) ?> —
          <?= e(date('M j, Y', strtotime($bill['period_end']))) ?>
        </div>
        <div class="text-muted small text-uppercase mt-2">Due Date</div>
        <div class="fw-semibold <?= strtotime($bill['due_date']) < time() && $bill['status'] !== 'paid' ? 'text-danger' : '' ?>">
          <?= e(date('F j, Y', strtotime($bill['due_date']))) ?>
        </div>
      </div>
    </div>

    <table class="table table-sm mb-3">
      <thead class="table-light">
        <tr><th>Description</th><th class="text-end">Value</th></tr>
      </thead>
      <tbody>
        <tr><td>Previous Reading</td><td class="text-end"><?= number_format((float)$bill['previous_reading'], 2) ?> m³</td></tr>
        <tr><td>Current Reading</td><td class="text-end"><?= number_format((float)$bill['current_reading'], 2) ?> m³</td></tr>
        <tr><td>Total Consumption</td><td class="text-end fw-semibold"><?= number_format((float)$bill['consumption'], 2) ?> m³</td></tr>
      </tbody>
    </table>

    <table class="table table-sm mb-3">
      <thead class="table-light">
        <tr><th>Charges</th><th class="text-end">Amount</th></tr>
      </thead>
      <tbody>
        <tr><td>Basic Charge</td><td class="text-end"><?= e(money($bill['basic_charge'])) ?></td></tr>
        <tr><td>Consumption Charge</td><td class="text-end"><?= e(money($bill['consumption_charge'])) ?></td></tr>
        <?php if ((float)$bill['other_fees'] > 0): ?>
          <tr><td>Other Fees</td><td class="text-end"><?= e(money($bill['other_fees'])) ?></td></tr>
        <?php endif; ?>
        <?php if ((float)$bill['penalty'] > 0): ?>
          <tr class="text-danger"><td>Overdue Penalty</td><td class="text-end"><?= e(money($bill['penalty'])) ?></td></tr>
        <?php endif; ?>
        <?php if ((float)$bill['discount'] > 0): ?>
          <tr class="text-success"><td>Discount</td><td class="text-end">-<?= e(money($bill['discount'])) ?></td></tr>
        <?php endif; ?>
      </tbody>
      <tfoot>
        <tr class="fw-bold border-top">
          <td>Total Amount Due</td>
          <td class="text-end"><?= e(money($bill['total_amount'])) ?></td>
        </tr>
        <?php if ((float)$bill['amount_paid'] > 0): ?>
          <tr class="text-success"><td>Amount Paid</td><td class="text-end"><?= e(money($bill['amount_paid'])) ?></td></tr>
          <tr class="fw-bold"><td>Balance</td><td class="text-end <?= (float)$bill['balance'] > 0 ? 'text-danger' : 'text-success' ?>"><?= e(money($bill['balance'])) ?></td></tr>
        <?php endif; ?>
      </tfoot>
    </table>

    <?php if ($payments): ?>
    <div class="mt-4">
      <h6 class="border-bottom pb-2 mb-2">Payment History</h6>
      <table class="table table-sm mb-0">
        <thead class="table-light">
          <tr><th>Receipt #</th><th>Date</th><th>Method</th><th class="text-end">Amount</th></tr>
        </thead>
        <tbody>
        <?php foreach ($payments as $p): ?>
          <tr>
            <td class="small"><?= e($p['receipt_number']) ?></td>
            <td class="small"><?= e(date('M j, Y', strtotime($p['payment_date']))) ?></td>
            <td class="small text-capitalize"><?= e(str_replace('_', ' ', $p['method'])) ?></td>
            <td class="text-end text-success"><?= e(money($p['amount'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <div class="text-center text-muted small mt-4 border-top pt-3">
      This is a system-generated statement. Please keep it for your records.
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>