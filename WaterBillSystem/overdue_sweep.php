<?php
/**
 * Automated overdue sweep. Run via cron or Windows Task Scheduler.
 *
 *   Linux/macOS (daily at 1:00 AM):
 *     0 1 * * * /usr/bin/php /path/to/waterbill/cron/overdue_sweep.php
 *
 *   Windows Task Scheduler:
 *     Program: C:\xampp\php\php.exe
 *     Arguments: C:\xampp\htdocs\waterbill\cron\overdue_sweep.php
 *
 * Safe to run multiple times — idempotent by design.
 */
require_once __DIR__ . '/../includes/init.php';

$pdo = db();

$stmt = $pdo->prepare(
    "SELECT b.id, b.balance, b.penalty, wr.penalty_rate
     FROM bills b
     INNER JOIN water_rates wr ON wr.id = b.rate_id
     WHERE b.due_date < CURDATE()
       AND b.status IN ('unpaid', 'partial')"
);
$stmt->execute();
$targets = $stmt->fetchAll();

$penaltyEnabled = setting('penalty_enabled', '1') === '1';
$penalized = 0;
$flipped   = 0;

$pdo->beginTransaction();
try {
    $update = $pdo->prepare(
        'UPDATE bills SET penalty = penalty + ?, total_amount = total_amount + ?, balance = balance + ?, status = "overdue" WHERE id = ?'
    );
    foreach ($targets as $b) {
        $add = 0.0;
        if ($penaltyEnabled && (float)$b['penalty'] === 0.0 && (float)$b['penalty_rate'] > 0) {
            $add = round((float)$b['balance'] * ((float)$b['penalty_rate'] / 100), 2);
            $penalized++;
        }
        $update->execute([$add, $add, $add, (int)$b['id']]);
        $flipped++;
    }
    $pdo->commit();

    log_activity('SWEEP', 'Bills', "[cron] Overdue sweep: $flipped flagged, $penalized penalized");
    echo "[OK] " . date('c') . " — flagged: $flipped, penalized: $penalized\n";
} catch (Throwable $ex) {
    $pdo->rollBack();
    fwrite(STDERR, "[FAIL] " . $ex->getMessage() . "\n");
    exit(1);
}