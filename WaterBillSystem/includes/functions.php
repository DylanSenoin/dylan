<?php
declare(strict_types=1);

function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function url(string $path): string { return BASE_URL . $path; }
function redirect(string $path): void { header('Location: ' . BASE_URL . $path); exit; }
function money($amount): string { return setting('currency_symbol', '₱') . number_format((float)$amount, 2); }
function flash(string $type, string $message): void { $_SESSION['flash'][] = ['type' => $type, 'message' => $message]; }
function get_flashes(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }

function setting(string $key, $default = null) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT setting_key, setting_value FROM system_settings') as $row) {
            $cache[$row['setting_key']] = $row['setting_value'];
        }
    }
    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

function log_activity(string $action, string $module, string $description = ''): void {
    $uid = auth_user()['id'] ?? null;
    $stmt = db()->prepare('INSERT INTO activity_logs (user_id, action, module, description, ip_address) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$uid, $action, $module, $description, $_SERVER['REMOTE_ADDR'] ?? null]);
}

function post(string $key, $default = null) { return $_POST[$key] ?? $default; }
function get(string $key, $default = null)  { return $_GET[$key]  ?? $default; }
function input_float($v): float { return round((float)str_replace(',', '', (string)$v), 2); }

function next_bill_number(string $period): string {
    $prefix = setting('bill_prefix', 'WB');
    $base   = $prefix . '-' . date('Ym', strtotime($period)) . '-';
    $stmt   = db()->prepare('SELECT bill_number FROM bills WHERE bill_number LIKE ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$base . '%']);
    $last = $stmt->fetchColumn();
    $seq  = $last ? ((int)substr($last, -5)) + 1 : 1;
    return $base . str_pad((string)$seq, 5, '0', STR_PAD_LEFT);
}
function next_receipt_number(): string {
    $prefix = setting('receipt_prefix', 'OR');
    $base   = $prefix . '-' . date('Ymd') . '-';
    $stmt   = db()->prepare('SELECT receipt_number FROM payments WHERE receipt_number LIKE ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$base . '%']);
    $last = $stmt->fetchColumn();
    $seq  = $last ? ((int)substr($last, -5)) + 1 : 1;
    return $base . str_pad((string)$seq, 5, '0', STR_PAD_LEFT);
}
function next_customer_code(): string {
    $stmt = db()->query("SELECT customer_code FROM customers ORDER BY id DESC LIMIT 1");
    $last = $stmt->fetchColumn();
    $seq  = $last ? ((int)substr($last, 3)) + 1 : 1;
    return 'CUS' . str_pad((string)$seq, 5, '0', STR_PAD_LEFT);
}
function period_start(string $period): string { return date('Y-m-01', strtotime($period)); }
function period_end(string $period): string   { return date('Y-m-t', strtotime($period)); }
function period_label(string $period): string { return date('F Y', strtotime($period)); }

function calculate_bill_charges(float $consumption, ?array $rate, ?float $otherFees = null): array {
    if ($otherFees === null) $otherFees = (float)setting('other_fees_default', 0);
    $otherFees = round($otherFees, 2);
    if (!$rate) return ['valid'=>false,'basic_charge'=>0.0,'consumption_charge'=>0.0,'other_fees'=>$otherFees,'total'=>0.0];
    $minConsumption = (float)$rate['min_consumption'];
    $minCharge      = (float)$rate['min_charge'];
    $ratePerCubic   = (float)$rate['rate_per_cubic'];
    $basicCharge       = round($minCharge, 2);
    $consumptionCharge = 0.0;
    if ($consumption > $minConsumption) {
        $consumptionCharge = round(($consumption - $minConsumption) * $ratePerCubic, 2);
    }
    $total = round($basicCharge + $consumptionCharge + $otherFees, 2);
    return ['valid'=>true,'basic_charge'=>$basicCharge,'consumption_charge'=>$consumptionCharge,'other_fees'=>$otherFees,'total'=>$total];
}

function find_applicable_rate(string $connectionType, string $periodStart): ?array {
    $stmt = db()->prepare('SELECT * FROM water_rates WHERE connection_type = ? AND is_active = 1 AND effective_date <= ? ORDER BY effective_date DESC, id DESC LIMIT 1');
    $stmt->execute([$connectionType, $periodStart]);
    return $stmt->fetch() ?: null;
}