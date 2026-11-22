<?php
require_once __DIR__ . '/../includes/init.php';
require_role('admin', 'staff');

$pdo     = db();
$me      = auth_user();
$errors  = [];
$period  = trim((string)(post('billing_period') ?? get('period', date('Y-m'))));

if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
    $period = date('Y-m');
}

$periodStart = $period . '-01';
$periodEnd   = date('Y-m-t', strtotime($periodStart));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (strtotime($periodStart) > strtotime(date('Y-m-01'))) {
        $errors[] = 'Cannot generate bills for a future billing period.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare(
            'SELECT mr.*, c.connection_type, c.customer_code
             FROM meter_readings mr
             INNER JOIN customers c ON c.id = mr.customer_id
             LEFT JOIN bills b ON b.reading_id = mr.id
             WHERE mr.billing_period = ? AND b.id IS NULL
             ORDER BY c.last_name, c.first_name'
        );
        $stmt->execute([$periodStart]);
        $readings = $stmt->fetchAll();

        if (!$readings) {
            flash('warning', 'No unbilled readings found for ' . period_label($periodStart) . '.');
            redirect('/pages/bill-generate.php?period=' . urlencode($period));
        }

        $dueDays  = (int)setting('due_days', 15);
        $dueDate  = date('Y-m-d', strtotime($periodEnd . " + $dueDays days"));
        $otherFee = (float)setting('other_fees_default', 0);

        $generated = 0;
        $skipped   = 0;
        $totalAmt  = 0.0;

        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare(
                'INSERT INTO bills
                    (bill_number, customer_id, reading_id, rate_id, billing_period,
                     period_start, period_end, consumption,
                     basic_charge, consumption_charge, other_fees, penalty, discount,
                     total_amount, amount_paid, balance, due_date, status, generated_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,0,0,?,0,?,?,"unpaid",?)'
            );

            foreach ($readings as $r) {
                $rate = find_applicable_rate($r['connection_type'], $periodStart);
                if (!$rate) { $skipped++; continue; }

                $calc = calculate_bill_charges((float)$r['consumption'], $rate, $otherFee);
                if (!$calc['valid']) { $skipped++; continue; }

                $billNumber = next_bill_number($periodStart);

                try {
                    $insert->execute([
                        $billNumber,
                        (int)$r['customer_id'],
                        (int)$r['id'],
                        (int)$rate['id'],
                        $periodStart,
                        $periodStart,
                        $periodEnd,
                        (float)$r['consumption'],
                        $calc['basic_charge'],
                        $calc['consumption_charge'],
                        $calc['other_fees'],
                        $calc['total'],
                        $calc['total'],
                        $dueDate,
                        (int)$me['id'],
                    ]);
                    $generated++;
                    $totalAmt += $calc['total'];
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') { $skipped++; continue; }
                    throw $e;
                }
            }
            $pdo->commit();

            log_activity('GENERATE', 'Bills',
                "Generated $generated bill(s) for " . period_label($periodStart) .
                " totaling ₱" . number_format($totalAmt, 2) . " (skipped $skipped)");

            $msg = "Generated $generated bill(s) totaling " . money($totalAmt) . ".";
            if ($skipped > 0) {
                $msg .= " Skipped $skipped reading(s) — likely no active rate for their connection type or already billed.";
            }
            flash('success', $msg);
            redirect('/pages/bills.php?period=' . urlencode($period));
        } catch (Throwable $ex) {
            $pdo->rollBack();
            $errors[] = 'Generation failed: ' . $ex->getMessage();
        }
    }
}

$stmt = $pdo->prepare(
    'SELECT mr.*, c.customer_code, c.first_name, c.last_name, c.connection_type
     FROM meter_readings mr
     INNER JOIN customers c ON c.id = mr.customer_id
     LEFT JOIN bills b ON b.reading_id = mr.id
     WHERE mr.billing_period = ? AND b.id IS NULL
     ORDER BY c.last_name, c.first_name'
);
$stmt->execute([$periodStart]);
$unbilledReadings = $stmt->fetchAll();

$preview = [];
$projectedTotal = 0.0;
$missingRates   = 0;

foreach ($unbilledReadings as $r) {
    $rate = find_applicable_rate($r['connection_type'], $periodStart);
    $calc = calculate_bill_charges((float)$r['consumption'], $rate);
    if (!$calc['valid']) {
        $missingRates++;
    } else {
        $projectedTotal += $calc['total'];
    }
    $preview[] = ['reading' => $r, 'rate' => $rate, 'calc' => $calc];
}

$dueDays  = (int)setting('due_days', 15);
$dueDate  = date('Y-m-d', strtotime($periodEnd . " + $dueDays days"));

$__title = 'Generate Bills';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <a href="<?= e(url('/pages/bills.php')) ?>" class="text-decoration-none small">
    <i class="bi bi-arrow-left me-1"></i>Back to Bills
  </a>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger py-2"><?= e($err) ?></div>
<?php endforeach; ?>

<div class="card mb-3">
  <div class="card-header"><i class="bi bi-calendar-event me-2"></i>Billing Period</div>
  <div class="card-body">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-4">
        <label class="form-label small">Select Period</label>
        <input type="month" name="period" class="form-control" value="<?= e($period) ?>" max="<?= e(date('Y-m')) ?>">
      </div>
      <div class="col-md-2">
        <button class="btn btn-outline-primary w-100">Preview</button>
      </div>
      <div class="col-md-6 text-md-end small text-muted">
        Period: <?= e(date('M j, Y', strtotime($periodStart))) ?> — <?= e(date('M j, Y', strtotime($periodEnd))) ?><br>
        Due date: <strong><?= e(date('M j, Y', strtotime($dueDate))) ?></strong>
        (<?= $dueDays ?> days after period end)
      </div>
    </form>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="label">Unbilled Readings</div>
      <div class="value"><?= count($unbilledReadings) ?></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="label">Projected Total</div>
      <div class="value text-primary"><?= e(money($projectedTotal)) ?></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="label">Missing Rates</div>
      <div class="value <?= $missingRates > 0 ? 'text-danger' : 'text-success' ?>"><?= $missingRates ?></div>
      <?php if ($missingRates > 0): ?>
        <small class="text-danger">Rows without a rate will be skipped</small>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if (!$unbilledReadings): ?>
  <div class="alert alert-info py-3">
    <i class="bi bi-info-circle me-2"></i>
    No unbilled readings for <strong><?= e(period_label($periodStart)) ?></strong>.
    Record meter readings for this period first, then come back.
    <a href="<?= e(url('/pages/reading-form.php')) ?>" class="ms-2">Record a reading →</a>
  </div>
<?php else: ?>

<div class="card mb-3">
  <div class="card-header">Preview — <?= e(period_label($periodStart)) ?></div>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Customer</th>
          <th>Type</th>
          <th class="text-end">Consumption</th>
          <th>Rate Applied</th>
          <th class="text-end">Basic</th>
          <th class="text-end">Consumption Chg</th>
          <th class="text-end">Other Fees</th>
          <th class="text-end">Total</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($preview as $p): $r = $p['reading']; ?>
        <tr>
          <td class="small">
            <?= e($r['first_name'] . ' ' . $r['last_name']) ?>
            <br><span class="text-muted"><?= e($r['customer_code']) ?></span>
          </td>
          <td class="small text-capitalize"><?= e($r['connection_type']) ?></td>
          <td class="text-end small"><?= number_format((float)$r['consumption'], 2) ?> m³</td>
          <td class="small">
            <?php if ($p['rate']): ?>
              <?= e($p['rate']['name']) ?>
              <br><span class="text-muted">₱<?= number_format((float)$p['rate']['rate_per_cubic'], 2) ?>/m³</span>
            <?php else: ?>
              <span class="badge text-bg-danger">No rate</span>
            <?php endif; ?>
          </td>
          <?php if ($p['calc']['valid']): ?>
            <td class="text-end small"><?= e(money($p['calc']['basic_charge'])) ?></td>
            <td class="text-end small"><?= e(money($p['calc']['consumption_charge'])) ?></td>
            <td class="text-end small"><?= e(money($p['calc']['other_fees'])) ?></td>
            <td class="text-end fw-semibold"><?= e(money($p['calc']['total'])) ?></td>
          <?php else: ?>
            <td class="text-end text-muted small" colspan="4">Will be skipped</td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr class="fw-bold border-top">
          <td colspan="7" class="text-end">Projected Total:</td>
          <td class="text-end text-primary"><?= e(money($projectedTotal)) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<form method="post" onsubmit="return confirm('Generate <?= count($unbilledReadings) ?> bill(s) totaling <?= e(money($projectedTotal)) ?> for <?= e(period_label($periodStart)) ?>? This cannot be undone.')">
  <?= csrf_field() ?>
  <input type="hidden" name="billing_period" value="<?= e($period) ?>">
  <div class="d-flex gap-2">
    <button class="btn btn-primary px-4">
      <i class="bi bi-check2-circle me-1"></i>Generate <?= count($unbilledReadings) ?> Bill(s)
    </button>
    <a href="<?= e(url('/pages/bills.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
  </div>
</form>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>