<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/statement_render.php';
require_login();

$pdo = db();
$me  = auth_user();
$id  = (int)get('id', 0);

$stmt = $pdo->prepare(
    'SELECT b.*, mr.previous_reading, mr.current_reading,
            c.customer_code, c.first_name, c.last_name, c.address, c.barangay, c.city,
            c.meter_number, c.connection_type, c.id AS cust_id,
            wr.rate_per_cubic, wr.min_consumption, wr.penalty_rate
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
    flash('danger', 'Statement not found.');
    redirect($me['role'] === 'customer' ? '/pages/my-bills.php' : '/pages/statements.php');
}

if ($me['role'] === 'customer' && (int)$bill['cust_id'] !== (int)$me['customer_id']) {
    http_response_code(403);
    exit('Access denied.');
}

$stmt = $pdo->prepare('SELECT * FROM payments WHERE bill_id = ? ORDER BY payment_date ASC');
$stmt->execute([$id]);
$bill['payments'] = $stmt->fetchAll();

$html = render_statement_html(prepare_statement_data($bill));

$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

if (class_exists('Dompdf\Dompdf')) {
    try {
        $dompdf = new \Dompdf\Dompdf([
            'isRemoteEnabled' => false,
            'defaultFont'     => 'Helvetica',
        ]);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $filename = 'Statement-' . preg_replace('/[^A-Za-z0-9\-]/', '', $bill['bill_number']) . '.pdf';
        $dompdf->stream($filename, ['Attachment' => true]);
        exit;
    } catch (Throwable $e) {
        // fall through
    }
}

$fallback = render_statement_html(prepare_statement_data($bill), true);
header('Content-Type: text/html; charset=utf-8');
echo '<div style="background:#fff3cd;color:#664d03;padding:12px;font-family:sans-serif;text-align:center;border-bottom:1px solid #ffe69c;">'
   . '<strong>PDF generator not installed.</strong> Use your browser\'s <em>Print → Save as PDF</em>. '
   . 'To enable direct PDF download, run <code style="background:#fff;padding:2px 6px;border-radius:4px;">composer install</code> once in the project root.'
   . '</div>'
   . $fallback;