<?php
require_once __DIR__ . '/../includes/init.php';
require_role('admin', 'staff');

$pdo    = db();
$me     = auth_user();
$errors = [];

$billId = (int)(get('bill_id') ?? post('bill_id') ?? 0);
$bill   = null;

if ($billId > 0) {
    $stmt = $pdo->prepare(
        'SELECT b.*, c.customer_code, c.first_name, c.last_name, c.meter_number,
                c.phone, c.email, c.address, c.barangay, c.city, c.connection_type
         FROM bills b
         INNER JOIN customers c ON c.id = b.customer_id
         WHERE b.id = ?
         LIMIT 1'
    );
    $stmt->execute([$billId]);
    $bill = $stmt->fetch();
    if (!$bill) {
        flash('danger', 'Bill not found.');
        redirect('/pages/payments.php');
    }
}

$previousPayments = [];
if ($bill) {
    $stmt = $pdo->prepare(
        'SELECT id, receipt_number, amount, payment_date, method
         FROM payments WHERE bill_id = ? ORDER BY payment_date ASC'
    );
    $stmt->execute([$billId]);
    $previousPayments = $stmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (!$bill) {
        $errors[] = 'No bill selected. Pick a bill to pay first.';
    } elseif ($bill['status'] === 'paid') {
        $errors[] = 'This bill is already fully paid.';
    } else {
        $amount      = input_float(post('amount', ''));
        $method      = (string)post('method', 'cash');
        $reference   = trim((string)post('reference_no', ''));
        $notes       = trim((string)post('notes', ''));
        $paymentDate = trim((string)post('payment_date', ''));

        if ($amount <= 0) {
            $errors[] = 'Payment amount must be greater than zero.';
        }
        if ($bill && $amount > (float)$bill['balance'] + 0.001) {
            $errors[] = 'Amount exceeds the current balance of ' . money($bill['balance']) . '.';
        }
        if (!in_array($method, ['cash', 'gcash', 'maya', 'bank_transfer', 'check'], true)) {
            $errors[] = 'Invalid payment method.';
        }
        if ($method !== 'cash' && $reference === '') {
            $errors[] = 'Reference number is required for non-cash payments.';
        }
        if ($paymentDate === '' || !strtotime($paymentDate)) {
            $errors[] = 'Payment date is required.';
        } elseif (strtotime($paymentDate) > time()) {
            $errors[] = 'Payment date cannot be in the future.';
        }

        $partialEnabled = setting('partial_enabled', '1') === '1';
        $isPartial      = $bill && abs($amount - (float)$bill['balance']) > 0.005;
        if ($isPartial && !$partialEnabled) {
            $errors[] = 'Partial payments are disabled. Full payment of '
                . money($bill['balance']) . ' is required.';
        }

        if (!$errors) {
            $pdo->beginTransaction();
            try {
                $lockStmt = $pdo->prepare(
                    'SELECT id, total_amount, amount_paid, balance, due_date, status
                     FROM bills WHERE id = ? FOR UPDATE'
                );
                $lockStmt->execute([$billId]);
                $locked = $lockStmt->fetch();

                if (!$locked) {
                    throw new RuntimeException('Bill no longer exists.');
                }
                if ($locked['status'] === 'paid') {
                    throw new RuntimeException('This bill is already paid — no further payments accepted.');
                }
                if ($amount > (float)$locked['balance'] + 0.001) {
                    throw new RuntimeException('Amount exceeds current balance. Reload and try again.');
                }

                $receiptNumber = next_receipt_number();

                $insertStmt = $pdo->prepare(
                    'INSERT INTO payments
                        (receipt_number, bill_id, customer_id, amount, payment_date,
                         method, reference_no, notes, recorded_by)
                     VALUES (?,?,?,?,?,?,?,?,?)'
                );
                $insertStmt->execute([
                    $receiptNumber,
                    $billId,
                    (int)$bill['customer_id'],
                    $amount,
                    date('Y-m-d H:i:s', strtotime($paymentDate)),
                    $method,
                    $reference !== '' ? $reference : null,
                    $notes !== '' ? $notes : null,
                    (int)$me['id'],
                ]);
                $newPaymentId = (int)$pdo->lastInsertId();

                $newPaid    = round((float)$locked['amount_paid'] + $amount, 2);
                $newBalance = round((float)$locked['total_amount'] - $newPaid, 2);
                if ($newBalance < 0) $newBalance = 0.0;

                if ($newBalance <= 0.005) {
                    $newStatus = 'paid';
                } elseif (strtotime($locked['due_date']) < strtotime('today')) {
                    $newStatus = 'overdue';
                } else {
                    $newStatus = 'partial';
                }

                $updateStmt = $pdo->prepare(
                    'UPDATE bills SET amount_paid = ?, balance = ?, status = ? WHERE id = ?'
                );
                $updateStmt->execute([$newPaid, $newBalance, $newStatus, $billId]);

                log_activity('PAYMENT', 'Payments',
                    "Recorded $receiptNumber of " . money($amount) . " for bill {$bill['bill_number']} → status: $newStatus");

                $pdo->commit();

                flash('success', "Payment recorded — Receipt {$receiptNumber}. Remaining balance: " . money($newBalance));
                redirect('/pages/receipt.php?id=' . $newPaymentId);
            } catch (Throwable $ex) {
                $pdo->rollBack();
                $errors[] = $ex->getMessage();
            }
        }
    }
}

if (!$bill) {
    $q = trim((string)get('q', ''));
    $where  = ["b.status IN ('unpaid','partial','overdue')"];
    $params = [];

    if ($q !== '') {
        $where[] = "(b.bill_number LIKE ? OR c.customer_code LIKE ?
                     OR CONCAT(c.first_name,' ',c.last_name) LIKE ? OR c.meter_number LIKE ?)";
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like);
    }

    $whereSql = implode(' AND ', $where);

    $stmt = $pdo->prepare(
        "SELECT b.*, c.customer_code, c.first_name, c.last_name, c.meter_number
         FROM bills b
         INNER JOIN customers c ON c.id = b.customer_id
         WHERE $whereSql
         ORDER BY b.due_date ASC, b.id DESC
         LIMIT 30"
    );
    $stmt->execute($params);
    $unpaidBills = $stmt->fetchAll();

    $__title = 'Record Payment';
    include __DIR__ . '/../includes/header.php';
    ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
      <a href="<?= e(url('/pages/payments.php')) ?>" class="text-decoration-none small">
        <i class="bi bi-arrow-left me-1"></i>Back to Payments
      </a>
    </div>

    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-search me-2"></i>Find a Bill to Pay</div>
      <div class="card-body">
        <form class="row g-2" method="get">
          <div class="col-md-9">
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-search"></i></span>
              <input name="q" class="form-control" placeholder="Search by customer name, customer code, meter number, or bill number…"
                     value="<?= e($q) ?>" autofocus>
            </div>
          </div>
          <div class="col-md-3">
            <button class="btn btn-primary w-100">Search</button>
          </div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>Unpaid Bills</span>
        <small class="text-muted">Most urgent first</small>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Bill #</th>
              <th>Customer</th>
              <th>Period</th>
              <th>Due Date</th>
              <th class="text-end">Balance</th>
              <th>Status</th>
              <th class="text-end"></th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$unpaidBills): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">
              <?= $q !== '' ? 'No unpaid bills match your search.' : 'No unpaid bills — all caught up! 🎉' ?>
            </td></tr>
          <?php else: foreach ($unpaidBills as $b): ?>
            <tr>
              <td class="small fw-semibold"><?= e($b['bill_number']) ?></td>
              <td class="small">
                <?= e($b['first_name'] . ' ' . $b['last_name']) ?>
                <br><span class="text-muted"><?= e($b['customer_code']) ?> · Meter <?= e($b['meter_number']) ?></span>
              </td>
              <td class="small"><?= e(period_label($b['billing_period'])) ?></td>
              <td class="small <?= strtotime($b['due_date']) < time() ? 'text-danger' : '' ?>">
                <?= e(date('M j, Y', strtotime($b['due_date']))) ?>
              </td>
              <td class="text-end fw-semibold"><?= e(money($b['balance'])) ?></td>
              <td>
                <span class="badge text-capitalize text-bg-<?= match($b['status']) {
                  'overdue' => 'danger', 'partial' => 'info', default => 'warning'
                } ?>"><?= e($b['status']) ?></span>
              </td>
              <td class="text-end">
                <a class="btn btn-sm btn-primary py-0"
                   href="<?= e(url('/pages/payment-form.php?bill_id=' . (int)$b['id'])) ?>">
                  Record Payment
                </a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$defaultAmount = number_format((float)$bill['balance'], 2, '.', '');
$formAmount    = post('amount', $defaultAmount);
$formMethod    = post('method', 'cash');
$formRef       = post('reference_no', '');
$formNotes     = post('notes', '');
$formDate      = post('payment_date', date('Y-m-d'));

$__title = 'Record Payment';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <a href="<?= e(url('/pages/bill-view.php?id=' . (int)$billId)) ?>" class="text-decoration-none small">
    <i class="bi bi-arrow-left me-1"></i>Back to Bill
  </a>
  <span class="badge text-bg-light border"><?= e($bill['bill_number']) ?></span>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger py-2"><?= e($err) ?></div>
<?php endforeach; ?>

<?php if ($bill['status'] === 'paid'): ?>
  <div class="alert alert-success d-flex align-items-center">
    <i class="bi bi-check2-circle fs-3 me-3"></i>
    <div>
      <strong>This bill is fully paid.</strong>
      Total <?= e(money($bill['total_amount'])) ?> collected — no further payments accepted.
    </div>
  </div>
<?php else: ?>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-file-earmark-text me-2"></i>Bill Summary</div>
      <div class="card-body">
        <dl class="row small mb-3">
          <dt class="col-5 text-muted">Customer</dt>
          <dd class="col-7"><?= e($bill['first_name'] . ' ' . $bill['last_name']) ?>
            <br><span class="text-muted"><?= e($bill['customer_code']) ?></span></dd>
          <dt class="col-5 text-muted">Meter #</dt>
          <dd class="col-7"><?= e($bill['meter_number']) ?></dd>
          <dt class="col-5 text-muted">Period</dt>
          <dd class="col-7"><?= e(period_label($bill['billing_period'])) ?></dd>
          <dt class="col-5 text-muted">Due Date</dt>
          <dd class="col-7 <?= strtotime($bill['due_date']) < time() ? 'text-danger fw-semibold' : '' ?>">
            <?= e(date('M j, Y', strtotime($bill['due_date']))) ?>
          </dd>
          <dt class="col-5 text-muted">Consumption</dt>
          <dd class="col-7"><?= number_format((float)$bill['consumption'], 2) ?> m³</dd>
        </dl>

        <table class="table table-sm mb-0">
          <tbody>
            <tr><td class="text-muted">Total Amount</td>
                <td class="text-end"><?= e(money($bill['total_amount'])) ?></td></tr>
            <?php if ((float)$bill['penalty'] > 0): ?>
              <tr class="small text-muted"><td class="ps-3">↳ includes penalty</td>
                  <td class="text-end"><?= e(money($bill['penalty'])) ?></td></tr>
            <?php endif; ?>
            <tr><td class="text-muted">Amount Paid</td>
                <td class="text-end text-success"><?= e(money($bill['amount_paid'])) ?></td></tr>
            <tr class="fw-bold border-top">
              <td>Current Balance</td>
              <td class="text-end text-danger fs-5"><?= e(money($bill['balance'])) ?></td>
            </tr>
          </tbody>
        </table>

        <?php if ($previousPayments): ?>
          <hr>
          <div class="small fw-semibold mb-2">Previous Payments</div>
          <table class="table table-sm mb-0">
            <tbody>
            <?php foreach ($previousPayments as $pp): ?>
              <tr>
                <td class="small"><?= e($pp['receipt_number']) ?><br>
                  <span class="text-muted"><?= e(date('M j, Y', strtotime($pp['payment_date']))) ?></span></td>
                <td class="small text-capitalize text-end"><?= e(str_replace('_', ' ', $pp['method'])) ?></td>
                <td class="small text-end text-success"><?= e(money($pp['amount'])) ?></td>
                <td class="text-end">
                  <a class="btn btn-sm btn-link py-0 text-decoration-none"
                     href="<?= e(url('/pages/receipt.php?id=' . (int)$pp['id'])) ?>">view</a>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-cash-coin me-2"></i>Payment Details</div>
      <div class="card-body">
        <form method="post" novalidate autocomplete="off">
          <?= csrf_field() ?>
          <input type="hidden" name="bill_id" value="<?= (int)$billId ?>">

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Amount Received (₱) <span class="text-danger">*</span></label>
              <div class="input-group">
                <span class="input-group-text">₱</span>
                <input type="number" step="0.01" min="0.01" max="<?= e(number_format((float)$bill['balance'], 2, '.', '')) ?>"
                       name="amount" id="amountInput" class="form-control form-control-lg"
                       value="<?= e($formAmount) ?>" required>
              </div>
              <small class="text-muted d-flex justify-content-between mt-1">
                <span>Max: <?= e(money($bill['balance'])) ?></span>
                <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none"
                        onclick="document.getElementById('amountInput').value='<?= e($defaultAmount) ?>'">
                  Pay full balance
                </button>
              </small>
            </div>

            <div class="col-md-6">
              <label class="form-label">Payment Date <span class="text-danger">*</span></label>
              <input type="date" name="payment_date" class="form-control"
                     value="<?= e($formDate) ?>" max="<?= e(date('Y-m-d')) ?>" required>
            </div>

            <div class="col-md-6">
              <label class="form-label">Payment Method <span class="text-danger">*</span></label>
              <select name="method" id="methodSelect" class="form-select" required>
                <option value="cash"          <?= $formMethod === 'cash'          ? 'selected' : '' ?>>Cash</option>
                <option value="gcash"         <?= $formMethod === 'gcash'         ? 'selected' : '' ?>>GCash</option>
                <option value="maya"          <?= $formMethod === 'maya'          ? 'selected' : '' ?>>Maya</option>
                <option value="bank_transfer" <?= $formMethod === 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                <option value="check"         <?= $formMethod === 'check'         ? 'selected' : '' ?>>Check</option>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label">
                Reference Number
                <span class="text-danger" id="refRequired" style="display:none">*</span>
              </label>
              <input name="reference_no" id="refInput" class="form-control"
                     value="<?= e($formRef) ?>" maxlength="60"
                     placeholder="GCash ref, check #, transfer ID…">
              <small class="text-muted" id="refHint">Required for non-cash payments.</small>
            </div>

            <div class="col-12">
              <label class="form-label">Notes <small class="text-muted">(optional)</small></label>
              <textarea name="notes" class="form-control" rows="2" maxlength="255"
                        placeholder="e.g. paid in person by spouse, cashier: JR"><?= e($formNotes) ?></textarea>
            </div>
          </div>

          <div class="alert alert-light border mt-3 mb-0 small">
            <i class="bi bi-info-circle me-1"></i>
            This bill will be marked
            <strong id="statusPreview"><?= e($bill['status']) ?></strong>
            after this payment.
            <?php if (setting('partial_enabled', '1') !== '1'): ?>
              <br><span class="text-warning">Partial payments are disabled — full balance required.</span>
            <?php endif; ?>
          </div>

          <div class="d-flex gap-2 mt-3">
            <button class="btn btn-primary px-4">
              <i class="bi bi-check2-circle me-1"></i>Record Payment &amp; Print Receipt
            </button>
            <a href="<?= e(url('/pages/bill-view.php?id=' . (int)$billId)) ?>" class="btn btn-outline-secondary">Cancel</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  const methodSelect = document.getElementById('methodSelect');
  const refInput     = document.getElementById('refInput');
  const refRequired  = document.getElementById('refRequired');
  const refHint      = document.getElementById('refHint');
  const amountInput  = document.getElementById('amountInput');
  const statusPre    = document.getElementById('statusPreview');

  const balance       = <?= json_encode((float)$bill['balance']) ?>;
  const dueDate       = new Date(<?= json_encode($bill['due_date'] . 'T00:00:00') ?>);
  const today         = new Date(new Date().toDateString());
  const isPastDue     = dueDate < today;

  function syncRef() {
    const needsRef = methodSelect.value !== 'cash';
    refRequired.style.display = needsRef ? '' : 'none';
    refHint.textContent = needsRef
      ? 'Required for non-cash payments.'
      : 'Not needed for cash — leave blank.';
  }

  function syncPreview() {
    const amt = parseFloat(amountInput.value) || 0;
    if (amt <= 0) {
      statusPre.textContent = '<?= e($bill['status']) ?>';
      return;
    }
    const remaining = balance - amt;
    let next;
    if (remaining <= 0.005) next = 'paid';
    else if (isPastDue)     next = 'overdue';
    else                    next = 'partial';
    statusPre.textContent = next + (remaining > 0.005 ? ' (remaining: ₱' + remaining.toFixed(2) + ')' : '');
  }

  methodSelect.addEventListener('change', syncRef);
  amountInput.addEventListener('input', syncPreview);
  syncRef();
  syncPreview();
})();
</script>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>