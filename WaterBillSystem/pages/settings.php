<?php
require_once __DIR__ . '/../includes/init.php';
require_role('admin');

$pdo = db();
$me  = auth_user();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $all = $pdo->query('SELECT * FROM system_settings')->fetchAll();

    $update = $pdo->prepare(
        'UPDATE system_settings SET setting_value = ? WHERE setting_key = ?'
    );

    $pdo->beginTransaction();
    try {
        foreach ($all as $s) {
            $key = $s['setting_key'];
            $newValue = null;

            switch ($s['input_type']) {
                case 'toggle':
                    $newValue = post($key) === '1' ? '1' : '0';
                    break;
                case 'number':
                    $raw = post($key, '');
                    if ($raw !== '') {
                        if (!is_numeric($raw)) throw new RuntimeException("'{$s['label']}' must be a number.");
                        $newValue = (string)(0 + $raw);
                    }
                    break;
                case 'textarea':
                case 'text':
                default:
                    $newValue = trim((string)post($key, ''));
                    break;
            }

            if ($newValue === null) continue;

            if ($key === 'due_days' && (!is_numeric($newValue) || (int)$newValue < 0 || (int)$newValue > 90)) {
                throw new RuntimeException('Due days must be between 0 and 90.');
            }
            if ($key === 'bill_prefix' && !preg_match('/^[A-Z0-9]{1,6}$/i', $newValue)) {
                throw new RuntimeException('Bill prefix must be 1–6 letters/numbers.');
            }
            if ($key === 'receipt_prefix' && !preg_match('/^[A-Z0-9]{1,6}$/i', $newValue)) {
                throw new RuntimeException('Receipt prefix must be 1–6 letters/numbers.');
            }

            if ($s['setting_value'] !== $newValue) {
                $update->execute([$newValue, $key]);
            }
        }
        $pdo->commit();
        log_activity('UPDATE', 'Settings', 'Updated system settings');
        flash('success', 'Settings saved.');
        redirect('/pages/settings.php');
    } catch (Throwable $ex) {
        $pdo->rollBack();
        $errors[] = $ex->getMessage();
    }
}

$grouped = [];
foreach ($pdo->query('SELECT * FROM system_settings ORDER BY setting_group, setting_key') as $s) {
    $grouped[$s['setting_group']][] = $s;
}

$groupLabels = [
    'provider' => 'Water Provider Details',
    'general'  => 'General',
    'billing'  => 'Billing Configuration',
];

$__title = 'System Settings';
include __DIR__ . '/../includes/header.php';
?>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger py-2"><?= e($err) ?></div>
<?php endforeach; ?>

<form method="post" novalidate>
  <?= csrf_field() ?>

  <?php foreach ($grouped as $group => $settings): ?>
    <div class="card mb-3">
      <div class="card-header">
        <i class="bi bi-<?= $group === 'provider' ? 'building' : ($group === 'billing' ? 'receipt' : 'sliders') ?> me-2"></i>
        <?= e($groupLabels[$group] ?? ucfirst($group)) ?>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <?php foreach ($settings as $s):
            $val = $s['setting_value'];
            $wide = $s['input_type'] === 'textarea';
          ?>
            <div class="col-md-<?= $wide ? '12' : '6' ?>">
              <?php if ($s['input_type'] === 'toggle'): ?>
                <div class="form-check form-switch mt-4">
                  <input class="form-check-input" type="checkbox"
                         name="<?= e($s['setting_key']) ?>" id="s_<?= e($s['setting_key']) ?>"
                         value="1" <?= $val === '1' ? 'checked' : '' ?>>
                  <label class="form-check-label" for="s_<?= e($s['setting_key']) ?>">
                    <?= e($s['label']) ?>
                  </label>
                </div>
              <?php elseif ($s['input_type'] === 'textarea'): ?>
                <label class="form-label"><?= e($s['label']) ?></label>
                <textarea name="<?= e($s['setting_key']) ?>" class="form-control" rows="2"><?= e($val) ?></textarea>
              <?php elseif ($s['input_type'] === 'number'): ?>
                <label class="form-label"><?= e($s['label']) ?></label>
                <input type="number" step="0.01" name="<?= e($s['setting_key']) ?>"
                       class="form-control" value="<?= e($val) ?>">
              <?php else: ?>
                <label class="form-label"><?= e($s['label']) ?></label>
                <input name="<?= e($s['setting_key']) ?>" class="form-control" value="<?= e($val) ?>">
              <?php endif; ?>
              <small class="text-muted"><code><?= e($s['setting_key']) ?></code></small>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>

  <div class="d-flex gap-2">
    <button class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i>Save Settings</button>
    <a href="<?= e(url('/pages/dashboard.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
  </div>
</form>

<div class="card mt-3">
  <div class="card-header"><i class="bi bi-info-circle me-2"></i>About</div>
  <div class="card-body small">
    <div class="row g-2">
      <div class="col-md-4"><span class="text-muted">Application</span><br><strong><?= e(APP_NAME) ?></strong></div>
      <div class="col-md-4"><span class="text-muted">Version</span><br><strong><?= e(APP_VERSION) ?></strong></div>
      <div class="col-md-4"><span class="text-muted">Server Time (<?= e(APP_TIMEZONE) ?>)</span><br><strong><?= e(date('F j, Y g:i A')) ?></strong></div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>