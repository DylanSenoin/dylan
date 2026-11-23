<?php
declare(strict_types=1);

function render_statement_html(array $bill, bool $autoPrint = false): string
{
    $provider    = e(setting('provider_name', APP_NAME));
    $address     = e(setting('provider_address', ''));
    $contact     = e(setting('provider_contact', ''));
    $email       = e(setting('provider_email', ''));
    $currency    = e(setting('currency_symbol', '₱'));

    $statusColor = match ($bill['status']) {
        'paid'    => '#198754',
        'overdue' => '#dc3545',
        'partial' => '#0dcaf0',
        default   => '#ffc107',
    };

    $m = fn($v) => $currency . number_format((float)$v, 2);

    $totalPaid    = (float)$bill['amount_paid'];
    $balance      = (float)$bill['balance'];
    $minCons      = (float)($bill['min_consumption'] ?? 0);

    $autoPrintJs = $autoPrint
        ? '<script>window.addEventListener("load", () => window.print());</script>'
        : '';

    return <<<HTML
<!doctype html>
<html><head>
<meta charset="utf-8">
<title>Statement — {$bill['bill_number']}</title>
<style>
  @page { margin: 18mm; }
  * { box-sizing: border-box; }
  body { font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; font-size: 12px; color: #222; margin: 0; padding: 0; }
  .container { max-width: 720px; margin: 0 auto; padding: 20px; }
  .header { border-bottom: 3px solid #1565c0; padding-bottom: 14px; margin-bottom: 20px; }
  .header table { width: 100%; border-collapse: collapse; }
  .header td { vertical-align: top; }
  .provider-name { font-size: 18px; font-weight: 700; color: #0b2545; margin: 0 0 4px; }
  .provider-meta { font-size: 11px; color: #666; line-height: 1.4; }
  .doc-title { text-align: right; font-size: 14px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: #1565c0; margin-bottom: 4px; }
  .bill-number { text-align: right; font-size: 20px; font-weight: 700; color: #0b2545; }
  .status-badge { display: inline-block; padding: 3px 10px; border-radius: 10px; font-size: 10px; text-transform: uppercase; letter-spacing: .6px; font-weight: 700; color: #fff; background: {$statusColor}; margin-top: 6px; }
  .row { display: table; width: 100%; margin-bottom: 18px; }
  .col { display: table-cell; width: 50%; vertical-align: top; padding-right: 12px; }
  .label { font-size: 10px; color: #888; text-transform: uppercase; letter-spacing: .6px; margin-bottom: 2px; }
  .value { font-size: 12px; font-weight: 600; color: #222; }
  .muted { color: #666; font-size: 11px; line-height: 1.5; }
  table.data { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
  table.data th { background: #f1f5f9; padding: 8px 10px; text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .5px; color: #555; border-top: 1px solid #dde5ee; border-bottom: 1px solid #dde5ee; }
  table.data td { padding: 8px 10px; border-bottom: 1px solid #eef3f9; }
  table.data .right { text-align: right; }
  .total-row td { font-weight: 700; font-size: 13px; border-top: 2px solid #0b2545; background: #f8fbff; }
  .grand-total td { font-weight: 700; font-size: 15px; color: #0b2545; }
  .balance-danger { color: #dc3545; }
  .balance-clear  { color: #198754; }
  .footer-note { text-align: center; color: #888; font-size: 10px; margin-top: 24px; padding-top: 14px; border-top: 1px solid #eef3f9; line-height: 1.6; }
  .signature { margin-top: 32px; }
  .signature .line { border-top: 1px solid #333; width: 220px; margin-top: 34px; text-align: center; padding-top: 4px; }
  .signature .line-label { font-size: 10px; color: #666; text-transform: uppercase; letter-spacing: .5px; }
  @media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <table>
      <tr>
        <td style="width: 60%;">
          <div class="provider-name">{$provider}</div>
          <div class="provider-meta">{$address}<br>{$contact} &middot; {$email}</div>
        </td>
        <td style="width: 40%;">
          <div class="doc-title">Billing Statement</div>
          <div class="bill-number">{$bill['bill_number']}</div>
          <span class="status-badge">{$bill['status']}</span>
        </td>
      </tr>
    </table>
  </div>

  <div class="row">
    <div class="col">
      <div class="label">Billed To</div>
      <div class="value">{$bill['first_name']} {$bill['last_name']}</div>
      <div class="muted">
        {$bill['customer_code']} &middot; Meter #{$bill['meter_number']}<br>
        {$bill['address']}<br>
        {$bill['barangay']}, {$bill['city']}<br>
        Connection: {$bill['connection_type']}
      </div>
    </div>
    <div class="col" style="text-align: right;">
      <div class="label">Billing Period</div>
      <div class="value">{$bill['period_label']}</div>
      <div class="muted">{$bill['period_start_fmt']} — {$bill['period_end_fmt']}</div>
      <div class="label" style="margin-top: 12px;">Due Date</div>
      <div class="value" style="color: {$statusColor};">{$bill['due_date_fmt']}</div>
    </div>
  </div>

  <table class="data">
    <thead><tr><th>Meter Reading</th><th class="right">Previous</th><th class="right">Current</th><th class="right">Consumption</th></tr></thead>
    <tbody><tr><td>Volume (m³)</td><td class="right">{$bill['prev_fmt']}</td><td class="right">{$bill['curr_fmt']}</td><td class="right" style="font-weight: 700;">{$bill['cons_fmt']}</td></tr></tbody>
  </table>

  <table class="data">
    <thead><tr><th>Charge Description</th><th class="right">Amount</th></tr></thead>
    <tbody>
      <tr><td>Basic Charge <span class="muted">(first {$minCons} m³)</span></td><td class="right">{$m($bill['basic_charge'])}</td></tr>
      <tr><td>Consumption Charge <span class="muted">(&#64; {$m($bill['rate_per_cubic'])}/m³)</span></td><td class="right">{$m($bill['consumption_charge'])}</td></tr>
      {$bill['extra_fee_rows']}
      {$bill['discount_row']}
    </tbody>
    <tfoot>
      <tr class="total-row"><td>Total Amount Due</td><td class="right">{$m($bill['total_amount'])}</td></tr>
      <tr><td>Amount Paid</td><td class="right" style="color: #198754;">{$m($totalPaid)}</td></tr>
      <tr class="grand-total"><td>Balance</td><td class="right balance-{$bill['balance_class']}">{$m($balance)}</td></tr>
    </tfoot>
  </table>

  {$bill['payment_history_block']}

  <div class="signature">
    <table style="width: 100%;">
      <tr>
        <td style="width: 50%;"><div class="line"><div class="line-label">Received By</div></div></td>
        <td style="width: 50%; text-align: right;"><div class="line" style="margin-left: auto;"><div class="line-label">Customer Signature</div></div></td>
      </tr>
    </table>
  </div>

  <div class="footer-note">
    This is a system-generated statement. Please retain for your records.<br>
    Generated on {$bill['generated_fmt']} by Water Bill Management System v{$bill['version']}
  </div>
</div>
{$autoPrintJs}
</body></html>
HTML;
}

function prepare_statement_data(array $bill): array
{
    $currency = setting('currency_symbol', '₱');
    $m = fn($v) => $currency . number_format((float)$v, 2);

    $extraFeeRows = '';
    if ((float)$bill['other_fees'] > 0) {
        $extraFeeRows .= '<tr><td>Other Fees</td><td class="right">' . $m($bill['other_fees']) . '</td></tr>';
    }
    if ((float)$bill['penalty'] > 0) {
        $penaltyRate = number_format((float)($bill['penalty_rate'] ?? 0), 2);
        $extraFeeRows .= '<tr><td>Overdue Penalty <span class="muted">(' . $penaltyRate . '% of balance)</span></td>'
            . '<td class="right" style="color: #dc3545;">' . $m($bill['penalty']) . '</td></tr>';
    }

    $discountRow = '';
    if ((float)$bill['discount'] > 0) {
        $discountRow = '<tr><td>Discount</td><td class="right" style="color: #198754;">-'
            . $m($bill['discount']) . '</td></tr>';
    }

    $paymentBlock = '';
    if (!empty($bill['payments'])) {
        $rows = '';
        foreach ($bill['payments'] as $p) {
            $rows .= '<tr><td>' . htmlspecialchars($p['receipt_number'], ENT_QUOTES)
                . '<br><span class="muted">' . date('M j, Y', strtotime($p['payment_date']))
                . '</span></td>'
                . '<td>' . ucfirst(str_replace('_', ' ', $p['method'])) . '</td>'
                . '<td class="right">' . $m($p['amount']) . '</td></tr>';
        }
        $paymentBlock = '<table class="data"><thead><tr><th>Receipt #</th><th>Method</th><th class="right">Amount</th></tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    return [
        'bill_number'           => htmlspecialchars($bill['bill_number'], ENT_QUOTES),
        'status'                => htmlspecialchars($bill['status'], ENT_QUOTES),
        'first_name'            => htmlspecialchars($bill['first_name'], ENT_QUOTES),
        'last_name'             => htmlspecialchars($bill['last_name'], ENT_QUOTES),
        'customer_code'         => htmlspecialchars($bill['customer_code'], ENT_QUOTES),
        'meter_number'          => htmlspecialchars($bill['meter_number'], ENT_QUOTES),
        'address'               => htmlspecialchars($bill['address'], ENT_QUOTES),
        'barangay'              => htmlspecialchars($bill['barangay'] ?? '', ENT_QUOTES),
        'city'                  => htmlspecialchars($bill['city'] ?? '', ENT_QUOTES),
        'connection_type'       => htmlspecialchars($bill['connection_type'], ENT_QUOTES),
        'period_label'          => date('F Y', strtotime($bill['billing_period'])),
        'period_start_fmt'      => date('M j, Y', strtotime($bill['period_start'])),
        'period_end_fmt'        => date('M j, Y', strtotime($bill['period_end'])),
        'due_date_fmt'          => date('F j, Y', strtotime($bill['due_date'])),
        'prev_fmt'              => number_format((float)$bill['previous_reading'], 2),
        'curr_fmt'              => number_format((float)$bill['current_reading'], 2),
        'cons_fmt'              => number_format((float)$bill['consumption'], 2),
        'basic_charge'          => $bill['basic_charge'],
        'consumption_charge'    => $bill['consumption_charge'],
        'rate_per_cubic'        => $bill['rate_per_cubic'] ?? 0,
        'min_consumption'       => number_format((float)($bill['min_consumption'] ?? 0), 2),
        'total_amount'          => $bill['total_amount'],
        'amount_paid'           => $bill['amount_paid'],
        'balance'               => $bill['balance'],
        'balance_class'         => (float)$bill['balance'] > 0 ? 'danger' : 'clear',
        'extra_fee_rows'        => $extraFeeRows,
        'discount_row'          => $discountRow,
        'payment_history_block' => $paymentBlock,
        'payments'              => $bill['payments'] ?? [],
        'generated_fmt'         => date('F j, Y g:i A'),
        'version'               => APP_VERSION,
    ];
}