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
    http_response_code(400);
    exit('Invalid report.');
}

$fromDate = trim((string)get('from', date('Y-m-01')));
$toDate   = trim((string)get('to',   date('Y-m-d')));
$year     = (int)get('year', (int)date('Y'));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) $fromDate = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate))   $toDate   = date('Y-m-d');
if ($year < 2000 || $year > 2100)                    $year     = (int)date('Y');

$headers = [];
$data    = [];

switch ($report) {
    case 'daily_collections':
        $stmt = $pdo->prepare(
            "SELECT DATE(payment_date) AS day, COUNT(*) AS n, SUM(amount) AS total
             FROM payments WHERE DATE(payment_date) BETWEEN ? AND ?
             GROUP BY day ORDER BY day DESC"
        );
        $stmt->execute([$fromDate, $toDate]);
        $headers = ['Date', 'Transactions', 'Total Collected'];
        foreach ($stmt->fetchAll() as $r) {
            $data[] = [$r['day'], (int)$r['n'], number_format((float)$r['total'], 2, '.', '')];
        }
        break;

    case 'monthly_collections':
        $stmt = $pdo->prepare(
            "SELECT DATE_FORMAT(payment_date,'%Y-%m') AS ym, COUNT(*) AS n, SUM(amount) AS total
             FROM payments WHERE payment_date BETWEEN ? AND ?
             GROUP BY ym ORDER BY ym DESC"
        );
        $stmt->execute([$fromDate . ' 00:00:00', $toDate . ' 23:59:59']);
        $headers = ['Month', 'Transactions', 'Total Collected'];
        foreach ($stmt->fetchAll() as $r) {
            $data[] = [date('F Y', strtotime($r['ym'] . '-01')), (int)$r['n'], number_format((float)$r['total'], 2, '.', '')];
        }
        break;

    case 'annual_revenue':
        $stmt = $pdo->prepare(
            "SELECT MONTH(payment_date) AS m, MONTHNAME(payment_date) AS month_name,
                    COUNT(*) AS n, SUM(amount) AS total
             FROM payments WHERE YEAR(payment_date) = ?
             GROUP BY m, month_name ORDER BY m"
        );
        $stmt->execute([$year]);
        $headers = ['Month', 'Payments', 'Revenue'];
        foreach ($stmt->fetchAll() as $r) {
            $data[] = [$r['month_name'], (int)$r['n'], number_format((float)$r['total'], 2, '.', '')];
        }
        break;

    case 'unpaid_bills':
        $stmt = $pdo->prepare(
            "SELECT b.bill_number, c.customer_code, CONCAT(c.first_name,' ',c.last_name) AS customer_name,
                    c.meter_number, b.billing_period, b.due_date, b.total_amount, b.amount_paid, b.balance
             FROM bills b INNER JOIN customers c ON c.id = b.customer_id
             WHERE b.status IN ('unpaid','partial') AND b.due_date BETWEEN ? AND ?
             ORDER BY b.due_date ASC"
        );
        $stmt->execute([$fromDate, $toDate]);
        $headers = ['Bill #', 'Customer Code', 'Customer', 'Meter', 'Period', 'Due Date', 'Total', 'Paid', 'Balance'];
        foreach ($stmt->fetchAll() as $r) {
            $data[] = [
                $r['bill_number'], $r['customer_code'], $r['customer_name'], $r['meter_number'],
                date('F Y', strtotime($r['billing_period'])), $r['due_date'],
                number_format((float)$r['total_amount'], 2, '.', ''),
                number_format((float)$r['amount_paid'], 2, '.', ''),
                number_format((float)$r['balance'], 2, '.', ''),
            ];
        }
        break;

    case 'overdue_accounts':
        $stmt = $pdo->query(
            "SELECT c.customer_code, CONCAT(c.first_name,' ',c.last_name) AS customer_name,
                    c.meter_number, c.phone, COUNT(b.id) AS overdue_bills,
                    SUM(b.balance) AS total_balance, MIN(b.due_date) AS oldest_due,
                    DATEDIFF(CURDATE(), MIN(b.due_date)) AS days_overdue
             FROM customers c INNER JOIN bills b ON b.customer_id = c.id
             WHERE b.status = 'overdue'
             GROUP BY c.id ORDER BY days_overdue DESC"
        );
        $headers = ['Customer Code', 'Customer', 'Meter', 'Phone', 'Overdue Bills', 'Oldest Due', 'Days Overdue', 'Total Balance'];
        foreach ($stmt->fetchAll() as $r) {
            $data[] = [
                $r['customer_code'], $r['customer_name'], $r['meter_number'], $r['phone'] ?? '',
                (int)$r['overdue_bills'], $r['oldest_due'], (int)$r['days_overdue'],
                number_format((float)$r['total_balance'], 2, '.', ''),
            ];
        }
        break;

    case 'consumption':
        $stmt = $pdo->prepare(
            "SELECT mr.billing_period, c.customer_code,
                    CONCAT(c.first_name,' ',c.last_name) AS customer_name, c.connection_type,
                    mr.previous_reading, mr.current_reading, mr.consumption
             FROM meter_readings mr INNER JOIN customers c ON c.id = mr.customer_id
             WHERE mr.billing_period BETWEEN ? AND ?
             ORDER BY mr.billing_period DESC, c.last_name"
        );
        $stmt->execute([$fromDate, $toDate]);
        $headers = ['Period', 'Customer Code', 'Customer', 'Type', 'Previous', 'Current', 'Consumption (m3)'];
        foreach ($stmt->fetchAll() as $r) {
            $data[] = [
                date('F Y', strtotime($r['billing_period'])),
                $r['customer_code'], $r['customer_name'], $r['connection_type'],
                number_format((float)$r['previous_reading'], 2, '.', ''),
                number_format((float)$r['current_reading'], 2, '.', ''),
                number_format((float)$r['consumption'], 2, '.', ''),
            ];
        }
        break;

    case 'payment_history':
        $stmt = $pdo->prepare(
            "SELECT p.receipt_number, p.payment_date, p.method, p.amount, p.reference_no,
                    b.bill_number, c.customer_code, CONCAT(c.first_name,' ',c.last_name) AS customer_name
             FROM payments p
             INNER JOIN bills b ON b.id = p.bill_id
             INNER JOIN customers c ON c.id = p.customer_id
             WHERE DATE(p.payment_date) BETWEEN ? AND ?
             ORDER BY p.payment_date DESC"
        );
        $stmt->execute([$fromDate, $toDate]);
        $headers = ['Receipt #', 'Date', 'Customer Code', 'Customer', 'Bill #', 'Method', 'Reference', 'Amount'];
        foreach ($stmt->fetchAll() as $r) {
            $data[] = [
                $r['receipt_number'], date('Y-m-d H:i', strtotime($r['payment_date'])),
                $r['customer_code'], $r['customer_name'], $r['bill_number'],
                ucfirst(str_replace('_', ' ', $r['method'])), $r['reference_no'] ?? '',
                number_format((float)$r['amount'], 2, '.', ''),
            ];
        }
        break;
}

function csv_safe($v): string
{
    $s = (string)$v;
    if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        $s = "'" . $s;
    }
    return $s;
}

$filename = $report . '_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, $headers);
foreach ($data as $row) {
    fputcsv($out, array_map('csv_safe', $row));
}
fclose($out);

log_activity('EXPORT', 'Reports', "Exported $report CSV (" . count($data) . " rows)");
exit;