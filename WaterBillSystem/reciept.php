<?php
require_once __DIR__ . '/../includes/init.php';
require_login();

$pdo    = db();
$me     = auth_user();
$id     = (int)get('id', 0);

$stmt = $pdo->prepare(
    'SELECT p.*,
            b.bill_number, b.billing_period, b.total_amount AS bill_total,
            b.balance AS bill_balance, b.status AS bill_status,
            c.customer_code, c.first_name, c.last_name, c.address, c.barangay, c.city,
            c.meter_number,
            u.full_name AS recorded_by_name, u.role AS recorded_by_role
     FROM payments p
     INNER JOIN bills b ON b.id = p.bill_id
     INNER JOIN customers c ON c.id = p.customer_id
     LEFT JOIN users u ON u.id = p.recorded_by
     WHERE p.id = ?
     LIMIT 1'
);
$stmt->execute([$id]);
$payment = $stmt->fetch();

if (!$payment) {
    flash('danger', 'Receipt not found.');
    redirect($me['role'] === 'customer' ? '/pages/my-bills.php' : '/pages/payments.php');
}

if ($me['role'] === 'customer' && (int)$payment['customer_id'] !== (int)$me['customer_id']) {
    http_response_code(403);
    exit('Access denied.');
}

$__title = 'Receipt';
$backUrl = $me['role'] === 'customer'
    ? url('/pages/my-bill-view.php?id=' . (int)$payment['bill_id'])
    : url('/pages/bill-view.php?id=' . (int)$payment['bill_id']);

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 no-print">
  <a href="<?= e($backUrl) ?>" class="text-decoration-none small">
    <i class="bi bi-arrow-left me-1"></i>Back
  </a>
  <div class="d-flex gap-2">
    <button class="btn btn-sm btn-outline-secondary" onclick="window.print()">
      <i class="bi bi-printer me-1"></i>Print
    </button>
  </div>
</div>

<div class="row justify-content-center">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-body p-4">

        <div class="text-center border-bottom pb-3 mb-3">
          <h5 class="mb-1"><i class="bi bi-droplet-fill text-primary me-2"></i><?= e(setting('provider_name', APP_NAME)) ?></h5>
          <div class="small text-muted">
            <?= e(setting('provider_address', '')) ?><br>
            <?= e(setting('provider_contact', '')) ?> · <?= e(setting('provider_email', '')) ?>
          </div>
          <div class="mt-2 fw-semibold text-uppercase" style="letter-spacing:.08em">
            Official Receipt
          </div>
        </div>

        <div class="d-flex justify-content-between mb-3">
          <div>
            <div class="text-muted small text-uppercase">Receipt No.</div>
            <div class="fw-bold fs-5"><?= e($payment['receipt_number']) ?></div>
          </div>
          <div class="text-end">
            <div class="text-muted small text-uppercase">Date</div>
            <div class="fw-semibold"><?= e(date('F j, Y', strtotime($payment['payment_date']))) ?></div>
            <div class="small text-muted"><?= e(date('g:i A', strtotime($payment['payment_date']))) ?></div>
          </div>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-sm-7">
            <div class="text-muted small text-uppercase">Received From</div>
            <div class="fw-semibold"><?= e($payment['first_name'] . ' ' . $payment['last_name']) ?></div>
            <div class="small"><?= e($payment['customer_code']) ?> · Meter <?= e($payment['meter_number']) ?></div>
            <div class="small text-muted">
              <?= e($payment['address']) ?><br>
              <?= e(trim(($payment['barangay'] ?? '') . ', ' . ($payment['city'] ?? ''), ', ')) ?>
            </div>
          </div>
          <div class="col-sm-5 text-sm-end">
            <div class="text-muted small text-uppercase">For Bill</div>
            <div class="fw-semibold"><?= e($payment['bill_number']) ?></div>
            <div class="small text-muted"><?= e(period_label($payment['billing_period'])) ?></div>
          </div>
        </div>

        <table class="table table-sm mb-3">
          <tbody>
            <tr>
              <td>Amount Paid</td>
              <td class="text-end fw-bold fs-5 text-success"><?= e(money($payment['amount'])) ?></td>
            </tr>
            <tr>
              <td>Payment Method</td>
              <td class="text-end text-capitalize"><?= e(str_replace('_', ' ', $payment['method'])) ?></td>
            </tr>
            <?php if ($payment['reference_no']): ?>
              <tr>
                <td>Reference No.</td>
                <td class="text-end"><?= e($payment['reference_no']) ?></td>
              </tr>
            <?php endif; ?>
            <?php if ($payment['notes']): ?>
              <tr>
                <td>Notes</td>
                <td class="text-end small text-muted"><?= e($payment['notes']) ?></td>
              </tr>
            <?php endif; ?>
            <tr class="border-top">
              <td>Bill Total</td>
              <td class="text-end"><?= e(money($payment['bill_total'])) ?></td>
            </tr>
            <tr>
              <td>Remaining Balance</td>
              <td class="text-end fw-bold <?= (float)$payment['bill_balance'] > 0 ? 'text-danger' : 'text-success' ?>">
                <?= e(money($payment['bill_balance'])) ?>
              </td>
            </tr>
            <tr>
              <td>Bill Status</td>
              <td class="text-end">
                <span class="badge text-capitalize text-bg-<?= match($payment['bill_status']) {
                  'paid' => 'success', 'overdue' => 'danger', 'partial' => 'info', default => 'warning'
                } ?>"><?= e($payment['bill_status']) ?></span>
              </td>
            </tr>
          </tbody>
        </table>

        <div class="row pt-3 border-top">
          <div class="col-6">
            <div class="text-muted small text-uppercase">Received By</div>
            <div class="fw-semibold mt-1"><?= e($payment['recorded_by_name'] ?: '—') ?></div>
            <div class="small text-muted text-capitalize"><?= e($payment['recorded_by_role'] ?: '') ?></div>
          </div>
          <div class="col-6 text-end">
            <div class="text-muted small text-uppercase">Signature</div>
            <div class="mt-4 border-top d-inline-block" style="width:180px"></div>
          </div>
        </div>

        <div class="text-center text-muted small mt-4 pt-3 border-top">
          This is a system-generated receipt. Keep this for your records.<br>
          Generated on <?= e(date('F j, Y g:i A')) ?>
        </div>

      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>