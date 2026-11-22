<?php
require_once __DIR__ . '/../includes/init.php';
require_role('admin');

$pdo = db();

$report = (string)get('report', 'daily_collections');
$validReports = [
    'daily_collections', 'monthly_collections', 'annual_revenue',
    'unpaid_bills', 'overdue_accounts', 'consumption', 'payment_history',
];
if (!in_array($report, $validReports, true)) {
    $report = 'daily_collections';
}

$today     = date('Y-m-d');
$fromDate  = trim((string)get('from', date('Y-m-01')));
$toDate    = trim((string)get('to',   $today));
$year      = (int)get('year', (int)date('Y'));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) $fromDate = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate))   $toDate   = $today;
if ($year < 2000 || $year > 2100)                    $year     = (int)date('Y');

$rows    = [];
$columns = [];
$summary = [];

switch ($report) {

    case 'daily_collections':
        $stmt = $pdo->prepare(
            "SELECT DATE(payment_date) AS day, COUNT(*) AS n, SUM(amount) AS total
             FROM payments
             WHERE DATE(payment_date) BETWEEN ? AND ?
             GROUP BY day ORDER BY day DESC"
        );
        $stmt->execute([$fromDate, $toDate]);
        $rows = $stmt->fetchAll();
        $columns = [
            'day'   => ['Date', 'date'],
            'n'     => ['Transactions', 'int'],
            'total' => ['Total Collected', 'money'],
        ];
        $summary = [
            'Transactions'    => array_sum(array_column($rows, 'n')),
            'Total Collected' => array_sum(array_column($rows, 'total')),
        ];
        break;

    case 'monthly_collections':
        $stmt = $pdo->prepare(
            "SELECT DATE_FORMAT(payment_date,'%Y-%m') AS ym,
                    DATE_FORMAT(payment_date,'%M %Y') AS label,
                    COUNT(*) AS n, SUM(amount) AS total
             FROM payments
             WHERE payment_date BETWEEN ? AND ?
             GROUP BY ym, label ORDER BY ym DESC"
        );
        $stmt->execute([$fromDate . ' 00:00:00', $toDate . ' 23:59:59']);
        $rows = $stmt->fetchAll();
        $columns = [
            'label' => ['Month', 'text'],
            'n'     => ['Transactions', 'int'],
            'total' => ['Total Collected', 'money'],
        ];
        $summary = [
            'Months'          => count($rows),
            'Total Collected' => array_sum(array_column($rows, 'total')),
        ];
        break;

    case 'annual_revenue':
        $stmt = $pdo->prepare(
            "SELECT MONTH(payment_date) AS m, MONTHNAME(payment_date) AS month_name,
                    COUNT(*) AS n, SUM(amount) AS total
             FROM payments WHERE YEAR(payment_date) = ?
             GROUP BY m, month_name ORDER BY m"
        );
        $stmt->execute([$year]);
        $rows = $stmt->fetchAll();
        $columns = [
            'month_name' => ['Month', 'text'],
            'n'          => ['Payments', 'int'],
            'total'      => ['Revenue', 'money'],
        ];
        $summary = [
            'Year'          => $year,
            'Total Revenue' => array_sum(array_column($rows, 'total')),
        ];
        break;

    case 'unpaid_bills':
        $stmt = $pdo->prepare(
            "SELECT b.bill_number, b.billing_period, b.due_date,
                    b.total_amount, b.amount_paid, b.balance, b.status,
                    c.customer_code, CONCAT(c.first_name,' ',c.last_name) AS customer_name,
                    c.meter_number
             FROM bills b
             INNER JOIN customers c ON c.id = b.customer_id
             WHERE b.status IN ('unpaid','partial') AND b.due_date BETWEEN ? AND ?
             ORDER BY b.due_date ASC"
        );
        $stmt->execute([$fromDate, $toDate]);
        $rows = $stmt->fetchAll();
        $columns = [
            'bill_number'    => ['Bill #', 'text'],
            'customer_name'  => ['Customer', 'text'],
            'meter_number'   => ['Meter', 'text'],
            'billing_period' => ['Period', 'period'],
            'due_date'       => ['Due Date', 'date'],
            'total_amount'   => ['Total', 'money'],
            'amount_paid'    => ['Paid', 'money'],
            'balance'        => ['Balance', 'money'],
        ];
        $summary = [
            'Bills'     => count($rows),
            'Total Due' => array_sum(array_column($rows, 'balance')),
        ];
        break;

    case 'overdue_accounts':
        $stmt = $pdo->query(
            "SELECT c.customer_code, CONCAT(c.first_name,' ',c.last_name) AS customer_name,
                    c.meter_number, c.phone,
                    COUNT(b.id) AS overdue_bills,
                    SUM(b.balance) AS total_balance,
                    MIN(b.due_date) AS oldest_due,
                    DATEDIFF(CURDATE(), MIN(b.due_date)) AS days_overdue
             FROM customers c
             INNER JOIN bills b ON b.customer_id = c.id
             WHERE b.status = 'overdue'
             GROUP BY c.id
             ORDER BY days_overdue DESC, total_balance DESC"
        );
        $rows = $stmt->fetchAll();
        $columns = [
            'customer_code' => ['Customer Code', 'text'],
            'customer_name' => ['Customer', 'text'],
            'meter_number'  => ['Meter', 'text'],
            'phone'         => ['Phone', 'text'],
            'overdue_bills' => ['Overdue Bills', 'int'],
            'oldest_due'    => ['Oldest Due', 'date'],
            'days_overdue'  => ['Days Overdue', 'int'],
            'total_balance' => ['Total Balance', 'money'],
        ];
        $summary = [
            'Customers'     => count($rows),
            'Total Overdue' => array_sum(array_column($rows, 'total_balance')),
        ];
        break;

    case 'consumption':
        $stmt = $pdo->prepare(
            "SELECT mr.billing_period, c.customer_code,
                    CONCAT(c.first_name,' ',c.last_name) AS customer_name,
                    c.connection_type,
                    mr.previous_reading, mr.current_reading, mr.consumption
             FROM meter_readings mr
             INNER JOIN customers c ON c.id = mr.customer_id
             WHERE mr.billing_period BETWEEN ? AND ?
             ORDER BY mr.billing_period DESC, c.last_name"
        );
        $stmt->execute([$fromDate, $toDate]);
        $rows = $stmt->fetchAll();
        $columns = [
            'billing_period'  => ['Period', 'period'],
            'customer_name'   => ['Customer', 'text'],
            'customer_code'   => ['Code', 'text'],
            'connection_type' => ['Type', 'text'],
            'previous_reading'=> ['Previous', 'num'],
            'current_reading' => ['Current', 'num'],
            'consumption'     => ['Consumption (m³)', 'num'],
        ];
        $summary = [
            'Readings'          => count($rows),
            'Total Consumption' => array_sum(array_column($rows, 'consumption')),
        ];
        break;

    case 'payment_history':
        $stmt = $pdo->prepare(
            "SELECT p.receipt_number, p.payment_date, p.method, p.amount, p.reference_no,
                    b.bill_number, b.billing_period,
                    c.customer_code, CONCAT(c.first_name,' ',c.last_name) AS customer_name
             FROM payments p
             INNER JOIN bills b ON b.id = p.bill_id
             INNER JOIN customers c ON c.id = p.customer_id
             WHERE DATE(p.payment_date) BETWEEN ? AND ?
             ORDER BY p.payment_date DESC"
        );
        $stmt->execute([$fromDate, $toDate]);
        $rows = $stmt->fetchAll();
        $columns = [
            'receipt_number' => ['Receipt #', 'text'],
            'payment_date'   => ['Date', 'datetime'],
            'customer_name'  => ['Customer', 'text'],
            'bill_number'    => ['Bill #', 'text'],
            'method'         => ['Method', 'method'],
            'amount'         => ['Amount', 'money'],
        ];
        $summary = [
            'Payments' => count($rows),
            'Total'    => array_sum(array_column($rows, 'amount')),
        ];
        break;
}

$reportLabels = [
    'daily_collections'   => 'Daily Collections',
    'monthly_collections' => 'Monthly Collections',
    'annual_revenue'      => 'Annual Revenue',
    'unpaid_bills'        => 'Unpaid Bills',
    'overdue_accounts'    => 'Overdue Accounts',
    'consumption'         => 'Customer Consumption',
    'payment_history'     => 'Payment History',
];

$exportQs = http_build_query(array_merge($_GET, ['report' => $report, 'export' => 'csv']));

$__title = 'Reports';
include __DIR__ . '/../includes/header.php';
?>

<div class="card mb-3">
  <div class="card-header"><i class="bi bi-graph-up-arrow me-2"></i>Reports & Analytics</div>
  <div class="card-body">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-3">
        <label class="form-label small text-muted mb-1">Report</label>
        <select name="report" class="form-select form-select-sm" onchange="this.form.submit()">
          <?php foreach ($reportLabels as $k => $v): ?>
            <option value="<?= $k ?>" <?= $report === $k ? 'selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <?php if ($report === 'annual_revenue'): ?>
        <div class="col-md-2">
          <label class="form-label small text-muted mb-1">Year</label>
          <input type="number" name="year" class="form-control form-control-sm"
                 min="2000" max="2100" value="<?= e((string)$year) ?>">
        </div>
      <?php elseif ($report === 'unpaid_bills'): ?>
        <div class="col-md-2">
          <label class="form-label small text-muted mb-1">Due From</label>
          <input type="date" name="from" class="form-control form-control-sm" value="<?= e($fromDate) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label small text-muted mb-1">Due To</label>
          <input type="date" name="to" class="form-control form-control-sm" value="<?= e($toDate) ?>">
        </div>
      <?php elseif ($report === 'consumption'): ?>
        <div class="col-md-2">
          <label class="form-label small text-muted mb-1">Period From</label>
          <input type="date" name="from" class="form-control form-control-sm" value="<?= e($fromDate) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label small text-muted mb-1">Period To</label>
          <input type="date" name="to" class="form-control form-control-sm" value="<?= e($toDate) ?>">
        </div>
      <?php elseif ($report !== 'overdue_accounts'): ?>
        <div class="col-md-2">
          <label class="form-label small text-muted mb-1">From</label>
          <input type="date" name="from" class="form-control form-control-sm" value="<?= e($fromDate) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label small text-muted mb-1">To</label>
          <input type="date" name="to" class="form-control form-control-sm" value="<?= e($toDate) ?>">
        </div>
      <?php endif; ?>

      <div class="col-md-3 d-flex gap-2">
        <button class="btn btn-sm btn-primary flex-grow-1">Apply</button>
        <a href="<?= e(url('/pages/report-export.php?' . $exportQs)) ?>" class="btn btn-sm btn-outline-success">
          <i class="bi bi-download me-1"></i>CSV
        </a>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">
          <i class="bi bi-printer"></i>
        </button>
      </div>
    </form>
  </div>
</div>

<div class="row g-3 mb-3">
  <?php foreach ($summary as $label => $value):
    $isMoney = is_string($label) && preg_match('/(Total|Revenue|Due|Collected|Overdue|Balance)/i', $label);
    $display = is_float($value) || $isMoney ? money($value) : number_format((float)$value);
    if (in_array($label, ['Year'], true)) $display = $value;
  ?>
    <div class="col-sm-6 col-xl-3">
      <div class="stat-card">
        <div class="label"><?= e($label) ?></div>
        <div class="value"><?= e((string)$display) ?></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><?= e($reportLabels[$report]) ?></span>
    <small class="text-muted"><?= count($rows) ?> row(s)</small>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <?php foreach ($columns as [$label, $type]): ?>
            <th class="<?= in_array($type, ['money','int','num'], true) ? 'text-end' : '' ?>">
              <?= e($label) ?>
            </th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="<?= count($columns) ?>" class="text-center text-muted py-4">
          No data for the selected period.
        </td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <?php foreach ($columns as $key => [$label, $type]): $v = $r[$key] ?? ''; ?>
            <td class="<?= in_array($type, ['money','int','num'], true) ? 'text-end' : '' ?> small">
              <?php
                switch ($type) {
                    case 'money':   echo e(money($v)); break;
                    case 'int':     echo e(number_format((int)$v)); break;
                    case 'num':     echo e(number_format((float)$v, 2)); break;
                    case 'date':    echo e($v ? date('M j, Y', strtotime($v)) : '—'); break;
                    case 'datetime':echo e($v ? date('M j, Y g:i A', strtotime($v)) : '—'); break;
                    case 'period':  echo e($v ? date('F Y', strtotime($v)) : '—'); break;
                    case 'method':  echo e(ucfirst(str_replace('_', ' ', $v))); break;
                    default:        echo e($v);
                }
              ?>
            </td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>