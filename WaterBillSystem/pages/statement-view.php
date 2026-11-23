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

header('Content-Type: text/html; charset=utf-8');
echo $html;