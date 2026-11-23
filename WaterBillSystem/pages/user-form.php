<?php
require_once __DIR__ . '/../includes/init.php';
require_role('admin');

$pdo    = db();
$me     = auth_user();
$id     = (int)get('id', 0);
$isEdit = $id > 0;
$errors = [];

$user = [
    'username'    => '',
    'full_name'   => '',
    'email'       => '',
    'phone'       => '',
    'role'        => 'staff',
    'customer_id' => null,
    'status'      => 'active',
];

$existing = null;

if ($isEdit) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    if (!$existing) {
        flash('danger', 'User not found.');
        redirect('/pages/users.php');
    }
    $user = $existing;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $user['username']    = trim((string)post('username', ''));
    $user['full_name']   = trim((string)post('full_name', ''));
    $user['email']       = trim((string)post('email', ''));
    $user['phone']       = trim((string)post('phone', ''));
    $user['role']        = (string)post('role', 'staff');
    $user['status']      = (string)post('status', 'active');
    $user['customer_id'] = post('customer_id') ? (int)post('customer_id') : null;

    $password = (string)post('password', '');

    if ($user['username'] === '')  $errors[] = 'Username is required.';
    if (strlen($user['username']) < 4) $errors[] = 'Username must be at least 4 characters.';
    if ($user['full_name'] === '') $errors[] = 'Full name is required.';
    if ($user['email'] !== '' && !filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email address.';
    }
    if (!in_array($user['role'], ['admin', 'staff', 'customer'], true)) {
        $errors[] = 'Invalid role.';
    }
    if (!in_array($user['status'], ['active', 'inactive'], true)) {
        $errors[] = 'Invalid status.';
    }

    if ($user['role'] === 'customer' && !$user['customer_id']) {
        $errors[] = 'A customer login must be linked to a customer record.';
    }
    if ($user['role'] !== 'customer') {
        $user['customer_id'] = null;
    }

    if (!$isEdit && strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($isEdit && $password !== '' && strlen($password) < 8) {
        $errors[] = 'New password must be at least 8 characters (or leave blank to keep current).';
    }

    $sql    = 'SELECT id FROM users WHERE username = ?';
    $params = [$user['username']];
    if ($isEdit) { $sql .= ' AND id <> ?'; $params[] = $id; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if ($stmt->fetch()) $errors[] = 'That username is already taken.';

    if ($user['role'] === 'customer' && $user['customer_id']) {
        $sql    = 'SELECT id FROM users WHERE customer_id = ? AND role = "customer"';
        $params = [$user['customer_id']];
        if ($isEdit) { $sql .= ' AND id <> ?'; $params[] = $id; }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetch()) $errors[] = 'That customer already has a login account.';

        $stmt = $pdo->prepare('SELECT id FROM customers WHERE id = ?');
        $stmt->execute([$user['customer_id']]);
        if (!$stmt->fetch()) $errors[] = 'Selected customer does not exist.';
    }

    if ($isEdit && (int)$existing['id'] === (int)$me['id']) {
        if ($user['role'] !== 'admin') $errors[] = 'You cannot change your own role away from admin.';
        if ($user['status'] !== 'active') $errors[] = 'You cannot deactivate your own account.';
    }

    if ($isEdit && $existing['role'] === 'admin' && $user['role'] !== 'admin') {
        $activeAdmins = (int)$pdo->query(
            "SELECT COUNT(*) FROM users WHERE role='admin' AND status='active'"
        )->fetchColumn();
        if ($activeAdmins <= 1) $errors[] = 'Cannot demote the last active administrator.';
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            if ($isEdit) {
                $stmt = $pdo->prepare(
                    'UPDATE users SET username=?, full_name=?, email=?, phone=?, role=?, customer_id=?, status=? WHERE id=?'
                );
                $stmt->execute([
                    $user['username'], $user['full_name'],
                    $user['email']  ?: null, $user['phone'] ?: null,
                    $user['role'], $user['customer_id'], $user['status'],
                    $id,
                ]);
                if ($password !== '') {
                    $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                    $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
                }
                log_activity('UPDATE', 'Users', "Updated user #$id ({$user['username']})");
                flash('success', 'User updated.');
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO users (username, password_hash, full_name, email, phone, role, customer_id, status)
                     VALUES (?,?,?,?,?,?,?,?)'
                );
                $stmt->execute([
                    $user['username'],
                    password_hash($password, PASSWORD_DEFAULT),
                    $user['full_name'],
                    $user['email']  ?: null,
                    $user['phone']  ?: null,
                    $user['role'], $user['customer_id'], $user['status'],
                ]);
                $id = (int)$pdo->lastInsertId();
                log_activity('CREATE', 'Users', "Created user #$id ({$user['username']}) — role {$user['role']}");
                flash('success', 'User created.');
            }
            $pdo->commit();
            redirect('/pages/users.php');
        } catch (Throwable $ex) {
            $pdo->rollBack();
            $errors[] = $ex->getMessage();
        }
    }
}

$allCustomers = $pdo->query(
    "SELECT id, customer_code, CONCAT(first_name,' ',last_name) AS name
     FROM customers WHERE status='active' ORDER BY last_name, first_name"
)->fetchAll();

$__title = $isEdit ? 'Edit User' : 'Create User';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <a href="<?= e(url('/pages/users.php')) ?>" class="text-decoration-none small">
    <i class="bi bi-arrow-left me-1"></i>Back to Users
  </a>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger py-2"><?= e($err) ?></div>
<?php endforeach; ?>

<form method="post" novalidate autocomplete="off">
  <?= csrf_field() ?>
  <div class="card mb-3">
    <div class="card-header"><i class="bi bi-person me-2"></i>Account Information</div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Username <span class="text-danger">*</span></label>
          <input name="username" class="form-control" value="<?= e($user['username']) ?>" required minlength="4">
        </div>
        <div class="col-md-6">
          <label class="form-label">Full Name <span class="text-danger">*</span></label>
          <input name="full_name" class="form-control" value="<?= e($user['full_name']) ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Email</label>
          <input type="email" name="email" class="form-control" value="<?= e($user['email']) ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Phone</label>
          <input name="phone" class="form-control" value="<?= e($user['phone']) ?>">
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header"><i class="bi bi-shield me-2"></i>Role & Access</div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Role</label>
          <select name="role" id="roleSelect" class="form-select">
            <?php foreach (['admin','staff','customer'] as $r): ?>
              <option value="<?= $r ?>" <?= $user['role'] === $r ? 'selected' : '' ?>>
                <?= ucfirst($r) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <option value="active"   <?= $user['status'] === 'active'   ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $user['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
          </select>
        </div>
        <div class="col-md-4" id="customerLinkWrap" style="display:none">
          <label class="form-label">Linked Customer <span class="text-danger">*</span></label>
          <select name="customer_id" class="form-select">
            <option value="">— Select a customer —</option>
            <?php foreach ($allCustomers as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= (int)($user['customer_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
                <?= e($c['customer_code'] . ' — ' . $c['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header">
      <i class="bi bi-key me-2"></i>Password
      <?php if ($isEdit): ?><small class="text-muted">(leave blank to keep current)</small><?php endif; ?>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label"><?= $isEdit ? 'New Password' : 'Password' ?><?= $isEdit ? '' : ' <span class="text-danger">*</span>' ?></label>
          <input type="password" name="password" class="form-control" <?= $isEdit ? '' : 'required' ?> minlength="8">
          <small class="text-muted">Minimum 8 characters.</small>
        </div>
      </div>
    </div>
  </div>

  <div class="d-flex gap-2">
    <button class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i><?= $isEdit ? 'Save Changes' : 'Create User' ?></button>
    <a href="<?= e(url('/pages/users.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
  </div>
</form>

<script>
(function () {
  const roleSelect = document.getElementById('roleSelect');
  const wrap       = document.getElementById('customerLinkWrap');
  function sync() { wrap.style.display = roleSelect.value === 'customer' ? '' : 'none'; }
  roleSelect.addEventListener('change', sync);
  sync();
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>