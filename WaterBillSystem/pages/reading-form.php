<?php
require_once __DIR__ . '/../includes/init.php';
require_role('admin', 'staff');

const MAX_METER_READING = 999999.99;
const MAX_CONSUMPTION   = 50000.00;

$pdo     = db();
$me      = auth_user();
$id      = (int)get('id', 0);
$isEdit  = $id > 0;
$errors  = [];
$isFirstReading = false;
$laterReading   = null;

$form = [
    'customer_id'      => (int)get('customer', 0),
    'billing_period'   => date('Y-m'),
    'reading_date'     => date('Y-m-d'),
    'previous_reading' => '0.00',
    'current_reading'  => '',
];

$existing = null;

if ($isEdit) {
    $stmt = $pdo->prepare(
        'SELECT mr.*, c.customer_code, c.first_name, c.last_name, c.meter_number,
                b.id AS bill_id, b.bill_number
         FROM meter_readings mr
         INNER JOIN customers c ON c.id = mr.customer_id
         LEFT JOIN bills b ON b.reading_id = mr.id
         WHERE mr.id = ?'
    );
    $stmt->execute([$id]);
    $existing = $stmt->fetch();

    if (!$existing) {
        flash('danger', 'Reading not found.');
        redirect('/pages/readings.php');
    }
    if ($existing['bill_id']) {
        flash('warning', "Cannot edit — bill {$existing['bill_number']} was already generated from this reading.");
        redirect('/pages/readings.php');
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM meter_readings WHERE customer_id = ? AND billing_period < ?'
    );
    $stmt->execute([(int)$existing['customer_id'], $existing['billing_period']]);
    $isFirstReading = ((int)$stmt->fetchColumn()) === 0;

    $stmt = $pdo->prepare(
        'SELECT id, billing_period FROM meter_readings
         WHERE customer_id = ? AND billing_period > ?
         ORDER BY billing_period ASC LIMIT 1'
    );
    $stmt->execute([(int)$existing['customer_id'], $existing['billing_period']]);
    $laterReading = $stmt->fetch() ?: null;

    $form = [
        'customer_id'      => (int)$existing['customer_id'],
        'billing_period'   => substr($existing['billing_period'], 0, 7),
        'reading_date'     => $existing['reading_date'],
        'previous_reading' => number_format((float)$existing['previous_reading'], 2, '.', ''),
        'current_reading'  => number_format((float)$existing['current_reading'], 2, '.', ''),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $readingDate = trim((string)post('reading_date', ''));

    if ($isEdit) {
        $customerId  = (int)$existing['customer_id'];
        $periodDate  = $existing['billing_period'];
        $previous    = $isFirstReading
            ? input_float(post('previous_reading', $existing['previous_reading']))
            : (float)$existing['previous_reading'];

        $stmt = $pdo->prepare('SELECT id FROM bills WHERE reading_id = ? LIMIT 1');
        $stmt->execute([$id]);
        if ($stmt->fetch()) {
            flash('danger', 'Cannot save — a bill now exists for this reading.');
            redirect('/pages/readings.php');
        }
    } else {
        $customerId    = (int)post('customer_id', 0);
        $billingPeriod = trim((string)post('billing_period', ''));
        $previous      = input_float(post('previous_reading', 0));

        if (!preg_match('/^\d{4}-\d{2}$/', $billingPeriod)) {
            $errors[] = 'Invalid billing period format.';
            $periodDate = null;
        } else {
            $periodDate = $billingPeriod . '-01';
        }
    }

    $currentRaw = trim((string)post('current_reading', ''));
    $current    = input_float($currentRaw);

    if ($currentRaw === '' || !is_numeric(str_replace(',', '', $currentRaw))) {
        $errors[] = 'Current reading is required.';
    }

    if ($isEdit && $laterReading && abs($current - (float)$existing['current_reading']) > 0.001) {
        $errors[] = 'Cannot change current reading because a newer reading exists for '
            . period_label($laterReading['billing_period'])
            . '. Delete or update newer readings first.';
    }

    if ($readingDate === '' || !strtotime($readingDate)) {
        $errors[] = 'Reading date is required.';
    } elseif (strtotime($readingDate) > strtotime('today')) {
        $errors[] = 'Reading date cannot be in the future.';
    }

    if ($previous < 0) $errors[] = 'Previous reading cannot be negative.';
    if ($previous > MAX_METER_READING) {
        $errors[] = 'Previous reading cannot exceed ' . number_format(MAX_METER_READING, 2) . ' m³.';
    }
    if ($current < 0)  $errors[] = 'Current reading cannot be negative.';
    if ($current > MAX_METER_READING) {
        $errors[] = 'Current reading cannot exceed ' . number_format(MAX_METER_READING, 2) . ' m³. Please check for a typo.';
    }
    if ($current < $previous && !$errors) {
        $errors[] = 'Current reading cannot be lower than the previous reading.';
    }
    if ($current >= $previous && ($current - $previous) > MAX_CONSUMPTION) {
        $errors[] = 'Computed consumption (' . number_format($current - $previous, 2)
            . ' m³) exceeds the maximum allowed single-period consumption of '
            . number_format(MAX_CONSUMPTION, 2) . ' m³. Please check your meter reading values.';
    }

    if (!$isEdit) {
        if ($customerId <= 0) {
            $errors[] = 'Please select a customer.';
        } else {
            $stmt = $pdo->prepare('SELECT id, status FROM customers WHERE id = ?');
            $stmt->execute([$customerId]);
            $c = $stmt->fetch();
            if (!$c) $errors[] = 'Selected customer not found.';
            elseif ($c['status'] !== 'active') $errors[] = 'Selected customer is inactive.';
        }

        if ($periodDate) {
            if (strtotime($periodDate) > strtotime(date('Y-m-01'))) {
                $errors[] = 'Billing period cannot be in the future.';
            }

            $stmt = $pdo->prepare('SELECT id FROM meter_readings WHERE customer_id = ? AND billing_period = ?');
            $stmt->execute([$customerId, $periodDate]);
            if ($stmt->fetch()) {
                $errors[] = 'A reading for that customer and billing period already exists.';
            }

            $stmt = $pdo->prepare(
                'SELECT billing_period, current_reading FROM meter_readings
                 WHERE customer_id = ? ORDER BY billing_period DESC LIMIT 1'
            );
            $stmt->execute([$customerId]);
            $last = $stmt->fetch();

            if ($last) {
                if (strtotime($periodDate) <= strtotime($last['billing_period'])) {
                    $errors[] = 'Billing period must be after the last reading ('
                        . date('F Y', strtotime($last['billing_period'])) . ').';
                }
                $expected = (float)$last['current_reading'];
                if (abs($previous - $expected) > 0.001) {
                    $errors[] = sprintf(
                        'Previous reading must match the last recorded reading (%.2f m³). You entered %.2f m³.',
                        $expected, $previous
                    );
                }
            }
        }
    }

    if (!$errors) {
        $consumption = round($current - $previous, 2);

        if ($isEdit) {
            $stmt = $pdo->prepare(
                'UPDATE meter_readings SET reading_date = ?, previous_reading = ?, current_reading = ?, consumption = ? WHERE id = ?'
            );
            $stmt->execute([$readingDate, $previous, $current, $consumption, $id]);
            log_activity('UPDATE', 'Meter Readings', "Updated reading #$id (period $periodDate, consumption {$consumption} m³)");
            flash('success', "Reading updated. Consumption: {$consumption} m³.");
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO meter_readings
                    (customer_id, reading_date, billing_period, previous_reading, current_reading, consumption, recorded_by)
                 VALUES (?,?,?,?,?,?,?)'
            );
            $stmt->execute([$customerId, $readingDate, $periodDate, $previous, $current, $consumption, $me['id']]);
            $newId = (int)$pdo->lastInsertId();
            log_activity('CREATE', 'Meter Readings',
                "Recorded reading #$newId for customer #$customerId, period $periodDate, consumption {$consumption} m³");
            flash('success', "Reading recorded. Consumption: {$consumption} m³.");
        }
        redirect('/pages/readings.php');
    }

    $form = [
        'customer_id'      => $customerId,
        'billing_period'   => $isEdit ? substr($existing['billing_period'], 0, 7) : (post('billing_period', '')),
        'reading_date'     => $readingDate,
        'previous_reading' => number_format($previous, 2, '.', ''),
        'current_reading'  => number_format($current, 2, '.', ''),
    ];
}

$customers = $pdo->query(
    "SELECT id, customer_code, first_name, last_name, meter_number
     FROM customers WHERE status = 'active'
     ORDER BY last_name, first_name"
)->fetchAll();

$lastMapRows = $pdo->query(
    "SELECT mr.customer_id, mr.current_reading
     FROM meter_readings mr
     INNER JOIN (
        SELECT customer_id, MAX(billing_period) AS mp
        FROM meter_readings GROUP BY customer_id
     ) latest ON latest.customer_id = mr.customer_id AND latest.mp = mr.billing_period"
)->fetchAll();
$lastReadingMap = [];
foreach ($lastMapRows as $r) {
    $lastReadingMap[(int)$r['customer_id']] = (float)$r['current_reading'];
}

$__title = $isEdit ? 'Edit Meter Reading' : 'Record Meter Reading';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <a href="<?= e(url('/pages/readings.php')) ?>" class="text-decoration-none small">
    <i class="bi bi-arrow-left me-1"></i>Back to Readings
  </a>
  <?php if ($isEdit && $existing): ?>
    <span class="badge text-bg-light border">
      <?= e($existing['customer_code']) ?> · <?= e(period_label($existing['billing_period'])) ?>
    </span>
  <?php endif; ?>
</div>

<?php if ($isEdit): ?>
  <div class="alert alert-info py-2 d-flex align-items-center">
    <i class="bi bi-lock me-2"></i>
    <div>
      <?php if ($isFirstReading): ?>
        <strong>Editing the first reading.</strong>
        Customer and billing period are locked; you may correct both previous and current readings.
      <?php else: ?>
        <strong>Editing an existing reading.</strong>
        Customer, billing period, and previous reading are locked.
      <?php endif; ?>
    </div>
  </div>
  <?php if ($laterReading): ?>
    <div class="alert alert-warning py-2 d-flex align-items-center">
      <i class="bi bi-exclamation-triangle me-2"></i>
      <div>
        A newer reading exists for <strong><?= e(period_label($laterReading['billing_period'])) ?></strong>.
        Delete newer readings first if you need to change the current reading.
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger py-2"><?= e($err) ?></div>
<?php endforeach; ?>

<form method="post" novalidate autocomplete="off">
  <?= csrf_field() ?>

  <div class="card mb-3">
    <div class="card-header"><i class="bi bi-person me-2"></i>Customer & Period</div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Customer <span class="text-danger">*</span></label>
          <?php if ($isEdit): ?>
            <input class="form-control" value="<?= e($existing['first_name'] . ' ' . $existing['last_name']) ?>" disabled>
            <small class="text-muted"><?= e($existing['customer_code']) ?> · Meter <?= e($existing['meter_number']) ?></small>
          <?php else: ?>
            <select name="customer_id" id="customerSelect" class="form-select" required>
              <option value="">— Select customer —</option>
              <?php foreach ($customers as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= (int)$form['customer_id'] === (int)$c['id'] ? 'selected' : '' ?>>
                  <?= e($c['customer_code'] . ' — ' . $c['first_name'] . ' ' . $c['last_name'] . ' (Meter ' . $c['meter_number'] . ')') ?>
                </option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
        </div>

        <div class="col-md-3">
          <label class="form-label">Billing Period <span class="text-danger">*</span></label>
          <?php if ($isEdit): ?>
            <input class="form-control" value="<?= e(date('F Y', strtotime($existing['billing_period']))) ?>" disabled>
          <?php else: ?>
            <input type="month" name="billing_period" class="form-control"
                   value="<?= e($form['billing_period']) ?>"
                   max="<?= e(date('Y-m')) ?>" required>
            <small class="text-muted">The month this reading covers.</small>
          <?php endif; ?>
        </div>

        <div class="col-md-3">
          <label class="form-label">Reading Date <span class="text-danger">*</span></label>
          <input type="date" name="reading_date" class="form-control"
                 value="<?= e($form['reading_date']) ?>"
                 max="<?= e(date('Y-m-d')) ?>" required>
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header"><i class="bi bi-speedometer me-2"></i>Meter Reading</div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Previous Reading (m³)</label>
          <input type="number" step="0.01" min="0" max="<?= e(number_format(MAX_METER_READING, 2, '.', '')) ?>"
                 name="previous_reading" id="prevReading"
                 class="form-control<?= ($isEdit && !$isFirstReading) ? ' bg-light' : '' ?>"
                 value="<?= e($form['previous_reading']) ?>" required
                 <?= ($isEdit && !$isFirstReading) ? 'readonly' : '' ?>>
          <small class="text-muted" id="prevHint">
            <?php if ($isEdit && $isFirstReading): ?>
              First reading — you may adjust the starting meter value.
            <?php elseif ($isEdit): ?>
              Locked — must match the previous period's reading.
            <?php else: ?>
              Auto-filled from the last recorded reading.
            <?php endif; ?>
          </small>
        </div>
        <div class="col-md-4">
          <label class="form-label">Current Reading (m³) <span class="text-danger">*</span></label>
          <input type="number" step="0.01" min="0" max="<?= e(number_format(MAX_METER_READING, 2, '.', '')) ?>"
                 name="current_reading" id="currReading"
                 class="form-control<?= ($isEdit && $laterReading) ? ' bg-light' : '' ?>"
                 value="<?= e($form['current_reading']) ?>" required
                 <?= ($isEdit && $laterReading) ? 'readonly' : '' ?>>
        </div>
        <div class="col-md-4">
          <label class="form-label">Computed Consumption</label>
          <div class="form-control bg-light d-flex align-items-center" style="height:calc(1.5em + .75rem + 2px);">
            <span id="consumptionDisplay">— m³</span>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="d-flex gap-2">
    <button class="btn btn-primary px-4">
      <i class="bi bi-check2 me-1"></i><?= $isEdit ? 'Save Changes' : 'Record Reading' ?>
    </button>
    <a href="<?= e(url('/pages/readings.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
  </div>
</form>

<script>
(function () {
  const isEdit         = <?= $isEdit ? 'true' : 'false' ?>;
  const maxReading     = <?= json_encode(MAX_METER_READING) ?>;
  const maxConsumption = <?= json_encode(MAX_CONSUMPTION) ?>;
  const lastReadingMap = <?= json_encode($lastReadingMap, JSON_FORCE_OBJECT) ?>;
  const customerSelect = document.getElementById('customerSelect');
  const prevInput      = document.getElementById('prevReading');
  const currInput      = document.getElementById('currReading');
  const consDisplay    = document.getElementById('consumptionDisplay');
  const prevHint       = document.getElementById('prevHint');

  const fmt = (n) => Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  function updatePreviousFromMap() {
    if (isEdit || !customerSelect) return;
    const cid = customerSelect.value;
    if (!cid) return;

    const last = lastReadingMap[cid];
    if (last !== undefined && last !== null) {
      prevInput.value = Number(last).toFixed(2);
      prevInput.readOnly = true;
      prevInput.classList.add('bg-light');
      prevHint.textContent = 'Locked — must match last recorded reading.';
    } else {
      if (prevInput.readOnly) prevInput.value = '0.00';
      prevInput.readOnly = false;
      prevInput.classList.remove('bg-light');
      prevHint.textContent = 'First reading — enter the meter’s starting value (0 for new installs).';
    }
    updateConsumption();
  }

  function updateConsumption() {
    const p = parseFloat(prevInput.value) || 0;
    const c = parseFloat(currInput.value) || 0;
    const cons = c - p;

    if (p > maxReading || c > maxReading) {
      consDisplay.innerHTML = '<span class="text-danger fw-bold">' + fmt(cons) + ' m³ (exceeds max limit — check for typo)</span>';
    } else if (cons < 0) {
      consDisplay.innerHTML = '<span class="text-danger fw-bold">' + fmt(cons) + ' m³ (invalid)</span>';
    } else if (cons > maxConsumption) {
      consDisplay.innerHTML = '<span class="text-danger fw-bold">' + fmt(cons) + ' m³ (exceeds max limit — check for typo)</span>';
    } else {
      consDisplay.innerHTML = '<span class="text-primary fw-bold">' + fmt(cons) + ' m³</span>';
    }
  }

  if (customerSelect) customerSelect.addEventListener('change', updatePreviousFromMap);
  if (prevInput) prevInput.addEventListener('input', updateConsumption);
  if (currInput) currInput.addEventListener('input', updateConsumption);

  if (!isEdit && customerSelect && customerSelect.value) updatePreviousFromMap();
  else updateConsumption();
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>