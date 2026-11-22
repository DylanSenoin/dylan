<?php
require_once __DIR__ . '/../includes/init.php';
require_role('admin', 'staff');

$pdo = db();
$id  = (int)get('id', 0);

$stmt = $pdo->prepare(
    'SELECT b.*,
            mr.previous_reading, mr.current_reading, mr.reading_date,
            c.customer_code, c.first_name, c.last_name, c.address, c.barangay, c.city,
            c.meter_number, c.connection_type, c.phone, c.email,
            wr.name AS rate_name, wr.min_consumption, wr.min_charge,
            wr.rate_per_cubic, wr.penalty_rate, wr.penalty_grace
     FROM bills b
     INNER JOIN meter_readings mr ON mr.id = b.reading_id
     INNER JOIN customers c ON c.id = b.customer_id
     INNER JOIN water_rates wr ON wr.id = b.rate_id
     WHERE b.id = ?
     LIMIT 1'
);
$stmt->execute([$id]);
$bill = $stmt->fetch();

if (!$bill) {
    flash('danger', 'Bill not found.');
    redirect('/pages/bills.php');
}

$stmt = $pdo->prepare('SELECT * FROM payments WHERE bill_id = ? ORDER BY payment_date ASC');
$stmt->execute([$id]);
$payments = $stmt->fetchAll();

$__title = 'Bill Details';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 no-print">
  <a href="<?= e(url('/pages/bills.php')) ?>" class="text-decoration-none small">
    <i class="bi bi-arrow-left me-1"></i>Back to Bills
  </a>
  <div class="d-flex gap-2">
    <?php if ($bill['status'] !== 'paid' && (float)$bill['balance'] > 0): ?>
      <a href="<?= e(url('/pages/payment-form.php?bill_id=' . (int)$bill['id'])) ?>"
         class="btn btn-sm btn-primary">
        <i class="bi bi-cash-coin me-1"></i>Record Payment
      </a>
    <?php endif; ?>
    <a href="<?= e(url('/pages/customer-view.php?id=' . (int)$bill['customer_id'])) ?>" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-person me-1"></i>Customer
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
        <div class="text-muted small text-uppercase">Bill</div>
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
        <div class="small text-capitalize mt-1">
          <?= e($bill['connection_type']) ?>
          <?= $bill['phone'] ? ' · ' . e($bill['phone']) : '' ?>
        </div>
      </div>
      <div class="col-md-6 text-md-end">
        <div class="text-muted small text-uppercase">Billing Period</div>
        <div class="fw-semibold"><?= e(period_label($bill['billing_period'])) ?></div>
        <div class="small text-muted">
          <?= e(date('M j', strtotime($bill['period_start']))) ?> —
          <?= e(date('M j, Y', strtotime($bill['period_end']))) ?>
        </div>
        <div class="text-muted small text-uppercase mt-2">Due Date</div>
        <div class="fw-semibold <?= strtotime($bill['due_date']) < time() && $bill['status'] !== 'paid' ? 'text-danger' : '' ?>">
          <?= e(date('F j, Y', strtotime($bill['due_date']))) ?>
        </div>
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-4">
        <div class="border rounded p-2 small">
          <div class="text-muted">Previous Reading</div>
          <div class="fw-semibold"><?= number_format((float)$bill['previous_reading'], 2) ?> m³</div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="border rounded p-2 small">
          <div class="text-muted">Current Reading</div>
          <div class="fw-semibold"><?= number_format((float)$bill['current_reading'], 2) ?> m³</div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="border rounded p-2 small bg-primary-subtle">
          <div class="text-muted">Consumption</div>
          <div class="fw-bold text-primary"><?= number_format((float)$bill['consumption'], 2) ?> m³</div>
        </div>
      </div>
    </div>

    <table class="table table-sm mb-3">
      <thead class="table-light">
        <tr><th>Charges</th><th class="text-end">Amount</th></tr>
      </thead>
      <tbody>
        <tr>
          <td>Basic Charge <small class="text-muted">(<?= e($bill['rate_name']) ?>, up to <?= number_format((float)$bill['min_consumption'], 0) ?> m³)</small></td>
          <td class="text-end"><?= e(money($bill['basic_charge'])) ?></td>
        </tr>
        <tr>
          <td>Consumption Charge <small class="text-muted">@ <?= e(money($bill['rate_per_cubic'])) ?>/m³</small></td>
          <td class="text-end"><?= e(money($bill['consumption_charge'])) ?></td>
        </tr>
        <?php if ((float)$bill['other_fees'] > 0): ?>
          <tr><td>Other Fees</td><td class="text-end"><?= e(money($bill['other_fees'])) ?></td></tr>
        <?php endif; ?>
        <?php if ((float)$bill['penalty'] > 0): ?>
          <tr class="text-danger">
            <td>Overdue Penalty <small class="text-muted">(<?= number_format((float)$bill['penalty_rate'], 2) ?>% of balance)</small></td>
            <td class="text-end"><?= e(money($bill['penalty'])) ?></td>
          </tr>
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
        <tr class="text-success"><td>Amount Paid</td><td class="text-end"><?= e(money($bill['amount_paid'])) ?></td></tr>
        <tr class="fw-bold fs-5">
          <td>Balance</td>
          <td class="text-end <?= (float)$bill['balance'] > 0 ? 'text-danger' : 'text-success' ?>">
            <?= e(money($bill['balance'])) ?>
          </td>
        </tr>
      </tfoot>
    </table>

    <?php if ($payments): ?>
    <div class="mt-4">
      <h6 class="border-bottom pb-2 mb-2">Payment History</h6>
      <table class="table table-sm mb-0">
        <thead class="table-light">
          <tr><th>Receipt #</th><th>Date</th><th>Method</th><th>Reference</th><th class="text-end">Amount</th></tr>
        </thead>
        <tbody>
        <?php foreach ($payments as $p): ?>
          <tr>
            <td class="small"><?= e($p['receipt_number']) ?></td>
            <td class="small"><?= e(date('M j, Y g:i A', strtotime($p['payment_date']))) ?></td>
            <td class="small text-capitalize"><?= e(str_replace('_', ' ', $p['method'])) ?></td>
            <td class="small text-muted"><?= e($p['reference_no'] ?: '—') ?></td>
            <td class="text-end text-success"><?= e(money($p['amount'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
      <div class="alert alert-light border py-2 small text-muted mt-3 mb-0">
        <i class="bi bi-info-circle me-1"></i>No payments recorded against this bill yet.
      </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>